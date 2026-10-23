#!/usr/bin/env bash
# Rehearses the v2 -> v3 migration on a masked dump inside ddev:
#   ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql [pgsql]
# Uses a scratch database "freescout_rehearsal"; the dev and test databases are not touched. With "pgsql" the dump is
# loaded into that MySQL scratch database first, then copied into a PostgreSQL scratch database of the same name on the
# "postgres" service (Tests/postgres/docker-compose.postgres.yaml), where the v3 migrations run.
set -euo pipefail
dump="${1:?usage: rehearse-migration.sh <dump.sql inside the container> [pgsql]}"
target="${2:-mysql}"
cd /var/www/html
export DB_TEST_HOST=db DB_TEST_DATABASE=freescout_rehearsal DB_TEST_USERNAME=root DB_TEST_PASSWORD=root REHEARSAL_DUMP="$dump"
# Log to stderr instead of storage/logs, so the dump's titles and names never end up in the dev log files.
export APP_LOG=errorlog
module=Modules/freescout-calendar-module

php artisan config:clear >/dev/null
mysql -uroot -proot -h db -e "DROP DATABASE IF EXISTS freescout_rehearsal; CREATE DATABASE freescout_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --database=testing --path=database/migrations --force >/dev/null
# Modules on the source install can add threads columns (ratings, translations and so on). Add the ones the dump
# inserts as nullable text, so its line items import into the core schema.
for column in $(grep -oE '^INSERT INTO `threads` \([^)]*\)' "$dump" | sed 's/^INSERT INTO `threads` //' | grep -oE '[a-z_0-9]+' | sort -u); do
    mysql -uroot -proot -h db freescout_rehearsal -e "ALTER TABLE threads ADD COLUMN IF NOT EXISTS \`$column\` LONGTEXT NULL"
done
mysql -uroot -proot -h db freescout_rehearsal < "$dump"

# Placeholders for the users, mailboxes and conversations the dump refers to, the v2 migrations marked as run,
# and a snapshot of the v2 items for comparison afterwards.
php artisan tinker <<'PHP'
DB::setDefaultConnection('testing');
foreach (file(getenv('REHEARSAL_DUMP')) as $line) {
    if (preg_match('/^-- user (\d+) (\d+) (\d+) (\d+)$/', trim($line), $m)) {
        $team = (int) $m[2] === App\User::TYPE_ROBOT;
        factory(App\User::class)->create(['id' => (int) $m[1], 'type' => (int) $m[2], 'role' => (int) $m[3], 'status' => (int) $m[4], 'email' => ($team ? 'fsteam' : 'user') . $m[1] . '@example.test', 'emails' => $team ? '[]' : null]);
    } elseif (preg_match('/^-- mailbox (\d+)$/', trim($line), $m)) {
        factory(App\Mailbox::class)->create(['id' => (int) $m[1]]);
    }
}
foreach (file(getenv('REHEARSAL_DUMP')) as $line) {
    if (preg_match('/^-- conversation (\d+) (\d+) (\d+)$/', trim($line), $m) && !App\Conversation::find((int) $m[1])) {
        factory(App\Conversation::class)->create(['id' => (int) $m[1], 'mailbox_id' => (int) $m[2], 'number' => (int) $m[3], 'status' => App\Conversation::STATUS_ACTIVE, 'created_by_user_id' => App\User::where('type', App\User::TYPE_USER)->value('id')]);
    }
}
$batch = (int) DB::table('migrations')->max('batch') + 1;
foreach (['2021_11_21_104200_create_calendars_table', '2021_11_21_104500_create_calendar_items_table', '2024_04_21_082300_update_calendars_table', '2024_09_09_112700_update_calendar_items_table', '2024_12_10_153547_add_title_template', '2025_07_07_131613_create_calendar_event_index_table', '2025_07_07_131807_add_sync_version_to_calendars_table'] as $migration) {
    DB::table('migrations')->insert(['migration' => $migration, 'batch' => $batch]);
}
file_put_contents('/tmp/rehearsal-items.json', json_encode(DB::table('calendar_items')->get(['id', 'start', 'end', 'is_all_day'])));
echo 'v2 items: ' . DB::table('calendar_items')->count() . ', calendars: ' . DB::table('calendars')->count() . PHP_EOL;
PHP

