<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\User;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Tests\TestCase;

class HarnessTest extends TestCase
{
    public function test_runs_on_the_testing_connection_with_the_module_booted(): void
    {
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertSame('freescout_test', DB::connection()->getDatabaseName());
        $this->assertTrue(defined('LJPC_CALENDARS_MODULE'));

        $user = $this->makeUser();
        $this->assertNotNull(User::find($user->id));
    }
}
