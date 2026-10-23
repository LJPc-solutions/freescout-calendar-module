<?php

// Mirrors public/index.php and artisan: FreeScout defines a null-safe e() (no double encoding) before the autoloader,
// so views escape null and arrays of HTML exactly like production. It only takes effect when it runs before
// vendor/autoload.php, because PHPUnit's own autoloader defines Laravel's e() first. Run PHPUnit with
// `php -d auto_prepend_file=Modules/freescout-calendar-module/Tests/null-safe-e.php` for that (see CLAUDE.md).
if (! function_exists('e')) {
    function e($value, $doubleEncode = false)
    {
        if ($value instanceof \Illuminate\Contracts\Support\Htmlable) {
            return $value->toHtml();
        }

        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', $doubleEncode);
    }
}
