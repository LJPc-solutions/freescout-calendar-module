# Calendar module 3.0, W1 (platform and data): implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the v2 backend with the v3 foundation:
- the iCalendar-based data model, the time model and recurrence
- one permission policy
- the local-calendar API and secure ICS export
- a resumable v2 → v3 migration
- the platform guards and the release pipeline

The work is verified on real (masked) v2 data.

**Architecture:**
- **Storage:**
  - Every event is stored as its VCALENDAR text in `calendar_events.ics`. The query columns (UTC times, all-day, status) are derived from it.
  - Recurrences are expanded into `calendar_occurrences` for a fixed window, with sabre/vobject's `EventIterator`.
- **Request path:** thin controllers call `CalendarPolicy`, then a service (`EventService`, `OccurrenceService`, `ExportService`, `MigrationService`).
- **Migration:** a chain of Laravel migrations calls `MigrationService` steps. Every step is idempotent, so a failed `migrate` can simply be re-run.

**Tech stack:**
- PHP 8.2+ on FreeScout ≥ 1.8.243 (Laravel 5.5.40 with FreeScout overrides).
- sabre/vobject 4.6, sabre/dav 4.7.
- PHPUnit 9.5 from FreeScout core, run inside ddev (MariaDB 10.11).

**Spec:** `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`. This plan covers workstream W1 (spec section 23).

**Out of scope for W1 (later workstreams):**
- The FullCalendar UI and the settings UI (W2).
- The ICS, CalDAV, Google and Microsoft sync providers and OAuth (W3).
- Invites and the `calendar_invites` table (W4).
- Reminders and the `calendar_reminder_log` table (W5).

**State between W1 and W2/W3:**
- The calendar page and the settings page are shells that only hold JSON config.
- Migrated ICS/CalDAV calendars exist but don't sync and are read-only until W3.
- The branch is never released in this state.

## Global constraints

