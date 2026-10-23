<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\FrontendConfig;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\DashboardCard;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\LineItemText;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class IntegrationHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function lineItem(Conversation $conversation, array $meta): Thread
    {
        return Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'created_by_user_id' => auth()->id(),
            'action_type' => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
            'meta'        => $meta,
        ]);
    }

    public function test_line_items_render_for_v3_and_v2_meta_and_escape_the_calendar_name(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => '<img src=x onerror=alert(1)> ' . uniqid(), 'provider' => 'local']);
        $event = app(EventService::class)->create($calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $event->update(['legacy_id' => '291']);

        $v3 = LineItemText::render(Thread::where('conversation_id', $conversation->id)->where('action_type', 186)->first(), true);
        $this->assertStringContainsString(':person', $v3); // FreeScout core fills in the person
        $this->assertStringContainsString('#e' . $event->id . '"', $v3);
        $this->assertStringContainsString('&lt;img', $v3);
        $this->assertStringNotContainsString('<img', $v3);

        $v2 = LineItemText::render($this->lineItem($conversation, ['calendar_item_id' => 291, 'calendar_id' => (string) $calendar->id]), true);
        $this->assertStringContainsString('#e' . $event->id . '"', $v2);

        $gone = LineItemText::render($this->lineItem($conversation, ['calendar_item_id' => 1, 'calendar_id' => (string) $calendar->id]), true);
        $this->assertStringContainsString('deleted by now', $gone);

        $plain = LineItemText::render($this->lineItem($conversation, ['event_id' => $event->id, 'calendar_id' => $calendar->id]), false);
        $this->assertStringNotContainsString('<a ', $plain);
    }

    public function test_a_line_item_follows_its_event_to_another_calendar(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $from = Calendar::create(['name' => 'Before move ' . uniqid(), 'provider' => 'local']);
        $to = Calendar::create(['name' => 'After move ' . uniqid(), 'provider' => 'local']);
        $event = app(EventService::class)->create($from, $user, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();

        app(EventService::class)->update($event, $user, ['calendar_id' => $to->id]);
        $moved = LineItemText::render($thread, true);

        $this->assertStringContainsString($to->name, $moved);
        $this->assertStringNotContainsString($from->name, $moved);
        $this->assertStringNotContainsString('deleted by now', $moved);

        // The calendar it was added to may be deleted later; the event lives on elsewhere.
        $from->delete();
        $this->assertStringContainsString($to->name, LineItemText::render($thread, true));

        // Only a deleted event falls back to the calendar it was added to.
        $event->fresh()->delete();
        $this->assertSame('Added to a calendar, but the calendar has been deleted by now.', LineItemText::render($thread, true));
    }

    public function test_dashboard_card_lists_upcoming_events_from_dashboard_calendars_only(): void
    {
        $user = $this->makeUser();
        $shown = Calendar::create(['name' => 'Dash ' . uniqid(), 'provider' => 'local']);
        $hidden = Calendar::create(['name' => 'NoDash ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $shown->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        CalendarPermission::create(['calendar_id' => $hidden->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);
        $soon = (new DateTimeImmutable('+2 hours', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d\TH:i');
        app(EventService::class)->create($shown, $user, ['title' => 'Visible soon', 'start' => $soon]);
        app(EventService::class)->create($hidden, $user, ['title' => 'Not on dashboard', 'start' => $soon]);

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString('Visible soon', $html);
        $this->assertStringNotContainsString('Not on dashboard', $html);
        $this->assertSame('', app(DashboardCard::class)->render($this->makeUser()));
    }

    public function test_calendar_page_is_a_shell_with_html_safe_json_config(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);

        $response = $this->actingAs($user)->get('/calendar');

        $response->assertStatus(200);
        $this->assertStringContainsString('id="ljpc-calendar-config"', $response->getContent());
        $this->assertStringContainsString('"timezone":"Europe\/Amsterdam"', $response->getContent());
        $this->assertStringNotContainsString('</script><script>', FrontendConfig::json(['x' => '</script><script>alert(1)</script>']));
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/build/calendar\.js\?v=\d+" nonce="[^"]+"></script>#', $response->getContent());
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/build/calendar\.css\?v=\d+">#', $response->getContent());
        // The page fills the window, so core's footer is left out (it would add a second scrollbar).
        $this->assertStringNotContainsString('<div class="footer">', $response->getContent());
    }

    public function test_page_config_follows_the_agents_language_clock_and_role(): void
    {
        $agent = $this->makeUser(['locale' => 'nl', 'time_format' => \App\User::TIME_FORMAT_12]);
        app()->setLocale('nl');

        $config = FrontendConfig::page($agent);

        $this->assertSame(['nl', true, null], [$config['locale'], $config['hour12'], $config['routes']['settings']]);
        $this->assertFalse($config['rtl']);
        $this->assertArrayHasKey('Every :count weeks', $config['strings']);
        $this->assertStringContainsString('__ID__', $config['routes']['event']);
        $this->assertSame(SettingsPage::url(), FrontendConfig::page($this->makeAdmin())['routes']['settings']);
    }

    private function dashboardUser(string $calendarName, string $title): \App\User
    {
        $user = $this->makeUser();
        $calendar = Calendar::create(['name' => $calendarName, 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        $soon = (new DateTimeImmutable('+2 hours', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d\TH:i');
        app(EventService::class)->create($calendar, $user, ['title' => $title, 'start' => $soon]);

        return $user;
    }

    public function test_dashboard_card_links_each_event_to_its_permalink_without_inline_layout(): void
    {
        $user = $this->dashboardUser('Linked ' . uniqid(), 'Linked event');
        $event = CalendarEvent::where('title_input', 'Linked event')->orderBy('id', 'desc')->firstOrFail();

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString(e(route('ljpccalendarmodule.index')) . '#e' . $event->id . '"', $html);
        $this->assertStringNotContainsString('style="display: flex', $html);
        $this->assertStringNotContainsString('ljpc_move_dash_card', $html);
    }

    public function test_dashboard_card_sits_in_a_card_row_and_moves_itself_among_the_mailbox_cards(): void
    {
        $user = $this->dashboardUser('Row ' . uniqid(), 'Row event');

        $html = app(DashboardCard::class)->render($user);

        // Its own card row keeps the layout intact without JavaScript; the script then joins the mailbox cards' row.
        $this->assertMatchesRegularExpression('#<div class="dash-cards margin-top ljpc-dash-cards">\s*<div class="dash-card dash-card-calendar">#', $html);
        $this->assertStringContainsString('<script nonce="' . \Helper::cspNonce() . '">', $html);
        $this->assertStringContainsString("querySelector('.dash-cards:not(.ljpc-dash-cards)')", $html);
    }

    public function test_dashboard_card_times_follow_the_agents_clock(): void
    {
        $tomorrow = new DateTimeImmutable('tomorrow 14:30', new DateTimeZone('Europe/Amsterdam'));
        $calendar = Calendar::create(['name' => 'Clock ' . uniqid(), 'provider' => 'local']);
        $clocks = ['h:ia' => \App\User::TIME_FORMAT_12, 'H:i' => \App\User::TIME_FORMAT_24];
        foreach ($clocks as $format => $clock) {
            $user = $this->makeUser(['timezone' => 'Europe/Amsterdam', 'time_format' => $clock]);
            CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
            if ($clock === \App\User::TIME_FORMAT_12) {
                app(EventService::class)->create($calendar, $user, ['title' => 'Afternoon call', 'start' => $tomorrow->format('Y-m-d\TH:i')]);
            }

            $html = app(DashboardCard::class)->render($user);

            // "02:30pm" on a 12-hour clock, as core shows times; "14:30" on a 24-hour one.
            $this->assertStringContainsString('<span class="ljpc-dash-when">' . $tomorrow->format($format) . ',', $html);
            $this->assertStringContainsString('title="' . $tomorrow->format('Y-m-d ' . $format) . '"', $html);
        }
    }

    public function test_dashboard_card_shows_events_without_a_link_when_the_agent_may_not_view_their_calendar(): void
    {
        $user = $this->makeUser();
        $viewable = Calendar::create(['name' => 'Viewable ' . uniqid(), 'provider' => 'local']);
        $dashboardOnly = Calendar::create(['name' => 'Dashboard only ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $viewable->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        CalendarPermission::create(['calendar_id' => $dashboardOnly->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true]);
        $soon = (new DateTimeImmutable('+2 hours', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d\TH:i');
        $linked = app(EventService::class)->create($viewable, $user, ['title' => 'Linked soon', 'start' => $soon]);
        $plain = app(EventService::class)->create($dashboardOnly, $user, ['title' => 'Plain soon', 'start' => $soon]);

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString('#e' . $linked->id . '" class="ljpc-dash-title">Linked soon</a>', $html);
        $this->assertStringContainsString('<span class="ljpc-dash-title">Plain soon</span>', $html);
        $this->assertStringNotContainsString('#e' . $plain->id . '"', $html);
    }

    public function test_dashboard_card_links_an_occurrence_of_a_repeating_event_to_that_occurrence(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $calendar = Calendar::create(['name' => 'Weekly ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        // Weekly series that began two weeks ago: the card shows tomorrow's occurrence, not the series' first date.
        $tomorrow = new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Europe/Amsterdam'));
        $first = $tomorrow->modify('-14 days');
        $weekly = ['freq' => 'WEEKLY', 'count' => 5];
        $timed = app(EventService::class)->create($calendar, $user, ['title' => 'Weekly standup', 'start' => $first->format('Y-m-d\TH:i'), 'recurrence' => $weekly]);
        $allDay = app(EventService::class)->create($calendar, $user, ['title' => 'Weekly rounds', 'all_day' => true, 'start' => $first->format('Y-m-d'), 'recurrence' => $weekly]);

        $html = app(DashboardCard::class)->render($user);

        $page = e(route('ljpccalendarmodule.index'));
        $this->assertStringContainsString($page . '#e' . $timed->id . '@' . $tomorrow->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . '"', $html);
        $this->assertStringContainsString($page . '#e' . $allDay->id . '@' . $tomorrow->format('Ymd') . '"', $html);
    }

    public function test_the_module_stylesheet_is_added_to_freescout_pages(): void
    {
        $this->assertContains(\Module::getPublicPath(LJPC_CALENDARS_MODULE) . '/css/module.css', \Eventy::filter('stylesheets', []));
    }

    public function test_settings_section_renders_through_core_for_admins_and_is_forbidden_for_users(): void
    {
        $response = $this->actingAs($this->makeAdmin())->get(route('settings', ['section' => 'calendar']));
        $response->assertStatus(200);
        $this->assertStringContainsString('class="ljpc-settings"', $response->getContent());

        $this->actingAs($this->makeUser())->get(route('settings', ['section' => 'calendar']))->assertStatus(403);
    }

    public function test_invite_line_items_say_what_happened_and_escape_the_title(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => 'Team ' . uniqid(), 'provider' => 'local']);
        $event = app(EventService::class)->create($calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00']);
        $title = '<b>Review</b>';
        $text = function (string $action, array $meta = [], ?CalendarEvent $about = null) use ($conversation, $user, $title) {
            \Modules\LJPcCalendarModule\Services\InviteLineItem::add($conversation, $user, $action, $about, ['title' => $title] + $meta);
            $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->orderBy('id', 'desc')->first();

            return $thread->getActionText('', true, false, null, 'PERSONX');
        };
        $safe = e($title);

        $added = $text('added', [], $event);
        $this->assertStringContainsString(e(__(':person added the invite ":title" to calendar ":calendar".', ['title' => $title, 'calendar' => $calendar->name, 'person' => 'PERSONX'])), $added);
        $this->assertStringContainsString('#e' . $event->id . '"', $added);
        $this->assertStringNotContainsString('<b>', $added);
        $this->assertStringContainsString($safe, $text('updated', [], $event));
        $this->assertStringContainsString(e(__(':person applied the cancellation of the invite ":title".', ['title' => $title, 'person' => 'PERSONX'])), $text('cancelled'));
        $this->assertStringContainsString(e(__(':person declined the invite ":title".', ['title' => $title, 'person' => 'PERSONX'])), $text('answered', ['answer' => 'declined']));
        $this->assertStringContainsString(e(__(':person sent the invite ":title" to :to.', ['title' => $title, 'to' => 'dana@example.com', 'person' => 'PERSONX'])), $text('sent', ['to' => 'dana@example.com']));
        $this->assertStringContainsString(e(__(':name accepted ":title".', ['name' => 'Dana <x>', 'title' => $title])), $text('attendee_answered', ['name' => 'Dana <x>', 'answer' => 'accepted']));
        $this->assertStringNotContainsString('View event', $text('cancelled'), 'no link without an event');
    }

    public function test_line_item_goes_through_core_get_action_text(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => '<img src=x onerror=alert(1)> Cal', 'provider' => 'local']);
        $event = app(EventService::class)->create($calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();

        $escaped = $thread->getActionText('', true, false, null, 'PERSONX');
        $this->assertStringContainsString('PERSONX', $escaped);
        $this->assertStringContainsString('&lt;img', $escaped);
        $this->assertStringNotContainsString('<img', $escaped);
        $this->assertStringContainsString('View event', $escaped);
        $this->assertStringContainsString('#e' . $event->id . '"', $escaped);

        $plain = $thread->getActionText('', false, false, null, 'PERSONX');
        $this->assertStringNotContainsString('<a ', $plain);
    }

    public function test_dashboard_card_escapes_calendar_names_and_event_titles(): void
    {
        $user = $this->dashboardUser('<b>Bold</b> Cal', '<script>alert(1)</script> Soon');

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
    }

    public function test_dashboard_before_filter_renders_the_card_and_survives_a_failing_card(): void
    {
        $user = $this->dashboardUser('Dash ' . uniqid(), '<i>Filter</i> Soon');
        $this->actingAs($user);

        $html = \Eventy::filter('dashboard.before', '');
        $this->assertStringContainsString('&lt;i&gt;Filter&lt;/i&gt; Soon', $html);

        $fake = new class(app(OccurrenceService::class)) extends DashboardCard {
            public function render(\App\User $user): string
            {
                throw new \RuntimeException('card failed');
            }
        };
        app()->instance(DashboardCard::class, $fake);

        $this->assertSame('BEFORE', \Eventy::filter('dashboard.before', 'BEFORE'));
    }

    public function test_a_failing_line_item_keeps_the_conversation_and_its_notifications_rendering(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $mailbox = $this->makeMailbox([$user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => 'Failing ' . uniqid(), 'provider' => 'local']);
        app(EventService::class)->create($calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();

        // Any error while rendering, for example module code that runs before its tables were migrated.
        Calendar::retrieved(function () {
            throw new \RuntimeException('line item failed');
        });
        try {
            // The arguments of core's conversation thread partial (conversations/partials/thread.blade.php).
            $this->assertStringContainsString('Added to a calendar.', $thread->getActionText('', true, false, null, 'PERSONX'));
            // Browser notifications.
            $this->assertStringContainsString('Added to a calendar.', $thread->getActionDescription($conversation->number, true, $user));

            // The arguments of core's agent notification emails (emails/user/notification.blade.php and notification_text).
            $this->assertStringContainsString('Added to a calendar.', $thread->getActionText('', true, false, $user, 'PERSONX'));
        } finally {
            app('events')->forget('eloquent.retrieved: ' . Calendar::class);
        }
    }

    public function test_line_item_times_use_the_viewing_users_timezone(): void
    {
        $creator = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $this->actingAs($creator);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$creator->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => 'Zones ' . uniqid(), 'provider' => 'local']);
        app(EventService::class)->create($calendar, $creator, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();

        // Core passes the viewer as the fifth filter argument (browser notifications); it wins over the logged-in user.
        $viewer = $this->makeUser(['timezone' => 'America/New_York']);
        $text = $thread->getActionDescription($conversation->number, true, $viewer);

        $this->assertStringContainsString('20-10-2026 04:00', $text);
    }

    public function test_line_item_times_follow_the_viewers_clock_and_name_the_zone_without_a_viewer(): void
    {
        $creator = $this->makeUser(['timezone' => 'Europe/Amsterdam', 'time_format' => \App\User::TIME_FORMAT_12]);
        $this->actingAs($creator);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$creator->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => 'Clocks ' . uniqid(), 'provider' => 'local']);
        app(EventService::class)->create($calendar, $creator, ['title' => 'x', 'start' => '2026-10-20T14:30'], $conversation);
        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();

        // "02:30pm" on a 12-hour clock, as core shows times; "14:30" on a 24-hour one.
        $twelve = $this->makeUser(['timezone' => 'Europe/Amsterdam', 'time_format' => \App\User::TIME_FORMAT_12]);
        $this->assertStringContainsString('Starts at 20-10-2026 02:30pm.', $thread->getActionDescription($conversation->number, true, $twelve));
        $twentyFour = $this->makeUser(['timezone' => 'Europe/Amsterdam', 'time_format' => \App\User::TIME_FORMAT_24]);
        $this->assertStringContainsString('Starts at 20-10-2026 14:30.', $thread->getActionDescription($conversation->number, true, $twentyFour));

        // The logged-in agent's clock applies when core passes no viewer; with nobody at all it is 24-hour.
        $this->assertStringContainsString('Starts at 20-10-2026 02:30pm.', LineItemText::render($thread, false));
        auth()->logout();
        $appZone = new DateTimeZone(config('app.timezone') ?: 'UTC');
        $expected = (new DateTimeImmutable('2026-10-20 14:30', new DateTimeZone('Europe/Amsterdam')))->setTimezone($appZone)->format('d-m-Y H:i');
        $this->assertStringContainsString('Starts at ' . $expected . ' (' . $appZone->getName() . ').', LineItemText::render($thread, false));
    }

    public function test_json_config_is_html_safe_and_round_trips(): void
    {
        $value = "</script><script>alert('1')</script> & \"q\"";
        $json = FrontendConfig::json(['x' => $value]);

        $this->assertDoesNotMatchRegularExpression('/[<>&\']/', $json);
        $this->assertSame($value, json_decode($json, true)['x']);

        $content = $this->actingAs($this->makeUser())->get('/calendar')->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="ljpc-calendar-config">(.*?)</script>#s', $content, $m));
        $config = json_decode($m[1], true);
        $this->assertNotEmpty($config['timezone']);
        $this->assertNotEmpty($config['routes']['events']);
    }

    public function test_menu_links_to_the_calendar_page_from_a_core_page(): void
    {
        $content = $this->actingAs($this->makeUser())->get('/')->getContent();

        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('ljpccalendarmodule.index'), '#') . '"[^>]*>[^<]*Calendar#s', $content);
    }

    public function test_dashboard_card_orders_all_day_and_timed_events_by_their_real_start(): void
    {
        // In Auckland (UTC+13 in October) an all-day event of tomorrow starts 11:00 UTC today, while 01:00 tomorrow is
        // 12:00 UTC today. Comparing the stored strings ("tomorrow 00:00" against "today 12:00") would flip them.
        $user = $this->makeUser(['timezone' => 'Pacific/Auckland']);
        $calendar = Calendar::create(['name' => 'Order ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Pacific/Auckland')))->format('Y-m-d');
        app(EventService::class)->create($calendar, $user, ['title' => 'Early tomorrow', 'start' => $tomorrow . 'T01:00']);
        app(EventService::class)->create($calendar, $user, ['title' => 'All day tomorrow', 'all_day' => true, 'start' => $tomorrow]);

        $html = app(DashboardCard::class)->render($user);

        $this->assertNotFalse(strpos($html, 'Early tomorrow'));
        $this->assertLessThan(strpos($html, 'Early tomorrow'), strpos($html, 'All day tomorrow'));
    }
}
