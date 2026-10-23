<?php

// Fails when the module bundles a package that FreeScout core already ships: the module autoloader is
// prepended, so its copy would replace core's mid-request. Without arguments it checks the oldest core the module
// supports (MIN_APP_VERSION) and the latest release, since core may ship a package in one and not the other.
$refs = array_slice($argv, 1);
$headers = "User-Agent: ljpc-calendar-release\r\n";
if (($token = getenv('GITHUB_TOKEN')) !== false && $token !== '') {
    $headers .= "Authorization: Bearer {$token}\r\n";
}
$context = stream_context_create(['http' => ['header' => $headers]]);
if (!$refs) {
    preg_match("/MIN_APP_VERSION = '([^']+)'/", (string) file_get_contents(__DIR__ . '/../../Providers/LJPcCalendarModuleServiceProvider.php'), $minimum);
    $release = json_decode((string) file_get_contents('https://api.github.com/repos/freescout-help-desk/freescout/releases/latest', false, $context), true);
    $refs = [$minimum[1] ?? null, $release['tag_name'] ?? null];
    if (in_array(null, $refs, true)) {
        fwrite(STDERR, "Could not determine the minimum and the latest FreeScout tag.\n");
        exit(1);
    }
}

$moduleLock = json_decode((string) file_get_contents(__DIR__ . '/../../composer.lock'), true);
if (!isset($moduleLock['packages'])) {
    fwrite(STDERR, "Could not read the module's composer.lock.\n");
    exit(1);
}
$module = array_column($moduleLock['packages'], 'name');

$failed = false;
foreach (array_unique($refs) as $ref) {
    $coreLock = json_decode((string) file_get_contents("https://raw.githubusercontent.com/freescout-help-desk/freescout/{$ref}/composer.lock"), true);
    if (!isset($coreLock['packages'])) {
        fwrite(STDERR, "Could not read the lock file of FreeScout {$ref}.\n");
        exit(1);
    }
    $shared = array_values(array_intersect($module, array_column($coreLock['packages'], 'name')));
    if ($shared) {
        fwrite(STDERR, "The module bundles packages that FreeScout {$ref} already ships:\n - " . implode("\n - ", $shared) . "\nAdd them to \"replace\" in composer.json.\n");
        $failed = true;
        continue;
    }
    echo 'OK: ' . count($module) . " module packages, none shipped by FreeScout {$ref}.\n";
}
exit($failed ? 1 : 0);
