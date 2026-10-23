<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Http\FrontendStrings;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Tests\TestCase;

/**
 * Dutch, German, French and Spanish translate every string; the other languages fall back to English (decision
 * 2026-10-23). The strings are the __() literals in the module's PHP and Blade files, the frontend's keys, the
 * provider labels and the migration report's messages, which are translated when the report is shown.
 */
class TranslationsTest extends TestCase
{
    private const COMPLETE = ['nl', 'de', 'fr', 'es'];

    public function test_dutch_german_french_and_spanish_translate_every_string(): void
    {
        $keys = self::keys();
        foreach (self::COMPLETE as $locale) {
            $this->assertSame([], array_values(array_diff($keys, array_keys(self::language($locale)))), $locale);
        }
    }

    public function test_no_language_keeps_strings_the_module_no_longer_uses(): void
    {
        $keys = self::keys();
        foreach (glob(self::root() . '/Resources/lang/*.json') as $path) {
            $locale = basename($path, '.json');
            $this->assertSame([], array_values(array_diff(array_keys(self::language($locale)), $keys)), $locale);
        }
    }

    public function test_translations_keep_every_placeholder(): void
    {
        foreach (self::COMPLETE as $locale) {
            foreach (self::language($locale) as $english => $translated) {
                preg_match_all('/:[a-z_]+/', $english, $expected);
                preg_match_all('/:[a-z_]+/', $translated, $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale . ': ' . $english);
            }
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function language(string $locale): array
    {
        return json_decode((string) file_get_contents(self::root() . '/Resources/lang/' . $locale . '.json'), true);
    }

    /** @return string[] */
    private static function keys(): array
    {
        $root = self::root();
        $keys = array_merge(FrontendStrings::KEYS, array_values(SettingsPage::PROVIDERS));
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file) use ($root) {
                $relative = substr($file->getPathname(), strlen($root) + 1);

                return !in_array($relative, ['Tests', 'vendor', 'docs', 'Public', 'Resources/frontend', '.git', '.github'], true);
            }
        ));
        foreach ($files as $file) {
            if (substr($file->getFilename(), -4) !== '.php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/__\(\s*(?:\'((?:\\\\.|[^\'\\\\])*)\'|"((?:\\\\.|[^"\\\\])*)")/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $keys[] = isset($match[2]) && $match[2] !== ''
                    ? str_replace(['\\"', '\\\\'], ['"', '\\'], $match[2])
                    : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
            }
        }
        // The report's messages are stored in English and translated when the report is shown (SettingsPage::report).
        preg_match_all("/'((?:\\\\.|[^'\\\\])*)'/", (string) file_get_contents($root . '/Services/MigrationService.php'), $literals);
        foreach ($literals[1] as $literal) {
            $literal = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
            if (strpos($literal, ' ') !== false && $literal[0] !== '"' && strpos($literal, 'Calendar migration') !== 0
                && (substr($literal, -1) === '.' || substr($literal, -6) === ':error')) {
                $keys[] = $literal;
            }
        }

        return array_values(array_unique($keys));
    }
}
