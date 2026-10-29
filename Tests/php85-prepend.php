<?php

// Core's PHPUnit 9.5 and Mockery declare implicitly nullable parameters, which PHP 8.4 and newer deprecate when a file
// compiles. Classes that load after Laravel's error handler is in place then throw. This loads them first, so the suite
// runs on PHP 8.5. Run PHPUnit with `php -d auto_prepend_file=Modules/freescout-calendar-module/Tests/php85-prepend.php`
// (see CLAUDE.md). Mockery's mock base class and PHPUnit adapter don't compile on 8.5; no module test uses them.
error_reporting(E_ALL & ~E_DEPRECATED);
$loader = require __DIR__ . '/../../../vendor/autoload.php';
foreach (array_keys($loader->getClassMap()) as $class) {
    if (preg_match('/^(PHPUnit\\\\|SebastianBergmann\\\\|Mockery)/', $class) && !preg_match('/^Mockery\\\\(Adapter\\\\|Mock$)/', $class)) {
        class_exists($class) || interface_exists($class) || trait_exists($class);
    }
}
