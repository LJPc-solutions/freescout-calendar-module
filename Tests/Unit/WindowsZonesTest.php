<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\CalendarProviders\Microsoft\WindowsZones;
use Modules\LJPcCalendarModule\Services\VTimezone;
use Modules\LJPcCalendarModule\Tests\TestCase;

class WindowsZonesTest extends TestCase
{
    /** WindowsZones as a server without the intl extension sees it: only the bundled CLDR map answers. */
    private static function withoutIntl(): WindowsZones
    {
        return new class () extends WindowsZones {
            protected static function intlIana(string $windowsName): ?string
            {
                return null;
            }

            protected static function intlWindows(string $iana): ?string
            {
                return null;
            }
        };
    }

    /** The class itself and, when this PHP has intl, the copy without it: both must give the same answers. */
    private static function both(): array
    {
        return ['bundled map' => self::withoutIntl(), 'intl' => new WindowsZones()];
    }

    public function test_windows_names_become_the_zone_cldr_names_for_them(): void
    {
        $this->assertTrue(class_exists(\IntlTimeZone::class), 'The test environment has intl, so both paths are tested');
        foreach (self::both() as $path => $zones) {
            $this->assertSame('Europe/Berlin', $zones::toIana('W. Europe Standard Time'), $path);
            $this->assertSame('America/New_York', $zones::toIana('Eastern Standard Time'), $path);
            $this->assertSame('Asia/Calcutta', $zones::toIana('India Standard Time'), $path);
            $this->assertSame('Australia/Sydney', $zones::toIana(' AUS Eastern Standard Time '), $path);
            $this->assertSame('UTC', $zones::toIana('UTC'), $path);
            // IANA names come back as they are; a custom Outlook zone and nonsense have no name.
            $this->assertSame('Europe/Amsterdam', $zones::toIana('Europe/Amsterdam'), $path);
            $this->assertNull($zones::toIana('tzone://Microsoft/Custom'), $path);
            $this->assertNull($zones::toIana('Customized Time Zone'), $path);
            $this->assertNull($zones::toIana(''), $path);
        }
    }

    public function test_the_bundled_map_gives_what_intl_gives_and_only_zones_freescout_can_store(): void
    {
        $this->assertCount(139, WindowsZones::TO_IANA);
        $withoutIntl = self::withoutIntl();
        foreach (WindowsZones::TO_IANA as $windows => $iana) {
            $this->assertTrue(VTimezone::isNamedZone($iana), $iana);
            $this->assertSame($iana === 'Etc/UTC' ? 'UTC' : $iana, $withoutIntl::toIana($windows), $windows);
            $this->assertSame($withoutIntl::toIana($windows), WindowsZones::toIana($windows), $windows);
        }
    }

    public function test_every_windows_name_comes_back_from_its_own_zone(): void
    {
        foreach (self::both() as $path => $zones) {
            foreach (array_keys(WindowsZones::TO_IANA) as $windows) {
                $this->assertSame($windows, $zones::fromIana((string) $zones::toIana($windows)), $path . ': ' . $windows);
            }
        }
    }

    public function test_iana_names_get_the_windows_name_graph_accepts(): void
    {
        foreach (self::both() as $path => $zones) {
            $this->assertSame('W. Europe Standard Time', $zones::fromIana('Europe/Amsterdam'), $path);
            $this->assertSame('W. Europe Standard Time', $zones::fromIana('Europe/Berlin'), $path);
            $this->assertSame('Romance Standard Time', $zones::fromIana('Europe/Brussels'), $path);
            $this->assertSame('Eastern Standard Time', $zones::fromIana('America/New_York'), $path);
            // A newer and an older name of one zone.
            $this->assertSame('India Standard Time', $zones::fromIana('Asia/Kolkata'), $path);
            $this->assertSame('India Standard Time', $zones::fromIana('Asia/Calcutta'), $path);
            $this->assertSame('FLE Standard Time', $zones::fromIana('Europe/Kyiv'), $path);
            $this->assertSame('Greenland Standard Time', $zones::fromIana('America/Nuuk'), $path);
            $this->assertSame('UTC', $zones::fromIana('UTC'), $path);
            $this->assertSame('UTC', $zones::fromIana('Etc/UTC'), $path);
            // A Windows name stays, and a name nobody knows is left for Graph to judge.
            $this->assertSame('Tokyo Standard Time', $zones::fromIana('Tokyo Standard Time'), $path);
            $this->assertSame('Mars/Olympus_Mons', $zones::fromIana('Mars/Olympus_Mons'), $path);
        }
    }
}