# The PostgreSQL connection exists only inside these tinker sessions, so the app's own config is not touched.
pgsql_connection="config(['database.connections.rehearsal_pg' => ['driver' => 'pgsql', 'host' => 'postgres', 'port' => '5432', 'database' => 'freescout_rehearsal', 'username' => 'rehearsal', 'password' => 'rehearsal', 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'disable']]);"
if [ "$target" = pgsql ]; then
    export REHEARSAL_CONNECTION=rehearsal_pg
    rm -rf storage/app/rehearsal-v2-migrations && mkdir storage/app/rehearsal-v2-migrations
    cp "$module"/Database/Migrations/202[1-5]_*.php storage/app/rehearsal-v2-migrations/
    # Core's schema and the v2 module tables on PostgreSQL, then every row the MySQL scratch database holds for them.
    { echo "$pgsql_connection"; cat <<'PHP'
(function () {
    $server = new PDO('pgsql:host=postgres;port=5432;dbname=rehearsal', 'rehearsal', 'rehearsal');
    $server->exec('DROP DATABASE IF EXISTS freescout_rehearsal');
    $server->exec('CREATE DATABASE freescout_rehearsal');
    // Tinker's Artisan has no migrate command, so the migrator runs directly.
    $migrator = app('migrator');
    $migrator->setConnection('rehearsal_pg');
    app('migration.repository')->setSource('rehearsal_pg');
    app('migration.repository')->createRepository();
    foreach (['database/migrations', 'storage/app/rehearsal-v2-migrations'] as $path) {
        $migrator->run([base_path($path)]);
    }
    $pg = DB::connection('rehearsal_pg');
    foreach (['users', 'mailboxes', 'mailbox_user', 'conversations', 'threads', 'calendars', 'calendar_items'] as $table) {
        $columns = array_flip(Schema::connection('rehearsal_pg')->getColumnListing($table));
        $copied = 0;
        foreach (DB::connection('testing')->table($table)->cursor() as $row) {
            $values = array_intersect_key((array) $row, $columns);
            foreach ($values as $key => $value) {
                // MySQL without strict mode keeps zero dates, which PostgreSQL refuses.
                if (is_string($value) && strpos($value, '0000-00-00') === 0) {
                    $values[$key] = null;
                }
            }
            $pg->table($table)->insert($values);
            $copied++;
        }
        if (isset($columns['id'])) {
            $pg->statement("SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM $table), 0) + 1, false)");
        }
        echo "copied $table: $copied" . PHP_EOL;
    }
})();
PHP
    } | php artisan tinker
    rm -rf storage/app/rehearsal-v2-migrations
    { echo "$pgsql_connection"; cat <<'PHP'
(function () {
    $migrator = app('migrator');
    $migrator->setConnection('rehearsal_pg');
    $migrator->run([base_path('Modules/freescout-calendar-module/Database/Migrations')]);
    echo implode(PHP_EOL, $migrator->getNotes()) . PHP_EOL;
})();
PHP
    } | php artisan tinker
else
    export REHEARSAL_CONNECTION=testing
    php artisan migrate --database=testing --path="$module/Database/Migrations" --force
fi

{ [ "$target" = pgsql ] && echo "$pgsql_connection"; cat <<'PHP'
DB::setDefaultConnection(getenv('REHEARSAL_CONNECTION'));
$service = app(Modules\LJPcCalendarModule\Services\MigrationService::class);
echo json_encode([
    'finished'    => $service->isFinished(),
    'calendars'   => DB::table('calendars')->count(),
    'events'      => DB::table('calendar_events')->count(),
    'occurrences' => DB::table('calendar_occurrences')->count(),
    'permissions' => DB::table('calendar_permissions')->count(),
    'connections' => DB::table('calendar_connections')->count(),
    'skipped'     => count($service->skippedItemIds()),
], JSON_PRETTY_PRINT) . PHP_EOL;
foreach ($service->report() as $entry) {
    echo strtoupper($entry['level']) . ': ' . __($entry['message'], array_map('strval', array_filter($entry['context'], 'is_scalar'))) . PHP_EOL;
}
$mismatch = 0;
foreach (json_decode(file_get_contents('/tmp/rehearsal-items.json')) as $item) {
    $event = Modules\LJPcCalendarModule\Entities\CalendarEvent::where('legacy_id', (string) $item->id)->first();
    if ($event && !$item->is_all_day && $event->starts_at !== substr($item->start, 0, 19)) {
        $mismatch++;
        echo "TIME MISMATCH item {$item->id}: {$item->start} -> {$event->starts_at}" . PHP_EOL;
    }
}
echo "timed events with a changed start: $mismatch" . PHP_EOL;
$resolved = 0;
$unresolved = [];
foreach (App\Thread::where('action_type', 186)->get() as $thread) {
    $text = Modules\LJPcCalendarModule\Services\LineItemText::render($thread, true);
    if (strpos($text, 'deleted by now') === false) {
        $resolved++;
    } else {
        $unresolved[] = json_encode($thread->getMetas());
    }
}
echo "line items resolved: $resolved, unresolved: " . count($unresolved) . PHP_EOL;
foreach (array_slice($unresolved, 0, 10) as $meta) {
    echo "  unresolved meta: $meta" . PHP_EOL;
}
unlink('/tmp/rehearsal-items.json');
PHP
} | php artisan tinker
