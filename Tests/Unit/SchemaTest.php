<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_new_calendar_gets_tokens_and_priority(): void
    {
        $calendar = Calendar::create(['name' => 'Support', 'color' => '#3498db', 'provider' => Calendar::PROVIDER_LOCAL]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_busy_token);
        $this->assertNotSame($calendar->export_token, $calendar->export_busy_token);
        $this->assertSame(100, $calendar->fresh()->display_priority);
        $this->assertTrue($calendar->isWritable());
        $this->assertArrayNotHasKey('export_token', $calendar->toArray());
    }

    public function test_deleting_a_calendar_cascades(): void
    {
        $calendar = Calendar::create(['name' => 'Cascade', 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => 1, 'can_view' => true]);
        $event = new CalendarEvent(['calendar_id' => $calendar->id, 'ics' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00']);
        $event->uid = 'abc@example.com';
        $event->save();
        CalendarOccurrence::create(['event_id' => $event->id, 'calendar_id' => $calendar->id, 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00']);

        $this->assertSame(sha1('abc@example.com'), $event->uid_hash);
        $this->assertSame($event->id, CalendarEvent::findByUid($calendar->id, 'abc@example.com')->id);

        $calendar->delete();

        $this->assertSame(0, CalendarPermission::where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, CalendarEvent::where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, CalendarOccurrence::where('calendar_id', $calendar->id)->count());
    }

    public function test_connection_credentials_are_encrypted_at_rest(): void
    {
        $connection = new CalendarConnection(['provider' => 'caldav', 'account' => 'lars']);
        $connection->setCredentials(['username' => 'lars', 'password' => 's3cret']);
        $connection->save();

        $raw = DB::table('calendar_connections')->where('id', $connection->id)->value('credentials');
        $this->assertStringNotContainsString('s3cret', $raw);
        $this->assertSame('s3cret', CalendarConnection::find($connection->id)->credentials()['password']);
        $this->assertArrayNotHasKey('credentials', $connection->toArray());
    }

    public function test_permission_rows_relation_is_not_shadowed_by_the_legacy_column(): void
    {
        $calendar = Calendar::create(['name' => 'Legacy', 'provider' => Calendar::PROVIDER_LOCAL, 'permissions' => json_encode(['1' => ['showInCalendar' => true]])]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => 1, 'can_view' => true]);

        $rows = $calendar->fresh()->permissionRows;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $rows);
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(CalendarPermission::class, $rows->first());

        $eager = Calendar::with('permissionRows')->find($calendar->id);
        $this->assertCount(1, $eager->permissionRows);
    }

    public function test_remote_ids_are_hashed_for_lookup_per_calendar(): void
    {
        $calendar = Calendar::create(['name' => 'Remote ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $other = Calendar::create(['name' => 'Other ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $remoteId = 'https://dav.example.com/calendars/support/' . str_repeat('a', 300) . '.ics';
        $event = new CalendarEvent(['calendar_id' => $calendar->id, 'ics' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00', 'remote_id' => $remoteId]);
        $event->uid = 'remote@example.com';
        $event->save();

        $this->assertSame(sha1($remoteId), $event->remote_id_hash);
        $this->assertSame($event->id, CalendarEvent::findByRemoteId($calendar->id, $remoteId)->id);
        $this->assertNull(CalendarEvent::findByRemoteId($other->id, $remoteId));
        $this->assertSame(0, $event->fresh()->sync_generation);
        $this->assertSame(0, $calendar->fresh()->sync_failures);

        $event->remote_id = '';
        $this->assertNull($event->remote_id);
        $this->assertNull($event->remote_id_hash);
        $event->remote_id = null;
        $this->assertNull($event->remote_id_hash);
    }
}