**Platform and dependencies**
- PHP `^8.2`. FreeScout `requiredAppVersion` `1.8.243`. Only Laravel 5.5 APIs, so no `Str::uuid()`, no `whenLoaded` and no Laravel 6+ helpers.
- Never bundle a package that FreeScout core ships. `psr/log` is listed under composer `replace`. CI fails when the module's lock file and core's lock file share a package.
- Allowed runtime dependencies: `sabre/dav ^4.7.1`, `sabre/vobject ^4.6.1` (sabre/dav 4.x is incompatible with vobject 5) and `dallgoot/yaml ^1.0` (kept for W3's legacy YAML reader). `johngrogg/ics-parser`, `spatie/icalendar-generator` and `spatie/enum` are removed.

**Module identity (never change)**
- alias `ljpccalendarmodule`, namespace `Modules\LJPcCalendarModule`
- view namespace `calendar::`, constant `LJPC_CALENDARS_MODULE`
- `latestVersionUrl`
- line-item `action_type` `186`
- route name `ljpccalendarmodule.index`

**Time and database**
- Event times are stored as UTC strings `Y-m-d H:i:s` in `dateTime` columns. Use `dateTime`, not `timestamp`: MySQL `TIMESTAMP` ends in 2038.
- Never use Eloquent date casting for `starts_at`/`ends_at`. All-day rows store the date at `00:00:00` with `all_day = 1`.
- Use only the query builder or Eloquent (FreeScout's table prefix is applied automatically). No raw SQL.

**Code and output**
- Code style is PSR-12 with 4-space indentation (FreeScout core style). The v2 WordPress-style files are being replaced.
- Every user-visible string goes through `__()` with an English key, in sentence case, with no em dashes.
- Never put Blade output inside `<script>` code. Pass server data as `<script type="application/json">` with `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`.

**Git**
- Conventional commits, a single-line message, no body.
- Never push branch `v3` to `origin`; a local pre-push hook enforces this.

**Running commands**
- Working copy: `~/Projects/freescout/Modules/freescout-calendar-module`. Called `$MOD` below; the path inside ddev is `Modules/freescout-calendar-module`.
- Run all PHP, artisan and test commands inside ddev from `~/Projects/freescout`. The test command is:
  `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`. Add `--filter <TestName>` to run one test.
- Before running tests, FreeScout's config cache must be cleared (`ddev exec php artisan config:clear`). `Tests/bootstrap.php` refuses to run otherwise, because tests would hit the dev database.

## File structure (end state of W1)

```
composer.json                         deps, replace psr/log, php ^8.2
module.json                           requiredAppVersion 1.8.243, author LJPc, authorUrl https://ljpc.nl
.gitignore                            vendor/, zips, phpunit cache
phpunit.xml                           module test suite on the "testing" connection
start.php                             routes only on PHP >= 8.2
Config/config.php                     ['name' => 'Calendar']
Providers/LJPcCalendarModuleServiceProvider.php   PHP guard, policy, hooks, commands, schedule
Http/routes.php                       page, agent API, admin API, export
Http/Controllers/CalendarPageController.php       page shell
Http/Controllers/ExportController.php             token export
Http/Controllers/Api/CalendarsController.php      agent: calendars the user may view
Http/Controllers/Api/EventsController.php         agent: range, show, create, update, delete
Http/Controllers/Api/ConversationEventsController.php  agent: create from conversation
Http/Controllers/Admin/CalendarsController.php    admin: CRUD, permissions, tokens, subjects, report
Http/Middleware/EnsureCalendarMigrated.php        503 until the v2 migration is complete
Http/FrontendConfig.php               JSON config for the page and settings shells
Http/EventPresenter.php               event/occurrence JSON for the agent API
Policies/CalendarPolicy.php           rights union of user/team/mailbox rows
Entities/Calendar.php                 (rewritten) calendars
Entities/CalendarConnection.php       encrypted provider credentials
Entities/CalendarPermission.php       permission rows
Entities/CalendarEvent.php            VCALENDAR source + derived columns
Entities/CalendarOccurrence.php       expanded instances
Services/EventIcs.php                 build/read/modify VCALENDARs, derive columns
Services/TimeInput.php                parse API times in the user's timezone
Services/RecurrenceRule.php           API recurrence array <-> RRULE
Services/OccurrenceService.php        expansion, window, range queries
Services/FieldValues.php              custom field validation and display
Services/TitleTemplate.php            {{title}} and {{field:<id>}} rendering
Services/EventService.php             create/update/delete local events
Services/ExportService.php            ICS export (full and busy)
Services/VTimezone.php                VTIMEZONE builder from PHP tz transitions
Services/ReadableBlock.php            "[FreeScout]" description block
Services/LineItemText.php             conversation line item text (v2 and v3 meta)
Services/DashboardCard.php            dashboard card data
Services/MigrationService.php         v2 -> v3 conversion steps and report
Console/RefreshOccurrences.php        daily window move
Database/Migrations/2026_10_05_000001_ljpc_calendar_v3_create_tables.php
Database/Migrations/2026_10_05_000002_ljpc_calendar_v3_convert_calendars.php
Database/Migrations/2026_10_05_000003_ljpc_calendar_v3_convert_permissions.php
Database/Migrations/2026_10_05_000004_ljpc_calendar_v3_convert_items.php
Database/Migrations/2026_10_05_000005_ljpc_calendar_v3_schedule_resync.php
Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php
Resources/views/index.blade.php       page shell (rewritten)
Resources/views/settings.blade.php    settings shell (rewritten)
Resources/views/partials/menu.blade.php, dash_card.blade.php, dash_card_event.blade.php   kept
Tests/bootstrap.php, Tests/TestCase.php, Tests/setup-test-db.sh, Tests/rehearse-migration.sh
Tests/Unit/*Test.php, Tests/Feature/*Test.php
.github/workflows/release.yml, .github/scripts/check-shared-packages.php,
.github/scripts/build-release.sh, .github/scripts/release-exclude.txt
```

Deleted in W1:
- `Http/Controllers/LJPcCalendarModule*Controller.php`
- `Http/Helpers/*`
- `Jobs/*`
- `Console/UpdateExternalCalendars.php`
- `Entities/CalendarItem.php`, `Entities/CalendarEventIndex.php`
- all v2 views except `menu`, `dash_card` and `dash_card_event`
- `Public/js/*`, `Public/settings/*`, `Public/css/toastui-calendar.min.css`, `Public/fonts/*`, `Public/images/ic-*`, `Public/images/img-bi*`
- `Resources/src/`

---

### Task 1: Dev environment and module test harness

**Files:**
- Create: `$MOD/phpunit.xml`
- Create: `$MOD/Tests/bootstrap.php`
- Create: `$MOD/Tests/TestCase.php`
- Create: `$MOD/Tests/setup-test-db.sh`
- Test: `$MOD/Tests/Unit/HarnessTest.php`

**Interfaces:**
- Produces: `Modules\LJPcCalendarModule\Tests\TestCase` with `makeUser(array $attributes = []): \App\User`, `makeAdmin(array $attributes = []): \App\User`, `makeMailbox(array $userIds = []): \App\Mailbox`, `makeTeam(array $memberIds): \App\User`. It uses `DatabaseTransactions`, so every test is rolled back.
- Produces: the test database `freescout_test` inside ddev, rebuilt with `ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh`.

- [ ] **Step 1: Update the local FreeScout core to ≥ 1.8.245**

Run on the host (the script asks one y/N question, answered by `printf`):

```bash
cd ~/Projects/freescout && ddev start && printf 'y' | ./update-freescout.sh
ddev exec php artisan freescout:after-app-update
grep "'version'" config/app.php
```

Expected: `'version' => '1.8.245'` or newer. If the script fails, stop and report the output to Lars; don't patch the core.

- [ ] **Step 2: Restore the module's vendor directory**

```bash
cd ~/Projects/freescout && ddev exec 'cd Modules/freescout-calendar-module && composer install --no-interaction'
```

Expected: `Generating autoload files` with no errors. The v2 lock file is installed at this point, and Task 2 changes it.

- [ ] **Step 3: Activate the module and Teams from the CLI**

Activating through the web UI renames the folder (FreeScout ≥ 1.8.240), so use the CLI instead.

```bash
cd ~/Projects/freescout
ddev exec 'echo "\App\Module::setActive(\"ljpccalendarmodule\", true); \App\Module::setActive(\"teams\", true);" | php artisan tinker'
ddev exec php artisan freescout:module-install ljpccalendarmodule
ddev exec php artisan freescout:clear-cache
ls Modules | grep -i calendar
```

Expected: the folder is still `freescout-calendar-module`, and `public/modules/ljpccalendarmodule` exists as a symlink.

- [ ] **Step 4: Write `Tests/bootstrap.php`**

```php
<?php

// Module test bootstrap: FreeScout's autoloader plus this module's own autoloader.
$root = dirname(__DIR__, 3);

if (file_exists($root . '/bootstrap/cache/config.php')) {
    fwrite(STDERR, "FreeScout's config is cached, so tests would run against the dev database. Run: php artisan config:clear\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 5: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.5/phpunit.xsd"
         bootstrap="Tests/bootstrap.php" colors="true" processIsolation="false" stopOnFailure="false">
    <testsuites>
        <testsuite name="Calendar">
            <directory suffix="Test.php">./Tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
        <env name="DB_TEST_HOST" value="db"/>
        <env name="DB_TEST_DATABASE" value="freescout_test"/>
        <env name="DB_TEST_USERNAME" value="root"/>
        <env name="DB_TEST_PASSWORD" value="root"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_DRIVER" value="sync"/>
        <env name="MAIL_DRIVER" value="log"/>
    </php>
</phpunit>
```

- [ ] **Step 6: Write `Tests/TestCase.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests;

use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase as FreeScoutTestCase;

abstract class TestCase extends FreeScoutTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'testing') {
            $this->fail('Module tests must run on the "testing" connection.');
        }
    }

    protected function makeUser(array $attributes = []): User
    {
        return factory(User::class)->create($attributes + [
            'role'     => User::ROLE_USER,
            'status'   => User::STATUS_ACTIVE,
            'type'     => User::TYPE_USER,
            'timezone' => 'Europe/Amsterdam',
        ]);
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return $this->makeUser($attributes + ['role' => User::ROLE_ADMIN]);
    }

    protected function makeMailbox(array $userIds = []): Mailbox
    {
        $mailbox = factory(Mailbox::class)->create();
        if ($userIds) {
            $mailbox->users()->attach($userIds);
        }

        return $mailbox;
    }

    /** A Teams-module team: a deleted robot user whose email starts with "fsteam" and whose emails JSON lists member ids. */
    protected function makeTeam(array $memberIds): User
    {
        return factory(User::class)->create([
            'role'   => User::ROLE_USER,
            'status' => User::STATUS_DELETED,
            'type'   => User::TYPE_ROBOT,
            'email'  => 'fsteam' . uniqid() . '@example.com',
            'emails' => json_encode(array_values($memberIds)),
        ]);
    }
}
```

- [ ] **Step 7: Write `Tests/setup-test-db.sh`**

```bash
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

echo "freescout_test is ready."
```

- [ ] **Step 8: Write the failing harness test `Tests/Unit/HarnessTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\User;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Tests\TestCase;

class HarnessTest extends TestCase
{
    public function test_runs_on_the_testing_connection_with_the_module_booted(): void
    {
        $this->assertSame('testing', DB::getDefaultConnection());
        $this->assertSame('freescout_test', DB::connection()->getDatabaseName());
        $this->assertTrue(defined('LJPC_CALENDARS_MODULE'));

        $user = $this->makeUser();
        $this->assertNotNull(User::find($user->id));
    }
}
```

- [ ] **Step 9: Run it before the database exists**

```bash
cd ~/Projects/freescout && ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter HarnessTest'
```

Expected: FAIL or ERROR, because `freescout_test` doesn't exist yet ("Unknown database").

- [ ] **Step 10: Create the test database and run the test again**

```bash
cd ~/Projects/freescout && ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter HarnessTest'
```

Expected: `freescout_test is ready.`, then `OK (1 test, 4 assertions)`.

- [ ] **Step 11: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add phpunit.xml Tests/bootstrap.php Tests/TestCase.php Tests/setup-test-db.sh Tests/Unit/HarnessTest.php
git commit -m "test: add module test harness running on the ddev testing database"
```

---

### Task 2: Platform guard, dependencies and module metadata

**Files:**
- Modify: `$MOD/composer.json` (full rewrite)
- Modify: `$MOD/module.json` (fields `author`, `authorUrl`, `requiredAppVersion`)
- Create: `$MOD/.gitignore`
- Modify: `$MOD/Providers/LJPcCalendarModuleServiceProvider.php:33-41` (`boot()`)
- Modify: `$MOD/start.php`
- Test: `$MOD/Tests/Unit/PlatformTest.php`

**Interfaces:**
- Produces: `LJPcCalendarModuleServiceProvider::MIN_PHP_VERSION_ID = 80200` and `public static function phpSupported(int $versionId): bool`.

- [ ] **Step 1: Write the failing test `Tests/Unit/PlatformTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Providers\LJPcCalendarModuleServiceProvider;
use Modules\LJPcCalendarModule\Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_php_gate_requires_8_2(): void
    {
        $this->assertFalse(LJPcCalendarModuleServiceProvider::phpSupported(80199));
        $this->assertTrue(LJPcCalendarModuleServiceProvider::phpSupported(80200));
        $this->assertTrue(LJPcCalendarModuleServiceProvider::phpSupported(80500));
    }

    public function test_composer_json_bundles_no_core_logger_and_requires_php_8_2(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertSame('^8.2', $composer['require']['php']);
        $this->assertArrayHasKey('psr/log', $composer['replace']);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter PlatformTest'`
Expected: ERROR `Call to undefined method ...::phpSupported()`.

- [ ] **Step 3: Rewrite `composer.json`**

`johngrogg/ics-parser` and `spatie/*` stay for now, because the v2 code still uses them. Task 3 removes both the code and the packages.

```json
{
    "name": "freescout/ljpccalendarmodule",
    "description": "Calendar module for FreeScout",
    "license": "AGPL-3.0",
    "authors": [
        {
            "name": "LJPc",
            "email": "info@ljpc.nl"
        }
    ],
    "extra": {
        "laravel": {
            "providers": [
                "Modules\\LJPcCalendarModule\\Providers\\LJPcCalendarModuleServiceProvider"
            ],
            "aliases": {}
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\LJPcCalendarModule\\": ""
        }
    },
    "require": {
        "php": "^8.2",
        "ext-json": "*",
        "dallgoot/yaml": "^1.0",
        "johngrogg/ics-parser": "^3",
        "sabre/dav": "^4.7.1",
        "sabre/vobject": "^4.6.1",
        "spatie/icalendar-generator": "^2.6"
    },
    "replace": {
        "psr/log": "*"
    },
    "config": {
        "platform": {
            "php": "8.2.0"
        },
        "sort-packages": true
    }
}
```

- [ ] **Step 4: Update the lock file and remove `vendor/` from git**

```bash
cd ~/Projects/freescout && ddev exec 'cd Modules/freescout-calendar-module && composer update --no-interaction'
cd ~/Projects/freescout/Modules/freescout-calendar-module
printf '/vendor/\n/*.zip\n/version.txt\n/dist/\n.phpunit.result.cache\n' > .gitignore
git rm -r --cached --quiet vendor
ls vendor/psr 2>/dev/null || echo "no psr dir"
```

Expected: `composer update` succeeds, and `vendor/psr` doesn't exist, so `psr/log` is not bundled.

- [ ] **Step 5: Add the PHP guard to the service provider**

In `Providers/LJPcCalendarModuleServiceProvider.php`, replace the `boot()` method (lines 33-41) and add the constant and the two methods below it:

```php
    public const MIN_PHP_VERSION_ID = 80200;

    public function boot()
    {
        if (!self::phpSupported(PHP_VERSION_ID)) {
            $this->registerPhpNotice();

            return;
        }

        require_once __DIR__ . '/../vendor/autoload.php';

        $this->registerConfig();
        $this->registerViews();
        $this->registerCommands();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    public static function phpSupported(int $versionId): bool
    {
        return $versionId >= self::MIN_PHP_VERSION_ID;
    }

    /** Admins see why the module is paused instead of the whole helpdesk crashing on an old PHP. */
    private function registerPhpNotice(): void
    {
        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return $html;
            }

            return $html . '<div class="alert alert-danger">' . e(__('The calendar module needs PHP 8.2 or newer. This server runs PHP :version, so the module is paused.', ['version' => PHP_VERSION])) . '</div>';
        });
    }
```

- [ ] **Step 6: Guard the routes in `start.php`**

Replace the whole file with:

```php
<?php

// Routes need the module's vendor code, which only loads on PHP 8.2+ (see the service provider).
if (PHP_VERSION_ID >= 80200 && !app()->routesAreCached()) {
    require __DIR__ . '/Http/routes.php';
}
```

- [ ] **Step 7: Update `module.json`**

Set `"author": "LJPc"`, `"authorUrl": "https://ljpc.nl"` and `"requiredAppVersion": "1.8.243"`. Leave every other field unchanged.

- [ ] **Step 8: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 9: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add composer.json composer.lock module.json .gitignore start.php Providers/LJPcCalendarModuleServiceProvider.php Tests/Unit/PlatformTest.php
git commit -m "build: require PHP 8.2, stop bundling psr/log and remove vendor from git"
```

---

### Task 3: Remove the v2 runtime code

v2's controllers, helpers and hooks contain the security findings tracked privately (C1, C2, H1 to H4, M1 to M4). They are deleted rather than patched. The permanent regression tests below make sure the old endpoints never come back.

**Files:**
- Delete:
  - `$MOD/Http/Controllers/LJPcCalendarModuleAPIController.php`
  - `$MOD/Http/Controllers/LJPcCalendarModuleCalendarController.php`
  - `$MOD/Http/Helpers/CalDAV.php`, `$MOD/Http/Helpers/ICS.php`, `$MOD/Http/Helpers/DateTimeRange.php`
  - `$MOD/Jobs/UpdateExternalCalendarJob.php`
  - `$MOD/Console/UpdateExternalCalendars.php`
  - `$MOD/Entities/CalendarItem.php`, `$MOD/Entities/CalendarEventIndex.php`
  - `$MOD/Resources/views/partials/conversation_button.blade.php`, `ics_attachment.blade.php`, `scripts.blade.php`, `translations.blade.php`
  - `$MOD/Public/js/`, `$MOD/Public/settings/`, `$MOD/Public/css/toastui-calendar.min.css`, `$MOD/Public/fonts/`
  - `$MOD/Public/images/ic-*`, `$MOD/Public/images/img-bi*`
  - `$MOD/Resources/src/`
- Modify: `$MOD/Http/routes.php` (empty group for now)
- Modify: `$MOD/Providers/LJPcCalendarModuleServiceProvider.php` (`hooks()`, `registerCommands()`, `registerSettings()`, the use-statements)
- Modify: `$MOD/Config/config.php`
- Modify: `$MOD/Database/Migrations/2024_04_21_082300_update_calendars_table.php` (no entity use)
- Modify: `$MOD/composer.json` (drop `johngrogg/ics-parser`, `spatie/icalendar-generator`)
- Test: `$MOD/Tests/Feature/LegacyEndpointsTest.php`

**Interfaces:**
- Produces: a provider whose `hooks()` registers nothing (Task 13 adds the v3 hooks), and a `registerCommands()` that is an empty list (Task 7 adds `RefreshOccurrences`).

- [ ] **Step 1: Write the failing regression test `Tests/Feature/LegacyEndpointsTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use Modules\LJPcCalendarModule\Tests\TestCase;

/**
 * Permanent regression tests: the removed v2 endpoints must never answer again. They are part of the fixes for the v2
 * security findings (C1, C2, H1 to H4, M1 to M4), which are tracked privately until 3.0 ships; see design section 18.
 */
class LegacyEndpointsTest extends TestCase
{
    public function test_v2_export_url_is_gone(): void
    {
        $this->get('/calendar/1/ics?token=' . md5('1'))->assertStatus(404);
    }

    public function test_v2_single_calendar_endpoint_is_gone(): void
    {
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/calendars/1')->assertStatus(404);
    }

    public function test_v2_event_write_endpoints_are_gone(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->json('PUT', '/calendar/api/events', ['id' => 1])->assertStatus(405);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events', ['id' => 1])->assertStatus(405);
    }
}
```

The `PUT`/`DELETE` assertions expect `405` once Task 10 adds `GET`/`POST` on `/calendar/api/events`. Until then that path has no routes at all, so the test temporarily asserts `404`. Write it with 404 now, and Task 10 changes these two lines to 405.

```php
        $this->actingAs($user)->json('PUT', '/calendar/api/events', ['id' => 1])->assertStatus(404);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events', ['id' => 1])->assertStatus(404);
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter LegacyEndpointsTest'`
Expected: FAIL. The export answers 200 or 403, and the calendar endpoint answers 200 or 404 (only if no calendar 1 exists), so at least one assertion fails.

- [ ] **Step 3: Delete the v2 runtime files**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git rm -q Http/Controllers/LJPcCalendarModuleAPIController.php Http/Controllers/LJPcCalendarModuleCalendarController.php \
  Http/Helpers/CalDAV.php Http/Helpers/ICS.php Http/Helpers/DateTimeRange.php Jobs/UpdateExternalCalendarJob.php \
  Console/UpdateExternalCalendars.php Entities/CalendarItem.php Entities/CalendarEventIndex.php \
  Resources/views/partials/conversation_button.blade.php Resources/views/partials/ics_attachment.blade.php \
  Resources/views/partials/scripts.blade.php Resources/views/partials/translations.blade.php
git rm -rq Public/js Public/settings Public/fonts Resources/src Public/css/toastui-calendar.min.css
git rm -q Public/images/ic-* Public/images/img-bi*
```

- [ ] **Step 4: Replace `Http/routes.php` with an empty group**

Tasks 10 to 13 fill it in.

```php
<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers'], function () {
});
```

- [ ] **Step 5: Strip the provider**

In `Providers/LJPcCalendarModuleServiceProvider.php`:
- Delete the use-statements for `App\Attachment`, `Carbon\Carbon`, `DateTimeImmutable`, `DateTimeZone`, `Modules\LJPcCalendarModule\Console\UpdateExternalCalendars`, `Modules\LJPcCalendarModule\Entities\Calendar` and `Modules\LJPcCalendarModule\Entities\CalendarItem`.
- Replace `registerCommands()`, `hooks()` and `registerSettings()` with:

```php
    public function registerCommands()
    {
        $this->commands([]);
    }

    public function hooks()
    {
    }
```

- [ ] **Step 6: Replace `Config/config.php`**

This drops the `CALENDAR_*` performance flags.

```php
<?php

return [
    'name' => 'Calendar',
];
```

- [ ] **Step 7: Make the 2024 migration independent of the entities**

In `Database/Migrations/2024_04_21_082300_update_calendars_table.php`:
- Delete the `use` lines for `Modules\LJPcCalendarModule\Entities\Calendar` and `...\CalendarItem`, and add `use Illuminate\Support\Facades\DB;`.
- Replace the block from `//migrate data` up to the second `Schema::table` with:

```php
        //migrate data
        $calendars = DB::table('calendars')->orderBy('id')->get();
        foreach ($calendars as $i => $calendar) {
            $update = [
                'color'       => $colors[$i % count($colors)],
                'permissions' => json_encode($permissions),
            ];
            if ($calendar->url) {
                DB::table('calendar_items')->where('calendar_id', $calendar->id)->delete();
                $update['type']          = 'ics';
                $update['custom_fields'] = json_encode(['url' => $calendar->url, 'refresh' => 'daily']);
            }
            DB::table('calendars')->where('id', $calendar->id)->update($update);
        }
```

In `down()`, replace the `Calendar::all()` loop with:

```php
        foreach (DB::table('calendars')->where('type', 'ics')->get() as $calendar) {
            $customFields = json_decode($calendar->custom_fields ?? '{}', true) ?: [];
            DB::table('calendars')->where('id', $calendar->id)->update(['url' => $customFields['url'] ?? null]);
        }
```

In `buildPermissions()`, replace `->remember( Helper::cacheTime() )` with nothing, so the query is `User::where('status', User::STATUS_ACTIVE)->get();`.

- [ ] **Step 8: Drop the removed packages**

In `composer.json`, delete the lines `"johngrogg/ics-parser": "^3",` and `"spatie/icalendar-generator": "^2.6",`. Then:

```bash
cd ~/Projects/freescout && ddev exec 'cd Modules/freescout-calendar-module && composer update --no-interaction'
```

Expected: the lock file only has the `dallgoot/yaml` and `sabre/*` families.

- [ ] **Step 9: Rebuild the test database and run all tests**

This proves a fresh install still migrates without the entities.

```bash
cd ~/Projects/freescout && ddev exec php artisan freescout:clear-cache && ddev exec php artisan config:clear
ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'
```

Expected: `freescout_test is ready.`, then `OK (6 tests, ...)`.

- [ ] **Step 10: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add -A Http Providers Config Database composer.json composer.lock Tests/Feature/LegacyEndpointsTest.php
git commit -m "refactor: remove v2 runtime code, hooks and parser dependencies"
```

---

### Task 4: v3 schema and entities

**Files:**
- Create: `$MOD/Database/Migrations/2026_10_05_000001_ljpc_calendar_v3_create_tables.php`
- Modify: `$MOD/Entities/Calendar.php` (full rewrite)
- Create: `$MOD/Entities/CalendarConnection.php`
- Create: `$MOD/Entities/CalendarPermission.php`
- Create: `$MOD/Entities/CalendarEvent.php`
- Create: `$MOD/Entities/CalendarOccurrence.php`
- Test: `$MOD/Tests/Unit/SchemaTest.php`

**Interfaces:**
- Produces: `Calendar`. Its constants are `PROVIDER_LOCAL|ICS|CALDAV|GOOGLE|MICROSOFT`. Its methods and properties:
  - `permissionRows()`, `events()`, `calendarConnection()`
  - `isLocal(): bool`
  - `isWritable(): bool` (only `local` in W1; W3 extends it)
  - `timezoneName(): string`
  - `fieldDefinitions(): array`
  - `static newToken(): string` (40 hex characters)
  - `static defaultPriority(string $provider): int`
  - A creating hook fills `export_token`, `export_busy_token` and `display_priority` when they're empty.
- Produces: `CalendarPermission`. Its constants are `SUBJECT_USER|TEAM|MAILBOX` and `RIGHTS = ['show_in_dashboard','can_view','can_create','can_edit']`. It has no timestamps.
- Produces: `CalendarConnection`, with `credentials(): array` and `setCredentials(array $credentials): void` (stored with `encrypt()`).
- Produces: `CalendarEvent`:
  - `ACTION_TYPE_ADD_TO_CALENDAR = 186`
  - `static hashUid(string $uid): string`
  - `static findByUid(int $calendarId, string $uid): ?self`
  - `calendar()`, `occurrences()`
  - setting `uid` also sets `uid_hash`
- Produces: `CalendarOccurrence`, with columns `event_id`, `calendar_id`, `recurrence_id`, `summary`, `starts_at`, `ends_at`, `all_day`, `status`, and no timestamps.

- [ ] **Step 1: Write the failing test `Tests/Unit/SchemaTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_new_calendar_gets_tokens_and_priority(): void
    {
        $calendar = Calendar::create(['name' => 'Support', 'color' => '#3498db', 'provider' => Calendar::PROVIDER_LOCAL]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_busy_token);
        $this->assertNotSame($calendar->export_token, $calendar->export_busy_token);
        $this->assertSame(100, $calendar->fresh()->display_priority);
        $this->assertTrue($calendar->isWritable());
        $this->assertArrayNotHasKey('export_token', $calendar->toArray());
    }

    public function test_deleting_a_calendar_cascades(): void
    {
        $calendar = Calendar::create(['name' => 'Cascade', 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => 1, 'can_view' => true]);
        $event = new CalendarEvent(['calendar_id' => $calendar->id, 'ics' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00']);
        $event->uid = 'abc@example.com';
        $event->save();
        CalendarOccurrence::create(['event_id' => $event->id, 'calendar_id' => $calendar->id, 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00']);

        $this->assertSame(sha1('abc@example.com'), $event->uid_hash);
        $this->assertSame($event->id, CalendarEvent::findByUid($calendar->id, 'abc@example.com')->id);

        $calendar->delete();

        $this->assertSame(0, CalendarPermission::where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, CalendarEvent::where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, CalendarOccurrence::where('calendar_id', $calendar->id)->count());
    }

    public function test_connection_credentials_are_encrypted_at_rest(): void
    {
        $connection = new CalendarConnection(['provider' => 'caldav', 'account' => 'lars']);
        $connection->setCredentials(['username' => 'lars', 'password' => 's3cret']);
        $connection->save();

        $raw = DB::table('calendar_connections')->where('id', $connection->id)->value('credentials');
        $this->assertStringNotContainsString('s3cret', $raw);
        $this->assertSame('s3cret', CalendarConnection::find($connection->id)->credentials()['password']);
        $this->assertArrayNotHasKey('credentials', $connection->toArray());
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter SchemaTest'`
Expected: ERRORs about the unknown column `provider` or the missing class `CalendarConnection`.

- [ ] **Step 3: Write the migration `2026_10_05_000001_ljpc_calendar_v3_create_tables.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LjpcCalendarV3CreateTables extends Migration
{
    public function up()
    {
        $columns = [
            'provider'                 => fn (Blueprint $t) => $t->string('provider', 20)->default('local'),
            'connection_id'            => fn (Blueprint $t) => $t->unsignedInteger('connection_id')->nullable(),
            'remote_id'                => fn (Blueprint $t) => $t->text('remote_id')->nullable(),
            'timezone'                 => fn (Blueprint $t) => $t->string('timezone', 64)->nullable(),
            'fields'                   => fn (Blueprint $t) => $t->json('fields')->nullable(),
            'display_priority'         => fn (Blueprint $t) => $t->integer('display_priority')->default(100),
            'sync_interval'            => fn (Blueprint $t) => $t->unsignedInteger('sync_interval')->default(5),
            'sync_state'               => fn (Blueprint $t) => $t->json('sync_state')->nullable(),
            'next_sync_at'             => fn (Blueprint $t) => $t->dateTime('next_sync_at')->nullable(),
            'last_synced_at'           => fn (Blueprint $t) => $t->dateTime('last_synced_at')->nullable(),
            'last_error'               => fn (Blueprint $t) => $t->text('last_error')->nullable(),
            'sync_lease_until'         => fn (Blueprint $t) => $t->dateTime('sync_lease_until')->nullable(),
            'default_reminder_minutes' => fn (Blueprint $t) => $t->integer('default_reminder_minutes')->nullable(),
            'export_token'             => fn (Blueprint $t) => $t->string('export_token', 40)->nullable(),
            'export_busy_token'        => fn (Blueprint $t) => $t->string('export_busy_token', 40)->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (!Schema::hasColumn('calendars', $name)) {
                Schema::table('calendars', function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }

        if (!Schema::hasTable('calendar_connections')) {
            Schema::create('calendar_connections', function (Blueprint $table) {
                $table->increments('id');
                $table->string('provider', 20);
                $table->string('account')->nullable();
                $table->text('server_url')->nullable();
                $table->text('credentials')->nullable();
                $table->string('status', 20)->default('ok');
                $table->text('last_error')->nullable();
                $table->date('secret_expires_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('calendar_permissions')) {
            Schema::create('calendar_permissions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('calendar_id');
                $table->string('subject_type', 10);
                $table->unsignedInteger('subject_id');
                $table->boolean('show_in_dashboard')->default(false);
                $table->boolean('can_view')->default(false);
                $table->boolean('can_create')->default(false);
                $table->boolean('can_edit')->default(false);
                $table->unique(['calendar_id', 'subject_type', 'subject_id'], 'ljpc_cal_perm_subject_unique');
                $table->foreign('calendar_id')->references('id')->on('calendars')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('calendar_id');
                $table->text('uid');
                $table->char('uid_hash', 40);
                $table->longText('ics');
                $table->text('remote_id')->nullable();
                $table->text('remote_href')->nullable();
                $table->string('etag')->nullable();
                $table->unsignedInteger('sequence')->default(0);
                $table->string('summary', 500)->default('');
                $table->text('title_input')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('all_day')->default(false);
                $table->string('timezone', 64)->nullable();
                $table->boolean('is_recurring')->default(false);
                $table->string('status', 20)->default('confirmed');
                $table->boolean('is_private')->default(false);
                $table->string('organizer_email')->nullable();
                $table->unsignedInteger('author_id')->nullable();
                $table->unsignedInteger('conversation_id')->nullable();
                $table->json('field_values')->nullable();
                $table->integer('reminder_minutes')->nullable();
                $table->boolean('read_only')->default(false);
                $table->string('legacy_id', 191)->nullable();
                $table->timestamps();
                $table->unique(['calendar_id', 'uid_hash'], 'ljpc_cal_events_uid_unique');
                $table->index('legacy_id', 'ljpc_cal_events_legacy_index');
                $table->index('conversation_id', 'ljpc_cal_events_conversation_index');
                $table->foreign('calendar_id')->references('id')->on('calendars')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('calendar_occurrences')) {
            Schema::create('calendar_occurrences', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('event_id');
                $table->unsignedInteger('calendar_id');
                $table->string('recurrence_id', 20)->nullable();
                $table->string('summary', 500)->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('all_day')->default(false);
                $table->string('status', 20)->default('confirmed');
                $table->index(['calendar_id', 'starts_at'], 'ljpc_cal_occ_start_index');
                $table->index(['calendar_id', 'ends_at'], 'ljpc_cal_occ_end_index');
                $table->foreign('event_id')->references('id')->on('calendar_events')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('calendar_occurrences');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_permissions');
        Schema::dropIfExists('calendar_connections');
    }
}
```

The schema follows spec section 5, with three deliberate differences:
- The permission columns are named `can_*`, because `create` is a reserved word in MySQL.
- `uid` is `text` plus a sha1 `uid_hash` for the unique index. UIDs can exceed what MySQL can index.
- `title_input` keeps the raw title, so a title template can be re-rendered.

- [ ] **Step 4: Rewrite `Entities/Calendar.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class Calendar extends Model
{
    public const PROVIDER_LOCAL = 'local';
    public const PROVIDER_ICS = 'ics';
    public const PROVIDER_CALDAV = 'caldav';
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_MICROSOFT = 'microsoft';

    protected $table = 'calendars';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'                  => 'boolean',
        'fields'                   => 'array',
        'sync_state'               => 'array',
        'display_priority'         => 'integer',
        'sync_interval'            => 'integer',
        'default_reminder_minutes' => 'integer',
        'connection_id'            => 'integer',
    ];

    protected $hidden = ['export_token', 'export_busy_token', 'sync_state', 'permissions', 'custom_fields'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Calendar $calendar) {
            $calendar->provider = $calendar->provider ?: self::PROVIDER_LOCAL;
            $calendar->export_token = $calendar->export_token ?: self::newToken();
            $calendar->export_busy_token = $calendar->export_busy_token ?: self::newToken();
            if ($calendar->display_priority === null) {
                $calendar->display_priority = self::defaultPriority($calendar->provider);
            }
        });
    }

    /** Not named permissions(): the v2 JSON column "permissions" exists until the cleanup migration and would shadow the relation. */
    public function permissionRows()
    {
        return $this->hasMany(CalendarPermission::class);
    }

    public function events()
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function calendarConnection()
    {
        return $this->belongsTo(CalendarConnection::class, 'connection_id');
    }

    public function isLocal(): bool
    {
        return $this->provider === self::PROVIDER_LOCAL;
    }

    /** Only local calendars are writable until the W3 providers exist. */
    public function isWritable(): bool
    {
        return $this->isLocal();
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: (config('app.timezone') ?: 'UTC');
    }

    public function fieldDefinitions(): array
    {
        return is_array($this->fields) ? $this->fields : [];
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function defaultPriority(string $provider): int
    {
        return $provider === self::PROVIDER_LOCAL ? 100 : 200;
    }
}
```

The v2 columns `type`, `permissions` and `custom_fields` are hidden here and dropped in Task 16.

- [ ] **Step 5: Write `Entities/CalendarPermission.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarPermission extends Model
{
    public const SUBJECT_USER = 'user';
    public const SUBJECT_TEAM = 'team';
    public const SUBJECT_MAILBOX = 'mailbox';

    public const RIGHTS = ['show_in_dashboard', 'can_view', 'can_create', 'can_edit'];

    public $timestamps = false;

    protected $table = 'calendar_permissions';

    protected $guarded = ['id'];

    protected $casts = [
        'calendar_id'       => 'integer',
        'subject_id'        => 'integer',
        'show_in_dashboard' => 'boolean',
        'can_view'          => 'boolean',
        'can_create'        => 'boolean',
        'can_edit'          => 'boolean',
    ];
}
```

- [ ] **Step 6: Write `Entities/CalendarConnection.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarConnection extends Model
{
    protected $table = 'calendar_connections';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    public function credentials(): array
    {
        $raw = $this->attributes['credentials'] ?? null;

        return $raw ? (json_decode(decrypt($raw), true) ?: []) : [];
    }

    public function setCredentials(array $credentials): void
    {
        $this->attributes['credentials'] = encrypt(json_encode($credentials));
    }
}
```

- [ ] **Step 7: Write `Entities/CalendarEvent.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    /** Thread action type for "added to calendar" line items (unchanged since v2). */
    public const ACTION_TYPE_ADD_TO_CALENDAR = 186;

    protected $table = 'calendar_events';

    protected $guarded = ['id', 'uid_hash'];

    protected $casts = [
        'calendar_id'      => 'integer',
        'author_id'        => 'integer',
        'conversation_id'  => 'integer',
        'sequence'         => 'integer',
        'reminder_minutes' => 'integer',
        'all_day'          => 'boolean',
        'is_recurring'     => 'boolean',
        'is_private'       => 'boolean',
        'read_only'        => 'boolean',
        'field_values'     => 'array',
    ];

    protected $hidden = ['ics'];

    public function setUidAttribute(string $uid): void
    {
        $this->attributes['uid'] = $uid;
        $this->attributes['uid_hash'] = self::hashUid($uid);
    }

    public static function hashUid(string $uid): string
    {
        return sha1($uid);
    }

    public static function findByUid(int $calendarId, string $uid): ?self
    {
        return self::where('calendar_id', $calendarId)->where('uid_hash', self::hashUid($uid))->first();
    }

    public function calendar()
    {
        return $this->belongsTo(Calendar::class);
    }

    public function occurrences()
    {
        return $this->hasMany(CalendarOccurrence::class, 'event_id');
    }
}
```

- [ ] **Step 8: Write `Entities/CalendarOccurrence.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarOccurrence extends Model
{
    public $timestamps = false;

    protected $table = 'calendar_occurrences';

    protected $guarded = ['id'];

    protected $casts = [
        'event_id'    => 'integer',
        'calendar_id' => 'integer',
        'all_day'     => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
```

- [ ] **Step 9: Migrate the test database and run the tests**

```bash
cd ~/Projects/freescout && ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'
```

Expected: `OK (9 tests, ...)`.

- [ ] **Step 10: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Database/Migrations/2026_10_05_000001_ljpc_calendar_v3_create_tables.php Entities Tests/Unit/SchemaTest.php
git commit -m "feat: add v3 calendar schema and entities"
```

---
### Task 5: Time model (`EventIcs`, `TimeInput`)

**Files:**
- Create: `$MOD/Services/EventIcs.php`
- Create: `$MOD/Services/TimeInput.php`
- Test: `$MOD/Tests/Unit/EventIcsTest.php`
- Test: `$MOD/Tests/Unit/TimeInputTest.php`

**Interfaces:**
- Produces `EventIcs`, a final class with static methods and `PRODID = '-//LJPc//FreeScout Calendar 3//EN'`:
  - `build(array $data): VCalendar`. `$data` keys: `uid`, `title`, `all_day`, `start`, `end`, `location?`, `description?`, `rrule?`, `sequence?`. For all-day events `start`/`end` are `Y-m-d` strings with an exclusive end; otherwise they are `DateTimeImmutable` in the event timezone.
  - `apply(VEvent $vevent, array $data): void`. Writes only the keys that are present, and always refreshes DTSTAMP.
  - `master(VCalendar $vcal, string $uid): VEvent`
  - `read(VEvent $vevent, DateTimeZone $tz): array`. Returns keys `title`, `location`, `description`, `all_day`, `start`, `end`, `rrule`, with the same start/end types as `build`.
  - `endOf(VEvent $vevent, DateTimeZone $tz): DateTimeImmutable`
  - `derive(VCalendar $vcal, string $uid, string $fallbackTimezone): array`. Returns keys `summary`, `all_day`, `timezone`, `starts_at`, `ends_at`, `is_recurring`, `status`, `is_private`, `sequence`, `organizer_email`. Times are UTC `Y-m-d H:i:s`, and all-day rows hold the date at `00:00:00`.
  - `recurrenceKey(\Sabre\VObject\Property\ICalendar\DateTime $property, DateTimeZone $tz): string`. Returns `Ymd` for all-day and UTC `Ymd\THis\Z` for timed events.
  - `findOverride(VCalendar, string $uid, string $key, DateTimeZone $tz): ?VEvent`
  - `addOverride(VCalendar, VEvent $master, string $key, DateTimeZone $tz): VEvent`
  - `removeOverride(VCalendar, string $uid, string $key, DateTimeZone $tz): void`
  - `addExdate(VEvent $master, string $key, DateTimeZone $tz): void` (fix round: floating masters stay floating; duplicates are ignored)
  - `dropExceptions(VCalendar $vcal, VEvent $master): void`
  - `bumpSequence(VEvent $vevent): void`
  - `email(string $calAddress): ?string`
- Produces `TimeInput`, a final class with static methods:
  - `normalize(bool $allDay, string $start, ?string $end, string $timezone): array`. Returns keys `all_day`, `start`, `end`, `timezone`, and throws `ValidationException` on bad input.
  - `userTimezone(?\App\User $user): string`

- [ ] **Step 1: Write the failing test `Tests/Unit/EventIcsTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class EventIcsTest extends TestCase
{
    private function ams(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam'));
    }

    public function test_timed_event_keeps_its_tzid_and_derives_utc_columns_across_dst(): void
    {
        $vcal = EventIcs::build(['uid' => 'u1', 'title' => 'Call', 'all_day' => false, 'start' => $this->ams('2026-10-27 10:00'), 'end' => $this->ams('2026-10-27 11:00')]);
        $ics = $vcal->serialize();

        $this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20261027T100000', $ics);
        $this->assertStringContainsString('PRODID:-//LJPc//FreeScout Calendar 3//EN', $ics);

        $derived = EventIcs::derive(Reader::read($ics), 'u1', 'UTC');
        $this->assertSame('2026-10-27 09:00:00', $derived['starts_at']); // CET, UTC+1 after 25 October
        $this->assertSame('2026-10-27 10:00:00', $derived['ends_at']);
        $this->assertSame('Europe/Amsterdam', $derived['timezone']);
        $this->assertFalse($derived['all_day']);
        $this->assertFalse($derived['is_recurring']);
        $this->assertSame('Call', $derived['summary']);

        $summer = EventIcs::derive(EventIcs::build(['uid' => 'u2', 'title' => 'x', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 10:30')]), 'u2', 'UTC');
        $this->assertSame('2026-10-20 08:00:00', $summer['starts_at']); // CEST, UTC+2
    }

    public function test_all_day_event_is_a_date_without_timezone(): void
    {
        $vcal = EventIcs::build(['uid' => 'd1', 'title' => 'Holiday', 'all_day' => true, 'start' => '2026-10-05', 'end' => '2026-10-07']);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261005', $vcal->serialize());

        $derived = EventIcs::derive($vcal, 'd1', 'Australia/Sydney');
        $this->assertTrue($derived['all_day']);
        $this->assertNull($derived['timezone']);
        $this->assertSame('2026-10-05 00:00:00', $derived['starts_at']);
        $this->assertSame('2026-10-07 00:00:00', $derived['ends_at']);
    }

    public function test_external_shapes_floating_utc_missing_dtend_private_and_organizer(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\n"
            . "BEGIN:VEVENT\r\nUID:f1\r\nDTSTART:20261005T090000\r\nSUMMARY:Floating\r\nCLASS:PRIVATE\r\nORGANIZER;CN=Boss:mailto:Boss@Example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:z1\r\nDTSTART:20261005T090000Z\r\nDURATION:PT45M\r\nSUMMARY:Utc\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:a1\r\nDTSTART;VALUE=DATE:20261005\r\nSUMMARY:No end\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $vcal = Reader::read($ics);

        $floating = EventIcs::derive($vcal, 'f1', 'Europe/Amsterdam');
        $this->assertSame('2026-10-05 07:00:00', $floating['starts_at']);
        $this->assertSame('2026-10-05 07:00:00', $floating['ends_at']);
        $this->assertTrue($floating['is_private']);
        $this->assertSame('boss@example.com', $floating['organizer_email']);

        $utc = EventIcs::derive($vcal, 'z1', 'Europe/Amsterdam');
        $this->assertSame('UTC', $utc['timezone']);
        $this->assertSame('2026-10-05 09:45:00', $utc['ends_at']);

        $allDay = EventIcs::derive($vcal, 'a1', 'Europe/Amsterdam');
        $this->assertSame('2026-10-06 00:00:00', $allDay['ends_at']);
    }

    public function test_overrides_and_exdates_use_the_masters_timezone(): void
    {
        $vcal = EventIcs::build(['uid' => 'r1', 'title' => 'Weekly', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'rrule' => 'FREQ=WEEKLY']);
        $master = EventIcs::master($vcal, 'r1');
        $tz = new DateTimeZone('Europe/Amsterdam');

        $override = EventIcs::addOverride($vcal, $master, '20261027T090000Z', $tz);
        EventIcs::apply($override, ['title' => 'Moved', 'all_day' => false, 'start' => $this->ams('2026-10-27 14:00'), 'end' => $this->ams('2026-10-27 15:00')]);
        EventIcs::addExdate($master, '20261103T090000Z');
        $ics = $vcal->serialize();

        $this->assertStringContainsString('RECURRENCE-ID;TZID=Europe/Amsterdam:20261027T100000', $ics);
        $this->assertStringContainsString('EXDATE;TZID=Europe/Amsterdam:20261103T100000', $ics);
        $this->assertNotNull(EventIcs::findOverride(Reader::read($ics), 'r1', '20261027T090000Z', $tz));

        EventIcs::removeOverride($vcal, 'r1', '20261027T090000Z', $tz);
        $this->assertNull(EventIcs::findOverride($vcal, 'r1', '20261027T090000Z', $tz));
    }

    public function test_read_returns_form_values(): void
    {
        $vcal = EventIcs::build(['uid' => 'x', 'title' => 'T', 'all_day' => false, 'start' => $this->ams('2026-10-20 10:00'), 'end' => $this->ams('2026-10-20 11:00'), 'location' => 'Office', 'description' => "Line 1\nLine 2", 'rrule' => 'FREQ=DAILY;COUNT=3']);
        $read = EventIcs::read(EventIcs::master($vcal, 'x'), new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame('Office', $read['location']);
        $this->assertSame("Line 1\nLine 2", $read['description']);
        $this->assertSame('FREQ=DAILY;COUNT=3', $read['rrule']);
        $this->assertSame('2026-10-20T10:00:00+02:00', $read['start']->format(DATE_ATOM));
    }
}
```

- [ ] **Step 2: Write the failing test `Tests/Unit/TimeInputTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Services\TimeInput;
use Modules\LJPcCalendarModule\Tests\TestCase;

class TimeInputTest extends TestCase
{
    public function test_naive_times_are_read_in_the_users_timezone_not_the_browsers(): void
    {
        $times = TimeInput::normalize(false, '2026-10-27T10:00', '2026-10-27T11:00', 'Europe/Amsterdam');

        $this->assertSame('2026-10-27T10:00:00+01:00', $times['start']->format(DATE_ATOM));
        $this->assertSame('Europe/Amsterdam', $times['start']->getTimezone()->getName());
    }

    public function test_times_with_an_offset_are_converted_to_the_users_timezone(): void
    {
        $times = TimeInput::normalize(false, '2026-10-27T09:00:00Z', null, 'Europe/Amsterdam');

        $this->assertSame('2026-10-27T10:00:00+01:00', $times['start']->format(DATE_ATOM));
        $this->assertSame('2026-10-27T11:00:00+01:00', $times['end']->format(DATE_ATOM)); // default one hour
    }

    public function test_all_day_dates_have_an_exclusive_end(): void
    {
        $times = TimeInput::normalize(true, '2026-10-05', null, 'Europe/Amsterdam');
        $this->assertSame(['2026-10-05', '2026-10-06'], [$times['start'], $times['end']]);

        $times = TimeInput::normalize(true, '2026-10-05T00:00:00+02:00', '2026-10-08', 'Europe/Amsterdam');
        $this->assertSame(['2026-10-05', '2026-10-08'], [$times['start'], $times['end']]);
    }

    public function test_rejects_bad_input(): void
    {
        foreach ([[false, 'tomorrow', null], [false, '2026-10-05T10:00', '2026-10-05T09:00'], [true, '2026-10-05', '2026-10-05'], [true, '2026-02-30', null]] as [$allDay, $start, $end]) {
            try {
                TimeInput::normalize($allDay, $start, $end, 'Europe/Amsterdam');
                $this->fail("Accepted $start / $end");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_user_timezone_falls_back_to_utc_when_invalid(): void
    {
        $this->assertSame('Europe/Amsterdam', TimeInput::userTimezone($this->makeUser(['timezone' => 'Europe/Amsterdam'])));
        $this->assertSame('UTC', TimeInput::userTimezone($this->makeUser(['timezone' => 'Mars/Olympus'])));
    }
}
```

- [ ] **Step 3: Run both and see them fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "EventIcsTest|TimeInputTest"'`
Expected: ERROR `Class "Modules\LJPcCalendarModule\Services\EventIcs" not found`.

- [ ] **Step 4: Write `Services/EventIcs.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/** Builds, reads and changes VCALENDAR text, the source of truth for every event. */
final class EventIcs
{
    public const PRODID = '-//LJPc//FreeScout Calendar 3//EN';

    public static function build(array $data): VCalendar
    {
        $vcal = new VCalendar(['PRODID' => self::PRODID]);
        $vevent = $vcal->add('VEVENT', ['UID' => $data['uid']]);
        $vevent->SEQUENCE = (int) ($data['sequence'] ?? 0);
        self::apply($vevent, $data);

        return $vcal;
    }

    public static function apply(VEvent $vevent, array $data): void
    {
        $vevent->DTSTAMP = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (array_key_exists('title', $data)) {
            $vevent->SUMMARY = (string) $data['title'];
        }
        foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $property) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            unset($vevent->{$property});
            if ((string) $data[$key] !== '') {
                $vevent->{$property} = (string) $data[$key];
            }
        }
        if (array_key_exists('start', $data)) {
            unset($vevent->DTSTART, $vevent->DTEND, $vevent->DURATION);
            if ($data['all_day']) {
                $vevent->add('DTSTART', str_replace('-', '', $data['start']), ['VALUE' => 'DATE']);
                $vevent->add('DTEND', str_replace('-', '', $data['end']), ['VALUE' => 'DATE']);
            } else {
                $vevent->add('DTSTART', $data['start']);
                $vevent->add('DTEND', $data['end']);
            }
        }
        if (array_key_exists('rrule', $data)) {
            unset($vevent->RRULE);
            if ($data['rrule']) {
                $vevent->RRULE = $data['rrule'];
            }
        }
    }

    public static function master(VCalendar $vcal, string $uid): VEvent
    {
        $first = null;
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID !== $uid) {
                continue;
            }
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
            $first = $first ?? $vevent;
        }
        if ($first === null) {
            throw new InvalidArgumentException('No VEVENT with UID ' . $uid);
        }

        return $first;
    }

    public static function read(VEvent $vevent, DateTimeZone $tz): array
    {
        $allDay = !$vevent->DTSTART->hasTime();
        $start = $vevent->DTSTART->getDateTime($tz);
        $end = self::endOf($vevent, $tz);

        return [
            'title'       => (string) ($vevent->SUMMARY ?? ''),
            'location'    => (string) ($vevent->LOCATION ?? ''),
            'description' => (string) ($vevent->DESCRIPTION ?? ''),
            'all_day'     => $allDay,
            'start'       => $allDay ? $start->format('Y-m-d') : $start,
            'end'         => $allDay ? $end->format('Y-m-d') : $end,
            'rrule'       => isset($vevent->RRULE) ? (string) $vevent->RRULE : null,
        ];
    }

    public static function endOf(VEvent $vevent, DateTimeZone $tz): DateTimeImmutable
    {
        if (isset($vevent->DTEND)) {
            return $vevent->DTEND->getDateTime($tz);
        }
        $start = $vevent->DTSTART->getDateTime($tz);
        if (isset($vevent->DURATION)) {
            return $start->add(DateTimeParser::parseDuration((string) $vevent->DURATION));
        }

        return $vevent->DTSTART->hasTime() ? $start : $start->modify('+1 day');
    }

    public static function derive(VCalendar $vcal, string $uid, string $fallbackTimezone): array
    {
        $master = self::master($vcal, $uid);
        $tz = new DateTimeZone($fallbackTimezone);
        $utc = new DateTimeZone('UTC');
        $allDay = !$master->DTSTART->hasTime();
        $start = $master->DTSTART->getDateTime($tz);
        $end = max($start, self::endOf($master, $tz));
        $class = strtoupper((string) ($master->CLASS ?? 'PUBLIC'));

        return [
            'summary'         => mb_substr((string) ($master->SUMMARY ?? ''), 0, 500),
            'all_day'         => $allDay,
            'timezone'        => $allDay ? null : $start->getTimezone()->getName(),
            'starts_at'       => $allDay ? $start->format('Y-m-d 00:00:00') : $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at'         => $allDay ? $end->format('Y-m-d 00:00:00') : $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'is_recurring'    => isset($master->RRULE) || isset($master->RDATE),
            'status'          => strtolower((string) ($master->STATUS ?? 'CONFIRMED')),
            'is_private'      => in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true),
            'sequence'        => (int) (string) ($master->SEQUENCE ?? 0),
            'organizer_email' => isset($master->ORGANIZER) ? self::email((string) $master->ORGANIZER) : null,
        ];
    }

    public static function email(string $calAddress): ?string
    {
        $email = preg_replace('/^mailto:/i', '', trim($calAddress));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    public static function recurrenceKey(DateTimeProperty $property, DateTimeZone $tz): string
    {
        $dateTime = $property->getDateTime($tz);

        return $property->hasTime()
            ? $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
            : $dateTime->format('Ymd');
    }

    public static function findOverride(VCalendar $vcal, string $uid, string $key, DateTimeZone $tz): ?VEvent
    {
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ((string) $vevent->UID === $uid && isset($vevent->{'RECURRENCE-ID'})
                && self::recurrenceKey($vevent->{'RECURRENCE-ID'}, $tz) === $key) {
                return $vevent;
            }
        }

        return null;
    }

    /** Adds an override for one occurrence, starting as a copy of the master at that occurrence's time. */
    public static function addOverride(VCalendar $vcal, VEvent $master, string $key, DateTimeZone $tz): VEvent
    {
        $override = clone $master;
        foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID', 'DTSTART', 'DTEND', 'DURATION'] as $name) {
            unset($override->{$name});
        }
        [$value, $params] = self::keyValue($master, $key);
        $masterStart = $master->DTSTART->getDateTime($tz);
        $masterEnd = self::endOf($master, $tz);

        if ($master->DTSTART->hasTime()) {
            $seconds = $masterEnd->getTimestamp() - $masterStart->getTimestamp();
            $override->add('DTSTART', $value);
            $override->add('DTEND', $value->modify('+' . $seconds . ' seconds'));
        } else {
            $days = (int) $masterStart->diff($masterEnd)->days;
            $start = DateTimeImmutable::createFromFormat('!Ymd', $value, $tz);
            $override->add('DTSTART', $value, ['VALUE' => 'DATE']);
            $override->add('DTEND', $start->modify('+' . max(1, $days) . ' days')->format('Ymd'), ['VALUE' => 'DATE']);
        }
        $override->add('RECURRENCE-ID', $value, $params);
        $vcal->add($override);

        return $override;
    }

    public static function removeOverride(VCalendar $vcal, string $uid, string $key, DateTimeZone $tz): void
    {
        $override = self::findOverride($vcal, $uid, $key, $tz);
        if ($override !== null) {
            $vcal->remove($override);
        }
    }

    public static function addExdate(VEvent $master, string $key): void
    {
        [$value, $params] = self::keyValue($master, $key);
        $master->add('EXDATE', $value, $params);
    }

    /** Removes all overrides and EXDATEs; used when a series' start or rule changes. */
    public static function dropExceptions(VCalendar $vcal, VEvent $master): void
    {
        unset($master->EXDATE);
        foreach ($vcal->select('VEVENT') as $vevent) {
            if ($vevent !== $master && (string) $vevent->UID === (string) $master->UID) {
                $vcal->remove($vevent);
            }
        }
    }

    public static function bumpSequence(VEvent $vevent): void
    {
        $vevent->SEQUENCE = (int) (string) ($vevent->SEQUENCE ?? 0) + 1;
    }

    /** The occurrence key as a RECURRENCE-ID/EXDATE value in the master's own timezone. */
    private static function keyValue(VEvent $master, string $key): array
    {
        if (!$master->DTSTART->hasTime()) {
            return [$key, ['VALUE' => 'DATE']];
        }
        $utc = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $key, new DateTimeZone('UTC'));
        if ($utc === false) {
            throw new InvalidArgumentException('Invalid recurrence id ' . $key);
        }

        return [$utc->setTimezone($master->DTSTART->getDateTime()->getTimezone()), []];
    }
}
```

- [ ] **Step 5: Write `Services/TimeInput.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Validation\ValidationException;

/** Turns API time input into event times in the agent's FreeScout timezone (never the browser's). */
final class TimeInput
{
    public static function normalize(bool $allDay, string $start, ?string $end, string $timezone): array
    {
        if ($allDay) {
            $startDate = self::date($start, 'start');
            $endDate = ($end !== null && $end !== '') ? self::date($end, 'end') : $startDate->modify('+1 day');
            if ($endDate <= $startDate) {
                throw ValidationException::withMessages(['end' => __('The end must be after the start.')]);
            }

            return ['all_day' => true, 'start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'timezone' => $timezone];
        }

        $tz = new DateTimeZone($timezone);
        $startTime = self::dateTime($start, $tz, 'start');
        $endTime = ($end !== null && $end !== '') ? self::dateTime($end, $tz, 'end') : $startTime->modify('+1 hour');
        if ($endTime < $startTime) {
            throw ValidationException::withMessages(['end' => __('The end must be after the start.')]);
        }

        return ['all_day' => false, 'start' => $startTime, 'end' => $endTime, 'timezone' => $timezone];
    }

    public static function userTimezone(?User $user): string
    {
        $timezone = ($user && $user->timezone) ? $user->timezone : (config('app.timezone') ?: 'UTC');

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private static function date(string $value, string $key): DateTimeImmutable
    {
        $day = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw ValidationException::withMessages([$key => __('Invalid date.')]);
        }

        return $date;
    }

    private static function dateTime(string $value, DateTimeZone $tz, string $key): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            throw ValidationException::withMessages([$key => __('Invalid date and time.')]);
        }
        try {
            $dateTime = new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            throw ValidationException::withMessages([$key => __('Invalid date and time.')]);
        }

        return $dateTime->setTimezone($tz);
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "EventIcsTest|TimeInputTest"'`
Expected: `OK (10 tests, ...)`. If an assertion on exact ICS text fails, print `$vcal->serialize()` and compare it with RFC 5545 before changing the expectation. The expectations above are what RFC 5545 requires.

- [ ] **Step 7: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/EventIcs.php Services/TimeInput.php Tests/Unit/EventIcsTest.php Tests/Unit/TimeInputTest.php
git commit -m "feat: add iCalendar time model with user-timezone input parsing"
```

---

### Task 6: Recurrence rules for the form

**Files:**
- Create: `$MOD/Services/RecurrenceRule.php`
- Test: `$MOD/Tests/Unit/RecurrenceRuleTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RecurrenceRule::toRrule(array $input, bool $allDay, string $timezone): string`. It throws `ValidationException` with keys `recurrence.*`.
- Produces: `RecurrenceRule::fromRrule(?string $rrule, string $timezone = 'UTC'): ?array`. It returns null when the form can't represent the rule.
- The API shape is `{freq: DAILY|WEEKLY|MONTHLY|YEARLY, interval?: int, byday?: string[], bysetpos?: 1|2|3|4|-1, bymonthday?: int, until?: "Y-m-d", count?: int}`. Every option maps onto Microsoft Graph's `patternedRecurrence` (spec section 7).

- [ ] **Step 1: Write the failing test `Tests/Unit/RecurrenceRuleTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Services\RecurrenceRule;
use Modules\LJPcCalendarModule\Tests\TestCase;

class RecurrenceRuleTest extends TestCase
{
    public function test_builds_rules_for_every_form_option(): void
    {
        $tz = 'Europe/Amsterdam';
        $this->assertSame('FREQ=DAILY;INTERVAL=2', RecurrenceRule::toRrule(['freq' => 'daily', 'interval' => 2], false, $tz));
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10', RecurrenceRule::toRrule(['freq' => 'WEEKLY', 'byday' => ['mo', 'we'], 'count' => 10], false, $tz));
        $this->assertSame('FREQ=MONTHLY;BYMONTHDAY=15', RecurrenceRule::toRrule(['freq' => 'MONTHLY', 'bymonthday' => 15], false, $tz));
        $this->assertSame('FREQ=MONTHLY;BYDAY=-1FR', RecurrenceRule::toRrule(['freq' => 'MONTHLY', 'byday' => ['FR'], 'bysetpos' => -1], false, $tz));
        $this->assertSame('FREQ=YEARLY;UNTIL=20271231', RecurrenceRule::toRrule(['freq' => 'YEARLY', 'until' => '2027-12-31'], true, $tz));
        // Timed events: UNTIL is the end of that local day, in UTC (RFC 5545 requires UTC with a TZID start).
        $this->assertSame('FREQ=WEEKLY;UNTIL=20271231T225959Z', RecurrenceRule::toRrule(['freq' => 'WEEKLY', 'until' => '2027-12-31'], false, $tz));
    }

    public function test_rejects_invalid_rules(): void
    {
        $bad = [
            ['freq' => 'HOURLY'],
            ['freq' => 'DAILY', 'interval' => 0],
            ['freq' => 'WEEKLY', 'byday' => ['XX']],
            ['freq' => 'MONTHLY', 'byday' => ['MO', 'TU'], 'bysetpos' => 2],
            ['freq' => 'MONTHLY', 'bymonthday' => 32],
            ['freq' => 'DAILY', 'until' => '2027-01-01', 'count' => 3],
            ['freq' => 'DAILY', 'until' => '31-12-2027'],
        ];
        foreach ($bad as $input) {
            try {
                RecurrenceRule::toRrule($input, false, 'UTC');
                $this->fail('Accepted ' . json_encode($input));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_round_trips_and_reports_unrepresentable_rules(): void
    {
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['MO', 'WE'], 'count' => 10], RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10'));
        $this->assertSame(['freq' => 'MONTHLY', 'interval' => 1, 'byday' => ['FR'], 'bysetpos' => -1], RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=-1FR'));
        $this->assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'until' => '2027-12-31'], RecurrenceRule::fromRrule('FREQ=WEEKLY;UNTIL=20271231T225959Z', 'Europe/Amsterdam'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=HOURLY'));
        $this->assertNull(RecurrenceRule::fromRrule('FREQ=MONTHLY;BYDAY=MO;BYSETPOS=1,3'));
        $this->assertNull(RecurrenceRule::fromRrule(null));
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter RecurrenceRuleTest'`
Expected: ERROR `Class ... RecurrenceRule not found`.

- [ ] **Step 3: Write `Services/RecurrenceRule.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Converts the event form's recurrence options to RRULE and back. Every option maps onto Graph's patternedRecurrence. */
final class RecurrenceRule
{
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public static function toRrule(array $input, bool $allDay, string $timezone): string
    {
        $freq = strtoupper((string) ($input['freq'] ?? ''));
        if (!in_array($freq, self::FREQUENCIES, true)) {
            self::fail('recurrence.freq', __('Choose how often the event repeats.'));
        }
        $parts = ['FREQ=' . $freq];

        $interval = (int) ($input['interval'] ?? 1);
        if ($interval < 1 || $interval > 999) {
            self::fail('recurrence.interval', __('The repeat interval must be between 1 and 999.'));
        }
        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        $byday = array_values(array_unique(array_map('strtoupper', (array) ($input['byday'] ?? []))));
        if (array_diff($byday, self::WEEKDAYS)) {
            self::fail('recurrence.byday', __('Invalid weekday.'));
        }
        $setpos = isset($input['bysetpos']) ? (int) $input['bysetpos'] : null;

        if ($freq === 'WEEKLY' && $byday) {
            $parts[] = 'BYDAY=' . implode(',', $byday);
        } elseif ($freq === 'MONTHLY' && $setpos !== null) {
            if (count($byday) !== 1 || !in_array($setpos, [1, 2, 3, 4, -1], true)) {
                self::fail('recurrence.bysetpos', __('Choose one weekday and its position in the month.'));
            }
            $parts[] = 'BYDAY=' . $setpos . $byday[0];
        } elseif ($freq === 'MONTHLY' && isset($input['bymonthday'])) {
            $day = (int) $input['bymonthday'];
            if ($day < 1 || $day > 31) {
                self::fail('recurrence.bymonthday', __('The day of the month must be between 1 and 31.'));
            }
            $parts[] = 'BYMONTHDAY=' . $day;
        }

        if (!empty($input['until']) && !empty($input['count'])) {
            self::fail('recurrence.until', __('Choose an end date or a number of times, not both.'));
        }
        if (!empty($input['count'])) {
            $count = (int) $input['count'];
            if ($count < 1 || $count > 999) {
                self::fail('recurrence.count', __('The number of times must be between 1 and 999.'));
            }
            $parts[] = 'COUNT=' . $count;
        } elseif (!empty($input['until'])) {
            $until = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input['until'], new DateTimeZone($timezone));
            if ($until === false || $until->format('Y-m-d') !== (string) $input['until']) {
                self::fail('recurrence.until', __('Invalid end date.'));
            }
            $parts[] = 'UNTIL=' . ($allDay
                ? $until->format('Ymd')
                : $until->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        }

        return implode(';', $parts);
    }

    public static function fromRrule(?string $rrule, string $timezone = 'UTC'): ?array
    {
        if (!$rrule) {
            return null;
        }
        $parts = [];
        foreach (explode(';', strtoupper(trim($rrule))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }
        $supported = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'COUNT', 'UNTIL', 'WKST'];
        if (array_diff(array_keys($parts), $supported) || !in_array($parts['FREQ'] ?? '', self::FREQUENCIES, true)) {
            return null;
        }

        $result = ['freq' => $parts['FREQ'], 'interval' => (int) ($parts['INTERVAL'] ?? 1)];
        if (isset($parts['BYDAY'])) {
            if ($parts['FREQ'] === 'WEEKLY') {
                $days = explode(',', $parts['BYDAY']);
                if (array_diff($days, self::WEEKDAYS)) {
                    return null;
                }
                $result['byday'] = $days;
            } elseif ($parts['FREQ'] === 'MONTHLY' && preg_match('/^(-1|[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY'], $m)) {
                $result['byday'] = [$m[2]];
                $result['bysetpos'] = (int) $m[1];
            } else {
                return null;
            }
        }
        if (isset($parts['BYMONTHDAY'])) {
            if ($parts['FREQ'] !== 'MONTHLY' || !ctype_digit($parts['BYMONTHDAY'])) {
                return null;
            }
            $result['bymonthday'] = (int) $parts['BYMONTHDAY'];
        }
        if (isset($parts['COUNT'])) {
            $result['count'] = (int) $parts['COUNT'];
        }
        if (isset($parts['UNTIL'])) {
            $until = strlen($parts['UNTIL']) > 8
                ? DateTimeImmutable::createFromFormat('Ymd\THis\Z', $parts['UNTIL'], new DateTimeZone('UTC'))
                : DateTimeImmutable::createFromFormat('!Ymd', $parts['UNTIL'], new DateTimeZone($timezone));
            if ($until === false) {
                return null;
            }
            $result['until'] = $until->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        }

        return $result;
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter RecurrenceRuleTest'`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/RecurrenceRule.php Tests/Unit/RecurrenceRuleTest.php
git commit -m "feat: add recurrence rule conversion for the event form"
```

---

### Task 7: Occurrence expansion and range queries

**Files:**
- Create: `$MOD/Services/OccurrenceService.php`
- Create: `$MOD/Console/RefreshOccurrences.php`
- Modify: `$MOD/Providers/LJPcCalendarModuleServiceProvider.php` (`registerCommands()`, `hooks()`)
- Test: `$MOD/Tests/Unit/OccurrenceServiceTest.php`

**Interfaces:**
- Consumes:
  - `EventIcs::recurrenceKey`
  - `CalendarEvent` (fields `ics`, `uid`, `timezone`, `is_recurring`, `summary`, `starts_at`, `ends_at`, `all_day`, `status`)
  - `Calendar::timezoneName()`
- Produces `OccurrenceService` (resolve it through `app(OccurrenceService::class)`). Constants: `MAX_PER_EVENT = 5000`, `WINDOW_OPTION = 'ljpccalendarmodule.occurrence_window'`, `PAST_MONTHS = 12`, `FUTURE_MONTHS = 24`. Methods:
  - `window(): array{0: DateTimeImmutable, 1: DateTimeImmutable}`
  - `moveWindow(?DateTimeImmutable $now = null): array`
  - `refresh(CalendarEvent $event): void`. Must be called inside the caller's transaction.
  - `refreshRecurring(): int`
  - `expand(CalendarEvent $event, DateTimeImmutable $from, DateTimeImmutable $to): array`. Returns rows with keys `recurrence_id`, `summary`, `starts_at`, `ends_at`, `all_day`, `status`.
  - `between(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, string $timezone): array`. Returns the same rows plus `event_id` and `calendar_id`.
  - `find(CalendarEvent $event, string $key): ?array`. Returns the expanded row for one occurrence key, or null.
  - `hasOccurrence(CalendarEvent $event, string $key): bool`
- Produces: the artisan command `calendar:refresh-occurrences`, scheduled daily at 03:17.

- [ ] **Step 1: Write the failing test `Tests/Unit/OccurrenceServiceTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Component\VCalendar;

class OccurrenceServiceTest extends TestCase
{
    private OccurrenceService $service;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OccurrenceService::class);
        $this->service->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create(['name' => 'Occurrences ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'timezone' => 'Europe/Amsterdam']);
    }

    private function store(VCalendar $vcal, string $uid): CalendarEvent
    {
        $event = new CalendarEvent(['calendar_id' => $this->calendar->id, 'ics' => $vcal->serialize()] + EventIcs::derive($vcal, $uid, 'Europe/Amsterdam'));
        $event->uid = $uid;
        $event->save();
        $this->service->refresh($event);

        return $event;
    }

    private function local(string $tz, string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone($tz));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function test_weekly_series_keeps_local_time_across_the_european_dst_change(): void
    {
        $vcal = EventIcs::build(['uid' => 'w', 'title' => 'Standup', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 10:15'), 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
        $event = $this->store($vcal, 'w');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-27 09:00:00', '2026-11-03 09:00:00'], $rows);
        $this->assertSame('20261027T090000Z', CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->skip(1)->first()->recurrence_id);
    }

    public function test_weekly_series_keeps_local_time_across_the_sydney_dst_change(): void
    {
        $vcal = EventIcs::build(['uid' => 's', 'title' => 'Sync', 'all_day' => false, 'start' => $this->local('Australia/Sydney', '2026-09-24 09:00'), 'end' => $this->local('Australia/Sydney', '2026-09-24 10:00'), 'rrule' => 'FREQ=WEEKLY;INTERVAL=2;COUNT=2']);
        $event = $this->store($vcal, 's');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
        $this->assertSame(['2026-09-23 23:00:00', '2026-10-07 22:00:00'], $rows);
    }

    public function test_exdate_and_override_are_respected(): void
    {
        $vcal = EventIcs::build(['uid' => 'e', 'title' => 'Daily', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-20 10:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-20 11:00'), 'rrule' => 'FREQ=DAILY;COUNT=3']);
        $master = EventIcs::master($vcal, 'e');
        $tz = new DateTimeZone('Europe/Amsterdam');
        EventIcs::addExdate($master, '20261021T080000Z', $tz);
        $override = EventIcs::addOverride($vcal, $master, '20261022T080000Z', $tz);
        EventIcs::apply($override, ['title' => 'Moved', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-22 15:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-22 16:00')]);
        $event = $this->store($vcal, 'e');

        $rows = CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-22 13:00:00', $rows[1]->starts_at);
        $this->assertSame('Moved', $rows[1]->summary);
        $this->assertNull($rows[0]->summary);
        $this->assertTrue($this->service->hasOccurrence($event, '20261022T080000Z'));
        $this->assertFalse($this->service->hasOccurrence($event, '20261021T080000Z'));
    }

    public function test_between_includes_multi_day_events_that_started_before_the_range(): void
    {
        $this->store(EventIcs::build(['uid' => 'm', 'title' => 'Trip', 'all_day' => true, 'start' => '2026-10-01', 'end' => '2026-10-11']), 'm');
        $this->store(EventIcs::build(['uid' => 'n', 'title' => 'Night', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-04 22:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-05 02:00')]), 'n');

        $rows = $this->service->between([$this->calendar->id], $this->local('Europe/Amsterdam', '2026-10-05 00:00'), $this->local('Europe/Amsterdam', '2026-10-12 00:00'), 'Europe/Amsterdam');

        $this->assertCount(2, $rows);
        $none = $this->service->between([$this->calendar->id], $this->local('Europe/Amsterdam', '2026-10-11 00:00'), $this->local('Europe/Amsterdam', '2026-10-12 00:00'), 'Europe/Amsterdam');
        $this->assertCount(0, $none); // the all-day end date is exclusive
    }

    public function test_ranges_outside_the_stored_window_are_expanded_on_the_fly(): void
    {
        $this->store(EventIcs::build(['uid' => 'f', 'title' => 'Forever', 'all_day' => false, 'start' => $this->local('Europe/Amsterdam', '2026-10-05 09:00'), 'end' => $this->local('Europe/Amsterdam', '2026-10-05 09:30'), 'rrule' => 'FREQ=WEEKLY']), 'f');

        $rows = $this->service->between([$this->calendar->id], $this->utc('2030-01-01'), $this->utc('2030-01-15'), 'Europe/Amsterdam');

        $this->assertCount(2, $rows);
        $this->assertSame('2030-01-07 08:00:00', $rows[0]['starts_at']);
        $this->assertSame(0, CalendarOccurrence::where('starts_at', '>=', '2030-01-01')->count());
    }

    public function test_infinite_series_is_capped(): void
    {
        $event = $this->store(EventIcs::build(['uid' => 'c', 'title' => 'Daily', 'all_day' => true, 'start' => '2000-01-01', 'end' => '2000-01-02', 'rrule' => 'FREQ=DAILY']), 'c');

        $rows = $this->service->expand($event, $this->utc('2000-01-01'), $this->utc('2100-01-01'));
        $this->assertCount(OccurrenceService::MAX_PER_EVENT, $rows);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter OccurrenceServiceTest'`
Expected: ERROR `Class ... OccurrenceService not found`.

- [ ] **Step 3: Write `Services/OccurrenceService.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;

/**
 * Stores occurrences of recurring events for a fixed window (12 months back, 24 ahead) and expands
 * on the fly outside it. Non-recurring events always have exactly one stored row.
 */
class OccurrenceService
{
    public const MAX_PER_EVENT = 5000;
    public const WINDOW_OPTION = 'ljpccalendarmodule.occurrence_window';
    public const PAST_MONTHS = 12;
    public const FUTURE_MONTHS = 24;

    public function window(): array
    {
        $stored = Option::get(self::WINDOW_OPTION, null, true, false);
        if (is_array($stored) && isset($stored['from'], $stored['to'])) {
            return [new DateTimeImmutable($stored['from'], self::utc()), new DateTimeImmutable($stored['to'], self::utc())];
        }

        return $this->moveWindow();
    }

    public function moveWindow(?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now', self::utc()))->setTimezone(self::utc())->setTime(0, 0);
        $from = $today->modify('-' . self::PAST_MONTHS . ' months');
        $to = $today->modify('+' . self::FUTURE_MONTHS . ' months');
        Option::set(self::WINDOW_OPTION, ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

        return [$from, $to];
    }

    public function refresh(CalendarEvent $event): void
    {
        [$from, $to] = $this->window();
        $rows = $event->is_recurring ? $this->expand($event, $from, $to) : [$this->single($event)];

        CalendarOccurrence::where('event_id', $event->id)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            CalendarOccurrence::insert(array_map(function (array $row) use ($event) {
                return $row + ['event_id' => $event->id, 'calendar_id' => $event->calendar_id];
            }, $chunk));
        }
    }

    public function refreshRecurring(): int
    {
        $count = 0;
        CalendarEvent::where('is_recurring', true)->with('calendar')->chunk(100, function ($events) use (&$count) {
            foreach ($events as $event) {
                DB::transaction(function () use ($event) {
                    $this->refresh($event);
                });
                $count++;
            }
        });

        return $count;
    }

    public function expand(CalendarEvent $event, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tz = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
        // Our own cap (MAX_PER_EVENT) bounds the output; sabre's cap would also count fast-forwarded instances.
        Settings::$maxRecurrences = -1;

        try {
            $iterator = new EventIterator(Reader::read($event->ics), $event->uid, $tz);
            $iterator->fastForward($from);
            $rows = [];
            while ($iterator->valid() && $iterator->getDtStart() < $to && count($rows) < self::MAX_PER_EVENT) {
                $instance = $iterator->getEventObject();
                $allDay = !$instance->DTSTART->hasTime();
                $summary = (string) ($instance->SUMMARY ?? '');
                $rows[] = [
                    'recurrence_id' => EventIcs::recurrenceKey($instance->{'RECURRENCE-ID'}, $tz),
                    'summary'       => $summary !== $event->summary ? mb_substr($summary, 0, 500) : null,
                    'starts_at'     => self::column($iterator->getDtStart(), $allDay),
                    'ends_at'       => self::column($iterator->getDtEnd(), $allDay),
                    'all_day'       => $allDay,
                    'status'        => strtolower((string) ($instance->STATUS ?? 'CONFIRMED')),
                ];
                $iterator->next();
            }

            return $rows;
        } catch (NoInstancesException $e) {
            return []; // every instance is excluded; nothing to show
        } catch (InvalidDataException | MaxInstancesExceededException $e) {
            Log::error('Calendar event recurrence could not be expanded', ['event_id' => $event->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function find(CalendarEvent $event, string $key): ?array
    {
        $center = DateTimeImmutable::createFromFormat('!Ymd', substr($key, 0, 8), self::utc());
        if ($center === false) {
            return null;
        }
        // An override may move an occurrence far from its original slot, so also look at the override's own start.
        foreach ($this->expand($event, $center->modify('-2 days'), $center->modify('+2 days')) as $row) {
            if ($row['recurrence_id'] === $key) {
                return $row;
            }
        }
        foreach ($this->expand($event, $center->modify('-' . self::PAST_MONTHS . ' months'), $center->modify('+' . self::FUTURE_MONTHS . ' months')) as $row) {
            if ($row['recurrence_id'] === $key) {
                return $row;
            }
        }

        return null;
    }

    public function hasOccurrence(CalendarEvent $event, string $key): bool
    {
        return $this->find($event, $key) !== null;
    }

    public function between(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, string $timezone): array
    {
        if (!$calendarIds) {
            return [];
        }
        $bounds = [
            'fromUtc'   => $from->setTimezone(self::utc())->format('Y-m-d H:i:s'),
            'toUtc'     => $to->setTimezone(self::utc())->format('Y-m-d H:i:s'),
            'fromLocal' => $from->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s'),
            'toLocal'   => $to->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s'),
        ];

        $rows = CalendarOccurrence::whereIn('calendar_id', $calendarIds)
            ->where('starts_at', '<', $to->setTimezone(self::utc())->modify('+14 hours')->format('Y-m-d H:i:s'))
            ->where('ends_at', '>=', $from->setTimezone(self::utc())->modify('-14 hours')->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')
            ->get(['event_id', 'calendar_id', 'recurrence_id', 'summary', 'starts_at', 'ends_at', 'all_day', 'status'])
            ->map(function (CalendarOccurrence $occurrence) {
                return $occurrence->toArray();
            })
            ->filter(function (array $row) use ($bounds) {
                return self::overlaps($row, $bounds);
            })
            ->values()
            ->all();

        [$windowFrom, $windowTo] = $this->window();
        if ($from < $windowFrom || $to > $windowTo) {
            $rows = array_merge($rows, $this->outsideWindow($calendarIds, $from, $to, $windowFrom, $windowTo, $bounds));
        }

        return $rows;
    }

    private function outsideWindow(array $calendarIds, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $windowFrom, DateTimeImmutable $windowTo, array $bounds): array
    {
        $windowFromText = $windowFrom->format('Y-m-d H:i:s');
        $windowToText = $windowTo->format('Y-m-d H:i:s');
        $rows = [];
        $events = CalendarEvent::whereIn('calendar_id', $calendarIds)->where('is_recurring', true)->with('calendar')->get();
        foreach ($events as $event) {
            foreach ($this->expand($event, $from, $to) as $row) {
                $inside = $row['starts_at'] >= $windowFromText && $row['starts_at'] < $windowToText;
                if (!$inside && self::overlaps($row, $bounds)) {
                    $rows[] = $row + ['event_id' => $event->id, 'calendar_id' => $event->calendar_id];
                }
            }
        }

        return $rows;
    }

    private static function overlaps(array $row, array $bounds): bool
    {
        if ($row['all_day']) {
            return $row['starts_at'] < $bounds['toLocal'] && $row['ends_at'] > $bounds['fromLocal'];
        }
        $zeroLength = $row['starts_at'] === $row['ends_at'];

        return $row['starts_at'] < $bounds['toUtc']
            && ($row['ends_at'] > $bounds['fromUtc'] || ($zeroLength && $row['starts_at'] >= $bounds['fromUtc']));
    }

    private function single(CalendarEvent $event): array
    {
        return [
            'recurrence_id' => null,
            'summary'       => null,
            'starts_at'     => $event->starts_at,
            'ends_at'       => $event->ends_at,
            'all_day'       => (bool) $event->all_day,
            'status'        => $event->status,
        ];
    }

    private static function column(DateTimeImmutable $dateTime, bool $allDay): string
    {
        return $allDay ? $dateTime->format('Y-m-d 00:00:00') : $dateTime->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
```

- [ ] **Step 4: Write `Console/RefreshOccurrences.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Console;

use Illuminate\Console\Command;
use Modules\LJPcCalendarModule\Services\OccurrenceService;

class RefreshOccurrences extends Command
{
    protected $signature = 'calendar:refresh-occurrences';

    protected $description = 'Moves the calendar occurrence window and re-expands recurring events';

    public function handle(OccurrenceService $occurrences)
    {
        $occurrences->moveWindow();
        $count = $occurrences->refreshRecurring();
        $this->info('Refreshed ' . $count . ' recurring events.');
    }
}
```

- [ ] **Step 5: Register the command and its daily schedule in the provider**

Add `use Modules\LJPcCalendarModule\Console\RefreshOccurrences;` and replace `registerCommands()` and `hooks()`:

```php
    public function registerCommands()
    {
        $this->commands([RefreshOccurrences::class]);
    }

    public function hooks()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('calendar:refresh-occurrences')->dailyAt('03:17')->withoutOverlapping();

            return $schedule;
        });
    }
```

- [ ] **Step 6: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter OccurrenceServiceTest'`
Expected: `OK (6 tests, ...)`. Then run `ddev exec php artisan calendar:refresh-occurrences`. Expected: `Refreshed 0 recurring events.`

- [ ] **Step 7: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/OccurrenceService.php Console/RefreshOccurrences.php Providers/LJPcCalendarModuleServiceProvider.php Tests/Unit/OccurrenceServiceTest.php
git commit -m "feat: expand recurring events into a stored occurrence window"
```

---

### Task 8: Permission policy

**Files:**
- Create: `$MOD/Policies/CalendarPolicy.php`
- Modify: `$MOD/Providers/LJPcCalendarModuleServiceProvider.php` (register the policy in `boot()`)
- Test: `$MOD/Tests/Unit/CalendarPolicyTest.php`

**Interfaces:**
- Consumes: `Calendar` (`enabled`, `permissionRows`, `isWritable()`), `CalendarPermission::RIGHTS`, `\App\User::hasAccessToMailbox()`, and `\Team::getMemberIds()` when the Teams module is active.
- Produces `CalendarPolicy`:
  - `static rights(User $user, Calendar $calendar): array`. Returns keys `show_in_dashboard`, `can_view`, `can_create`, `can_edit`, all booleans.
  - Gate abilities `view`, `create`, `edit` and `dashboard` on a `Calendar` instance (`$user->can('edit', $calendar)`). `create` and `edit` also require `$calendar->isWritable()`.
  - `static viewableCalendarIds(User $user): array` returns the ids of enabled calendars the user may view.

- [ ] **Step 1: Write the failing test `Tests/Unit/CalendarPolicyTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Tests\TestCase;

class CalendarPolicyTest extends TestCase
{
    private function calendar(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Policy ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function grant(Calendar $calendar, string $type, int $id, array $rights): void
    {
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => $type, 'subject_id' => $id] + $rights);
    }

    public function test_no_rows_means_no_access_but_admins_see_everything_enabled(): void
    {
        $calendar = $this->calendar();

        $this->assertSame(['show_in_dashboard' => false, 'can_view' => false, 'can_create' => false, 'can_edit' => false], CalendarPolicy::rights($this->makeUser(), $calendar));
        $this->assertTrue($this->makeAdmin()->can('edit', $calendar));
        $this->assertFalse($this->makeAdmin()->can('view', $this->calendar(['enabled' => false])));
    }

    public function test_rights_are_the_union_of_user_and_mailbox_rows(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_USER, $user->id, ['can_view' => true]);
        $this->grant($calendar, CalendarPermission::SUBJECT_MAILBOX, $mailbox->id, ['can_create' => true]);

        $rights = CalendarPolicy::rights($user, $calendar->fresh());
        $this->assertTrue($rights['can_view']);
        $this->assertTrue($rights['can_create']);
        $this->assertFalse($rights['can_edit']);
        $this->assertFalse(CalendarPolicy::rights($this->makeUser(), $calendar->fresh())['can_create']); // not in the mailbox
        $this->assertSame([$calendar->id], array_values(array_intersect(CalendarPolicy::viewableCalendarIds($user), [$calendar->id])));
    }

    public function test_team_rows_apply_to_team_members(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('Teams module is not active.');
        }
        $member = $this->makeUser();
        $team = $this->makeTeam([$member->id]);
        $calendar = $this->calendar();
        $this->grant($calendar, CalendarPermission::SUBJECT_TEAM, $team->id, ['can_view' => true, 'can_edit' => true]);

        $this->assertTrue($member->can('edit', $calendar->fresh()));
        $this->assertFalse($this->makeUser()->can('view', $calendar->fresh()));
    }

    public function test_read_only_providers_never_allow_writes(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendar(['provider' => Calendar::PROVIDER_ICS]);
        $this->grant($calendar, CalendarPermission::SUBJECT_USER, $user->id, ['can_view' => true, 'can_create' => true, 'can_edit' => true]);

        $this->assertTrue($user->can('view', $calendar->fresh()));
        $this->assertFalse($user->can('create', $calendar->fresh()));
        $this->assertFalse($user->can('edit', $calendar->fresh()));
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter CalendarPolicyTest'`
Expected: ERROR `Class ... CalendarPolicy not found`.

- [ ] **Step 3: Write `Policies/CalendarPolicy.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Policies;

use App\User;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;

/** The only place that decides calendar access. Rights are the union of every user, team and mailbox row that applies. */
class CalendarPolicy
{
    public static function rights(User $user, Calendar $calendar): array
    {
        $rights = array_fill_keys(CalendarPermission::RIGHTS, false);
        if (!$calendar->enabled) {
            return $rights;
        }
        if ($user->isAdmin()) {
            return array_fill_keys(CalendarPermission::RIGHTS, true);
        }
        foreach ($calendar->permissionRows as $row) {
            if (!self::applies($user, $row)) {
                continue;
            }
            foreach (CalendarPermission::RIGHTS as $right) {
                $rights[$right] = $rights[$right] || (bool) $row->{$right};
            }
        }

        return $rights;
    }

    public static function viewableCalendarIds(User $user): array
    {
        return Calendar::where('enabled', true)->with('permissionRows')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return self::rights($user, $calendar)['can_view'];
            })
            ->pluck('id')
            ->all();
    }

    public function view(User $user, Calendar $calendar): bool
    {
        return self::rights($user, $calendar)['can_view'];
    }

    public function create(User $user, Calendar $calendar): bool
    {
        return $calendar->isWritable() && self::rights($user, $calendar)['can_create'];
    }

    public function edit(User $user, Calendar $calendar): bool
    {
        return $calendar->isWritable() && self::rights($user, $calendar)['can_edit'];
    }

    public function dashboard(User $user, Calendar $calendar): bool
    {
        return self::rights($user, $calendar)['show_in_dashboard'];
    }

    private static function applies(User $user, CalendarPermission $row): bool
    {
        switch ($row->subject_type) {
            case CalendarPermission::SUBJECT_USER:
                return $row->subject_id === (int) $user->id;
            case CalendarPermission::SUBJECT_MAILBOX:
                return (bool) $user->hasAccessToMailbox($row->subject_id);
            case CalendarPermission::SUBJECT_TEAM:
                return in_array((int) $user->id, self::teamMemberIds($row->subject_id), true);
        }

        return false;
    }

    /** Team rows only count while the Teams module is active, because that module defines membership. */
    private static function teamMemberIds(int $teamId): array
    {
        if (!class_exists('Team')) {
            return [];
        }
        $team = User::find($teamId);

        return $team ? array_map('intval', \Team::getMemberIds($team)) : [];
    }
}
```

- [ ] **Step 4: Register the policy**

In the provider, add `use Modules\LJPcCalendarModule\Entities\Calendar;` and `use Modules\LJPcCalendarModule\Policies\CalendarPolicy;`. In `boot()`, directly after the `require_once` line, add:

```php
        \Illuminate\Support\Facades\Gate::policy(Calendar::class, CalendarPolicy::class);
```

- [ ] **Step 5: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter CalendarPolicyTest'`
Expected: `OK (4 tests, ...)`. The team test runs because Task 1 activated Teams. If it reports "skipped", activate Teams as in Task 1, step 3.

- [ ] **Step 6: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Policies/CalendarPolicy.php Providers/LJPcCalendarModuleServiceProvider.php Tests/Unit/CalendarPolicyTest.php
git commit -m "feat: add calendar policy with user, team and mailbox permission rows"
```

---
### Task 9: Event service (custom fields, title templates, local writes, line items)

**Files:**
- Create: `$MOD/Services/FieldValues.php`
- Create: `$MOD/Services/TitleTemplate.php`
- Create: `$MOD/Services/EventService.php`
- Test: `$MOD/Tests/Unit/FieldValuesTest.php`
- Test: `$MOD/Tests/Unit/EventServiceTest.php`

**Interfaces:**
- Consumes:
  - `EventIcs` (`build`, `apply`, `master`, `read`, `derive`, `findOverride`, `addOverride`, `removeOverride`, `addExdate`, `dropExceptions`, `bumpSequence`)
  - `TimeInput`
  - `RecurrenceRule::toRrule`
  - `OccurrenceService::refresh`, `OccurrenceService::hasOccurrence`
- Produces `FieldValues`:
  - `TYPES = ['text','number','dropdown','boolean','multiselect','date','email','source']`
  - `static validate(Calendar $calendar, array $input): array`. Returns `[<field id as string> => value]`; throws `ValidationException` with keys `field_values.<id>`.
  - `static display(array $field, $value): string`
  - Field definition shape: `{id: int|string, name: string, type: string, required: bool, options: string[]}`.
- Produces `TitleTemplate`:
  - `static render(Calendar $calendar, string $title, array $values): string`. Placeholders are `{{title}}` and `{{field:<id>}}`. When the calendar has no template, a non-empty title is required.
  - `static fieldIds(string $template): array`
- Produces `EventService` (resolve it through `app(EventService::class)`), with constants `SCOPE_SERIES = 'series'` and `SCOPE_OCCURRENCE = 'occurrence'`:
  - `create(Calendar $calendar, User $user, array $input, ?Conversation $conversation = null): CalendarEvent`
  - `update(CalendarEvent $event, User $user, array $input): CalendarEvent`. Only the keys present in `$input` change. Extra keys: `scope`, `recurrence_id`, `calendar_id` (move).
  - `delete(CalendarEvent $event, string $scope, ?string $recurrenceId): void`
  - `static newUid(): string`
  - `$input` keys: `title`, `all_day`, `start`, `end`, `location`, `description`, `recurrence`, `field_values`, `reminder_minutes`.
  - With a conversation, a line item is added: `action_type` 186 with meta `{event_id, calendar_id}`.

- [ ] **Step 1: Write the failing test `Tests/Unit/FieldValuesTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\FieldValues;
use Modules\LJPcCalendarModule\Services\TitleTemplate;
use Modules\LJPcCalendarModule\Tests\TestCase;

class FieldValuesTest extends TestCase
{
    private function calendar(array $fields, ?string $template = null): Calendar
    {
        return new Calendar(['name' => 'x', 'fields' => $fields, 'title_template' => $template]);
    }

    public function test_casts_and_validates_each_type(): void
    {
        $calendar = $this->calendar([
            ['id' => 1, 'name' => 'Count', 'type' => 'number', 'required' => false, 'options' => []],
            ['id' => 2, 'name' => 'Paid', 'type' => 'boolean', 'required' => false, 'options' => []],
            ['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']],
            ['id' => 4, 'name' => 'Tags', 'type' => 'multiselect', 'required' => false, 'options' => ['A', 'B']],
            ['id' => 5, 'name' => 'Due', 'type' => 'date', 'required' => false, 'options' => []],
            ['id' => 6, 'name' => 'Mail', 'type' => 'email', 'required' => false, 'options' => []],
            ['id' => 7, 'name' => 'Note', 'type' => 'text', 'required' => false, 'options' => []],
        ]);

        $values = FieldValues::validate($calendar, ['1' => '3', '2' => '0', '3' => 'Visit', '4' => ['B'], '5' => '2026-10-05', '6' => 'a@b.nl', '7' => '<b>x</b>']);

        $this->assertSame(['1' => 3, '2' => false, '3' => 'Visit', '4' => ['B'], '5' => '2026-10-05', '6' => 'a@b.nl', '7' => '<b>x</b>'], $values);
    }

    public function test_reports_missing_and_invalid_values_per_field(): void
    {
        $calendar = $this->calendar([
            ['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call']],
            ['id' => 6, 'name' => 'Mail', 'type' => 'email', 'required' => false, 'options' => []],
        ]);
        try {
            FieldValues::validate($calendar, ['6' => 'not-an-email']);
            $this->fail('Accepted invalid values');
        } catch (ValidationException $e) {
            $this->assertSame(['field_values.3', 'field_values.6'], array_keys($e->errors()));
        }
    }

    public function test_title_template_uses_title_and_field_values(): void
    {
        $fields = [['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call']], ['id' => 4, 'name' => 'Tags', 'type' => 'multiselect', 'required' => false, 'options' => ['A', 'B']]];

        $this->assertSame('Acme - Call (A, B)', TitleTemplate::render($this->calendar($fields, '{{title}} - {{field:3}} ({{field:4}})'), 'Acme', ['3' => 'Call', '4' => ['A', 'B']]));
        $this->assertSame('Acme -', TitleTemplate::render($this->calendar($fields, '{{title}} - {{field:3}}'), 'Acme', []));
        $this->assertSame(['3', '4'], TitleTemplate::fieldIds('{{field:3}} {{ field:4 }}'));

        $this->expectException(ValidationException::class);
        TitleTemplate::render($this->calendar($fields), '  ', []);
    }
}
```

- [ ] **Step 2: Write the failing test `Tests/Unit/EventServiceTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class EventServiceTest extends TestCase
{
    private EventService $service;
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->service = app(EventService::class);
        $this->calendar = Calendar::create(['name' => 'Service ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function starts(CalendarEvent $event): array
    {
        return CalendarOccurrence::where('event_id', $event->id)->orderBy('starts_at')->pluck('starts_at')->all();
    }

    public function test_creates_a_timed_event_in_the_users_timezone(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $event = $this->service->create($this->calendar, $user, ['title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00', 'location' => 'Office']);

        $this->assertSame('Call', $event->summary);
        $this->assertSame('2026-10-20 08:00:00', $event->starts_at);
        $this->assertSame($user->id, $event->author_id);
        $this->assertSame(['2026-10-20 08:00:00'], $this->starts($event));
        $this->assertStringContainsString('LOCATION:Office', $event->ics);
    }

    public function test_applies_title_template_and_validates_fields(): void
    {
        $this->calendar->update([
            'fields' => [['id' => 3, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']]],
            'title_template' => '{{title}} ({{field:3}})',
        ]);
        $user = $this->makeUser();

        $event = $this->service->create($this->calendar, $user, ['title' => 'Acme', 'start' => '2026-10-20T10:00', 'field_values' => ['3' => 'Visit']]);
        $this->assertSame('Acme (Visit)', $event->summary);
        $this->assertSame('Acme', $event->title_input);

        $updated = $this->service->update($event, $user, ['field_values' => ['3' => 'Call']]);
        $this->assertSame('Acme (Call)', $updated->summary);

        $this->expectException(ValidationException::class);
        $this->service->create($this->calendar, $user, ['title' => 'Acme', 'start' => '2026-10-20T10:00']);
    }

    public function test_series_edits_move_every_occurrence_and_drop_exceptions(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);
        $this->service->delete($event->fresh(), EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
        $this->assertCount(2, $this->starts($event));

        $moved = $this->service->update($event->fresh(), $user, ['start' => '2026-10-20T11:00', 'end' => '2026-10-20T11:15']);

        $this->assertSame(['2026-10-20 09:00:00', '2026-10-21 09:00:00', '2026-10-22 09:00:00'], $this->starts($moved));
        $this->assertStringNotContainsString('EXDATE', $moved->ics);
        $this->assertSame(2, $moved->sequence); // one bump from the delete, one from the move
    }

    public function test_occurrence_edits_create_an_override(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Standup', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T10:15', 'recurrence' => ['freq' => 'DAILY', 'count' => 3]]);

        $updated = $this->service->update($event, $user, ['scope' => 'occurrence', 'recurrence_id' => '20261021T080000Z', 'title' => 'Late standup', 'start' => '2026-10-21T14:00', 'end' => '2026-10-21T14:15']);

        $this->assertSame(['2026-10-20 08:00:00', '2026-10-21 12:00:00', '2026-10-22 08:00:00'], $this->starts($updated));
        $this->assertSame('Late standup', CalendarOccurrence::where('event_id', $event->id)->where('recurrence_id', '20261021T080000Z')->value('summary'));
        $this->assertSame('Standup', $updated->summary);
        $this->assertSame('Standup', $updated->title_input);
    }

    public function test_rejects_unknown_occurrences(): void
    {
        $event = $this->service->create($this->calendar, $this->makeUser(), ['title' => 'x', 'start' => '2026-10-20T10:00', 'recurrence' => ['freq' => 'WEEKLY', 'count' => 2]]);

        $this->expectException(ValidationException::class);
        $this->service->delete($event, EventService::SCOPE_OCCURRENCE, '20261021T080000Z');
    }

    public function test_deleting_a_series_removes_its_occurrences(): void
    {
        $event = $this->service->create($this->calendar, $this->makeUser(), ['title' => 'x', 'all_day' => true, 'start' => '2026-10-20']);
        $this->service->delete($event, EventService::SCOPE_SERIES, null);

        $this->assertNull(CalendarEvent::find($event->id));
        $this->assertSame(0, CalendarOccurrence::where('event_id', $event->id)->count());
    }

    public function test_conversation_events_add_a_line_item(): void
    {
        $user = $this->makeUser();
        $mailbox = $this->makeMailbox([$user->id]);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $mailbox->id, 'status' => Conversation::STATUS_ACTIVE]);

        $event = $this->service->create($this->calendar, $user, ['title' => 'Follow up', 'start' => '2026-10-20T10:00'], $conversation);

        $thread = Thread::where('conversation_id', $conversation->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->first();
        $this->assertNotNull($thread);
        $this->assertSame(['event_id' => $event->id, 'calendar_id' => $this->calendar->id], $thread->getMetas());
        $this->assertSame($conversation->id, $event->conversation_id);
    }

    public function test_moving_to_another_calendar_revalidates_its_fields(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00']);
        $strict = Calendar::create(['name' => 'Strict ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [['id' => 9, 'name' => 'Kind', 'type' => 'text', 'required' => true, 'options' => []]]]);

        try {
            $this->service->update($event, $user, ['calendar_id' => $strict->id]);
            $this->fail('Moved without the required field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('field_values.9', $e->errors());
        }

        $moved = $this->service->update($event->fresh(), $user, ['calendar_id' => $strict->id, 'field_values' => ['9' => 'Call']]);
        $this->assertSame($strict->id, $moved->calendar_id);
        $this->assertSame($strict->id, CalendarOccurrence::where('event_id', $event->id)->value('calendar_id'));
    }
}
```

- [ ] **Step 3: Run both and see them fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "FieldValuesTest|EventServiceTest"'`
Expected: ERROR `Class ... FieldValues not found`.

- [ ] **Step 4: Write `Services/FieldValues.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;

/** Validates custom field values against a calendar's field definitions. Values are keyed by field id. */
final class FieldValues
{
    public const TYPES = ['text', 'number', 'dropdown', 'boolean', 'multiselect', 'date', 'email', 'source'];

    public static function validate(Calendar $calendar, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $id = (string) $field['id'];
            $key = 'field_values.' . $id;
            $value = $input[$id] ?? null;

            if ($value === null || $value === '' || $value === []) {
                if (!empty($field['required']) && $field['type'] !== 'boolean') {
                    $errors[$key] = __(':field is required.', ['field' => $field['name']]);
                }
                continue;
            }
            $cast = self::cast($field, $value);
            if ($cast === null) {
                $errors[$key] = __(':field has an invalid value.', ['field' => $field['name']]);
                continue;
            }
            $values[$id] = $cast;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    public static function display(array $field, $value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        }
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return (string) $value;
    }

    private static function cast(array $field, $value)
    {
        $options = array_map('strval', (array) ($field['options'] ?? []));
        switch ($field['type']) {
            case 'number':
                return is_numeric($value) ? $value + 0 : null;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            case 'dropdown':
                return is_string($value) && in_array($value, $options, true) ? $value : null;
            case 'multiselect':
                $list = array_map('strval', (array) $value);

                return array_diff($list, $options) ? null : array_values($list);
            case 'date':
                return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
            default: // text, source
                return is_scalar($value) ? mb_substr((string) $value, 0, 2000) : null;
        }
    }
}
```

- [ ] **Step 5: Write `Services/TitleTemplate.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;

/** Renders a calendar's title template. Placeholders: {{title}} and {{field:<id>}}. */
final class TitleTemplate
{
    private const PLACEHOLDER = '/\{\{\s*(title|field:([^}\s]+))\s*\}\}/';

    public static function render(Calendar $calendar, string $title, array $values): string
    {
        $title = trim($title);
        $template = trim((string) $calendar->title_template);
        if ($template === '') {
            if ($title === '') {
                throw ValidationException::withMessages(['title' => __('The title is required.')]);
            }

            return mb_substr($title, 0, 500);
        }

        $fields = [];
        foreach ($calendar->fieldDefinitions() as $field) {
            $fields[(string) $field['id']] = $field;
        }
        $result = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($title, $values, $fields) {
            if ($match[1] === 'title') {
                return $title;
            }
            $id = $match[2];

            return isset($values[$id], $fields[$id]) ? FieldValues::display($fields[$id], $values[$id]) : '';
        }, $template);
        $result = trim(preg_replace('/\s+/', ' ', $result));
        if ($result === '' && $title === '') {
            throw ValidationException::withMessages(['title' => __('The title is required.')]);
        }

        return mb_substr($result !== '' ? $result : $title, 0, 500);
    }

    public static function fieldIds(string $template): array
    {
        preg_match_all('/\{\{\s*field:([^}\s]+)\s*\}\}/', $template, $matches);

        return $matches[1];
    }
}
```

- [ ] **Step 6: Write `Services/EventService.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use App\Thread;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/** Creates, changes and deletes events. W1 writes local calendars only; W3 adds provider writes in store(). */
class EventService
{
    public const SCOPE_SERIES = 'series';
    public const SCOPE_OCCURRENCE = 'occurrence';

    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    public function create(Calendar $calendar, User $user, array $input, ?Conversation $conversation = null): CalendarEvent
    {
        $times = TimeInput::normalize((bool) ($input['all_day'] ?? false), (string) ($input['start'] ?? ''), $input['end'] ?? null, TimeInput::userTimezone($user));
        $values = FieldValues::validate($calendar, (array) ($input['field_values'] ?? []));
        $titleInput = (string) ($input['title'] ?? '');

        $event = new CalendarEvent([
            'calendar_id'      => $calendar->id,
            'author_id'        => $user->id,
            'conversation_id'  => $conversation ? $conversation->id : null,
            'field_values'     => $values,
            'title_input'      => $titleInput,
            'reminder_minutes' => $input['reminder_minutes'] ?? null,
        ]);
        $event->uid = self::newUid();
        $event->setRelation('calendar', $calendar);

        $vcal = EventIcs::build([
            'uid'         => $event->uid,
            'title'       => TitleTemplate::render($calendar, $titleInput, $values),
            'all_day'     => $times['all_day'],
            'start'       => $times['start'],
            'end'         => $times['end'],
            'location'    => $input['location'] ?? null,
            'description' => $input['description'] ?? null,
            'rrule'       => !empty($input['recurrence']) ? RecurrenceRule::toRrule($input['recurrence'], $times['all_day'], $times['timezone']) : null,
        ]);

        DB::transaction(function () use ($event, $vcal, $conversation, $user) {
            $this->store($event, $vcal);
            if ($conversation) {
                $this->addLineItem($event, $conversation, $user);
            }
        });

        return $event;
    }

    public function update(CalendarEvent $event, User $user, array $input): CalendarEvent
    {
        if (isset($input['calendar_id']) && (int) $input['calendar_id'] !== $event->calendar_id) {
            $target = Calendar::findOrFail((int) $input['calendar_id']);
            $event->calendar_id = $target->id;
            $event->setRelation('calendar', $target);
            $input['field_values'] = $input['field_values'] ?? (array) $event->field_values;
        }
        $calendar = $event->calendar;
        $occurrenceScope = $event->is_recurring && ($input['scope'] ?? self::SCOPE_SERIES) === self::SCOPE_OCCURRENCE;

        if (array_key_exists('field_values', $input)) {
            $event->field_values = FieldValues::validate($calendar, (array) $input['field_values']);
        }
        if (array_key_exists('title', $input) && !$occurrenceScope) {
            $event->title_input = (string) $input['title']; // an occurrence's own title lives only in its override
        }
        if (array_key_exists('reminder_minutes', $input)) {
            $event->reminder_minutes = $input['reminder_minutes'];
        }

        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        $tz = new DateTimeZone($event->timezone ?: $calendar->timezoneName());

        if ($occurrenceScope) {
            $key = $this->occurrenceKey($event, $input['recurrence_id'] ?? null);
            $override = EventIcs::findOverride($vcal, $event->uid, $key, $tz) ?? EventIcs::addOverride($vcal, $master, $key, $tz);
            $this->applyInput($override, $event, $input, $user, $tz, false);
        } elseif ($this->applyInput($master, $event, $input, $user, $tz, true)) {
            EventIcs::dropExceptions($vcal, $master);
        }

        DB::transaction(function () use ($event, $vcal) {
            $this->store($event, $vcal);
        });

        return $event->fresh();
    }

    public function delete(CalendarEvent $event, string $scope, ?string $recurrenceId): void
    {
        if ($event->is_recurring && $scope === self::SCOPE_OCCURRENCE) {
            $key = $this->occurrenceKey($event, $recurrenceId);
            $vcal = Reader::read($event->ics);
            $master = EventIcs::master($vcal, $event->uid);
            $tz = new DateTimeZone($event->timezone ?: $event->calendar->timezoneName());
            EventIcs::removeOverride($vcal, $event->uid, $key, $tz);
            EventIcs::addExdate($master, $key, $tz);
            EventIcs::bumpSequence($master);
            DB::transaction(function () use ($event, $vcal) {
                $this->store($event, $vcal);
            });

            return;
        }
        DB::transaction(function () use ($event) {
            $event->delete(); // occurrences cascade through the foreign key
        });
    }

    public static function newUid(): string
    {
        return bin2hex(random_bytes(16)) . '@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'freescout');
    }

    /** Applies form input to one VEVENT. Returns true when a master's start or rule changed. */
    private function applyInput(VEvent $component, CalendarEvent $event, array $input, User $user, DateTimeZone $tz, bool $isMaster): bool
    {
        $current = EventIcs::read($component, $tz);
        $data = [];
        if (array_key_exists('title', $input) || array_key_exists('field_values', $input)) {
            $title = array_key_exists('title', $input) ? (string) $input['title'] : ($event->title_input ?? $current['title']);
            $data['title'] = TitleTemplate::render($event->calendar, $title, (array) $event->field_values);
        }
        foreach (['location', 'description'] as $key) {
            if (array_key_exists($key, $input)) {
                $data[$key] = $input[$key];
            }
        }

        $scheduleChanged = false;
        if (array_key_exists('start', $input)) {
            $allDay = array_key_exists('all_day', $input) ? (bool) $input['all_day'] : $current['all_day'];
            $times = TimeInput::normalize($allDay, (string) $input['start'], $input['end'] ?? null, TimeInput::userTimezone($user));
            $data += ['all_day' => $times['all_day'], 'start' => $times['start'], 'end' => $times['end']];
            $scheduleChanged = $times['all_day'] !== $current['all_day'] || !self::sameMoment($times['start'], $current['start']);
        }
        if ($isMaster && array_key_exists('recurrence', $input)) {
            $allDay = $data['all_day'] ?? $current['all_day'];
            $rrule = $input['recurrence'] ? RecurrenceRule::toRrule($input['recurrence'], $allDay, TimeInput::userTimezone($user)) : null;
            if ($rrule !== $current['rrule']) {
                $data['rrule'] = $rrule;
                $scheduleChanged = true;
            }
        }
        if ($data) {
            EventIcs::apply($component, $data);
            EventIcs::bumpSequence($component);
        }

        return $isMaster && $scheduleChanged;
    }

    private function occurrenceKey(CalendarEvent $event, ?string $key): string
    {
        if (!$key || !$this->occurrences->hasOccurrence($event, $key)) {
            throw ValidationException::withMessages(['recurrence_id' => __('This occurrence does not exist.')]);
        }

        return $key;
    }

    private function store(CalendarEvent $event, VCalendar $vcal): void
    {
        $event->ics = $vcal->serialize();
        $event->fill(EventIcs::derive($vcal, $event->uid, $event->calendar->timezoneName()));
        $event->save();
        $this->occurrences->refresh($event);
    }

    private function addLineItem(CalendarEvent $event, Conversation $conversation, User $user): void
    {
        Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'user_id'            => $conversation->user_id,
            'created_by_user_id' => $user->id,
            'action_type'        => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            'meta'               => ['event_id' => $event->id, 'calendar_id' => $event->calendar_id],
        ]);
    }

    private static function sameMoment($a, $b): bool
    {
        if ($a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable) {
            return $a->getTimestamp() === $b->getTimestamp();
        }

        return (string) $a === (string) $b;
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "FieldValuesTest|EventServiceTest"'`
Expected: `OK (11 tests, ...)`. If `test_conversation_events_add_a_line_item` fails on `getMetas()`, read `freescout/app/Thread.php:1300-1340`. If `Thread::create` stores meta differently, use `$thread->setMetas([...])` and then `$thread->save()`, keeping the same meta keys, and note the change in the commit message.

- [ ] **Step 8: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/FieldValues.php Services/TitleTemplate.php Services/EventService.php Tests/Unit/FieldValuesTest.php Tests/Unit/EventServiceTest.php
git commit -m "feat: add event service with custom fields, title templates and recurrence edits"
```

---

### Task 10: Agent API

**Files:**
- Create: `$MOD/Http/EventPresenter.php`
- Create: `$MOD/Http/Controllers/Api/CalendarsController.php`
- Create: `$MOD/Http/Controllers/Api/EventsController.php`
- Create: `$MOD/Http/Controllers/Api/ConversationEventsController.php`
- Modify: `$MOD/Http/routes.php`
- Modify: `$MOD/Tests/Feature/LegacyEndpointsTest.php` (the PUT/DELETE expectations go from 404 to 405)
- Test: `$MOD/Tests/Feature/AgentApiTest.php`

**Interfaces:**
- Consumes: `CalendarPolicy` (`rights`, `viewableCalendarIds`, abilities), `EventService`, `OccurrenceService::between` and `::find`, `TimeInput::userTimezone`, `RecurrenceRule::fromRrule`.
- Produces the HTTP contract that W2 builds on. All routes are under the subdirectory prefix, need the agent role (`user` or `admin`), and return JSON.
  - `GET /calendar/api/calendars` returns `[{id, name, color, provider, display_priority, fields, has_title_template, rights:{show_in_dashboard,can_view,can_create,can_edit}, writable, last_error}]`.
  - `GET /calendar/api/events?start=<ISO>&end=<ISO>` returns `[{id:"<event_id>[:<recurrence_id>]", event_id, calendar_id, recurrence_id, title, start, end, all_day, status, is_recurring, editable}]`. Timed values are ISO 8601 with an offset in the agent's timezone; all-day values are `Y-m-d` with an exclusive end. The range is at most 400 days.
  - `GET /calendar/api/events/{ref}?recurrence_id=` returns the event detail. `ref` is an id, a v2 legacy id or a UID. The detail is `{id, calendar_id, uid, title, title_input, location, description, all_day, start, end, recurrence, recurrence_custom, is_recurring, field_values, reminder_minutes, author:{id,name}|null, conversation:{id,number,url}|null, editable}`.
  - `POST /calendar/api/events` with body `{calendar_id, title, all_day, start, end, location, description, recurrence, field_values, reminder_minutes}` returns 201 and the detail.
  - `PATCH /calendar/api/events/{id}` takes any subset of the POST body, plus `scope` (`series`|`occurrence`) and `recurrence_id`, and returns 200 and the detail.
    - With `scope=occurrence`, the body must not contain `field_values`, `reminder_minutes` or `recurrence`; those belong to the series (422).
    - `scope=occurrence` on a non-repeating event is 422.
    - An occurrence keeps its own title unless `title` is sent.
    - A series title change also updates occurrences that don't have their own title.
  - `DELETE /calendar/api/events/{id}?scope=&recurrence_id=` returns `{deleted:true}`.
  - `POST /calendar/api/conversations/{id}/events` takes the POST body and returns 201 and the detail. It adds a line item to the conversation.
  - Errors: 404 when the event, calendar or conversation is unknown or not visible to the user (no existence oracle); 403 only when it is visible but the right is missing or the event is read-only; 422 with `errors` for validation failures.

- [ ] **Step 1: Write the failing test `Tests/Feature/AgentApiTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class AgentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
    }

    private function calendarFor($user, array $rights, array $attributes = []): Calendar
    {
        $calendar = Calendar::create($attributes + ['name' => 'Api ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id] + $rights);

        return $calendar;
    }

    private function body(Calendar $calendar, array $overrides = []): array
    {
        return $overrides + ['calendar_id' => $calendar->id, 'title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00'];
    }

    public function test_calendar_list_only_shows_viewable_calendars_without_secrets(): void
    {
        $user = $this->makeUser();
        $visible = $this->calendarFor($user, ['can_view' => true]);
        $hidden = Calendar::create(['name' => 'Hidden ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);

        $response = $this->actingAs($user)->json('GET', '/calendar/api/calendars');

        $response->assertStatus(200);
        $ids = array_column(json_decode($response->getContent(), true), 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        foreach (['export_token', 'export_busy_token', 'permissions', 'sync_state', 'custom_fields'] as $secret) {
            $this->assertStringNotContainsString('"' . $secret . '"', $response->getContent());
        }
    }

    public function test_create_needs_the_create_right_and_returns_times_in_the_agents_timezone(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $readOnly = $this->calendarFor($user, ['can_view' => true]);
        $writable = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($readOnly))->assertStatus(403);

        $response = $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($writable));
        $response->assertStatus(201)->assertJsonFragment(['start' => '2026-10-20T10:00:00+02:00', 'title' => 'Call']);
        $this->assertSame($user->id, json_decode($response->getContent(), true)['author']['id']);
    }

    public function test_validation_errors_are_422(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, ['end' => '2026-10-20T09:00']))->assertStatus(422);
        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar, ['recurrence' => ['freq' => 'HOURLY']]))->assertStatus(422);
        $this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-01-01&end=2028-01-01')->assertStatus(422);
    }

    public function test_range_returns_occurrences_of_viewable_calendars_only(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $mine = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $other = $this->makeUser();
        $theirs = $this->calendarFor($other, ['can_view' => true, 'can_create' => true]);
        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($mine, ['recurrence' => ['freq' => 'DAILY', 'count' => 2]]))->assertStatus(201);
        $this->actingAs($other)->json('POST', '/calendar/api/events', $this->body($theirs))->assertStatus(201);

        $rows = json_decode($this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-10-19T00:00:00%2B02:00&end=2026-10-26T00:00:00%2B02:00')->getContent(), true);

        $this->assertSame([$mine->id, $mine->id], array_column($rows, 'calendar_id'));
        $this->assertSame('2026-10-21T10:00:00+02:00', $rows[1]['start']);
        $this->assertStringContainsString(':20261021T080000Z', $rows[1]['id']);
        $this->assertFalse($rows[0]['editable']); // the view and create rights don't include edit
    }

    public function test_update_and_delete_need_the_edit_right_on_the_events_own_calendar(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $id = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];

        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['title' => 'Changed'])->assertStatus(403);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $id)->assertStatus(403);

        CalendarPermission::where('calendar_id', $calendar->id)->update(['can_edit' => true]);
        $this->actingAs($user)->json('PATCH', '/calendar/api/events/' . $id, ['title' => 'Changed'])->assertStatus(200)->assertJsonFragment(['title' => 'Changed']);
        $this->actingAs($user)->json('DELETE', '/calendar/api/events/' . $id)->assertStatus(200);
        $this->assertNull(CalendarEvent::find($id));
    }

    public function test_events_in_read_only_providers_cannot_be_edited(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true, 'can_edit' => true], ['provider' => Calendar::PROVIDER_ICS]);

        $this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->assertStatus(403);
    }

    public function test_show_finds_by_id_legacy_id_and_uid_but_only_when_visible(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $id = json_decode($this->actingAs($user)->json('POST', '/calendar/api/events', $this->body($calendar))->getContent(), true)['id'];
        $event = CalendarEvent::find($id);
        $event->update(['legacy_id' => '291']);

        $this->actingAs($user)->json('GET', '/calendar/api/events/' . $id)->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $this->actingAs($user)->json('GET', '/calendar/api/events/291')->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $this->actingAs($user)->json('GET', '/calendar/api/events/' . rawurlencode($event->uid))->assertStatus(200);
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/events/' . $id)->assertStatus(404);
    }

    public function test_conversation_events_need_access_to_the_conversation(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $foreign = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox()->id, 'status' => Conversation::STATUS_ACTIVE]);
        $own = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);

        $this->actingAs($user)->json('POST', '/calendar/api/conversations/' . $foreign->id . '/events', $this->body($calendar))->assertStatus(403);

        $response = $this->actingAs($user)->json('POST', '/calendar/api/conversations/' . $own->id . '/events', $this->body($calendar));
        $response->assertStatus(201);
        $this->assertSame($own->id, json_decode($response->getContent(), true)['conversation']['id']);
        $this->assertTrue(Thread::where('conversation_id', $own->id)->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->exists());
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter AgentApiTest'`
Expected: FAILs with 404 responses (the routes don't exist yet).

- [ ] **Step 3: Write `Http/EventPresenter.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Conversation;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\EventIcs;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\RecurrenceRule;
use Sabre\VObject\Reader;

/** JSON shapes of the agent API. Timed values are ISO 8601 with offset in the agent's timezone; all-day values are dates. */
final class EventPresenter
{
    public static function calendar(Calendar $calendar, User $user): array
    {
        return [
            'id'                 => $calendar->id,
            'name'               => $calendar->name,
            'color'              => $calendar->color,
            'provider'           => $calendar->provider,
            'display_priority'   => $calendar->display_priority,
            'fields'             => $calendar->fieldDefinitions(),
            'has_title_template' => trim((string) $calendar->title_template) !== '',
            'rights'             => CalendarPolicy::rights($user, $calendar),
            'writable'           => $calendar->isWritable(),
            'last_error'         => $calendar->last_error,
        ];
    }

    public static function occurrence(array $row, CalendarEvent $event, bool $canEdit, string $timezone): array
    {
        return [
            'id'            => $row['recurrence_id'] ? $event->id . ':' . $row['recurrence_id'] : (string) $event->id,
            'event_id'      => $event->id,
            'calendar_id'   => $event->calendar_id,
            'recurrence_id' => $row['recurrence_id'],
            'title'         => $row['summary'] ?? $event->summary,
            'start'         => self::time($row['starts_at'], (bool) $row['all_day'], $timezone),
            'end'           => self::time($row['ends_at'], (bool) $row['all_day'], $timezone),
            'all_day'       => (bool) $row['all_day'],
            'status'        => $row['status'],
            'is_recurring'  => (bool) $event->is_recurring,
            'editable'      => $canEdit && !$event->read_only,
        ];
    }

    public static function event(CalendarEvent $event, User $user, string $timezone, ?string $recurrenceId = null): array
    {
        $tz = new DateTimeZone($event->timezone ?: $timezone);
        $vcal = Reader::read($event->ics);
        $master = EventIcs::master($vcal, $event->uid);
        $override = $recurrenceId ? EventIcs::findOverride($vcal, $event->uid, $recurrenceId, $tz) : null;
        $data = EventIcs::read($override ?? $master, $tz);
        $rrule = isset($master->RRULE) ? (string) $master->RRULE : null;
        $recurrence = RecurrenceRule::fromRrule($rrule, $timezone);

        $start = self::formValue($data['start'], $data['all_day'], $timezone);
        $end = self::formValue($data['end'], $data['all_day'], $timezone);
        if ($recurrenceId && !$override && ($row = app(OccurrenceService::class)->find($event, $recurrenceId))) {
            $start = self::time($row['starts_at'], (bool) $row['all_day'], $timezone);
            $end = self::time($row['ends_at'], (bool) $row['all_day'], $timezone);
        }

        $conversation = $event->conversation_id ? Conversation::find($event->conversation_id) : null;
        $author = $event->author_id ? User::find($event->author_id) : null;

        return [
            'id'                => $event->id,
            'calendar_id'       => $event->calendar_id,
            'uid'               => $event->uid,
            'title'             => $data['title'],
            'title_input'       => $event->title_input,
            'location'          => $data['location'],
            'description'       => $data['description'],
            'all_day'           => $data['all_day'],
            'start'             => $start,
            'end'               => $end,
            'recurrence'        => $recurrence,
            'recurrence_custom' => $rrule !== null && $recurrence === null,
            'is_recurring'      => (bool) $event->is_recurring,
            'field_values'      => (object) ($event->field_values ?? []),
            'reminder_minutes'  => $event->reminder_minutes,
            'author'            => $author ? ['id' => $author->id, 'name' => $author->getFullName()] : null,
            'conversation'      => $conversation && $user->can('view', $conversation)
                ? ['id' => $conversation->id, 'number' => $conversation->number, 'url' => $conversation->url()]
                : null,
            'editable'          => $user->can('edit', $event->calendar) && !$event->read_only,
        ];
    }

    private static function time(string $utc, bool $allDay, string $timezone): string
    {
        if ($allDay) {
            return substr($utc, 0, 10);
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
    }

    private static function formValue($value, bool $allDay, string $timezone): string
    {
        return $allDay ? (string) $value : $value->setTimezone(new DateTimeZone($timezone))->format(DATE_ATOM);
    }
}
```

- [ ] **Step 4: Write `Http/Controllers/Api/CalendarsController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;

class CalendarsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $calendars = Calendar::where('enabled', true)->with('permissionRows')->orderBy('display_priority')->orderBy('name')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return CalendarPolicy::rights($user, $calendar)['can_view'];
            })
            ->map(function (Calendar $calendar) use ($user) {
                return EventPresenter::calendar($calendar, $user);
            })
            ->values();

        return response()->json($calendars);
    }
}
```

- [ ] **Step 5: Write `Http/Controllers/Api/EventsController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\TimeInput;

class EventsController extends Controller
{
    private EventService $events;
    private OccurrenceService $occurrences;

    public function __construct(EventService $events, OccurrenceService $occurrences)
    {
        $this->events = $events;
        $this->occurrences = $occurrences;
    }

    public function index(Request $request)
    {
        $request->validate(['start' => 'required|string|max:40', 'end' => 'required|string|max:40']);
        $user = $request->user();
        $timezone = TimeInput::userTimezone($user);
        [$from, $to] = self::range((string) $request->input('start'), (string) $request->input('end'), $timezone);

        $calendars = Calendar::whereIn('id', CalendarPolicy::viewableCalendarIds($user))->with('permissionRows')->get()->keyBy('id');
        $rows = $this->occurrences->between($calendars->keys()->all(), $from, $to, $timezone);
        $events = CalendarEvent::whereIn('id', array_unique(array_column($rows, 'event_id')))->get()->keyBy('id');
        $canEdit = $calendars->map(function (Calendar $calendar) use ($user) {
            return $user->can('edit', $calendar);
        });

        $result = [];
        foreach ($rows as $row) {
            if (isset($events[$row['event_id']])) {
                $event = $events[$row['event_id']];
                $result[] = EventPresenter::occurrence($row, $event, (bool) $canEdit[$event->calendar_id], $timezone);
            }
        }

        return response()->json($result);
    }

    public function show(Request $request, $ref)
    {
        $user = $request->user();
        $event = self::findVisible((string) $ref, CalendarPolicy::viewableCalendarIds($user));
        abort_unless($event !== null, 404);

        return response()->json(EventPresenter::event($event, $user, TimeInput::userTimezone($user), $request->input('recurrence_id')));
    }

    public function store(Request $request)
    {
        $data = self::validateEvent($request, true);
        $user = $request->user();
        $calendar = Calendar::findOrFail($data['calendar_id']);
        abort_unless($user->can('create', $calendar), 403);

        $event = $this->events->create($calendar, $user, $data);

        return response()->json(EventPresenter::event($event->fresh(), $user, TimeInput::userTimezone($user)), 201);
    }

    public function update(Request $request, $id)
    {
        $data = self::validateEvent($request, false);
        $user = $request->user();
        $event = CalendarEvent::findOrFail($id);
        abort_unless($user->can('edit', $event->calendar) && !$event->read_only, 403);
        if (isset($data['calendar_id']) && (int) $data['calendar_id'] !== $event->calendar_id) {
            abort_unless($user->can('create', Calendar::findOrFail($data['calendar_id'])), 403);
        }

        $event = $this->events->update($event, $user, $data);

        return response()->json(EventPresenter::event($event, $user, TimeInput::userTimezone($user)));
    }

    public function destroy(Request $request, $id)
    {
        $request->validate(['scope' => 'nullable|in:series,occurrence', 'recurrence_id' => 'nullable|string|max:20']);
        $event = CalendarEvent::findOrFail($id);
        abort_unless($request->user()->can('edit', $event->calendar) && !$event->read_only, 403);

        $this->events->delete($event, (string) $request->input('scope', EventService::SCOPE_SERIES), $request->input('recurrence_id'));

        return response()->json(['deleted' => true]);
    }

    /** Shape validation only; EventService validates meaning (times, fields, recurrence). */
    public static function validateEvent(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'calendar_id'      => $required . '|integer',
            'title'            => 'nullable|string|max:500',
            'all_day'          => 'sometimes|boolean',
            'start'            => $required . '|string|max:40',
            'end'              => 'nullable|string|max:40',
            'location'         => 'nullable|string|max:500',
            'description'      => 'nullable|string|max:20000',
            'recurrence'       => 'nullable|array',
            'field_values'     => 'sometimes|array',
            'reminder_minutes' => 'nullable|integer|min:-1|max:40320',
            'scope'            => 'sometimes|in:series,occurrence',
            'recurrence_id'    => 'nullable|string|max:20',
        ]);
    }

    private static function range(string $start, string $end, string $timezone): array
    {
        try {
            $from = new DateTimeImmutable($start, new DateTimeZone($timezone));
            $to = new DateTimeImmutable($end, new DateTimeZone($timezone));
        } catch (Exception $e) {
            throw ValidationException::withMessages(['start' => __('Invalid date and time.')]);
        }
        if ($to <= $from || $from->diff($to)->days > 400) {
            throw ValidationException::withMessages(['end' => __('The range must be longer than zero and at most 400 days.')]);
        }

        return [$from, $to];
    }

    private static function findVisible(string $ref, array $calendarIds): ?CalendarEvent
    {
        if (!$calendarIds) {
            return null;
        }
        $visible = function () use ($calendarIds) {
            return CalendarEvent::whereIn('calendar_id', $calendarIds);
        };
        if (ctype_digit($ref) && ($event = $visible()->find((int) $ref))) {
            return $event;
        }

        return $visible()->where('legacy_id', $ref)->first()
            ?? $visible()->where('uid_hash', CalendarEvent::hashUid($ref))->first();
    }
}
```

- [ ] **Step 6: Write `Http/Controllers/Api/ConversationEventsController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Api;

use App\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\EventPresenter;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\TimeInput;

class ConversationEventsController extends Controller
{
    public function store(Request $request, EventService $events, $id)
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);
        abort_unless($user->can('view', $conversation), 403);

        $data = EventsController::validateEvent($request, true);
        $calendar = Calendar::findOrFail($data['calendar_id']);
        abort_unless($user->can('create', $calendar), 403);

        $event = $events->create($calendar, $user, $data, $conversation);

        return response()->json(EventPresenter::event($event->fresh(), $user, TimeInput::userTimezone($user)), 201);
    }
}
```

- [ ] **Step 7: Replace `Http/routes.php`**

```php
<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers'], function () {
    $agent = ['middleware' => ['auth', 'roles'], 'roles' => ['user', 'admin']];

    Route::get('/calendar/api/calendars', $agent + ['uses' => 'Api\CalendarsController@index'])->name('ljpccalendarmodule.api.calendars');
    Route::get('/calendar/api/events', $agent + ['uses' => 'Api\EventsController@index'])->name('ljpccalendarmodule.api.events');
    Route::post('/calendar/api/events', $agent + ['uses' => 'Api\EventsController@store'])->name('ljpccalendarmodule.api.events.store');
    Route::get('/calendar/api/events/{ref}', $agent + ['uses' => 'Api\EventsController@show'])->name('ljpccalendarmodule.api.events.show');
    Route::patch('/calendar/api/events/{id}', $agent + ['uses' => 'Api\EventsController@update'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.events.update');
    Route::delete('/calendar/api/events/{id}', $agent + ['uses' => 'Api\EventsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.events.destroy');
    Route::post('/calendar/api/conversations/{id}/events', $agent + ['uses' => 'Api\ConversationEventsController@store'])->where('id', '[0-9]+')->name('ljpccalendarmodule.api.conversations.events.store');
});
```

- [ ] **Step 8: Update the legacy test expectations**

In `Tests/Feature/LegacyEndpointsTest.php`, change both `assertStatus(404)` calls in `test_v2_event_write_endpoints_are_gone` to `assertStatus(405)`.

- [ ] **Step 9: Run all tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`, and the AgentApiTest count is 8. If `test_conversation_events_need_access_to_the_conversation` fails with 403 for the own conversation, check `ConversationPolicy::checkIsOnlyAssigned` in core. A user restricted to assigned conversations gets 403 by design; give the test user the default (unrestricted) mailbox access.

- [ ] **Step 10: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Http Tests/Feature/AgentApiTest.php Tests/Feature/LegacyEndpointsTest.php
git commit -m "feat: add agent calendar and event API with server-side authorization"
```

---
### Task 11: Secure ICS export with VTIMEZONE and the readable block

**Files:**
- Create: `$MOD/Services/VTimezone.php`
- Create: `$MOD/Services/ReadableBlock.php`
- Create: `$MOD/Services/ExportService.php`
- Create: `$MOD/Http/Controllers/ExportController.php`
- Modify: `$MOD/Http/routes.php` (a second group without the `web` middleware)
- Test: `$MOD/Tests/Feature/ExportTest.php`

**Interfaces:**
- Consumes: `CalendarEvent`, `Calendar::fieldDefinitions()`, `FieldValues::display`, `EventIcs::PRODID`.
- Produces:
  - `VTimezone::addTo(VCalendar $vcal, string $tzid, int $fromYear, int $toYear): void`
  - `ReadableBlock`, with `MARKER = '[FreeScout]'`, `strip(string $description): string`, `lines(CalendarEvent $event): array` and `append(string $description, CalendarEvent $event): string`. W3 and W4 reuse it.
  - `ExportService::render(Calendar $calendar, bool $busyOnly): string`
  - Route `ljpccalendarmodule.export`: `GET /calendar/{id}/export/{token}.ics`. The token is 40 hex characters, compared with `hash_equals`. The route is throttled at `throttle:60,1`. A wrong token or a disabled calendar returns 404.

- [ ] **Step 1: Write the failing test `Tests/Feature/ExportTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Services\ReadableBlock;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Sabre\VObject\Reader;

class ExportTest extends TestCase
{
    private Calendar $calendar;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create([
            'name'     => 'Export ' . uniqid(),
            'provider' => Calendar::PROVIDER_LOCAL,
            'fields'   => [['id' => 5, 'name' => 'Customer', 'type' => 'text', 'required' => false, 'options' => []]],
        ]);
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $this->event = app(EventService::class)->create($this->calendar, $user, [
            'title' => 'Call', 'start' => '2026-10-20T10:00', 'end' => '2026-10-20T11:00',
            'description' => 'Bring the contract', 'location' => 'Office', 'field_values' => ['5' => 'Acme'],
            'recurrence' => ['freq' => 'WEEKLY', 'count' => 4],
        ], $conversation);
    }

    private function url(string $token): string
    {
        return '/calendar/' . $this->calendar->id . '/export/' . $token . '.ics';
    }

    public function test_full_export_is_valid_icalendar_with_timezone_and_readable_block(): void
    {
        $response = $this->get($this->url($this->calendar->export_token));

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $ics = $response->getContent();
        $parsed = Reader::read($ics);
        $this->assertCount(1, $parsed->select('VEVENT'));
        $this->assertSame('Europe/Amsterdam', (string) $parsed->VTIMEZONE->TZID);
        $description = (string) $parsed->VEVENT->DESCRIPTION;
        $this->assertStringContainsString("Bring the contract\n\n[FreeScout]\nCustomer: Acme\nTicket #", $description);
        $this->assertSame('Call', (string) $parsed->VEVENT->SUMMARY);
    }

    public function test_busy_export_hides_everything_but_times(): void
    {
        $parsed = Reader::read($this->get($this->url($this->calendar->export_busy_token))->getContent());

        $this->assertSame('Busy', (string) $parsed->VEVENT->SUMMARY);
        $this->assertFalse(isset($parsed->VEVENT->DESCRIPTION));
        $this->assertFalse(isset($parsed->VEVENT->LOCATION));
        $this->assertTrue(isset($parsed->VEVENT->RRULE));
    }

    public function test_wrong_tokens_and_disabled_calendars_are_not_found(): void
    {
        $this->get($this->url(str_repeat('a', 40)))->assertStatus(404);
        $this->get('/calendar/' . $this->calendar->id . '/export/' . md5((string) $this->calendar->id) . '.ics')->assertStatus(404);

        $this->calendar->update(['enabled' => false]);
        $this->get($this->url($this->calendar->export_token))->assertStatus(404);
    }

    public function test_export_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('ljpccalendarmodule.export');

        $this->assertContains('throttle:60,1', $route->middleware());
        $this->assertNotContains('web', $route->middleware());
    }

    public function test_readable_block_is_replaced_not_duplicated(): void
    {
        $once = ReadableBlock::append('Notes', $this->event);
        $twice = ReadableBlock::append($once, $this->event);

        $this->assertSame($once, $twice);
        $this->assertSame('Notes', ReadableBlock::strip($twice));
        $this->assertSame("Keep [FreeScout] inline", ReadableBlock::strip("Keep [FreeScout] inline"));
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter ExportTest'`
Expected: FAILs or ERRORs (the export route and `ReadableBlock` don't exist yet).

- [ ] **Step 3: Write `Services/VTimezone.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/** Builds a VTIMEZONE with explicit transitions from PHP's tz database (RFC 5545 section 3.6.5). */
final class VTimezone
{
    public static function addTo(VCalendar $vcal, string $tzid, int $fromYear, int $toYear): void
    {
        $tz = new DateTimeZone($tzid);
        $begin = (new DateTimeImmutable($fromYear . '-01-01 00:00:00', $tz))->getTimestamp();
        $end = (new DateTimeImmutable(($toYear + 1) . '-01-01 00:00:00', $tz))->getTimestamp();
        $transitions = $tz->getTransitions($begin, $end);

        $component = $vcal->add('VTIMEZONE', ['TZID' => $tzid]);
        $previous = $transitions[0]['offset'];
        foreach ($transitions as $index => $transition) {
            $part = $component->add($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD');
            $local = (new DateTimeImmutable('@' . $transition['ts']))->modify(sprintf('%+d seconds', $previous));
            $part->add('DTSTART', $local->format('Ymd\THis'));
            $part->add('TZOFFSETFROM', self::offset($index === 0 ? $transition['offset'] : $previous));
            $part->add('TZOFFSETTO', self::offset($transition['offset']));
            $part->add('TZNAME', $transition['abbr']);
            $previous = $transition['offset'];
        }
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
```

- [ ] **Step 4: Write `Services/ReadableBlock.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Conversation;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;

/**
 * The "[FreeScout]" block at the end of a description, which makes custom fields and the ticket link visible
 * in every calendar client. FreeScout's database stays the source; the block is rewritten on every write.
 */
final class ReadableBlock
{
    public const MARKER = '[FreeScout]';

    public static function strip(string $description): string
    {
        $lines = preg_split("/\r\n|\n/", $description);
        foreach ($lines as $index => $line) {
            if (trim($line) === self::MARKER) {
                return rtrim(implode("\n", array_slice($lines, 0, $index)));
            }
        }

        return $description;
    }

    public static function lines(CalendarEvent $event): array
    {
        $fields = [];
        foreach ($event->calendar->fieldDefinitions() as $field) {
            $fields[(string) $field['id']] = $field;
        }
        $lines = [];
        foreach ((array) $event->field_values as $id => $value) {
            if (isset($fields[(string) $id])) {
                $lines[] = $fields[(string) $id]['name'] . ': ' . FieldValues::display($fields[(string) $id], $value);
            }
        }
        if ($event->conversation_id && ($conversation = Conversation::find($event->conversation_id))) {
            $lines[] = __('Ticket #:number: :url', ['number' => $conversation->number, 'url' => $conversation->url()]);
        }

        return $lines;
    }

    public static function append(string $description, CalendarEvent $event): string
    {
        $clean = rtrim(self::strip($description));
        $lines = self::lines($event);
        if (!$lines) {
            return $clean;
        }

        return ltrim($clean . "\n\n" . self::MARKER . "\n" . implode("\n", $lines));
    }
}
```

- [ ] **Step 5: Write `Services/ExportService.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/** Renders a calendar as ICS from the database, never by proxying an upstream feed. */
class ExportService
{
    private const BUSY_KEEP = ['UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'STATUS', 'SEQUENCE'];

    public function render(Calendar $calendar, bool $busyOnly): string
    {
        $vcal = new VCalendar(['PRODID' => EventIcs::PRODID]);
        $vcal->add('X-WR-CALNAME', $calendar->name);
        $this->addTimezones($vcal, $calendar);

        foreach ($calendar->events()->cursor() as $event) {
            /** @var CalendarEvent $event */
            $event->setRelation('calendar', $calendar);
            foreach (Reader::read($event->ics)->select('VEVENT') as $vevent) {
                if ((string) $vevent->UID !== $event->uid) {
                    continue;
                }
                $copy = clone $vevent;
                $busyOnly ? self::reduceToBusy($copy) : self::addReadableBlock($copy, $event);
                $vcal->add($copy);
            }
        }

        return $vcal->serialize();
    }

    private function addTimezones(VCalendar $vcal, Calendar $calendar): void
    {
        $firstYears = [];
        foreach ($calendar->events()->whereNotNull('timezone')->get(['timezone', 'starts_at']) as $row) {
            if ($row->timezone === 'UTC') {
                continue;
            }
            $year = (int) substr($row->starts_at, 0, 4);
            $firstYears[$row->timezone] = min($firstYears[$row->timezone] ?? $year, $year);
        }
        $lastYear = (int) gmdate('Y') + 2;
        foreach ($firstYears as $tzid => $firstYear) {
            VTimezone::addTo($vcal, $tzid, $firstYear, max($lastYear, $firstYear));
        }
    }

    private static function reduceToBusy(VEvent $vevent): void
    {
        foreach ($vevent->children() as $child) {
            if (!in_array($child->name, self::BUSY_KEEP, true)) {
                $vevent->remove($child);
            }
        }
        $vevent->SUMMARY = __('Busy');
        $vevent->TRANSP = 'OPAQUE';
    }

    private static function addReadableBlock(VEvent $vevent, CalendarEvent $event): void
    {
        $description = ReadableBlock::append((string) ($vevent->DESCRIPTION ?? ''), $event);
        unset($vevent->DESCRIPTION);
        if ($description !== '') {
            $vevent->DESCRIPTION = $description;
        }
    }
}
```

- [ ] **Step 6: Write `Http/Controllers/ExportController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\ExportService;

class ExportController extends Controller
{
    public function show(ExportService $export, $id, $token)
    {
        $calendar = Calendar::find($id);
        $token = (string) $token;
        $full = $calendar && $calendar->enabled && $calendar->export_token && hash_equals($calendar->export_token, $token);
        $busy = $calendar && $calendar->enabled && !$full && $calendar->export_busy_token && hash_equals($calendar->export_busy_token, $token);
        abort_unless($full || $busy, 404);

        return response($export->render($calendar, $busy), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendar-' . $calendar->id . '.ics"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }
}
```

- [ ] **Step 7: Add the export route group to `Http/routes.php`**

Append below the existing group. The export has no session (calendar clients send no cookies), so it doesn't use `web`.

```php
Route::group(['middleware' => ['throttle:60,1'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\LJPcCalendarModule\Http\Controllers'], function () {
    Route::get('/calendar/{id}/export/{token}.ics', 'ExportController@show')
        ->where(['id' => '[0-9]+', 'token' => '[a-f0-9]{40}'])
        ->name('ljpccalendarmodule.export');
});
```

- [ ] **Step 8: Run all tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`, and the ExportTest count is 5.

- [ ] **Step 9: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/VTimezone.php Services/ReadableBlock.php Services/ExportService.php Http/Controllers/ExportController.php Http/routes.php Tests/Feature/ExportTest.php
git commit -m "feat: add token export with VTIMEZONE, busy mode and readable block"
```

---

### Task 12: Admin API (calendars, fields, permissions, tokens, subjects)

**Files:**
- Create: `$MOD/Http/Controllers/Admin/CalendarsController.php`
- Modify: `$MOD/Http/routes.php`
- Test: `$MOD/Tests/Feature/AdminApiTest.php`

**Interfaces:**
- Consumes: `Calendar`, `CalendarPermission`, `FieldValues::TYPES`, `TitleTemplate::fieldIds`, the route `ljpccalendarmodule.export`.
- Produces the admin HTTP contract for W2. All routes need the `admin` role.
  - `GET /calendar/api/admin/calendars` (route `ljpccalendarmodule.admin.calendars`) returns a list of admin calendar objects, sorted by priority and then name.
  - An admin calendar object is `{id, name, color, enabled, provider, display_priority, timezone, title_template, fields, default_reminder_minutes, remote_id, permissions:[{subject_type, subject_id, show_in_dashboard, can_view, can_create, can_edit}], export:{full, busy}, sync:{last_synced_at, next_sync_at, last_error}, connection:{id, provider, account, status, last_error}|null}`. It never contains credentials.
  - `POST /calendar/api/admin/calendars` (W1 only accepts `provider: local`) returns 201 and the object.
  - `PUT /calendar/api/admin/calendars/{id}` returns 200 and the object. A sent `permissions` array replaces all rows; a sent `fields` array replaces the definitions. Existing ids are kept, and new fields get an id ≥ 1000.
  - `DELETE /calendar/api/admin/calendars/{id}` returns `{deleted:true}` and cascades to the events.
  - `POST /calendar/api/admin/calendars/{id}/tokens` with body `{which: full|busy}` returns the object with a new URL.
  - `GET /calendar/api/admin/subjects` (route `ljpccalendarmodule.admin.subjects`) returns `{users:[{id,name}], teams:[{id,name}], mailboxes:[{id,name}]}`. `teams` is empty when the Teams module is inactive.

- [ ] **Step 1: Write the failing test `Tests/Feature/AdminApiTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Tests\TestCase;

class AdminApiTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name'   => 'Team agenda ' . uniqid(),
            'color'  => '#3498db',
            'fields' => [['name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']]],
        ];
    }

    public function test_agents_cannot_use_the_admin_api(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->json('GET', '/calendar/api/admin/calendars')->assertStatus(403);
        $this->actingAs($user)->json('POST', '/calendar/api/admin/calendars', $this->payload())->assertStatus(403);
    }

    public function test_creates_a_local_calendar_with_fields_permissions_and_template(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeUser();
        $response = $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload([
            'name'        => '<b>Sales</b> ' . uniqid(),
            'permissions' => [['subject_type' => 'user', 'subject_id' => $agent->id, 'can_view' => true, 'can_create' => true]],
        ]));

        $response->assertStatus(201);
        $body = json_decode($response->getContent(), true);
        $this->assertStringStartsWith('Sales ', $body['name']);
        $this->assertSame('local', $body['provider']);
        $this->assertGreaterThanOrEqual(1000, $body['fields'][0]['id']);
        $this->assertSame([['subject_type' => 'user', 'subject_id' => $agent->id, 'show_in_dashboard' => false, 'can_view' => true, 'can_create' => true, 'can_edit' => false]], $body['permissions']);
        $this->assertStringContainsString('/export/', $body['export']['full']);

        $fieldId = $body['fields'][0]['id'];
        $this->actingAs($admin)->json('PUT', '/calendar/api/admin/calendars/' . $body['id'], ['title_template' => '{{title}} ({{field:' . $fieldId . '}})'])->assertStatus(200);
        $this->actingAs($admin)->json('PUT', '/calendar/api/admin/calendars/' . $body['id'], ['title_template' => '{{field:999999}}'])->assertStatus(422);
    }

    public function test_validation(): void
    {
        $admin = $this->makeAdmin();
        $existing = Calendar::create(['name' => 'Taken ' . uniqid(), 'provider' => 'local']);

        $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload(['name' => $existing->name]))->assertStatus(422);
        $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload(['color' => 'red; background:url(x)']))->assertStatus(422);
        $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload(['provider' => 'caldav']))->assertStatus(422);
        $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload(['timezone' => 'Mars/Olympus']))->assertStatus(422);
        $this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars', $this->payload(['permissions' => [['subject_type' => 'user', 'subject_id' => 99999999, 'can_view' => true]]]))->assertStatus(422);
    }

    public function test_update_replaces_permissions_and_keeps_field_ids(): void
    {
        $admin = $this->makeAdmin();
        $mailbox = $this->makeMailbox();
        $calendar = Calendar::create(['name' => 'Upd ' . uniqid(), 'provider' => 'local', 'fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]]]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $admin->id, 'can_view' => true]);

        $body = json_decode($this->actingAs($admin)->json('PUT', '/calendar/api/admin/calendars/' . $calendar->id, [
            'fields'      => [['id' => 172604180330, 'name' => 'Type', 'type' => 'text'], ['name' => 'Extra', 'type' => 'boolean']],
            'permissions' => [['subject_type' => 'mailbox', 'subject_id' => $mailbox->id, 'show_in_dashboard' => true, 'can_view' => true]],
        ])->getContent(), true);

        $this->assertSame(172604180330, $body['fields'][0]['id']);
        $this->assertSame('Type', $body['fields'][0]['name']);
        $this->assertCount(2, $body['fields']);
        $this->assertSame(['mailbox'], array_column($body['permissions'], 'subject_type'));
    }

    public function test_index_never_returns_credentials(): void
    {
        $admin = $this->makeAdmin();
        $connection = new CalendarConnection(['provider' => 'caldav', 'account' => 'lars']);
        $connection->setCredentials(['password' => 'hunter2']);
        $connection->save();
        Calendar::create(['name' => 'Remote ' . uniqid(), 'provider' => 'caldav', 'connection_id' => $connection->id]);

        $content = $this->actingAs($admin)->json('GET', '/calendar/api/admin/calendars')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('"credentials"', $content);
        $this->assertStringContainsString('"account":"lars"', $content);
    }

    public function test_regenerating_a_token_invalidates_the_old_url(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Tok ' . uniqid(), 'provider' => 'local']);
        $old = '/calendar/' . $calendar->id . '/export/' . $calendar->export_token . '.ics';
        $this->get($old)->assertStatus(200);

        $body = json_decode($this->actingAs($admin)->json('POST', '/calendar/api/admin/calendars/' . $calendar->id . '/tokens', ['which' => 'full'])->getContent(), true);

        $this->get($old)->assertStatus(404);
        $this->get(parse_url($body['export']['full'], PHP_URL_PATH))->assertStatus(200);
    }

    public function test_delete_and_subjects(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Del ' . uniqid(), 'provider' => 'local']);
        $this->actingAs($admin)->json('DELETE', '/calendar/api/admin/calendars/' . $calendar->id)->assertStatus(200);
        $this->assertNull(Calendar::find($calendar->id));

        $subjects = json_decode($this->actingAs($admin)->json('GET', '/calendar/api/admin/subjects')->getContent(), true);
        $this->assertContains($admin->id, array_column($subjects['users'], 'id'));
        $this->assertArrayHasKey('mailboxes', $subjects);
        $this->assertArrayHasKey('teams', $subjects);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter AdminApiTest'`
Expected: FAILs with 404 (the routes don't exist yet).

- [ ] **Step 3: Write `Http/Controllers/Admin/CalendarsController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mailbox;
use App\User;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\FieldValues;
use Modules\LJPcCalendarModule\Services\TitleTemplate;

class CalendarsController extends Controller
{
    public function index()
    {
        $calendars = Calendar::with(['permissionRows', 'calendarConnection'])->orderBy('display_priority')->orderBy('name')->get();

        return response()->json($calendars->map(function (Calendar $calendar) {
            return self::present($calendar);
        })->values());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $calendar = DB::transaction(function () use ($data) {
            $calendar = Calendar::create(array_merge(['provider' => Calendar::PROVIDER_LOCAL, 'color' => '#3498db'], array_diff_key($data, ['permissions' => 1])));
            self::syncPermissions($calendar, $data['permissions'] ?? []);

            return $calendar;
        });

        return response()->json(self::present($calendar->fresh(['permissionRows', 'calendarConnection'])), 201);
    }

    public function update(Request $request, $id)
    {
        $calendar = Calendar::findOrFail($id);
        $data = $this->validated($request, $calendar);
        DB::transaction(function () use ($calendar, $data) {
            $calendar->update(array_diff_key($data, ['permissions' => 1]));
            if (array_key_exists('permissions', $data)) {
                self::syncPermissions($calendar, $data['permissions']);
            }
        });

        return response()->json(self::present($calendar->fresh(['permissionRows', 'calendarConnection'])));
    }

    public function destroy($id)
    {
        Calendar::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function regenerateToken(Request $request, $id)
    {
        $request->validate(['which' => 'required|in:full,busy']);
        $calendar = Calendar::findOrFail($id);
        $column = $request->input('which') === 'full' ? 'export_token' : 'export_busy_token';
        $calendar->update([$column => Calendar::newToken()]);

        return response()->json(self::present($calendar->fresh(['permissionRows', 'calendarConnection'])));
    }

    public function subjects()
    {
        $users = User::where('type', User::TYPE_USER)->where('status', '!=', User::STATUS_DELETED)->orderBy('first_name')->get()
            ->map(function (User $user) {
                return ['id' => $user->id, 'name' => $user->getFullName()];
            })->values();
        $teams = class_exists('Team')
            ? User::where('type', User::TYPE_ROBOT)->where('email', 'like', 'fsteam%')->orderBy('first_name')->get()
                ->map(function (User $team) {
                    return ['id' => $team->id, 'name' => $team->first_name];
                })->values()
            : [];
        $mailboxes = Mailbox::orderBy('name')->get(['id', 'name'])->map(function (Mailbox $mailbox) {
            return ['id' => $mailbox->id, 'name' => $mailbox->name];
        })->values();

        return response()->json(['users' => $users, 'teams' => $teams, 'mailboxes' => $mailboxes]);
    }

    public static function present(Calendar $calendar): array
    {
        $connection = $calendar->calendarConnection;

        return [
            'id'                       => $calendar->id,
            'name'                     => $calendar->name,
            'color'                    => $calendar->color,
            'enabled'                  => (bool) $calendar->enabled,
            'provider'                 => $calendar->provider,
            'display_priority'         => $calendar->display_priority,
            'timezone'                 => $calendar->timezone,
            'title_template'           => $calendar->title_template,
            'fields'                   => $calendar->fieldDefinitions(),
            'default_reminder_minutes' => $calendar->default_reminder_minutes,
            'remote_id'                => $calendar->remote_id,
            'permissions'              => $calendar->permissionRows->map(function (CalendarPermission $row) {
                return ['subject_type' => $row->subject_type, 'subject_id' => $row->subject_id] + $row->only(CalendarPermission::RIGHTS);
            })->values()->all(),
            'export'                   => [
                'full' => route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $calendar->export_token]),
                'busy' => route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $calendar->export_busy_token]),
            ],
            'sync'                     => ['last_synced_at' => $calendar->last_synced_at, 'next_sync_at' => $calendar->next_sync_at, 'last_error' => $calendar->last_error],
            'connection'               => $connection
                ? ['id' => $connection->id, 'provider' => $connection->provider, 'account' => $connection->account, 'status' => $connection->status, 'last_error' => $connection->last_error]
                : null,
        ];
    }

    private function validated(Request $request, ?Calendar $calendar): array
    {
        $subjectTypes = [CalendarPermission::SUBJECT_USER, CalendarPermission::SUBJECT_TEAM, CalendarPermission::SUBJECT_MAILBOX];
        $rules = [
            'name'                           => [$calendar ? 'sometimes' : 'required', 'string', 'max:191', Rule::unique('calendars', 'name')->ignore($calendar ? $calendar->id : null)],
            'provider'                       => ['sometimes', Rule::in([Calendar::PROVIDER_LOCAL])],
            'color'                          => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'enabled'                        => 'sometimes|boolean',
            'timezone'                       => ['sometimes', 'nullable', Rule::in(DateTimeZone::listIdentifiers())],
            'display_priority'               => 'sometimes|integer|min:0|max:9999',
            'default_reminder_minutes'       => 'sometimes|nullable|integer|min:0|max:40320',
            'title_template'                 => 'sometimes|nullable|string|max:191',
            'fields'                         => 'sometimes|array|max:50',
            'fields.*.name'                  => 'required_with:fields|string|max:100',
            'fields.*.type'                  => ['required_with:fields', Rule::in(FieldValues::TYPES)],
            'fields.*.required'              => 'sometimes|boolean',
            'fields.*.options'               => 'sometimes|array|max:100',
            'fields.*.options.*'             => 'string|max:100',
            'permissions'                    => 'sometimes|array',
            'permissions.*.subject_type'     => ['required_with:permissions', Rule::in($subjectTypes)],
            'permissions.*.subject_id'       => 'required_with:permissions|integer',
            'permissions.*.show_in_dashboard' => 'sometimes|boolean',
            'permissions.*.can_view'         => 'sometimes|boolean',
            'permissions.*.can_create'       => 'sometimes|boolean',
            'permissions.*.can_edit'         => 'sometimes|boolean',
        ];
        $data = $request->validate($rules);
        if ($request->has('fields')) {
            $data['fields'] = self::normalizeFields((array) $request->input('fields'));
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = trim(strip_tags($data['name']));
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => __('The name is required.')]);
            }
        }
        $template = array_key_exists('title_template', $data) ? (string) $data['title_template'] : (string) ($calendar->title_template ?? '');
        $fieldIds = array_map('strval', array_column($data['fields'] ?? ($calendar ? $calendar->fieldDefinitions() : []), 'id'));
        if (array_diff(TitleTemplate::fieldIds($template), $fieldIds)) {
            throw ValidationException::withMessages(['title_template' => __('The title template uses a field that does not exist.')]);
        }
        if (isset($data['permissions'])) {
            self::assertSubjectsExist($data['permissions']);
        }

        return $data;
    }

    private static function normalizeFields(array $fields): array
    {
        $result = [];
        $used = [];
        foreach ($fields as $field) {
            $id = (string) ($field['id'] ?? '');
            $usable = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) && !(ctype_digit($id) && (int) $id < 1000) && !isset($used[$id]);
            $id = $usable ? $id : (string) ((int) (microtime(true) * 1000) + random_int(0, 999) + count($result));
            $used[$id] = true;
            $result[] = [
                'id'       => ctype_digit($id) ? (int) $id : $id,
                'name'     => trim(strip_tags((string) $field['name'])),
                'type'     => $field['type'],
                'required' => (bool) ($field['required'] ?? false),
                'options'  => array_values(array_unique(array_filter(array_map('trim', (array) ($field['options'] ?? [])), 'strlen'))),
            ];
        }

        return $result;
    }

    private static function assertSubjectsExist(array $rows): void
    {
        foreach ($rows as $index => $row) {
            $exists = $row['subject_type'] === CalendarPermission::SUBJECT_MAILBOX
                ? Mailbox::where('id', $row['subject_id'])->exists()
                : User::where('id', $row['subject_id'])->exists();
            if (!$exists) {
                throw ValidationException::withMessages(['permissions.' . $index . '.subject_id' => __('This user, team or mailbox does not exist.')]);
            }
        }
    }

    private static function syncPermissions(Calendar $calendar, array $rows): void
    {
        CalendarPermission::where('calendar_id', $calendar->id)->delete();
        foreach ($rows as $row) {
            $rights = [];
            foreach (CalendarPermission::RIGHTS as $right) {
                $rights[$right] = (bool) ($row[$right] ?? false);
            }
            if (!array_filter($rights)) {
                continue;
            }
            CalendarPermission::updateOrCreate(
                ['calendar_id' => $calendar->id, 'subject_type' => $row['subject_type'], 'subject_id' => (int) $row['subject_id']],
                $rights
            );
        }
    }
}
```

- [ ] **Step 4: Add the admin routes**

In the first group of `Http/routes.php`, directly after the `$agent = ...` line, add:

```php
    $admin = ['middleware' => ['auth', 'roles'], 'roles' => ['admin']];

    Route::get('/calendar/api/admin/calendars', $admin + ['uses' => 'Admin\CalendarsController@index'])->name('ljpccalendarmodule.admin.calendars');
    Route::post('/calendar/api/admin/calendars', $admin + ['uses' => 'Admin\CalendarsController@store'])->name('ljpccalendarmodule.admin.calendars.store');
    Route::put('/calendar/api/admin/calendars/{id}', $admin + ['uses' => 'Admin\CalendarsController@update'])->where('id', '[0-9]+')->name('ljpccalendarmodule.admin.calendars.update');
    Route::delete('/calendar/api/admin/calendars/{id}', $admin + ['uses' => 'Admin\CalendarsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.admin.calendars.destroy');
    Route::post('/calendar/api/admin/calendars/{id}/tokens', $admin + ['uses' => 'Admin\CalendarsController@regenerateToken'])->where('id', '[0-9]+')->name('ljpccalendarmodule.admin.calendars.tokens');
    Route::get('/calendar/api/admin/subjects', $admin + ['uses' => 'Admin\CalendarsController@subjects'])->name('ljpccalendarmodule.admin.subjects');
```

- [ ] **Step 5: Run all tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`, and the AdminApiTest count is 7.

- [ ] **Step 6: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Http/Controllers/Admin/CalendarsController.php Http/routes.php Tests/Feature/AdminApiTest.php
git commit -m "feat: add admin calendar API with permission rows and token rotation"
```

---

### Task 13: Page shells, menu, settings section, dashboard card and line items

**Files:**
- Create: `$MOD/Http/FrontendConfig.php`
- Create: `$MOD/Http/Controllers/CalendarPageController.php`
- Create: `$MOD/Services/LineItemText.php`
- Create: `$MOD/Services/DashboardCard.php`
- Modify: `$MOD/Resources/views/index.blade.php` (full rewrite)
- Modify: `$MOD/Resources/views/settings.blade.php` (full rewrite)
- Modify: `$MOD/Http/routes.php` (the page route)
- Modify: `$MOD/Providers/LJPcCalendarModuleServiceProvider.php` (`hooks()`)
- Test: `$MOD/Tests/Feature/IntegrationHooksTest.php`

**Interfaces:**
- Consumes: `OccurrenceService::between`, `CalendarPolicy::rights`, `TimeInput::userTimezone`, `CalendarEvent::findByUid`.
- Produces:
  - `FrontendConfig::page(User $user): array` with keys `locale`, `timezone`, `csrf`, `user:{id,is_admin}`, `routes:{calendars, events, event, conversation_events}` and `migration_complete`. The `event` and `conversation_events` routes contain the placeholder `__ID__`.
  - `FrontendConfig::settings(): array` with keys `csrf` and `routes:{calendars, subjects}`.
  - `FrontendConfig::json(array $config): string`, HTML-safe JSON.
  - The view `calendar::index`, holding `#ljpc-calendar-app` and `<script type="application/json" id="ljpc-calendar-config">`.
  - The view `calendar::settings`, holding `#ljpc-calendar-settings-app` and `#ljpc-calendar-settings-config`. W2 mounts its bundles on these.
  - `LineItemText::render(\App\Thread $thread, bool $escape): string`. It keeps the `:person` placeholder for FreeScout core.
  - `DashboardCard::render(User $user): string`

- [ ] **Step 1: Write the failing test `Tests/Feature/IntegrationHooksTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\FrontendConfig;
use Modules\LJPcCalendarModule\Services\DashboardCard;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\LineItemText;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class IntegrationHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function lineItem(Conversation $conversation, array $meta): Thread
    {
        return Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'created_by_user_id' => auth()->id(),
            'action_type' => CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR,
            'source_via'  => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
            'meta'        => $meta,
        ]);
    }

    public function test_line_items_render_for_v3_and_v2_meta_and_escape_the_calendar_name(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $calendar = Calendar::create(['name' => '<img src=x onerror=alert(1)> ' . uniqid(), 'provider' => 'local']);
        $event = app(EventService::class)->create($calendar, $user, ['title' => 'x', 'start' => '2026-10-20T10:00'], $conversation);
        $event->update(['legacy_id' => '291']);

        $v3 = LineItemText::render(Thread::where('conversation_id', $conversation->id)->where('action_type', 186)->first(), true);
        $this->assertStringContainsString(':person', $v3); // FreeScout core fills in the person
        $this->assertStringContainsString('#' . $event->id . '"', $v3);
        $this->assertStringContainsString('&lt;img', $v3);
        $this->assertStringNotContainsString('<img', $v3);

        $v2 = LineItemText::render($this->lineItem($conversation, ['calendar_item_id' => 291, 'calendar_id' => (string) $calendar->id]), true);
        $this->assertStringContainsString('#' . $event->id . '"', $v2);

        $gone = LineItemText::render($this->lineItem($conversation, ['calendar_item_id' => 1, 'calendar_id' => (string) $calendar->id]), true);
        $this->assertStringContainsString('deleted by now', $gone);

        $plain = LineItemText::render($this->lineItem($conversation, ['event_id' => $event->id, 'calendar_id' => $calendar->id]), false);
        $this->assertStringNotContainsString('<a ', $plain);
    }

    public function test_dashboard_card_lists_upcoming_events_from_dashboard_calendars_only(): void
    {
        $user = $this->makeUser();
        $shown = Calendar::create(['name' => 'Dash ' . uniqid(), 'provider' => 'local']);
        $hidden = Calendar::create(['name' => 'NoDash ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $shown->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        CalendarPermission::create(['calendar_id' => $hidden->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'can_view' => true]);
        $soon = (new DateTimeImmutable('+2 hours', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d\TH:i');
        app(EventService::class)->create($shown, $user, ['title' => 'Visible soon', 'start' => $soon]);
        app(EventService::class)->create($hidden, $user, ['title' => 'Not on dashboard', 'start' => $soon]);

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString('Visible soon', $html);
        $this->assertStringNotContainsString('Not on dashboard', $html);
        $this->assertSame('', app(DashboardCard::class)->render($this->makeUser()));
    }

    public function test_calendar_page_is_a_shell_with_html_safe_json_config(): void
    {
        $user = $this->makeUser(['timezone' => 'Europe/Amsterdam']);

        $response = $this->actingAs($user)->get('/calendar');

        $response->assertStatus(200);
        $this->assertStringContainsString('id="ljpc-calendar-config"', $response->getContent());
        $this->assertStringContainsString('"timezone":"Europe\/Amsterdam"', $response->getContent());
        $this->assertStringNotContainsString('</script><script>', FrontendConfig::json(['x' => '</script><script>alert(1)</script>']));
    }

    public function test_settings_view_is_a_shell_with_admin_routes(): void
    {
        $this->actingAs($this->makeAdmin());

        $html = view('calendar::settings')->render();

        $this->assertStringContainsString('id="ljpc-calendar-settings-config"', $html);
        $this->assertStringContainsString('calendar\/api\/admin\/calendars', $html);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter IntegrationHooksTest'`
Expected: ERRORs (the classes don't exist and `/calendar` returns 404).

- [ ] **Step 3: Write `Http/FrontendConfig.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

use App\User;
use Modules\LJPcCalendarModule\Services\TimeInput;

/** Server data for the W2 bundles, passed as JSON in a non-executed script tag (never Blade inside JavaScript). */
final class FrontendConfig
{
    public static function page(User $user): array
    {
        return [
            'locale'             => \Helper::getRealAppLocale(),
            'timezone'           => TimeInput::userTimezone($user),
            'csrf'               => csrf_token(),
            'user'               => ['id' => $user->id, 'is_admin' => $user->isAdmin()],
            'routes'             => [
                'calendars'           => route('ljpccalendarmodule.api.calendars'),
                'events'              => route('ljpccalendarmodule.api.events'),
                'event'               => route('ljpccalendarmodule.api.events.show', ['ref' => '__ID__']),
                'conversation_events' => route('ljpccalendarmodule.api.conversations.events.store', ['id' => '__ID__']),
            ],
            'migration_complete' => true,
        ];
    }

    public static function settings(): array
    {
        return [
            'csrf'   => csrf_token(),
            'routes' => [
                'calendars' => route('ljpccalendarmodule.admin.calendars'),
                'subjects'  => route('ljpccalendarmodule.admin.subjects'),
            ],
        ];
    }

    public static function json(array $config): string
    {
        return json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
```

`route(... ['id' => '__ID__'])` fails the `[0-9]+` constraint when the URL is generated. Laravel 5.5 doesn't validate `where` constraints during URL generation, so this works. If it ever throws, generate the URL with `id => 0` and replace `/0/` on the client.

- [ ] **Step 4: Write `Http/Controllers/CalendarPageController.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Http\FrontendConfig;

class CalendarPageController extends Controller
{
    public function index(Request $request)
    {
        return view('calendar::index', ['config' => FrontendConfig::page($request->user())]);
    }
}
```

- [ ] **Step 5: Rewrite `Resources/views/index.blade.php`**

```blade
@extends('layouts.app')

@section('title', __('Calendar'))
@section('content_class', 'content-full')

@section('content')
    <div id="ljpc-calendar-app" class="ljpc-calendar"></div>
    <script type="application/json" id="ljpc-calendar-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json($config) !!}</script>
@endsection
```

- [ ] **Step 6: Rewrite `Resources/views/settings.blade.php`**

```blade
<div id="ljpc-calendar-settings-app" class="ljpc-calendar"></div>
<script type="application/json" id="ljpc-calendar-settings-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json(\Modules\LJPcCalendarModule\Http\FrontendConfig::settings()) !!}</script>
```

- [ ] **Step 7: Write `Services/LineItemText.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Thread;
use DateTimeImmutable;
use DateTimeZone;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;

/**
 * Text for "added to calendar" line items. Handles v3 meta {event_id} and v2 meta {calendar_item_id, event_uid}.
 * Leaves ":person" in place; FreeScout core substitutes it.
 */
final class LineItemText
{
    public static function render(Thread $thread, bool $escape): string
    {
        $meta = (array) $thread->getMetas();
        $calendar = Calendar::find((int) ($meta['calendar_id'] ?? 0));
        if (!$calendar) {
            return __('Added to a calendar, but the calendar has been deleted by now.');
        }
        $name = $escape ? htmlspecialchars($calendar->name) : $calendar->name;
        $event = self::event($calendar, $meta);
        if (!$event) {
            return __('Added to calendar ":calendar", but the event has been deleted by now.', ['calendar' => $name]);
        }

        $timezone = new DateTimeZone(TimeInput::userTimezone(auth()->user()));
        $start = $event->all_day
            ? (new DateTimeImmutable(substr($event->starts_at, 0, 10), $timezone))->format(__('d-m-Y'))
            : (new DateTimeImmutable($event->starts_at, new DateTimeZone('UTC')))->setTimezone($timezone)->format(__('d-m-Y H:i'));
        $text = __('Added to calendar ":calendar" by :person. Starts at :start.', ['calendar' => $name, 'start' => $start]);
        if (!$escape) {
            return $text;
        }
        $link = route('ljpccalendarmodule.index') . '#' . $event->id;

        return $text . ' <a href="' . htmlspecialchars($link) . '" target="_blank" class="link-blue">' . htmlspecialchars(__('View event')) . '</a>';
    }

    private static function event(Calendar $calendar, array $meta): ?CalendarEvent
    {
        if (isset($meta['event_id'])) {
            return CalendarEvent::where('calendar_id', $calendar->id)->find((int) $meta['event_id']);
        }
        foreach (['calendar_item_id', 'event_uid'] as $key) {
            if (!empty($meta[$key])) {
                $event = CalendarEvent::where('calendar_id', $calendar->id)->where('legacy_id', (string) $meta[$key])->first();
                if ($event) {
                    return $event;
                }
            }
        }

        return !empty($meta['event_uid']) ? CalendarEvent::findByUid($calendar->id, (string) $meta['event_uid']) : null;
    }
}
```

- [ ] **Step 8: Write `Services/DashboardCard.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\User;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\View;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;

/** The next three occurrences within seven days, read from the database only (no remote calls). */
class DashboardCard
{
    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    public function render(User $user): string
    {
        $calendars = Calendar::where('enabled', true)->with('permissionRows')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return CalendarPolicy::rights($user, $calendar)['show_in_dashboard'];
            })
            ->keyBy('id');
        if ($calendars->isEmpty()) {
            return '';
        }

        $timezone = TimeInput::userTimezone($user);
        $tz = new DateTimeZone($timezone);
        $now = new DateTimeImmutable('now', $tz);
        $rows = $this->occurrences->between($calendars->keys()->all(), $now, $now->modify('+7 days'), $timezone);
        usort($rows, function (array $a, array $b) {
            return strcmp($a['starts_at'], $b['starts_at']);
        });
        $rows = array_slice($rows, 0, 3);
        if (!$rows) {
            return '';
        }

        $events = CalendarEvent::whereIn('id', array_column($rows, 'event_id'))->get()->keyBy('id');
        $items = [];
        foreach ($rows as $row) {
            if (!isset($events[$row['event_id']])) {
                continue;
            }
            $start = $row['all_day']
                ? new DateTimeImmutable(substr($row['starts_at'], 0, 10), $tz)
                : (new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            $calendar = $calendars[$row['calendar_id']];
            $items[] = [
                'title'    => $row['summary'] ?? $events[$row['event_id']]->summary,
                'start'    => [
                    'time' => $row['all_day'] ? __('All day') : $start->format('H:i'),
                    'diff' => Carbon::createFromTimestamp($start->getTimestamp(), $timezone)->diffForHumans(),
                    'full' => $start->format(__('Y-m-d H:i')),
                ],
                'calendar' => ['name' => $calendar->name, 'id' => $calendar->id, 'color' => $calendar->color],
            ];
        }

        return View::make('calendar::partials/dash_card', ['events' => $items])->render();
    }
}
```

- [ ] **Step 9: Add the page route**

In the first group of `Http/routes.php`, after the `$admin = ...` line, add:

```php
    Route::get('/calendar', $agent + ['uses' => 'CalendarPageController@index'])->name('ljpccalendarmodule.index');
```

- [ ] **Step 10: Register the hooks in the provider**

Add `use Illuminate\Support\Facades\View;`, `use Modules\LJPcCalendarModule\Entities\CalendarEvent;`, `use Modules\LJPcCalendarModule\Services\DashboardCard;` and `use Modules\LJPcCalendarModule\Services\LineItemText;`. Then replace `hooks()` with:

```php
    public function hooks()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('calendar:refresh-occurrences')->dailyAt('03:17')->withoutOverlapping();

            return $schedule;
        });

        \Eventy::addAction('menu.append', function () {
            echo View::make('calendar::partials/menu')->render();
        });
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['calendar'] = ['ljpccalendarmodule.index'];

            return $menu;
        });

        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['calendar'] = ['title' => __('Calendar'), 'icon' => 'calendar', 'order' => 200];

            return $sections;
        }, 15);
        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section === 'calendar' ? 'calendar::settings' : $view;
        }, 20, 2);

        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user) {
                return $html;
            }
            try {
                return $html . app(DashboardCard::class)->render($user);
            } catch (\Throwable $e) {
                report($e); // logged with trace; the FreeScout dashboard must keep working

                return $html;
            }
        });

        \Eventy::addFilter('thread.action_text', function ($didThis, $thread, $conversationNumber, $escape) {
            if ((int) $thread->action_type !== CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR) {
                return $didThis;
            }

            return LineItemText::render($thread, (bool) $escape);
        }, 20, 4);
    }
```

- [ ] **Step 11: Run all tests and check the dev UI by hand**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`, and the IntegrationHooksTest count is 4.

Then run `ddev exec php artisan freescout:clear-cache` and open https://freescout.ddev.site in Playwright (`playwright-skill`), logged in as an admin:
- The dashboard loads without errors.
- The top menu has "Calendar".
- `/calendar` renders an empty shell. Its page source contains `ljpc-calendar-config` and no inline `<script>` code from this module.
- Settings > Calendar renders the settings shell.

Save the screenshots in the session scratchpad. Run `ddev exec php artisan config:clear` before running the tests again.

- [ ] **Step 12: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Http Services/LineItemText.php Services/DashboardCard.php Resources/views/index.blade.php Resources/views/settings.blade.php Providers/LJPcCalendarModuleServiceProvider.php Tests/Feature/IntegrationHooksTest.php
git commit -m "feat: add page shells, menu, settings section, dashboard card and line item text"
```

---
### Task 14: Migration service, part 1: calendars, connections and permissions

**Files:**
- Create: `$MOD/Services/MigrationService.php`
- Create: `$MOD/Database/Migrations/2026_10_05_000002_ljpc_calendar_v3_convert_calendars.php`
- Create: `$MOD/Database/Migrations/2026_10_05_000003_ljpc_calendar_v3_convert_permissions.php`
- Test: `$MOD/Tests/Unit/MigrationCalendarsTest.php`

**Interfaces:**
- Consumes: `Calendar::newToken`, `Calendar::defaultPriority`, `CalendarConnection::setCredentials`, `FieldValues::TYPES`, `OccurrenceService` (constructor dependency, used in Task 15).
- Produces `MigrationService`:
  - Constants: `REPORT_OPTION = 'ljpccalendarmodule.migration_report'`, `STATE_OPTION = 'ljpccalendarmodule.migration_state'`, `SKIPPED_OPTION = 'ljpccalendarmodule.migration_skipped_items'`.
  - `convertCalendars(): void`. Idempotent: it only touches rows with `export_token IS NULL`.
  - `convertPermissions(): void`. Idempotent: it skips calendars that already have permission rows.
  - `report(): array`. Each entry is `{level: info|warning|error, message: <English __() key with :placeholders>, context: {...scalars}, at: "Y-m-d H:i:s"}`. W2 renders entries with `__($message, $context)`.
- v2 sources:
  - `calendars.type` (`normal|ics|caldav`)
  - `calendars.custom_fields` (`fields[]` with CSV `options`, `url`, `username`, `password`, `refresh`, `display_priority` (fork 2.6.7+), `last_update`)
  - `calendars.permissions` (`{<user or team id>: {showInDashboard, showInCalendar, createItems, editItems}}`)
  - `calendars.title_template` (`{{title}}` and `{{<field name>}}`)

- [ ] **Step 1: Write the failing test `Tests/Unit/MigrationCalendarsTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationCalendarsTest extends TestCase
{
    private function v2Calendar(array $attributes): int
    {
        return DB::table('calendars')->insertGetId($attributes + [
            'name'       => 'V2 ' . uniqid(),
            'enabled'    => 1,
            'color'      => '#3498db',
            'type'       => 'normal',
            'created_at' => '2024-05-01 10:00:00',
            'updated_at' => '2024-05-01 10:00:00',
        ]);
    }

    private function messages(): array
    {
        return array_column(app(MigrationService::class)->report(), 'message');
    }

    public function test_converts_local_ics_and_caldav_calendars(): void
    {
        $local = $this->v2Calendar([
            'custom_fields'  => json_encode(['fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => 'Call, Visit,']], 'display_priority' => 1]),
            'title_template' => '{{title}} - {{Kind}} {{Unknown}}',
            'color'          => 'red',
        ]);
        $ics = $this->v2Calendar(['type' => 'ics', 'custom_fields' => json_encode(['url' => 'https://example.com/feed.ics', 'refresh' => '1 hour', 'last_update' => 1791200539])]);
        $caldav = $this->v2Calendar(['type' => 'caldav', 'custom_fields' => json_encode(['fields' => [], 'url' => 'https://dav.example.com:8443/remote.php/dav/calendars/lars/work/', 'username' => 'lars', 'password' => 's3cret', 'refresh' => '5 minutes'])]);
        $empty = $this->v2Calendar(['custom_fields' => null]);

        app(MigrationService::class)->convertCalendars();

        $localCalendar = Calendar::find($local);
        $this->assertSame('local', $localCalendar->provider);
        $this->assertSame(1, $localCalendar->display_priority);
        $this->assertSame([['id' => 172604180330, 'name' => 'Kind', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']]], $localCalendar->fields);
        $this->assertSame('{{title}} - {{field:172604180330}} {{Unknown}}', $localCalendar->title_template);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $localCalendar->color);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $localCalendar->export_token);

        $icsCalendar = Calendar::find($ics);
        $this->assertSame(['ics', 'https://example.com/feed.ics', 60, 200], [$icsCalendar->provider, $icsCalendar->remote_id, $icsCalendar->sync_interval, $icsCalendar->display_priority]);

        $davCalendar = Calendar::find($caldav);
        $connection = CalendarConnection::find($davCalendar->connection_id);
        $this->assertSame(['caldav', 'lars', 'https://dav.example.com:8443'], [$connection->provider, $connection->account, $connection->server_url]);
        $this->assertSame(['username' => 'lars', 'password' => 's3cret'], $connection->credentials());
        $this->assertStringNotContainsString('s3cret', DB::table('calendar_connections')->where('id', $connection->id)->value('credentials'));

        $this->assertSame([], Calendar::find($empty)->fieldDefinitions());
        $this->assertContains('The title template of ":calendar" uses ":placeholder", which matches no field; it was left as text.', $this->messages());

        // Idempotent: a second run changes nothing and creates no second connection.
        $token = $localCalendar->export_token;
        app(MigrationService::class)->convertCalendars();
        $this->assertSame($token, Calendar::find($local)->export_token);
        $this->assertSame(1, CalendarConnection::where('account', 'lars')->count());
    }

    public function test_converts_permissions_including_teams_and_drops_deleted_users(): void
    {
        $user = $this->makeUser();
        $deleted = $this->makeUser(['status' => \App\User::STATUS_DELETED]);
        $team = $this->makeTeam([$user->id]);
        $calendar = $this->v2Calendar(['permissions' => json_encode([
            (string) $user->id    => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => false, 'editItems' => true],
            (string) $deleted->id => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            (string) $team->id    => ['showInDashboard' => false, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            '99999999'            => ['showInDashboard' => true, 'showInCalendar' => true, 'createItems' => true, 'editItems' => true],
            (string) ($user->id + 1000000) => ['showInDashboard' => false, 'showInCalendar' => false, 'createItems' => false, 'editItems' => false],
        ])]);

        app(MigrationService::class)->convertPermissions();
        app(MigrationService::class)->convertPermissions();

        $rows = CalendarPermission::where('calendar_id', $calendar)->orderBy('subject_type')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['team', $team->id, true, true], [$rows[0]->subject_type, $rows[0]->subject_id, $rows[0]->can_create, $rows[0]->can_view]);
        $this->assertSame(['user', $user->id, true, false, true], [$rows[1]->subject_type, $rows[1]->subject_id, $rows[1]->show_in_dashboard, $rows[1]->can_create, $rows[1]->can_edit]);
        $this->assertContains('A permission on ":calendar" for a deleted user or team (#:id) was dropped.', $this->messages());
        $this->assertContains('Team ":team" now gives its members access to ":calendar". Team permissions had no effect in version 2, so check this row.', $this->messages());
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter MigrationCalendarsTest'`
Expected: ERROR `Class ... MigrationService not found`.

- [ ] **Step 3: Write `Services/MigrationService.php`**

`convertItems`, `isComplete`, `isFinished`, `finish` and `scheduleExternalResync` are added in Tasks 15 and 16.

```php
<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;

/**
 * Converts v2 data (public 2.6.x and the 2.7.x fork) to the v3 model. Every step is idempotent so a failed
 * `migrate` can simply run again. Progress and problems go to a report shown under Settings > Calendar.
 */
class MigrationService
{
    public const REPORT_OPTION = 'ljpccalendarmodule.migration_report';
    public const STATE_OPTION = 'ljpccalendarmodule.migration_state';
    public const SKIPPED_OPTION = 'ljpccalendarmodule.migration_skipped_items';

    private const REFRESH_MINUTES = [
        '1 minute' => 1, '5 minutes' => 5, '15 minutes' => 15, '30 minutes' => 30, '1 hour' => 60,
        '2 hours' => 120, '6 hours' => 360, '12 hours' => 720, 'daily' => 1440,
    ];
    private const PALETTE = ['#3498db', '#e74c3c', '#e67e22', '#1abc9c', '#9b59b6', '#2ecc71', '#f1c40f', '#833471', '#9980fa'];

    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    public function report(): array
    {
        $report = Option::get(self::REPORT_OPTION, [], true, false);

        return is_array($report) ? $report : [];
    }

    public function convertCalendars(): void
    {
        foreach (DB::table('calendars')->whereNull('export_token')->orderBy('id')->get() as $row) {
            DB::transaction(function () use ($row) {
                $this->convertCalendar($row);
            });
        }
    }

    public function convertPermissions(): void
    {
        foreach (DB::table('calendars')->orderBy('id')->get(['id', 'name', 'permissions']) as $row) {
            if (CalendarPermission::where('calendar_id', $row->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($row) {
                foreach (self::json($row->permissions ?? null) as $subjectId => $rights) {
                    $this->convertPermission($row, (int) $subjectId, (array) $rights);
                }
            });
        }
    }

    private function convertCalendar(object $row): void
    {
        $customFields = self::json($row->custom_fields ?? null);
        $providers = ['normal' => Calendar::PROVIDER_LOCAL, 'ics' => Calendar::PROVIDER_ICS, 'caldav' => Calendar::PROVIDER_CALDAV];
        $provider = $providers[$row->type ?? 'normal'] ?? null;
        if ($provider === null) {
            $this->log('warning', 'Calendar ":calendar" had an unknown type ":type" and was converted to a local calendar.', ['calendar' => $row->name, 'type' => (string) $row->type]);
            $provider = Calendar::PROVIDER_LOCAL;
        }
        $fields = $this->fields($row, $customFields['fields'] ?? []);

        $update = [
            'provider'          => $provider,
            'fields'            => json_encode($fields),
            'display_priority'  => isset($customFields['display_priority']) && is_numeric($customFields['display_priority'])
                ? (int) $customFields['display_priority']
                : Calendar::defaultPriority($provider),
            'sync_interval'     => self::REFRESH_MINUTES[$customFields['refresh'] ?? ''] ?? 5,
            'title_template'    => $this->template($row, $fields),
            'color'             => self::color($row->color ?? null, (int) $row->id),
            'export_token'      => Calendar::newToken(),
            'export_busy_token' => Calendar::newToken(),
        ];
        if ($provider !== Calendar::PROVIDER_LOCAL) {
            $url = trim((string) ($customFields['url'] ?? ''));
            if ($url === '') {
                $this->log('error', 'Calendar ":calendar" has no URL and cannot sync until one is set.', ['calendar' => $row->name]);
            }
            $update['remote_id'] = $url !== '' ? $url : null;
        }
        if ($provider === Calendar::PROVIDER_CALDAV) {
            $update['connection_id'] = $this->caldavConnection($customFields)->id;
        }

        DB::table('calendars')->where('id', $row->id)->update($update);
        $this->log('info', 'Calendar ":calendar" was converted (:provider).', ['calendar' => $row->name, 'provider' => $provider]);
    }

    private function convertPermission(object $calendar, int $subjectId, array $v2): void
    {
        $rights = [
            'show_in_dashboard' => !empty($v2['showInDashboard']),
            'can_view'          => !empty($v2['showInCalendar']),
            'can_create'        => !empty($v2['createItems']),
            'can_edit'          => !empty($v2['editItems']),
        ];
        if (!array_filter($rights)) {
            return;
        }
        $user = User::find($subjectId);
        $isTeam = $user && (int) $user->type === User::TYPE_ROBOT && str_starts_with((string) $user->email, 'fsteam');
        if (!$user || (!$isTeam && (int) $user->status === User::STATUS_DELETED)) {
            $this->log('warning', 'A permission on ":calendar" for a deleted user or team (#:id) was dropped.', ['calendar' => $calendar->name, 'id' => $subjectId]);

            return;
        }

        CalendarPermission::create([
            'calendar_id'  => $calendar->id,
            'subject_type' => $isTeam ? CalendarPermission::SUBJECT_TEAM : CalendarPermission::SUBJECT_USER,
            'subject_id'   => $subjectId,
        ] + $rights);
        if ($isTeam) {
            $this->log('info', 'Team ":team" now gives its members access to ":calendar". Team permissions had no effect in version 2, so check this row.', ['team' => (string) $user->first_name, 'calendar' => $calendar->name]);
        }
    }

    private function fields(object $calendar, $fields): array
    {
        $result = [];
        foreach ((array) $fields as $field) {
            if (!is_array($field) || !isset($field['id'], $field['name'])) {
                $this->log('warning', 'A custom field without an id or name on ":calendar" was dropped.', ['calendar' => $calendar->name]);
                continue;
            }
            $options = $field['options'] ?? [];
            if (is_string($options)) {
                $options = explode(',', $options);
            }
            $type = in_array($field['type'] ?? 'text', FieldValues::TYPES, true) ? $field['type'] : 'text';
            $result[] = [
                'id'       => is_numeric($field['id']) ? (int) $field['id'] : (string) $field['id'],
                'name'     => trim(strip_tags((string) $field['name'])),
                'type'     => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options'  => array_values(array_filter(array_map('trim', (array) $options), 'strlen')),
            ];
        }

        return $result;
    }

    private function template(object $calendar, array $fields): ?string
    {
        $template = trim((string) ($calendar->title_template ?? ''));
        if ($template === '') {
            return null;
        }
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field['id'];
        }

        return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function (array $match) use ($byName, $calendar) {
            if ($match[1] === 'title') {
                return '{{title}}';
            }
            if (isset($byName[$match[1]])) {
                return '{{field:' . $byName[$match[1]] . '}}';
            }
            $this->log('warning', 'The title template of ":calendar" uses ":placeholder", which matches no field; it was left as text.', ['calendar' => $calendar->name, 'placeholder' => $match[0]]);

            return $match[0];
        }, $template);
    }

    private function caldavConnection(array $customFields): CalendarConnection
    {
        $parts = parse_url((string) ($customFields['url'] ?? '')) ?: [];
        $connection = new CalendarConnection([
            'provider'   => Calendar::PROVIDER_CALDAV,
            'account'    => (string) ($customFields['username'] ?? ''),
            'server_url' => isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : null,
            'status'     => 'ok',
        ]);
        $connection->setCredentials([
            'username' => (string) ($customFields['username'] ?? ''),
            'password' => (string) ($customFields['password'] ?? ''),
        ]);
        $connection->save();

        return $connection;
    }

    private static function color(?string $color, int $id): string
    {
        if ($color && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }
        if ($color && preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $color, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return self::PALETTE[$id % count(self::PALETTE)];
    }

    private static function json($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $report = $this->report();
        $report[] = ['level' => $level, 'message' => $message, 'context' => $context, 'at' => gmdate('Y-m-d H:i:s')];
        Option::set(self::REPORT_OPTION, $report);
        if ($level === 'error') {
            Log::error('Calendar migration: ' . __($message, array_map('strval', $context)));
        }
    }
}
```

- [ ] **Step 4: Write the two migrations**

`2026_10_05_000002_ljpc_calendar_v3_convert_calendars.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ConvertCalendars extends Migration
{
    public function up()
    {
        set_time_limit(0);
        app(MigrationService::class)->convertCalendars();
    }

    public function down()
    {
    }
}
```

`2026_10_05_000003_ljpc_calendar_v3_convert_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ConvertPermissions extends Migration
{
    public function up()
    {
        set_time_limit(0);
        app(MigrationService::class)->convertPermissions();
    }

    public function down()
    {
    }
}
```

- [ ] **Step 5: Run the tests and migrate the dev and test databases**

```bash
cd ~/Projects/freescout && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter MigrationCalendarsTest'
ddev exec php artisan migrate --force
ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'
```

Expected: `OK (2 tests, ...)`, then the migrations run, then `OK` for the full suite.

- [ ] **Step 6: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/MigrationService.php Database/Migrations/2026_10_05_000002_ljpc_calendar_v3_convert_calendars.php Database/Migrations/2026_10_05_000003_ljpc_calendar_v3_convert_permissions.php Tests/Unit/MigrationCalendarsTest.php
git commit -m "feat: migrate v2 calendars, CalDAV credentials and permissions to v3"
```

---

### Task 15: Migration service, part 2: v2 events

**Files:**
- Modify: `$MOD/Services/MigrationService.php` (add `convertItems`, `skippedItemIds` and the private helpers)
- Create: `$MOD/Database/Migrations/2026_10_05_000004_ljpc_calendar_v3_convert_items.php`
- Test: `$MOD/Tests/Unit/MigrationItemsTest.php`

**Interfaces:**
- Consumes: `EventIcs::build`, `EventIcs::derive`, `OccurrenceService::refresh`, and the v2 columns of `calendar_items` (`calendar_id`, `author_id`, `is_all_day`, `title`, `body`, `location`, `start`, `end` (UTC), `custom_fields` (`{author_id, conversation_id, custom_field_<id>}`), `created_at`).
- Produces:
  - `MigrationService::convertItems(): void`. Idempotent per item: it skips items whose `legacy_id` already exists, or that are recorded as skipped.
  - `MigrationService::skippedItemIds(): array`
  - Every converted event gets `uid = "v2-item-<id>@<app host>"`, `legacy_id = "<id>"` and `title_input = title`.
- All-day rule: v2 stored "local midnight as UTC", but the browser timezone could differ from the app timezone. Therefore:
  - The start date is the nearest midnight in `app.timezone` (a local time at or after 12:00 rounds up to the next day).
  - The day count is `max(1, round((end - start + 1 second) / 86400))`.
  - The end date is the start date plus that many days.

- [ ] **Step 1: Write the failing test `Tests/Unit/MigrationItemsTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use App\Conversation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarOccurrence;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationItemsTest extends TestCase
{
    private Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Amsterdam']);
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        $this->calendar = Calendar::create(['name' => 'Items ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]]]);
    }

    private function item(array $attributes): int
    {
        return DB::table('calendar_items')->insertGetId($attributes + [
            'calendar_id'  => $this->calendar->id,
            'author_id'    => 1,
            'is_all_day'   => 0,
            'is_private'   => 0,
            'is_read_only' => 0,
            'title'        => 'Item',
            'body'         => '',
            'state'        => 'published',
            'location'     => null,
            'created_at'   => '2025-01-01 10:00:00',
            'updated_at'   => '2025-01-01 10:00:00',
        ]);
    }

    private function converted(int $itemId): CalendarEvent
    {
        return CalendarEvent::where('legacy_id', (string) $itemId)->firstOrFail();
    }

    public function test_timed_items_keep_their_utc_instant_and_links(): void
    {
        $user = $this->makeUser();
        $conversation = factory(Conversation::class)->create(['mailbox_id' => $this->makeMailbox([$user->id])->id, 'status' => Conversation::STATUS_ACTIVE]);
        $id = $this->item([
            'title' => 'Call Acme', 'body' => 'Notes', 'location' => 'Office', 'author_id' => $user->id,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00',
            'custom_fields' => json_encode(['author_id' => $user->id, 'conversation_id' => (string) $conversation->id, 'custom_field_172604180330' => 'Visit']),
        ]);

        app(MigrationService::class)->convertItems();

        $event = $this->converted($id);
        $this->assertSame(['2026-10-20 08:00:00', '2026-10-20 09:00:00', 'Europe/Amsterdam'], [$event->starts_at, $event->ends_at, $event->timezone]);
        $this->assertSame(['Call Acme', $user->id, $conversation->id], [$event->summary, $event->author_id, $event->conversation_id]);
        $this->assertSame(['172604180330' => 'Visit'], $event->field_values);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20261020T100000', $event->ics);
        $this->assertSame(1, CalendarOccurrence::where('event_id', $event->id)->count());
    }

    public function test_all_day_items_become_dates_even_when_stored_from_another_browser_timezone(): void
    {
        $amsterdamBrowser = $this->item(['is_all_day' => 1, 'start' => '2026-10-04 22:00:00', 'end' => '2026-10-05 21:59:59']);
        $utcBrowser = $this->item(['is_all_day' => 1, 'start' => '2026-10-05 00:00:00', 'end' => '2026-10-05 23:59:59']);
        $threeDays = $this->item(['is_all_day' => 1, 'start' => '2026-10-04 22:00:00', 'end' => '2026-10-07 21:59:59']);

        app(MigrationService::class)->convertItems();

        foreach ([$amsterdamBrowser, $utcBrowser] as $id) {
            $this->assertSame(['2026-10-05 00:00:00', '2026-10-06 00:00:00', true], [$this->converted($id)->starts_at, $this->converted($id)->ends_at, $this->converted($id)->all_day]);
        }
        $this->assertSame('2026-10-08 00:00:00', $this->converted($threeDays)->ends_at);
    }

    public function test_is_idempotent_and_reports_items_it_cannot_convert(): void
    {
        $this->item(['start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $orphan = $this->item(['calendar_id' => 99999999, 'title' => 'Orphan', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $external = Calendar::create(['name' => 'Ext ' . uniqid(), 'provider' => Calendar::PROVIDER_ICS]);
        $stale = $this->item(['calendar_id' => $external->id, 'title' => 'Stale', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);

        $service = app(MigrationService::class);
        $service->convertItems();
        $service->convertItems();

        $this->assertSame(1, CalendarEvent::where('calendar_id', $this->calendar->id)->count());
        $this->assertEqualsCanonicalizing([$orphan, $stale], $service->skippedItemIds());
        $errors = array_values(array_filter($service->report(), function (array $entry) {
            return $entry['level'] === 'error';
        }));
        $this->assertSame('Orphan', $errors[0]['context']['title']);
        $this->assertSame('2026-10-20 08:00:00', $errors[0]['context']['start']);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter MigrationItemsTest'`
Expected: ERROR `Call to undefined method ...::convertItems()`.

- [ ] **Step 3: Add item conversion to `Services/MigrationService.php`**

Add these use-statements: `use DateTimeImmutable;`, `use DateTimeZone;`, `use Illuminate\Support\Facades\Schema;`, `use Modules\LJPcCalendarModule\Entities\CalendarEvent;`. Then add these methods to the class:

```php
    public function convertItems(): void
    {
        if (!Schema::hasTable('calendar_items')) {
            return;
        }
        $timezone = new DateTimeZone(config('app.timezone') ?: 'UTC');
        $skipped = $this->skippedItemIds();
        $converted = 0;

        DB::table('calendar_items')->orderBy('id')->chunk(200, function ($items) use ($timezone, &$skipped, &$converted) {
            foreach ($items as $item) {
                $id = (int) $item->id;
                if (in_array($id, $skipped, true) || CalendarEvent::where('legacy_id', (string) $id)->where('calendar_id', $item->calendar_id)->exists()) {
                    continue;
                }
                $calendar = Calendar::find($item->calendar_id);
                if (!$calendar || !$calendar->isLocal()) {
                    $skipped[] = $id;
                    $this->log('error', $calendar
                        ? 'Event ":title" (#:id) belongs to an external calendar and was not converted; it is listed here with its data.'
                        : 'Event ":title" (#:id) belongs to a calendar that no longer exists and was not converted; it is listed here with its data.', self::itemContext($item));
                    continue;
                }
                try {
                    DB::transaction(function () use ($item, $calendar, $timezone) {
                        $this->convertItem($item, $calendar, $timezone);
                    });
                    $converted++;
                } catch (\Throwable $e) {
                    $skipped[] = $id;
                    $this->log('error', 'Event ":title" (#:id) could not be converted: :error', self::itemContext($item) + ['error' => $e->getMessage()]);
                }
            }
            Option::set(self::SKIPPED_OPTION, array_values(array_unique($skipped)));
        });

        $this->log('info', ':count events were converted. All-day events were read in the timezone :timezone.', ['count' => $converted, 'timezone' => $timezone->getName()]);
    }

    public function skippedItemIds(): array
    {
        return array_map('intval', (array) Option::get(self::SKIPPED_OPTION, [], true, false));
    }

    private function convertItem(object $item, Calendar $calendar, DateTimeZone $timezone): void
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string) $item->start, $utc);
        $end = new DateTimeImmutable((string) $item->end, $utc);
        $customFields = self::json($item->custom_fields ?? null);

        if ($item->is_all_day) {
            $local = $start->setTimezone($timezone);
            $startDate = ((int) $local->format('H') >= 12 ? $local->modify('+1 day') : $local)->format('Y-m-d');
            $days = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp() + 1) / 86400));
            $times = ['all_day' => true, 'start' => $startDate, 'end' => (new DateTimeImmutable($startDate, $utc))->modify('+' . $days . ' days')->format('Y-m-d')];
        } else {
            $startLocal = $start->setTimezone($timezone);
            $times = ['all_day' => false, 'start' => $startLocal, 'end' => max($startLocal, $end->setTimezone($timezone))];
        }

        $fieldValues = [];
        foreach ($customFields as $key => $value) {
            if (str_starts_with((string) $key, 'custom_field_') && $value !== null && $value !== '') {
                $fieldValues[substr((string) $key, 13)] = $value;
            }
        }

        $event = new CalendarEvent([
            'calendar_id'     => $calendar->id,
            'author_id'       => $item->author_id ?: ($customFields['author_id'] ?? null),
            'conversation_id' => isset($customFields['conversation_id']) && is_numeric($customFields['conversation_id']) ? (int) $customFields['conversation_id'] : null,
            'field_values'    => $fieldValues,
            'title_input'     => (string) $item->title,
            'legacy_id'       => (string) $item->id,
        ]);
        $event->uid = 'v2-item-' . $item->id . '@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'freescout');
        $event->setRelation('calendar', $calendar);

        $vcal = EventIcs::build(['uid' => $event->uid, 'title' => (string) $item->title, 'location' => $item->location, 'description' => $item->body] + $times);
        $event->ics = $vcal->serialize();
        $event->fill(EventIcs::derive($vcal, $event->uid, $calendar->timezoneName()));
        $event->save();
        $this->occurrences->refresh($event);
    }

    private static function itemContext(object $item): array
    {
        return [
            'id'          => (int) $item->id,
            'title'       => (string) $item->title,
            'calendar_id' => (int) $item->calendar_id,
            'start'       => (string) $item->start,
            'end'         => (string) $item->end,
        ];
    }
```

- [ ] **Step 4: Write `2026_10_05_000004_ljpc_calendar_v3_convert_items.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ConvertItems extends Migration
{
    public function up()
    {
        set_time_limit(0);
        app(MigrationService::class)->convertItems();
    }

    public function down()
    {
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/Projects/freescout && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter MigrationItemsTest'
ddev exec php artisan migrate --force && ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'
```

Expected: `OK (3 tests, ...)`, then `OK` for the full suite.

- [ ] **Step 6: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/MigrationService.php Database/Migrations/2026_10_05_000004_ljpc_calendar_v3_convert_items.php Tests/Unit/MigrationItemsTest.php
git commit -m "feat: migrate v2 events to iCalendar events with legacy links"
```

---

### Task 16: Migration completion, cleanup and the migration gate

**Files:**
- Modify: `$MOD/Services/MigrationService.php` (add `scheduleExternalResync`, `isComplete`, `isFinished`, `finish`)
- Create: `$MOD/Database/Migrations/2026_10_05_000005_ljpc_calendar_v3_schedule_resync.php`
- Create: `$MOD/Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php`
- Create: `$MOD/Http/Middleware/EnsureCalendarMigrated.php`
- Modify: `$MOD/Http/routes.php` (agent API behind the gate; the admin report route)
- Modify: `$MOD/Http/FrontendConfig.php` (`migration_complete`)
- Modify: `$MOD/Http/Controllers/Admin/CalendarsController.php` (add `migration()`)
- Modify: `$MOD/Tests/setup-test-db.sh` (restore the empty legacy schema for tests)
- Test: `$MOD/Tests/Feature/MigrationGateTest.php`

**Interfaces:**
- Produces:
  - `MigrationService::scheduleExternalResync(): void`
  - `MigrationService::isComplete(): bool`. True when every calendar is converted (no `export_token IS NULL` and no non-NULL legacy `permissions` while the legacy columns exist) and every `calendar_items` row is converted or recorded as skipped, or the table is gone.
  - `MigrationService::isFinished(): bool` (`STATE_OPTION === 'complete'`)
  - `MigrationService::finish(): void`
  - The `EnsureCalendarMigrated` middleware returns 503 JSON on the agent API until the migration is finished.
  - `GET /calendar/api/admin/migration` (route `ljpccalendarmodule.admin.migration`) returns `{finished: bool, entries: report}`.
  - The cleanup migration's `up()` throws while `!isComplete()`, which keeps all v2 data. Its `down()` recreates the empty v2 schema; it's used only for the module test database.

- [ ] **Step 1: Write the failing test `Tests/Feature/MigrationGateTest.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class MigrationGateTest extends TestCase
{
    public function test_completeness_tracks_unconverted_items(): void
    {
        $service = app(MigrationService::class);
        $calendar = Calendar::create(['name' => 'Gate ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendar_items')->insert([
            'calendar_id' => $calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => 'Pending', 'body' => '', 'state' => 'published', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00',
        ]);

        $this->assertFalse($service->isComplete());
        $service->convertItems();
        $this->assertTrue($service->isComplete());
    }

    public function test_agent_api_is_gated_until_the_migration_is_finished_but_the_page_is_not(): void
    {
        $user = $this->makeUser();
        Option::set(MigrationService::STATE_OPTION, 'pending');

        $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(503);
        $this->actingAs($user)->get('/calendar')->assertStatus(200)->assertSee('migration_complete":false', false);

        app(MigrationService::class)->finish();
        $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(200);
    }

    public function test_admins_can_read_the_report(): void
    {
        app(MigrationService::class)->finish();

        $body = json_decode($this->actingAs($this->makeAdmin())->json('GET', '/calendar/api/admin/migration')->assertStatus(200)->getContent(), true);

        $this->assertTrue($body['finished']);
        $this->assertSame('The upgrade to version 3 is complete.', end($body['entries'])['message']);
        $this->actingAs($this->makeUser())->json('GET', '/calendar/api/admin/migration')->assertStatus(403);
    }
}
```

If `assertSee` in FreeScout's Laravel 5.5 doesn't accept a second argument, use `$this->assertStringContainsString('migration_complete":false', $response->getContent())` instead.

- [ ] **Step 2: Run it and see it fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter MigrationGateTest'`
Expected: ERROR `Call to undefined method ...::isComplete()`.

- [ ] **Step 3: Add completion to `Services/MigrationService.php`**

```php
    public function scheduleExternalResync(): void
    {
        $count = DB::table('calendars')
            ->whereIn('provider', [Calendar::PROVIDER_ICS, Calendar::PROVIDER_CALDAV])
            ->update(['next_sync_at' => gmdate('Y-m-d H:i:s')]);
        if ($count) {
            $this->log('info', ':count external calendars will be synchronized again; their events appear after the first sync.', ['count' => $count]);
        }
    }

    public function isComplete(): bool
    {
        // Every calendar must be converted (it gets export tokens); a calendar that failed conversion blocks cleanup.
        if (Schema::hasColumn('calendars', 'custom_fields') && DB::table('calendars')->whereNull('export_token')->exists()) {
            return false;
        }
        // convertPermissions() sets the legacy column to NULL when done; a non-NULL value means a calendar failed.
        if (Schema::hasColumn('calendars', 'permissions') && DB::table('calendars')->whereNotNull('permissions')->exists()) {
            return false;
        }
        if (!Schema::hasTable('calendar_items')) {
            return true;
        }
        $itemIds = DB::table('calendar_items')->pluck('id')->map('intval')->all();
        if (!$itemIds) {
            return true;
        }
        $converted = CalendarEvent::whereNotNull('legacy_id')->pluck('legacy_id')->map('intval')->all();

        return !array_diff($itemIds, $converted, $this->skippedItemIds());
    }

    public function isFinished(): bool
    {
        return Option::get(self::STATE_OPTION, '', true, false) === 'complete';
    }

    public function finish(): void
    {
        Option::set(self::STATE_OPTION, 'complete');
        $this->log('info', 'The upgrade to version 3 is complete.');
    }
```

- [ ] **Step 4: Write `2026_10_05_000005_ljpc_calendar_v3_schedule_resync.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3ScheduleResync extends Migration
{
    public function up()
    {
        app(MigrationService::class)->scheduleExternalResync();
    }

    public function down()
    {
    }
}
```

- [ ] **Step 5: Write `2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\LJPcCalendarModule\Services\MigrationService;

class LjpcCalendarV3CleanupV2 extends Migration
{
    private const LEGACY_COLUMNS = ['type', 'permissions', 'custom_fields', 'sync_version', 'last_full_sync', 'force_legacy_mode'];

    public function up()
    {
        $service = app(MigrationService::class);
        if (!$service->isComplete()) {
            throw new RuntimeException('The calendar upgrade is incomplete, so the version 2 data was kept. See Settings > Calendar for the report, then run the module migrations again.');
        }

        Schema::dropIfExists('calendar_event_index');
        Schema::dropIfExists('calendar_items');
        foreach (self::LEGACY_COLUMNS as $column) {
            if (Schema::hasColumn('calendars', $column)) {
                Schema::table('calendars', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
        File::deleteDirectory(storage_path('app/ljpccalendarmodule'));

        $service->finish();
    }

    /** Recreates the empty v2 schema. Only used to build the module's test database (Tests/setup-test-db.sh). */
    public function down()
    {
        if (!Schema::hasTable('calendar_items')) {
            Schema::create('calendar_items', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('calendar_id');
                $table->integer('author_id');
                $table->boolean('is_all_day');
                $table->boolean('is_private');
                $table->boolean('is_read_only')->default(false);
                $table->string('title');
                $table->text('body');
                $table->string('state');
                $table->string('location')->nullable();
                $table->dateTime('start');
                $table->dateTime('end');
                $table->json('custom_fields')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('calendar_event_index')) {
            Schema::create('calendar_event_index', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('calendar_id');
                $table->string('event_uid');
                $table->json('event_data');
                $table->timestamps();
            });
        }
        $columns = [
            'type'              => function (Blueprint $t) { $t->string('type')->default('normal'); },
            'permissions'       => function (Blueprint $t) { $t->json('permissions')->nullable(); },
            'custom_fields'     => function (Blueprint $t) { $t->json('custom_fields')->nullable(); },
            'sync_version'      => function (Blueprint $t) { $t->unsignedInteger('sync_version')->default(0); },
            'last_full_sync'    => function (Blueprint $t) { $t->timestamp('last_full_sync')->nullable(); },
            'force_legacy_mode' => function (Blueprint $t) { $t->boolean('force_legacy_mode')->default(false); },
        ];
        foreach ($columns as $name => $define) {
            if (!Schema::hasColumn('calendars', $name)) {
                Schema::table('calendars', $define);
            }
        }
    }
}
```

- [ ] **Step 6: Write `Http/Middleware/EnsureCalendarMigrated.php`**

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Middleware;

use Closure;
use Modules\LJPcCalendarModule\Services\MigrationService;

/** Keeps the agent API closed until the v2 to v3 data migration has finished. */
class EnsureCalendarMigrated
{
    public function handle($request, Closure $next)
    {
        if (!app(MigrationService::class)->isFinished()) {
            return response()->json(['message' => __('The calendar is being upgraded. An administrator can find the report under Settings > Calendar.')], 503);
        }

        return $next($request);
    }
}
```

- [ ] **Step 7: Gate the agent API, add the report route and update the page config**

In `Http/routes.php`, directly below `$agent = ...`, add:

```php
    $agentApi = ['middleware' => ['auth', 'roles', \Modules\LJPcCalendarModule\Http\Middleware\EnsureCalendarMigrated::class], 'roles' => ['user', 'admin']];
```

Change the seven `/calendar/api/...` agent routes from `$agent + [...]` to `$agentApi + [...]`. The `/calendar` page keeps `$agent`. Then add to the admin routes:

```php
    Route::get('/calendar/api/admin/migration', $admin + ['uses' => 'Admin\CalendarsController@migration'])->name('ljpccalendarmodule.admin.migration');
```

In `Admin/CalendarsController`, add `use Modules\LJPcCalendarModule\Services\MigrationService;` and:

```php
    public function migration(MigrationService $migration)
    {
        return response()->json(['finished' => $migration->isFinished(), 'entries' => $migration->report()]);
    }
```

In `Http/FrontendConfig::page()`, replace `'migration_complete' => true,` with:

```php
            'migration_complete' => app(\Modules\LJPcCalendarModule\Services\MigrationService::class)->isFinished(),
```

In `FrontendConfig::settings()`, add `'migration' => route('ljpccalendarmodule.admin.migration'),` to `routes`.

- [ ] **Step 8: Restore the legacy schema in the test database**

Append to `Tests/setup-test-db.sh`, before the final `echo`:

```bash
# The cleanup migration dropped the v2 tables; recreate them (empty) so migration tests can insert v2 fixtures.
php artisan tinker <<'PHP'
DB::setDefaultConnection('testing');
require base_path('Modules/freescout-calendar-module/Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php');
(new LjpcCalendarV3CleanupV2())->down();
echo "legacy v2 schema restored\n";
PHP
```

- [ ] **Step 9: Migrate everything and run all tests**

```bash
cd ~/Projects/freescout && ddev exec php artisan migrate --force
ddev exec 'echo "echo app(Modules\LJPcCalendarModule\Services\MigrationService::class)->isFinished() ? \"finished\" : \"pending\";" | php artisan tinker'
ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh
ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'
```

Expected:
- The dev database prints `finished`.
- The setup ends with `legacy v2 schema restored` and `freescout_test is ready.`
- The suite reports `OK` (MigrationGateTest: 3 tests).

- [ ] **Step 10: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Services/MigrationService.php Database/Migrations/2026_10_05_000005_ljpc_calendar_v3_schedule_resync.php Database/Migrations/2026_10_05_000006_ljpc_calendar_v3_cleanup_v2.php Http Tests/setup-test-db.sh Tests/Feature/MigrationGateTest.php
git commit -m "feat: finish the v2 migration with a completeness guard, cleanup and API gate"
```

---

### Task 17: Release pipeline

**Files:**
- Modify: `$MOD/.github/workflows/release.yml` (full rewrite)
- Create: `$MOD/.github/scripts/check-shared-packages.php`
- Create: `$MOD/.github/scripts/build-release.sh`
- Create: `$MOD/.github/scripts/release-exclude.txt`

**Interfaces:**
- Produces: `bash .github/scripts/build-release.sh <tag>`, which writes `<tag>.zip` in the repo root. A tag without `-` also gets `latest.zip` and `version.txt`. The zip contains `LJPcCalendarModule/` with `vendor/` and without dev files.
- Produces: `php .github/scripts/check-shared-packages.php [freescout-ref]`, which exits 1 when the module's lock file contains a package from FreeScout's `composer.lock` at that ref. The default ref is the latest tag.
- W2 adds `npm ci && npm run build` and the `Public/build` diff check to this workflow.

- [ ] **Step 1: Write `.github/scripts/check-shared-packages.php`**

```php
<?php

// Fails when the module bundles a package that FreeScout core already ships: the module autoloader is
// prepended, so its copy would replace core's mid-request.
$ref = $argv[1] ?? null;
if ($ref === null) {
    $context = stream_context_create(['http' => ['header' => "User-Agent: ljpc-calendar-release\r\n"]]);
    $tags = json_decode((string) file_get_contents('https://api.github.com/repos/freescout-help-desk/freescout/tags?per_page=1', false, $context), true);
    $ref = $tags[0]['name'] ?? null;
    if (!$ref) {
        fwrite(STDERR, "Could not determine the latest FreeScout tag.\n");
        exit(1);
    }
}

$coreLock = json_decode((string) file_get_contents("https://raw.githubusercontent.com/freescout-help-desk/freescout/{$ref}/composer.lock"), true);
$moduleLock = json_decode((string) file_get_contents(__DIR__ . '/../../composer.lock'), true);
if (!isset($coreLock['packages'], $moduleLock['packages'])) {
    fwrite(STDERR, "Could not read both lock files (FreeScout ref {$ref}).\n");
    exit(1);
}

$core = array_column(array_merge($coreLock['packages'], $coreLock['packages-dev'] ?? []), 'name');
$module = array_column($moduleLock['packages'], 'name');
$shared = array_values(array_intersect($module, $core));

if ($shared) {
    fwrite(STDERR, "The module bundles packages that FreeScout {$ref} already ships:\n - " . implode("\n - ", $shared) . "\nAdd them to \"replace\" in composer.json.\n");
    exit(1);
}
echo 'OK: ' . count($module) . " module packages, none shipped by FreeScout {$ref}.\n";
```

- [ ] **Step 2: Run the check locally**

Run: `cd ~/Projects/freescout/Modules/freescout-calendar-module && php .github/scripts/check-shared-packages.php`
Expected: `OK: N module packages, none shipped by FreeScout 1.8.245.`

If it lists packages (for example a Symfony polyfill), add each one to `replace` in `composer.json` as `"<name>": "*"`. Then run `ddev exec 'cd Modules/freescout-calendar-module && composer update --no-interaction'`, and run the check and the test suite again.

- [ ] **Step 3: Write `.github/scripts/release-exclude.txt`**

```
.git
.github
.gitignore
.phpunit.result.cache
CLAUDE.md
README.md
Tests
docs
phpunit.xml
Resources/frontend
node_modules
dist
*.zip
version.txt
```

- [ ] **Step 4: Write `.github/scripts/build-release.sh`**

```bash
#!/usr/bin/env bash
# Builds the installable zip: .github/scripts/build-release.sh <tag>
# Stable tags (no "-") also produce latest.zip and version.txt, which every install auto-updates from.
set -euo pipefail

tag="${1:?usage: build-release.sh <tag>}"
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

test -f "$root/vendor/autoload.php" || { echo "vendor/ is missing; run composer install --no-dev first" >&2; exit 1; }

mkdir "$work/LJPcCalendarModule"
rsync -a --exclude-from="$root/.github/scripts/release-exclude.txt" "$root/" "$work/LJPcCalendarModule/"
(cd "$work" && zip -qr "$root/$tag.zip" LJPcCalendarModule)

if [[ "$tag" != *-* ]]; then
    cp "$root/$tag.zip" "$root/latest.zip"
    echo "$tag" > "$root/version.txt"
fi
echo "Built $tag.zip"
```

- [ ] **Step 5: Test the build script locally**

Run it once with a pre-release tag and once with a fake stable tag, then clean up.

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module && chmod +x .github/scripts/build-release.sh
bash .github/scripts/build-release.sh 3.0.0-dev
unzip -l 3.0.0-dev.zip | grep -E 'LJPcCalendarModule/(vendor/autoload.php|module.json|Tests/|docs/|CLAUDE.md)' ; ls latest.zip version.txt 2>&1 | head -2
bash .github/scripts/build-release.sh 9.9.9 && cat version.txt
rm -f 3.0.0-dev.zip 9.9.9.zip latest.zip version.txt
```

Expected:
- The pre-release zip lists `vendor/autoload.php` and `module.json`, and nothing under `Tests/`, `docs/` or `CLAUDE.md`.
- `ls` reports that `latest.zip` and `version.txt` don't exist after the pre-release build.
- After the `9.9.9` build, `version.txt` contains `9.9.9`.

- [ ] **Step 6: Rewrite `.github/workflows/release.yml`**

Pre-release tags are never uploaded to the update server, because every install would update to them. They are kept as a workflow artifact only.

```yaml
name: Release

on:
  push:
    tags:
      - '*'

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2

      - name: Install PHP dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction

      - name: Check for packages FreeScout already ships
        run: php .github/scripts/check-shared-packages.php

      - name: Build release zip
        run: bash .github/scripts/build-release.sh "${GITHUB_REF_NAME}"

      - name: Keep pre-release zip as artifact
        if: ${{ contains(github.ref_name, '-') }}
        uses: actions/upload-artifact@v4
        with:
          name: ${{ github.ref_name }}
          path: ${{ github.ref_name }}.zip

      - name: Upload to FTP
        if: ${{ !contains(github.ref_name, '-') }}
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USERNAME }}
          password: ${{ secrets.FTP_PASSWORD }}
          local-dir: ./
          server-dir: './'
          exclude: |
            **
            !latest.zip
            !${{ github.ref_name }}.zip
            !version.txt
```

The v2 workflow's exclude list used `${GITHUB_REF_NAME}` inside a `with:` block, where shell variables aren't expanded. The v3 version uses the `${{ github.ref_name }}` expression instead.

- [ ] **Step 7: Commit**

Don't push and don't tag: `v3` stays local (see the global constraints).

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add .github
git commit -m "ci: build releases with composer, check shared packages and never publish pre-release tags"
```

---

### Task 18: Rehearse the migration on the real masked v2 dumps

This task touches production servers. Only the main session runs Step 1, never a subagent. It is read-only and has the same scope Lars approved on 2026-10-05: calendar tables only, secrets masked on the server, data kept outside the repo and deleted after the 3.0 release.

**Files:**
- Modify: `~/Projects/freescout-calendar-v3-testdata/dump-calendar.sh` (private, not in the repo)
- Create: `$MOD/Tests/rehearse-migration.sh`
- Modify: `$MOD/CLAUDE.md` (the testing and rehearsal workflow)

**Interfaces:**
- Consumes: the masked dumps in `~/Projects/freescout-calendar-v3-testdata/`. Hosts and access routes are in `PRIVATE-NOTES.md` in that folder.
- Produces: `ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql`. It prints counts, the report, the time checks and the line-item resolution.

- [ ] **Step 1: Re-dump the threads with column names**

The first dumps used `INSERT INTO threads VALUES (...)` without column names. That fails when the source FreeScout version has a different number of `threads` columns than the local 1.8.245.

1. In `~/Projects/freescout-calendar-v3-testdata/dump-calendar.sh`, add `--complete-insert` to the `threads` `mariadb-dump` line only.
2. Re-run the three dumps exactly as recorded in `PRIVATE-NOTES.md`. The script already uses `--single-transaction --skip-lock-tables` and the JOIN-based conversation query.
3. Re-run the masking verification from the private notes. Every calendar row must show `non_redacted_http=0`.

- [ ] **Step 2: Write `Tests/rehearse-migration.sh`**

```bash
#!/usr/bin/env bash
# Rehearses the v2 -> v3 migration on a masked dump inside ddev:
#   ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql
# Uses a scratch database "freescout_rehearsal"; the dev and test databases are not touched.
set -euo pipefail
dump="${1:?usage: rehearse-migration.sh <dump.sql inside the container>}"
cd /var/www/html
export DB_TEST_HOST=db DB_TEST_DATABASE=freescout_rehearsal DB_TEST_USERNAME=root DB_TEST_PASSWORD=root REHEARSAL_DUMP="$dump"
module=Modules/freescout-calendar-module

php artisan config:clear >/dev/null
mysql -uroot -proot -h db -e "DROP DATABASE IF EXISTS freescout_rehearsal; CREATE DATABASE freescout_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --database=testing --path=database/migrations --force >/dev/null
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

php artisan migrate --database=testing --path="$module/Database/Migrations" --force

php artisan tinker <<'PHP'
DB::setDefaultConnection('testing');
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
```

- [ ] **Step 3: Run the rehearsal for each of the three dumps**

The dumps live outside the ddev mount. Copy each one in, run, and remove it right away.

```bash
cd ~/Projects/freescout
for dump in install-a-fork-2.7.3.sql install-b-fork-2.7.3.sql install-c-public-2.6.4.sql; do
  install -m 600 ~/Projects/freescout-calendar-v3-testdata/$dump storage/app/rehearsal.sql
  echo "=== $dump"
  ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql \
    | tee ~/Projects/freescout-calendar-v3-testdata/rehearsal-${dump%.sql}.log | tail -40
  rm -f storage/app/rehearsal.sql
done
ddev exec 'mysql -uroot -proot -h db -e "DROP DATABASE IF EXISTS freescout_rehearsal"'
```

Acceptance criteria per dump (the expected numbers come from `PRIVATE-NOTES.md` section F):
- `finished` is `true`, and `calendars` equals the v2 calendar count.
- `events + skipped` equals the v2 item count: 114, 598 and 5.
- `timed events with a changed start: 0`.
- No `ERROR` lines except items that belong to an external or deleted calendar. Each of those must show its title and times in the report.
- `line items resolved` covers every line item of a local calendar. The only unresolved entries allowed are v2 CalDAV metas (`event_uid`), which W3 resolves after the first sync. List them in the commit message count.
- The calendars with `custom_fields = NULL` and the fork's `display_priority` values (1, 2, 3, 4, 100) came through. Check in `rehearsal-*.log` or with a quick `SELECT name, provider, display_priority FROM calendars` added temporarily to the script.

If a criterion fails:
1. Reproduce it in a unit test in `MigrationItemsTest` or `MigrationCalendarsTest` that fails.
2. Fix `MigrationService`.
3. Rebuild the test database, run the suite, then re-run the rehearsal.

Never edit a dump to make it pass.

- [ ] **Step 4: Record the workflow in `CLAUDE.md`**

Add the following section to `$MOD/CLAUDE.md`, under "Local development". It contains no hosts and no data.

```markdown
## Tests and migration rehearsal

- One-time setup (and after schema changes): `ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh`.
  This builds `freescout_test`, copies the `modules` table so the module boots, and restores the empty v2 tables.
- Run the tests: `ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`.
  `Tests/bootstrap.php` refuses to run while the config is cached, because the tests would hit the dev database.
- Rehearse the v2 migration on a masked dump: copy it to `~/Projects/freescout/storage/app/rehearsal.sql` (mode 600), then run
  `ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql`.
  Delete the copy afterwards. The private notes say which dumps exist.
```

- [ ] **Step 5: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add Tests/rehearse-migration.sh CLAUDE.md
git commit -m "test: add v2 migration rehearsal script and document the test workflow"
```

---

## Self-review (done while writing this plan)

**Spec coverage for W1 (spec section 23):**

| Spec item | Where |
|---|---|
| Release pipeline, dependency cleanup, PHP guard | Tasks 2, 3, 17 |
| Schema | Task 4. `calendar_invites` and `calendar_reminder_log` are created in W4 and W5, which use them |
| `MigrationService`, all 7 steps of section 17, report, gate | Tasks 14, 15, 16. Rehearsed on real data in Task 18 |
| Time model (section 6), recurrence (section 7) | Tasks 5, 6, 7 |
| `CalendarPolicy` (section 10) | Task 8, enforced in Tasks 10, 12, 13 |
| Local events through `EventService` | Task 9 |
| API | Tasks 10 and 12 |
| Export (section 16) and the readable block (section 15) | Task 11 |
| Security items (section 18) | Task 3 (removal plus regression tests), 10 (server-side authorization, conversation policy), 11 (tokens), 12 (no secrets, `strip_tags`, color regex), 13 (JSON config, escaping). "Remote URLs" belongs to W3 (the ICS provider) |
| Line items v2 and v3 (section 12) | Task 13 |
| Dashboard card (section 12) | Task 13 holds the backend part, so the core dashboard never breaks between W1 and W2. W2 restyles it |
| Fork parity: `display_priority` | Columns, migration and API in Tasks 4, 12 and 14. Rendering in W2 |
| `ljpc.nl`, author "LJPc" | Task 2. The README, description and version bump follow in W5 |

**Deliberate deviations from the spec, all recorded inside the tasks:**
- The permission columns are `can_*` (`create` is a reserved word in MySQL).
- `uid` is `text` plus a sha1 `uid_hash`.
- `dateTime` instead of `timestamp` (the 2038 limit).
- `title_input` holds the raw title for re-rendering templates.
- `summary` on occurrences holds per-occurrence titles.
- sabre/vobject is 4.6 (sabre/dav 4.x can't use vobject 5).

**Checked:**
- No placeholders.
- Every class and method that a later task uses is defined in an earlier task. Examples: `OccurrenceService::find` (Task 7) is used in Task 10, `MigrationService::finish` (Task 16) in Task 16's test, and `ReadableBlock` (Task 11) is reused by W3 and W4.
