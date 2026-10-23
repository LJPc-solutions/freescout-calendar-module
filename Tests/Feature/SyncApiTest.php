<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\User;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteEvent;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Tests\Support\FakeProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

/** "Sync now" on the calendar page: the external calendars someone can view, never another calendar's error for agents. */
class SyncApiTest extends TestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeProvider(false);
        app(ProviderRegistry::class)->swap(Calendar::PROVIDER_ICS, $this->provider);
    }

    private function feed(string $name): Calendar
    {
        return Calendar::create(['name' => $name . ' ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'https://calendar.example.com/feed.ics', 'sync_interval' => 60]);
    }

    private static function viewer(User $user, Calendar $calendar): void
    {
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);
    }

    private static function event(string $uid): RemoteEvent
    {
        return new RemoteEvent($uid, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//Feed//EN\r\nBEGIN:VEVENT\r\nUID:" . $uid
            . "\r\nDTSTAMP:20261001T000000Z\r\nSUMMARY:" . $uid . "\r\nDTSTART:20261020T080000Z\r\nDTEND:20261020T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    /** The results by calendar id, without the ones other tests left behind. */
    private function sync(User $user, array $ids): array
    {
        $results = $this->actingAs($user)->postJson(route('ljpccalendarmodule.api.sync'))->assertStatus(200)->json()['calendars'];

        return array_values(array_filter($results, function (array $result) use ($ids) {
            return in_array($result['id'], $ids, true);
        }));
    }

    public function test_an_agent_syncs_the_external_calendars_they_can_view(): void
    {
        $agent = $this->makeUser();
        $mine = $this->feed('Mine');
        $other = $this->feed('Other');
        $local = Calendar::create(['name' => 'Local ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        self::viewer($agent, $mine);
        self::viewer($agent, $local);
        $this->provider->onPull(new ChangeSet([self::event('a@example.com')], [], [], true, true));

        $results = $this->sync($agent, [$mine->id, $other->id, $local->id]);

        $this->assertSame([[
            'id' => $mine->id, 'name' => $mine->name, 'state' => 'synced', 'created' => 1, 'updated' => 0, 'deleted' => 0,
            'message' => __('Synced: :created new, :updated changed, :deleted removed.', ['created' => 1, 'updated' => 0, 'deleted' => 0]),
        ]], $results);
        $this->assertCount(1, $this->provider->calls('pull'));
    }

    public function test_only_administrators_read_why_a_calendar_failed(): void
    {
        $agent = $this->makeUser();
        $feed = $this->feed('Broken');
        self::viewer($agent, $feed);

        $this->provider->onPull(new ProviderUnavailable('The feed answered with an error.', 500));
        $this->assertSame(__('This calendar could not be synced right now.'), $this->sync($agent, [$feed->id])[0]['message']);

        $feed->update(['last_synced_at' => null]);
        $this->provider->onPull(new ProviderUnavailable('The feed answered with an error.', 500));
        $this->assertSame(__('Syncing failed: :error', ['error' => 'The feed answered with an error. (HTTP 500)']), $this->sync($this->makeAdmin(), [$feed->id])[0]['message']);
    }

    public function test_guests_of_the_api_must_sign_in(): void
    {
        $this->postJson(route('ljpccalendarmodule.api.sync'))->assertStatus(401);
    }
}
