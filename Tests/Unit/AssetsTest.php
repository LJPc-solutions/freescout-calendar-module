<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Http\Assets;
use Modules\LJPcCalendarModule\Tests\TestCase;

class AssetsTest extends TestCase
{
    public function test_entries_load_as_versioned_modules_with_the_csp_nonce(): void
    {
        $this->assertMatchesRegularExpression(
            '#^<script type="module" src="[^"]*/modules/ljpccalendarmodule/build/settings\.js\?v=\d+" nonce="[^"]+"></script>$#',
            Assets::script('settings')
        );
        $this->assertMatchesRegularExpression(
            '#^<link rel="stylesheet" href="[^"]*/modules/ljpccalendarmodule/build/settings\.css\?v=\d+">$#',
            Assets::style('settings')
        );
    }
}
