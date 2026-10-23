<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Option;
use App\User;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class ConversationModalTest extends TestCase
{
    private function conversationFor(User $user): Conversation
    {
        return factory(Conversation::class)->create([
            'mailbox_id'     => $this->makeMailbox([$user->id])->id,
            'status'         => Conversation::STATUS_ACTIVE,
            'subject'        => '</script><script>alert(1)</script> Printer',
            'customer_email' => 'customer@example.test',
            'user_id'        => $user->id,
        ]);
    }

    private function calendarWith(User $user, array $rights): Calendar
    {
        $calendar = Calendar::create(['name' => 'Conv ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id] + $rights);

        return $calendar;
    }

    /** What the toolbar prints, through the same hook core's conversation view calls (rendering that view fails under test on PHP 8.3). */
    private function toolbar(User $user, Conversation $conversation): string
    {
        $this->actingAs($user);
        ob_start();
        \Eventy::action('conversation.action_buttons', $conversation, $conversation->mailbox);

        return (string) ob_get_clean();
    }

    public function test_agents_who_can_create_somewhere_get_the_button_and_a_safe_config(): void
    {
        $user = $this->makeUser(['first_name' => 'Kim', 'last_name' => 'Agent']);
        $this->calendarWith($user, ['can_view' => true, 'can_create' => true]);
        $conversation = $this->conversationFor($user);

        $html = $this->toolbar($user, $conversation);

        $this->assertStringContainsString('ljpc-add-to-calendar', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/build/conversation\.js\?v=\d+" nonce="[^"]+"></script>#', $html);
        $this->assertSame(1, preg_match('#<script type="application/json" id="ljpc-conversation-config">(.*?)</script>#s', $html, $match));
        $config = json_decode($match[1], true);
        $this->assertSame([$conversation->id, 'customer@example.test', 'Kim Agent'], [$config['conversation']['id'], $config['conversation']['customer_email'], $config['conversation']['assignee']]);
        $this->assertStringContainsString('alert(1)', $config['conversation']['subject']);
        $this->assertStringEndsWith('/calendar/api/conversations/' . $conversation->id . '/events', $config['routes']['conversation_events']);
    }

    public function test_no_button_without_a_calendar_to_create_in_or_while_the_upgrade_runs(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarWith($user, ['can_view' => true]);
        $conversation = $this->conversationFor($user);
        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));

        CalendarPermission::where('calendar_id', $calendar->id)->update(['can_create' => true]);
        Option::set(MigrationService::STATE_OPTION, 'running');
        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));
    }

    public function test_read_only_and_disabled_calendars_offer_no_button(): void
    {
        $user = $this->makeUser();
        $conversation = $this->conversationFor($user);
        $feed = Calendar::create(['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://calendar.example.com/feed.ics']);
        CalendarPermission::create(['calendar_id' => $feed->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
        $disabled = $this->calendarWith($user, ['can_view' => true, 'can_create' => true]);
        $disabled->update(['enabled' => false]);

        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation), 'a feed is read-only and a disabled calendar is not offered');

        $disabled->update(['enabled' => true]);
        $this->assertStringContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));
    }

    public function test_admins_get_the_button_without_permission_rows_once_a_calendar_is_writable(): void
    {
        Calendar::query()->update(['enabled' => false]); // only the calendars of this test count
        $admin = $this->makeAdmin();
        $conversation = $this->conversationFor($admin);
        Calendar::create(['name' => 'Feed ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://calendar.example.com/feed.ics']);

        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($admin, $conversation), 'not even an admin can add to a feed');

        Calendar::create(['name' => 'Desk ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $this->assertStringContainsString('ljpc-add-to-calendar', $this->toolbar($admin, $conversation));
    }

    public function test_a_failing_check_never_breaks_the_conversation_page(): void
    {
        $user = $this->makeUser();
        $conversation = $this->conversationFor($user);
        app()->instance(MigrationService::class, new class(app(OccurrenceService::class)) extends MigrationService {
            public function isFinished(): bool
            {
                throw new \RuntimeException('broken calendar');
            }
        });

        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));
    }
}
