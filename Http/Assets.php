<?php

namespace Modules\LJPcCalendarModule\Http;

/**
 * Tags for the Vite entries in Public/build. They load as ES modules with FreeScout's CSP nonce, never through the
 * "javascripts" filter (Minify would concatenate them). ?v= is the file's modification time, so a new build is
 * never served from a stale cache.
 */
final class Assets
{
    public static function script(string $entry): string
    {
        return '<script type="module" src="' . e(self::url($entry . '.js')) . '"' . \Helper::cspNonceAttr() . '></script>';
    }

    public static function style(string $entry): string
    {
        return '<link rel="stylesheet" href="' . e(self::url($entry . '.css')) . '">';
    }

    private static function url(string $file): string
    {
        $path = __DIR__ . '/../Public/build/' . $file;

        return asset(\Module::getPublicPath(LJPC_CALENDARS_MODULE) . '/build/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : 0);
    }
}
