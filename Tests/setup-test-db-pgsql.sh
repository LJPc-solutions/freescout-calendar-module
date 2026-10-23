#!/usr/bin/env bash
# Builds the module test database on PostgreSQL (the ddev "postgres" service, Tests/postgres) instead of MySQL:
#   ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db-pgsql.sh
# Core's "testing" connection is MySQL only; this needs it to read its driver, port and charset from DB_TEST_DRIVER,
# DB_TEST_PORT and DB_TEST_CHARSET (a local change to FreeScout's config/database.php, see CLAUDE.md). Run the tests
# with the same variables set, as CLAUDE.md shows.
set -euo pipefail
cd /var/www/html
export DB_TEST_DRIVER=pgsql DB_TEST_HOST=postgres DB_TEST_PORT=5432 DB_TEST_DATABASE=freescout_test DB_TEST_USERNAME=rehearsal DB_TEST_PASSWORD=rehearsal DB_TEST_CHARSET=utf8
php artisan config:clear >/dev/null
php -r '$p = new PDO("pgsql:host=postgres;port=5432;dbname=rehearsal", "rehearsal", "rehearsal"); $p->exec("DROP DATABASE IF EXISTS freescout_test"); $p->exec("CREATE DATABASE freescout_test");'
php artisan migrate --database=testing --path=database/migrations --force >/dev/null
php artisan tinker <<'PHP'
(function () {
    foreach (DB::connection('mysql')->table('modules')->get() as $row) {
        DB::connection('testing')->table('modules')->insert((array) $row);
    }
    DB::connection('testing')->statement("SELECT setval(pg_get_serial_sequence('modules', 'id'), (SELECT MAX(id) FROM modules))");
    echo "modules copied\n";
})();
PHP
php artisan migrate --database=testing --force
php artisan tinker <<'PHP'
require base_path('Modules/freescout-calendar-module/Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php');
(function () {
    DB::setDefaultConnection('testing');
    DB::table('options')->whereIn('name', ['ljpccalendarmodule.migration_report', 'ljpccalendarmodule.migration_skipped_items'])->delete();
    (new LjpcCalendarV3CleanupV2())->down();
    echo "legacy v2 schema restored\n";
})();
PHP
echo "freescout_test on PostgreSQL is ready."
