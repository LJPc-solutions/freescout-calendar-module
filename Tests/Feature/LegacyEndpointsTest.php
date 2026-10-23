<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use Modules\LJPcCalendarModule\Tests\TestCase;

/**
 * Permanent regression tests: the removed v2 endpoints must never answer again. They are part of the fixes for the v2
 * security findings (C1, C2, H1 to H4, M1 to M4), which are tracked privately until 3.0 ships; see design section 18.
 */
class LegacyEndpointsTest extends TestCase
{
    public function test_v2_export_url_is_gone(): void
    {
        $this->get('/calendar/1/ics?token=' . md5('1'))->assertStatus(404);
    }

    public function test_v2_single_calendar_endpoint_is_gone(): void
    {
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/calendars/1')->assertStatus(404);
    }

    public function test_v2_event_write_endpoints_are_gone(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->json('PUT', '/calendar/api/events', ['id' => 1])->assertStatus(405);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events', ['id' => 1])->assertStatus(405);
    }
}
