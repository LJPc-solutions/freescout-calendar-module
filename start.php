<?php

// Routes need the module's vendor code, which only loads on PHP 8.2+ and FreeScout 1.8.243+ (see the service provider).
if (PHP_VERSION_ID >= 80200
    && \Helper::checkAppVersion(\Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider::MIN_APP_VERSION)
    && !app()->routesAreCached()) {
    require __DIR__ . '/Http/routes.php';
}
