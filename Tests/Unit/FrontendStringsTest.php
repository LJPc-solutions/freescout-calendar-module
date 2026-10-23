<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Http\FrontendStrings;
use Modules\LJPcCalendarModule\Tests\TestCase;

class FrontendStringsTest extends TestCase
{
    /** Every t('...') literal in the frontend sources must be listed, and every listed key must still be used. */
    public function test_the_key_list_matches_the_frontend_sources(): void
    {
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../Resources/frontend/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!preg_match('/\.(ts|vue)$/', $file->getFilename())) {
                continue;
            }
            preg_match_all('/\bt\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $literal) {
                $used[] = stripcslashes(substr($literal, 1, -1));
            }
        }
        $used = array_values(array_unique($used));

        $this->assertSame([], array_values(array_diff($used, FrontendStrings::KEYS)), 'Used in the frontend but missing from FrontendStrings::KEYS');
        $this->assertSame([], array_values(array_diff(FrontendStrings::KEYS, $used)), 'Listed in FrontendStrings::KEYS but no longer used');
        $this->assertSame(count(FrontendStrings::KEYS), count(array_unique(FrontendStrings::KEYS)), 'FrontendStrings::KEYS has duplicates');
    }

    public function test_every_key_is_translated_with_the_key_as_fallback(): void
    {
        $strings = FrontendStrings::all();

        $this->assertSame(FrontendStrings::KEYS, array_keys($strings));
        $this->assertSame('Every :count weeks', $strings['Every :count weeks']);
    }
}
