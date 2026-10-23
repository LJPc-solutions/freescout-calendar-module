<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Events\UserReplied;
use App\Thread;
use App\User;
use Illuminate\Validation\ValidationException;

/**
 * Calendar messages sent as replies in the conversation (spec section 13, iMIP): core sends the thread like any reply;
 * the thread's meta holds the iTIP message, which the swiftmessage hook adds as an inline text/calendar part (Outlook
 * only reads it inside multipart/alternative) and the subject hook uses for the subject.
 */
final class ItipMail
{
    public const META = 'ljpccalendarmodule.itip';

    /**
     * @param array<string, string> $to address => name; core addresses the reply to the first one's customer, and to
     *                                  all of them when there are more
     * @param string|null $from the mailbox alias to send from, when the invite names that rather than the mailbox address
     * @param bool $attach whether the iTIP message also goes as invite.ics, for clients that only read attachments
     */
    public static function send(Conversation $conversation, User $user, array $to, ?string $from, string $subject, string $body, string $method, string $ics, bool $attach = false): Thread
    {
        // The recipient becomes a customer, so core addresses the reply to them rather than to the conversation's customer.
        $first = (string) array_key_first($to);
        $name = (string) $to[$first];
        $customer = Customer::create($first, $name !== '' && $name !== $first ? ['first_name' => mb_substr($name, 0, 191)] : []);
        if (!$customer) {
            throw ValidationException::withMessages(['invite' => __('The address :email can\'t receive mail.', ['email' => $first])]);
        }
        // The title in it is the sender's text, which may hold line breaks; a subject is one line.
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));
        // As core's Conversation::createUserThread(), which addresses the conversation's own customer only.
        $thread = Thread::create($conversation, Thread::TYPE_MESSAGE, $body, [
            'meta' => [self::META => ['method' => $method, 'subject' => $subject, 'ics' => $ics]],
        ], false);
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->user_id = $conversation->user_id;
        $thread->customer_id = $customer->id;
        $thread->created_by_user_id = $user->id;
        $thread->setTo(array_keys($to));
        if ($from !== null) {
            $thread->from = $from;
        }
        $thread->save();
        if ($attach) {
            Attachment::create('invite.ics', 'application/ics', Attachment::TYPE_TEXT, $ics, null, false, $thread->id);
            $thread->has_attachments = true;
            $thread->save();
        }
        $conversation->mailbox->updateFoldersCounters();
        event(new UserReplied($conversation, $thread));
        \Eventy::action('conversation.user_replied', $conversation, $thread);

        return $thread;
    }

    /** email.reply_to_customer.swiftmessage: the iTIP part of our replies. */
    public static function addPart(\Swift_Message $message, $thread): void
    {
        $itip = $thread instanceof Thread ? $thread->getMeta(self::META) : null;
        if (!is_array($itip) || empty($itip['ics'])) {
            return;
        }
        $part = new \Swift_MimePart($itip['ics'], 'text/calendar', 'utf-8');
        $part->getHeaders()->get('Content-Type')->setParameter('method', $itip['method']);
        $message->attach($part);
        // Core attaches invite.ics as text/calendar (from its extension); as a second calendar part it could read as a
        // second invite, so it goes as application/ics, as Gmail sends it.
        foreach ($message->getChildren() as $child) {
            if ($child instanceof \Swift_Attachment && $child->getFilename() === 'invite.ics') {
                $child->setContentType('application/ics');
            }
        }
    }

    /** email.reply_to_customer.subject: "Accepted: <title>" and so on, instead of "Re: <conversation subject>". */
    public static function subject($subject, $thread)
    {
        $itip = $thread instanceof Thread ? $thread->getMeta(self::META) : null;

        return is_array($itip) && !empty($itip['subject']) ? $itip['subject'] : $subject;
    }
}
