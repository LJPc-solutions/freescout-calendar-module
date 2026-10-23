<?php

namespace Modules\LJPcCalendarModule\Tests\Support;

use App\Attachment;
use App\Conversation;
use App\Thread;

/** Customer messages with invite files in a conversation; the using test fakes the attachment disk first. */
trait InviteMail
{
    protected static function inviteFixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/invites/' . $name);
    }

    /** A customer's message in $conversation with these attachments: file name => content. */
    protected function inviteThread(Conversation $conversation, array $files, string $from = 'anna@example.com'): Thread
    {
        $thread = factory(Thread::class)->create([
            'conversation_id' => $conversation->id, 'type' => Thread::TYPE_CUSTOMER, 'from' => $from, 'has_attachments' => true,
        ]);
        foreach ($files as $name => $content) {
            $mime = str_ends_with(strtolower($name), '.pdf') ? 'application/pdf' : 'text/calendar';
            Attachment::create($name, $mime, Attachment::TYPE_TEXT, $content, null, false, $thread->id);
        }

        return $thread->fresh();
    }
}
