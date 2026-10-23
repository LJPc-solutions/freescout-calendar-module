#!/usr/bin/env bash
# Rebuilds the module test database inside ddev: ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
set -euo pipefail
cd /var/www/html

export DB_TEST_HOST=db DB_TEST_DATABASE=freescout_test DB_TEST_USERNAME=root DB_TEST_PASSWORD=root

php artisan config:clear >/dev/null
mysql -uroot -proot -h db -e "DROP DATABASE IF EXISTS freescout_test; CREATE DATABASE freescout_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Core schema first, then copy the module activation state so the module boots on the test connection.
php artisan migrate --database=testing --path=database/migrations --force
mysqldump -uroot -proot -h db db modules | mysql -uroot -proot -h db freescout_test

# All remaining migrations, including this module's.
php artisan migrate --database=testing --force

# The migration report is written by the module migrations above; tests start from an empty report.
mysql -uroot -proot -h db freescout_test -e "DELETE FROM options WHERE name IN ('ljpccalendarmodule.migration_report', 'ljpccalendarmodule.migration_skipped_items')"

# The cleanup migration dropped the v2 tables; recreate them (empty) so migration tests can insert v2 fixtures.
restore_output=$(php artisan tinker <<'PHP'
DB::setDefaultConnection('testing');
require base_path('Modules/freescout-calendar-module/Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php');
(new LjpcCalendarV3CleanupV2())->down();
echo "legacy v2 schema restored\n";
PHP
)
echo "$restore_output"
if ! grep -q "legacy v2 schema restored" <<<"$restore_output"; then
    echo "Restoring the legacy v2 schema failed." >&2
    exit 1
fi

echo "freescout_test is ready."
