<?php
// Prints the two PHP array constants of CalendarProviders/Microsoft/WindowsZones.php from CLDR's windowsZones.xml
// (argv[1]). Needs intl, which ddev has. From ~/Projects/freescout:
//
//   curl -sS -o storage/app/windowsZones.xml https://raw.githubusercontent.com/unicode-org/cldr/release-48-2/common/supplemental/windowsZones.xml
//   ddev exec php Modules/freescout-calendar-module/Tests/tools/windows-zones.php storage/app/windowsZones.xml > /tmp/windows-zones-constants.txt
//   rm storage/app/windowsZones.xml
//
// It reports "139 windows names, 596 iana names, ICU 76.1" on stderr for CLDR 48.2. Paste both constants into
// WindowsZones and update the CLDR and ICU versions in its class comment.
$xml = simplexml_load_file($argv[1]);
$toIana = [];
$toWindows = [];
foreach ($xml->windowsZones->mapTimezones->mapZone as $zone) {
    $windows = (string) $zone['other'];
    foreach (preg_split('/\s+/', trim((string) $zone['type'])) as $id) {
        if ((string) $zone['territory'] === '001') {
            $toIana[$windows] = $id;
        }
        $toWindows[$id] = $windows;
    }
}
// Names PHP lists that CLDR knows under another (older or newer) name, resolved with ICU's canonical id.
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $name) {
    if (!isset($toWindows[$name])) {
        $canonical = IntlTimeZone::getCanonicalID($name);
        if (is_string($canonical) && isset($toWindows[$canonical])) {
            $toWindows[$name] = $toWindows[$canonical];
        }
    }
}
ksort($toIana, SORT_STRING);
ksort($toWindows, SORT_STRING);
$print = function (string $name, array $map) {
    echo '    public const ' . $name . " = [\n";
    foreach ($map as $key => $value) {
        echo '        ' . var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
    }
    echo "    ];\n";
};
$print('TO_IANA', $toIana);
echo "\n";
$print('TO_WINDOWS', $toWindows);
fwrite(STDERR, count($toIana) . ' windows names, ' . count($toWindows) . ' iana names, ICU ' . INTL_ICU_VERSION . "\n");
