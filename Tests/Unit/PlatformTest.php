<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_php_gate_requires_8_2(): void
    {
        $this->assertFalse(LJPcCalendarModuleServiceProvider::phpSupported(80199));
        $this->assertTrue(LJPcCalendarModuleServiceProvider::phpSupported(80200));
        $this->assertTrue(LJPcCalendarModuleServiceProvider::phpSupported(80500));
    }

    public function test_platform_guard_refuses_old_php_and_old_freescout_before_the_upgrade_starts(): void
    {
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(80200, true);
        LJPcCalendarModuleServiceProvider::assertPlatformSupported(80500, true);

        $cases = [
            'old PHP'           => [80199, true, 'needs PHP 8.2 or newer'],
            'old FreeScout'     => [80200, false, 'needs FreeScout 1.8.243 or newer'],
            'old PHP and core'  => [70400, false, 'needs PHP 8.2 or newer'],
        ];
        foreach ($cases as $case => [$php, $appSupported, $message]) {
            try {
                LJPcCalendarModuleServiceProvider::assertPlatformSupported($php, $appSupported);
                $this->fail('No refusal for ' . $case);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
                $this->assertStringContainsString('so the calendar upgrade did not start', $e->getMessage(), $case);
            }
        }
    }

    public function test_core_version_gate_matches_the_module_requirement(): void
    {
        $module = json_decode(file_get_contents(dirname(__DIR__, 2) . '/module.json'), true);

        $this->assertSame($module['requiredAppVersion'], LJPcCalendarModuleServiceProvider::MIN_APP_VERSION);
    }

    public function test_composer_json_bundles_no_core_logger_and_requires_php_8_2(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertSame('^8.2', $composer['require']['php']);
        $this->assertArrayHasKey('psr/log', $composer['replace']);
    }
}
