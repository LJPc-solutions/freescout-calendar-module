<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Storage;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\InviteService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\Support\InviteMail;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** The invite card below a message and the clicks it sends (spec sections 12 and 13). */
class InviteCardTest extends TestCase
{
    use InviteMail;

    private User $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::getDiskName());
        app(OccurrenceService::class)->moveWindow(new \DateTimeImmutable('2026-10-05', new \DateTimeZone('UTC')));
        $this->agent = $this->makeUser(['timezone' => 'America/New_York', 'time_format' => User::TIME_FORMAT_12]);
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->makeMailbox([$this->agent->id])->id, 'status' => Conversation::STATUS_ACTIVE, 'customer_email' => 'anna@example.com',
        ]);
    }

    private function calendar(array $rights = ['can_view' => true, 'can_create' => true, 'can_edit' => true]): Calendar
    {
        $calendar = Calendar::create(['name' => 'Team <b>' . uniqid() . '</b>', 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $this->agent->id] + $rights);

        return $calendar;
    }

    /** What core prints below the thread's attachments, through the hook its view calls. */
    private function below(Thread $thread, ?User $user = null): string
    {
        $this->actingAs($user ?? $this->agent);
        ob_start();
        \Eventy::action('thread.attachments_list_append', $thread, $this->conversation, $this->conversation->mailbox);

        return (string) ob_get_clean();
    }

    private function apply(CalendarInvite $invite, array $body = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->agent)->postJson(route('ljpccalendarmodule.api.invites.apply', ['id' => $invite->id]), $body);
    }

    public function test_a_new_invite_shows_its_details_in_the_agents_zone_and_offers_the_calendars_they_can_add_to(): void
    {
        $writable = $this->calendar();
        $viewOnly = $this->calendar(['can_view' => true]);
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => str_replace('SUMMARY:Contract review', 'SUMMARY:Contract <script>review', self::inviteFixture('gmail-request.ics'))]);

        $html = $this->below($thread);

        $this->assertStringContainsString('data-ljpc-invite="', $html);
        $this->assertStringContainsString('Contract &lt;script&gt;review', $html);
        $this->assertStringNotContainsString('<script>review', $html);
        $this->assertStringContainsString('03-11-2026, 04:00am – 05:00am', $html, 'New York, 12-hour clock');
        $this->assertStringContainsString('Anna Example &lt;anna@example.com&gt;', $html);
        $this->assertStringContainsString('<option value="' . $writable->id . '">' . e($writable->name) . '</option>', $html);
        $this->assertStringNotContainsString('<option value="' . $viewOnly->id . '">', $html);
        $this->assertStringContainsString(e(__('Add to calendar')), $html);
        $this->assertSame(1, substr_count($html, 'build/invite.js'), 'the card script once per page');
    }

    public function test_the_card_warns_when_the_sender_is_not_the_organizer(): void
    {
        $this->calendar();
        $html = $this->below($this->inviteThread($this->conversation, ['invite.ics' => self::inviteFixture('gmail-request.ics')], 'someone@example.net'));

        $this->assertStringContainsString(e(__('This mail comes from :sender, not from the organizer :organizer. Check the invite before you use it.', ['sender' => 'someone@example.net', 'organizer' => 'anna@example.com'])), $html);
    }

    public function test_messages_from_agents_get_no_card(): void
    {
        $this->calendar();
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => self::inviteFixture('gmail-request.ics')]);
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->save();

        $this->assertSame('', trim($this->below($thread->fresh())));
    }

    public function test_adding_answers_with_the_card_as_it_is_now(): void
    {
        $calendar = $this->calendar();
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => self::inviteFixture('gmail-request.ics')]);
        $invite = app(InviteService::class)->scanThread($thread)[0];

        $answer = $this->apply($invite, ['calendar_id' => $calendar->id])->assertStatus(200)->json();

        $event = CalendarEvent::where('calendar_id', $calendar->id)->firstOrFail();
        $this->assertSame(__('Added to calendar ":calendar".', ['calendar' => $calendar->name]), $answer['message']);
        $this->assertStringContainsString(e(__('This version is in calendar ":calendar".', ['calendar' => $calendar->name])), $answer['html']);
        $this->assertStringContainsString('#e' . $event->id . '"', $answer['html']);
        $this->assertStringNotContainsString('data-ljpc-invite-form', $answer['html'], 'nothing more to do');
    }

    public function test_the_clicks_check_the_agents_rights(): void
    {
        $viewOnly = $this->calendar(['can_view' => true]);
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => self::inviteFixture('gmail-request.ics')]);
        $invite = app(InviteService::class)->scanThread($thread)[0];

        $this->apply($invite, ['calendar_id' => $viewOnly->id])->assertStatus(403);
        $this->apply($invite, ['calendar_id' => $this->calendar()->id], $this->makeUser())->assertStatus(404); // not in the mailbox
        $this->assertSame(0, CalendarEvent::where('uid', '7kukuqrdgsj8e2a3l4nbo1m1fs@google.com')->count());

        // An update needs the right to edit the calendar the event is in.
        $this->apply($invite, ['calendar_id' => $editable = $this->calendar()->id])->assertStatus(200);
        CalendarPermission::where('calendar_id', $editable)->update(['can_edit' => false]);
        $moved = app(InviteService::class)->scanThread($this->inviteThread($this->conversation, ['invite.ics' => str_replace('SEQUENCE:0', 'SEQUENCE:1', self::inviteFixture('gmail-request.ics'))]))[0];
        $this->apply($moved)->assertStatus(403);
    }

    public function test_a_card_with_nothing_to_do_says_why(): void
    {
        $calendar = $this->calendar();
        $thread = $this->inviteThread($this->conversation, ['invite.ics' => self::inviteFixture('gmail-request.ics')]);
        $invite = app(InviteService::class)->scanThread($thread)[0];
        $this->apply($invite, ['calendar_id' => $calendar->id])->assertStatus(200);

        $this->apply($invite->fresh(), ['calendar_id' => $calendar->id])->assertStatus(422)->assertJsonFragment(['invite' => [__('This invite is already in a calendar.')]]);
        $this->assertStringContainsString(e(__('The event this cancels is in no calendar.')), $this->below($this->inviteThread($this->conversation, ['cancel.ics' => str_replace('7kukuqrdgsj8e2a3l4nbo1m1fs', 'other', self::inviteFixture('cancel-series.ics'))])));
    }
}
