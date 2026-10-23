# Calendar module v3, W2 (frontend): implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the v3 calendar module its user interface: a FullCalendar 7 calendar page with the event form and recurrence, the "Add to calendar" modal on conversations, the dashboard card, and server-rendered settings pages, on top of the W1 backend.

**Architecture:** The calendar page and the conversation modal are Vue 3 apps written in TypeScript, built with Vite into committed files under `Public/build/`. They talk to the W1 agent JSON API and receive their configuration as JSON in a non-executed script tag. The settings pages are FreeScout-style Blade forms; their POST actions replace W1's admin JSON API, and a small TypeScript bundle enhances them (field builder, select-all, copy buttons). All times are shown and typed in the agent's FreeScout timezone, never the browser's.

**Tech stack:** PHP 8.2+ on FreeScout 1.8.243+ (Laravel 5.5), Vue 3.5.43, FullCalendar 7.1.1 through `@fullcalendar/vue3` (self-contained in v7), TypeScript 6.0.3 with vue-tsc 3.3.12 (vue-tsc does not support TypeScript 7 yet), Vite 8.3.3, Vitest 5.0.3 with jsdom 30.1.2, temporal-polyfill 1.0.5, SortableJS 1.15.7, Playwright 1.63.0 for the end-to-end suite.

**Context:** W1 is complete (`v3` at d408497). Read `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`, sections 6, 10, 11, 12 and 17, before starting. The frontend code in this plan was type-checked, unit-tested and built against these exact package versions, and exercised in a headless browser with a mocked API, while the plan was written.

## Decisions for W2 (taken with Lars on 2026-10-06, recorded in the spec's decision log)

- The settings are server-rendered FreeScout forms plus a little TypeScript, not a Vue app. W1's admin JSON API (`/calendar/api/admin/*`) is replaced by form actions under `/calendar/settings/*`.
- The Connections settings and the screens that add ICS, CalDAV, Google and Microsoft calendars belong to W3, with their backend. W2 only creates local calendars, but edits every calendar.
- The "Dashboard" right stays independent of "View", as in v2. One real install relies on it. The matrix explains it.
- The frontend is TypeScript.
- A stuck migration is resolved from the settings page: every pending v2 item gets a "Skip" button that records its full row in the report. The admin then runs Manage > System > Tools > Migrate DB.

## Deviations from the spec text, decided while planning

- **Locale:** the calendar follows `app()->getLocale()`, the agent's own language, like the rest of the page. `Helper::getRealAppLocale()` returns the install-wide `.env` locale, so it would ignore the agent's profile.
- **Cache busting:** assets use `?v=<file modification time>`, not the module version. A rebuild during development then never serves a stale bundle.
- **Recurring drags between all-day and timed:** dragging an occurrence of a repeating event between the all-day row and the time grid is blocked (`eventAllow`). The form shows the whole rule and handles that change.
- **No page reload after the modal saves:** the conversation modal does not reload the page after saving, because the agent may be typing a reply. It confirms the save, links to the event, and the line item appears the next time the conversation is opened.

## Global constraints

**Platform, identity, data:** everything in W1's Global Constraints still applies. In particular:
- PHP `^8.2`, FreeScout `1.8.243`+, Laravel 5.5 APIs only.
- Module identity never changes: alias `ljpccalendarmodule`, namespace `Modules\LJPcCalendarModule`, view namespace `calendar::`, constant `LJPC_CALENDARS_MODULE`, line-item `action_type` `186`, route name `ljpccalendarmodule.index`.
- UTC `dateTime` columns; never Eloquent date casting for `starts_at`/`ends_at`.
- Query builder or Eloquent only, no raw SQL. PSR-12 for PHP.

**Frontend:**
- Node 22 and npm. This shell sets `NODE_ENV=production` and npm `omit=dev`, so every install is `npm ci --include=dev` (or `npm install --include=dev`), run in `Resources/frontend`.
- Exact versions, pinned without `^`:
  - dependencies: `vue@3.5.43`, `@fullcalendar/vue3@7.1.1`, `temporal-polyfill@1.0.5`, `sortablejs@1.15.7`
  - devDependencies: `vite@8.3.3`, `@vitejs/plugin-vue@6.0.9`, `typescript@6.0.3`, `vue-tsc@3.3.12`, `vitest@5.0.3`, `jsdom@30.1.2`, `@types/sortablejs@1.15.9`; `@playwright/test@1.63.0` and `@types/node@22.20.5` are added in Task 9.
- `package-lock.json` is committed.
- **TypeScript and Vue:**
  - TypeScript runs with `strict`, `noUncheckedIndexedAccess` and `verbatimModuleSyntax`.
  - Vue components are single-file components with `<script setup lang="ts">`.
  - **No `<style>` blocks in components.** Each entry imports one CSS file (`calendar.css`, `conversation.css`, `settings.css`), so the build emits exactly one CSS file per entry.
  - Never `v-html` or `innerHTML` with data. In TypeScript, HTML may only be inserted from a server-rendered `<template>` (the settings field builder).
- **FullCalendar 7:**
  - It is used only through `@fullcalendar/vue3` and its subpaths (`/daygrid`, `/timegrid`, `/list`, `/multimonth`, `/interaction`, `/themes/classic`, `/locales/<code>`), plus `skeleton.css`, `themes/classic/theme.css` and `themes/classic/palette.css`.
  - Its class names are hashed. Style it only through `--fc-classic-*` variables and our own class hooks (`eventClass`, `popoverClass`); never target `.fc-*` class names.
  - Avoid the patterns behind its open regressions: no `weekends: false`, no `setOption('slotMinTime', ...)` on a mounted view, no background events.
- **Time:**
  - Never use the browser's timezone. FullCalendar runs with `timeZone` set to the agent's FreeScout timezone.
  - Form values are sent without an offset (`YYYY-MM-DDTHH:mm`), so the server reads them in that same timezone.
  - Conversions use `temporal-polyfill`.
  - All-day end dates are exclusive in the API and inclusive in the form.
- **Strings:**
  - Every user-visible string in TypeScript goes through `t('English key')` with a single- or double-quoted literal.
  - Every key is listed in `Http/FrontendStrings.php`. `Tests/Unit/FrontendStringsTest.php` fails on any missing or unused key.
  - Blade uses `__()`.
  - All text is in sentence case, with no em dashes and no en dashes in our own copy.
- **Loading bundles:**
  - Bundles load as `<script type="module" src="...">` with `\Helper::cspNonceAttr()`, through `Http/Assets.php`, and never through the `javascripts` filter.
  - Config is passed as `<script type="application/json">` built with `FrontendConfig::json()`.
- **The build:**
  - `Public/build/` is committed and must equal a fresh `npm run build`. CI fails otherwise.
  - The build is deterministic: the same sources give the same file hashes.
- **Look:**
  - The UI must look native to FreeScout. Use Bootstrap 3 markup, FreeScout's glyphicons and FreeScout's palette:
    - primary `#0078d7`
    - heading background `#deecf9`
    - text `#253540`
    - muted `#4f5d6b`
    - borders `#d6dde3`
  - Every screen has loading, empty and error states.
  - Every form control has a label above it, and every error sits directly under its control.

**Process:**
- Conventional commits with a single-line message and no body. Never push `v3`.
- PHP tests: run `ddev exec php artisan config:clear` first, then `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`.
- Frontend checks: run `npm run check` (vue-tsc and Vitest) and `npm run build` in `Resources/frontend`.
- UI work is verified in a real browser against the local ddev FreeScout at https://freescout.ddev.site. Use the Playwright skill or MCP, or the Task 9 suite once it exists.

---

## File structure (end state of W2)

```
Http/
  Assets.php                         script and style tags for Public/build entries (?v=filemtime, CSP nonce)
  CalendarForm.php                   turns the settings form into calendar data; validation; permission sync
  FrontendConfig.php                 JSON config for the calendar page and the conversation modal
  FrontendStrings.php                every frontend string key, translated with __()
  SettingsPage.php                   view data and helpers for Settings > Calendar (list, new, edit, upgrade report)
  Controllers/Settings/CalendarsController.php   form actions: store, update, delete, tokens, skip item
  Controllers/Admin/CalendarsController.php      DELETED (replaced by the form actions)
Policies/CalendarPolicy.php          + viewableCalendars()
Services/
  MigrationService.php               + pendingItems(), skipItem(), report keeps identical entries once
  DashboardCard.php, EventService.php, FieldValues.php, TimeInput.php   small fixes (Task 1), card items (Task 8)
Providers/LJPcCalendarModuleServiceProvider.php  + settings.section_params, conversation.action_buttons, stylesheets
Resources/views/
  index.blade.php                    calendar page shell + assets
  settings.blade.php                 dispatcher for the settings modes + assets
  settings/report.blade.php, list.blade.php, new.blade.php, edit.blade.php, fields.blade.php, field_row.blade.php, permissions.blade.php
  partials/conversation_button.blade.php, dash_card.blade.php, dash_card_event.blade.php
Public/
  build/                             Vite output (committed): calendar.js/.css, conversation.js/.css, settings.js/.css, chunks/
  css/module.css                     the dashboard card (through FreeScout's stylesheets filter)
  css/default.css                    DELETED (unused since W1)
Resources/frontend/
  package.json, package-lock.json, tsconfig.json, vite.config.ts, vitest.config.ts, playwright.config.ts
  src/env.d.ts
  src/shared/   types.ts config.ts i18n.ts storage.ts alerts.ts api.ts time.ts recurrence.ts form-model.ts format.ts
                Modal.vue FieldInput.vue TimeFields.vue shared.css
  src/calendar/ main.ts App.vue CalendarSidebar.vue EventDetails.vue EventForm.vue RecurrenceEditor.vue ScopeDialog.vue
                events.ts locales.ts permalink.ts page-config.ts calendar.css
  src/conversation/ main.ts AddToCalendar.vue prefill.ts conversation-config.ts conversation.css
  src/settings/ main.ts field-builder.ts select-all.ts template-chips.ts copy.ts confirm.ts settings.css
  tests/        settings.test.ts time.test.ts recurrence.test.ts form-model.test.ts shared.test.ts
                calendar-events.test.ts prefill.test.ts                      (Vitest, 40 tests)
  e2e/          helpers.ts calendar.spec.ts recurring.spec.ts permalink.spec.ts conversation.spec.ts
                settings.spec.ts mobile.spec.ts                              (Playwright against ddev)
Tests/
  Feature/SettingsFormTest.php, SettingsPageTest.php, ConversationModalTest.php   (AdminApiTest.php DELETED)
  Unit/FrontendStringsTest.php, AssetsTest.php
  e2e/seed.sh                        prepares the ddev dev database for the Playwright suite
```

---
### Task 0: Restyle the service provider to PSR-12

The service provider is the last WordPress-style file (tabs, spaces inside parentheses, an unused `use Eventy;`); the W1 ledger asked to restyle it the next time it is touched. W2 adds three hooks to it (Tasks 3, 7 and 8), so this comes first and changes nothing else.

**Files:**
- Modify: `Providers/LJPcCalendarModuleServiceProvider.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: the same provider in PSR-12 (4 spaces), so later tasks add their hooks in that style. The file must keep parsing on PHP 7.1 (CI lints it there): no arrow functions, no typed properties.

- [ ] **Step 1: Replace the file**

Replace `Providers/LJPcCalendarModuleServiceProvider.php` with:

```php
<?php

namespace Modules\LJPcCalendarModule\Providers;

use Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\LJPcCalendarModule\Console\RefreshOccurrences;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\DashboardCard;
use Modules\LJPcCalendarModule\Services\LineItemText;

define('LJPC_CALENDARS_MODULE', 'ljpccalendarmodule');

class LJPcCalendarModuleServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public const MIN_PHP_VERSION_ID = 80200;
    public const MIN_APP_VERSION = '1.8.243';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        if (!self::phpSupported(PHP_VERSION_ID)) {
            $this->registerPhpNotice();

            return;
        }
        // FreeScout installs third-party module updates without checking requiredAppVersion.
        if (!\Helper::checkAppVersion(self::MIN_APP_VERSION)) {
            $this->registerAppVersionNotice();

            return;
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        \Illuminate\Support\Facades\Gate::policy(Calendar::class, CalendarPolicy::class);

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

    /**
     * The v3 migration calls this before it changes anything. Throwing (not returning) keeps the migration unrecorded,
     * so nothing is converted and the next migrate after the PHP or FreeScout upgrade does the work.
     * Callers pass PHP_VERSION_ID and \Helper::checkAppVersion(self::MIN_APP_VERSION).
     */
    public static function assertPlatformSupported(int $phpVersionId, bool $appVersionSupported): void
    {
        if (!self::phpSupported($phpVersionId)) {
            throw new \RuntimeException(__('The calendar module needs PHP 8.2 or newer. This server runs PHP :version, so the calendar upgrade did not start.', ['version' => PHP_VERSION]));
        }
        if (!$appVersionSupported) {
            throw new \RuntimeException(__('The calendar module needs FreeScout 1.8.243 or newer. This server runs FreeScout :version, so the calendar upgrade did not start.', ['version' => (string) Config::get('app.version')]));
        }
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

    /** Admins see why the module is paused on a FreeScout version older than the module needs. */
    private function registerAppVersionNotice(): void
    {
        \Eventy::addFilter('dashboard.before', function ($html = '') {
            $user = auth()->user();
            if (!$user || !$user->isAdmin()) {
                return $html;
            }

            return $html . '<div class="alert alert-danger">' . e(__('The calendar module needs FreeScout 1.8.243 or newer. This server runs FreeScout :version, so the module is paused.', ['version' => (string) Config::get('app.version')])) . '</div>';
        });
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('ljpccalendarmodule.php'),
        ], 'config');
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'ljpccalendarmodule');
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/ljpccalendarmodule');

        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/ljpccalendarmodule';
        }, Config::get('view.paths')), [$sourcePath]), 'calendar');
    }

    /**
     * Artisan commands
     */
    public function registerCommands()
    {
        $this->commands([RefreshOccurrences::class]);
    }

    /**
     * Module hooks.
     */
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
        // FreeScout answers 404 for a section whose settings array is empty; the shell has no stored settings
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            return $section === 'calendar' ? ['calendar' => true] : $settings;
        }, 20, 2);
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

        \Eventy::addFilter('thread.action_text', function ($didThis, $thread, $conversationNumber, $escape, $viewedByUser = null) {
            if ((int) $thread->action_type !== CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR) {
                return $didThis;
            }
            try {
                return LineItemText::render($thread, (bool) $escape, $viewedByUser instanceof \App\User ? $viewedByUser : null);
            } catch (\Throwable $e) {
                report($e); // logged with trace; conversation pages and notification emails must keep rendering

                // Core has no text of its own for this action type ($didThis is empty), so a neutral line stands in.
                return __('Added to a calendar.');
            }
        }, 20, 5);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ . '/../Resources/lang');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
```

- [ ] **Step 2: Prove that only whitespace and the unused import changed**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
diff <(git show HEAD:Providers/LJPcCalendarModuleServiceProvider.php | tr -d ' \t\n' | sed 's/useEventy;//') <(tr -d ' \t\n' < Providers/LJPcCalendarModuleServiceProvider.php) && echo same
docker run --rm -v "$PWD":/module:ro -w /module php:7.1-cli php -l Providers/LJPcCalendarModuleServiceProvider.php
```

Expected: `same`, then `No syntax errors detected`.

- [ ] **Step 3: Run the suite**

Run: `ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK` (192 tests, as at the end of W1).

- [ ] **Step 4: Commit**

```bash
git add Providers/LJPcCalendarModuleServiceProvider.php
git commit -m "style: restyle the service provider to PSR-12"
```

---

### Task 1: Agent API and validation follow-ups

These are the W1 review items the frontend depends on, from the progress ledger's "W2 carry-over" and M9 lists.

**Files:**
- Modify: `Policies/CalendarPolicy.php` (add `viewableCalendars()`)
- Modify: `Http/EventPresenter.php` (`calendar()`: `sync_failed` replaces `last_error`)
- Modify: `Http/Controllers/Api/CalendarsController.php`, `Http/Controllers/Api/EventsController.php` (one calendars query; no `ics` column in the list)
- Modify: `Services/EventService.php` (switching all-day needs a start)
- Modify: `Services/TimeInput.php` (strict all-day dates; end-before-start wording)
- Modify: `Services/FieldValues.php` (text/source reject booleans; multiselect deduplicated)
- Modify: `Services/DashboardCard.php` (order by real start; no `ics` column)
- Test: `Tests/Feature/AgentApiTest.php`, `Tests/Unit/EventServiceTest.php`, `Tests/Unit/TimeInputTest.php`, `Tests/Unit/FieldValuesTest.php`, `Tests/Feature/IntegrationHooksTest.php`

**Interfaces:**
- Consumes: W1 `CalendarPolicy::rights()`, `EventPresenter`, `OccurrenceService::between()`.
- Produces:
  - `CalendarPolicy::viewableCalendars(User $user): \Illuminate\Database\Eloquent\Collection`: enabled calendars the user can view, ordered by `display_priority` then `name`, with `permissionRows` loaded.
  - Agent calendar shape (`GET /calendar/api/calendars`): `{id, name, color, provider, display_priority, fields, has_title_template, rights, writable, sync_failed: bool}`. `last_error` is no longer sent to agents.

- [ ] **Step 1: Write the failing tests**

Add to `Tests/Feature/AgentApiTest.php` (class `AgentApiTest`):

```php
    public function test_calendar_list_says_whether_sync_failed_without_the_error_text(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true], ['last_error' => 'HTTP 401 from https://user:secret@dav.example.test']);

        $content = $this->actingAs($user)->json('GET', '/calendar/api/calendars')->assertStatus(200)->getContent();
        $listed = collect(json_decode($content, true))->firstWhere('id', $calendar->id);

        $this->assertTrue($listed['sync_failed']);
        $this->assertArrayNotHasKey('last_error', $listed);
        $this->assertStringNotContainsString('secret', $content);
    }

    public function test_event_list_never_loads_the_icalendar_text(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarFor($user, ['can_view' => true, 'can_create' => true]);
        $this->eventIn($calendar, $user);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $rows = json_decode($this->actingAs($user)->json('GET', '/calendar/api/events?start=2026-10-19&end=2026-10-26')->assertStatus(200)->getContent(), true);
        $queries = array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query');
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame('Seed', $rows[0]['title']);
        foreach ($queries as $query) {
            if (preg_match('/from [`"]?\w*calendar_events[`"]?\s/i', $query)) {
                $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $query, 'The event list must select only the columns it shows.');
            }
        }
    }
```

Add to `Tests/Unit/EventServiceTest.php` (class `EventServiceTest`):

```php
    public function test_switching_all_day_without_a_start_is_rejected(): void
    {
        $user = $this->makeUser();
        $event = $this->service->create($this->calendar, $user, ['title' => 'Call', 'start' => '2026-10-20T10:00']);

        try {
            $this->service->update($event, $user, ['all_day' => true]);
            $this->fail('Accepted all_day without a start');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start', $e->errors());
        }
        $this->assertFalse((bool) $event->fresh()->all_day);
    }
```

Add to `Tests/Unit/TimeInputTest.php` (class `TimeInputTest`):

```php
    public function test_all_day_values_are_a_date_or_a_date_with_a_valid_time(): void
    {
        foreach (['2026-10-05xyz', '2026-10-05 10:00', '2026-10-05T', '2026-10-05T25'] as $value) {
            try {
                TimeInput::normalize(true, $value, null, 'Europe/Amsterdam');
                $this->fail('Accepted ' . $value);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('start', $e->errors());
            }
        }
        $this->assertSame('2026-10-05', TimeInput::normalize(true, '2026-10-05T00:00:00Z', null, 'UTC')['start']);
    }

    public function test_a_timed_end_before_the_start_says_so(): void
    {
        try {
            TimeInput::normalize(false, '2026-10-05T10:00', '2026-10-05T09:00', 'UTC');
            $this->fail('Accepted an end before the start');
        } catch (ValidationException $e) {
            $this->assertSame([__('The end can\'t be before the start.')], $e->errors()['end']);
        }
        $this->assertSame('2026-10-05 10:00', TimeInput::normalize(false, '2026-10-05T10:00', '2026-10-05T10:00', 'UTC')['end']->format('Y-m-d H:i'));
    }
```

Add to `Tests/Unit/FieldValuesTest.php` (class `FieldValuesTest`):

```php
    public function test_text_rejects_booleans_and_multiselect_values_are_deduplicated(): void
    {
        $this->rejects($this->one('text'), true);
        $this->rejects($this->one('source'), false);
        $this->assertSame(['1' => ['A', 'B']], FieldValues::validate($this->one('multiselect', ['A', 'B']), ['1' => ['A', 'B', 'A']]));
    }
```

Add to `Tests/Feature/IntegrationHooksTest.php` (class `IntegrationHooksTest`):

```php
    public function test_dashboard_card_orders_all_day_and_timed_events_by_their_real_start(): void
    {
        // In Auckland (UTC+13 in October) an all-day event of tomorrow starts 11:00 UTC today, while 01:00 tomorrow is
        // 12:00 UTC today. Comparing the stored strings ("tomorrow 00:00" against "today 12:00") would flip them.
        $user = $this->makeUser(['timezone' => 'Pacific/Auckland']);
        $calendar = Calendar::create(['name' => 'Order ' . uniqid(), 'provider' => 'local']);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'show_in_dashboard' => true, 'can_view' => true]);
        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Pacific/Auckland')))->format('Y-m-d');
        app(EventService::class)->create($calendar, $user, ['title' => 'Early tomorrow', 'start' => $tomorrow . 'T01:00']);
        app(EventService::class)->create($calendar, $user, ['title' => 'All day tomorrow', 'all_day' => true, 'start' => $tomorrow]);

        $html = app(DashboardCard::class)->render($user);

        $this->assertNotFalse(strpos($html, 'Early tomorrow'));
        $this->assertLessThan(strpos($html, 'Early tomorrow'), strpos($html, 'All day tomorrow'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "sync_failed|never_loads_the_icalendar|switching_all_day_without|all_day_values_are_a_date|timed_end_before|reject_booleans|by_their_real_start"'`
Expected: 7 failures (unknown `sync_failed` key; `select *` on calendar_events; no exception for `all_day` alone; garbage dates accepted; old message; booleans accepted and duplicates kept; the timed event listed first).

- [ ] **Step 3: Implement**

`Policies/CalendarPolicy.php`: replace `viewableCalendarIds()` with these two methods:

```php
    /** Enabled calendars the user can view, in display order, with their permission rows loaded. */
    public static function viewableCalendars(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Calendar::where('enabled', true)->with('permissionRows')->orderBy('display_priority')->orderBy('name')->get()
            ->filter(function (Calendar $calendar) use ($user) {
                return self::rights($user, $calendar)['can_view'];
            })
            ->values();
    }

    public static function viewableCalendarIds(User $user): array
    {
        return self::viewableCalendars($user)->pluck('id')->all();
    }
```

`Http/EventPresenter.php`, in `calendar()`: replace the line `'last_error'         => $calendar->last_error,` with:

```php
            // Agents only learn that a sync failed; the error text can hold provider details (W3 scrubs it for admins).
            'sync_failed'        => trim((string) $calendar->last_error) !== '',
```

`Http/Controllers/Api/CalendarsController.php`: replace the body of `index()` with:

```php
        $user = $request->user();

        return response()->json(CalendarPolicy::viewableCalendars($user)->map(function (Calendar $calendar) use ($user) {
            return EventPresenter::calendar($calendar, $user);
        })->values());
```

`Http/Controllers/Api/EventsController.php`, in `index()`: replace the three consecutive lines that build `$calendars`, `$rows` and `$events` with:

```php
        $calendars = CalendarPolicy::viewableCalendars($user)->keyBy('id');
        $rows = $this->occurrences->between($calendars->keys()->all(), $from, $to, $timezone);
        // The list shows titles and flags only; loading every event's VCALENDAR would multiply the response time.
        $events = CalendarEvent::whereIn('id', array_unique(array_column($rows, 'event_id')))
            ->get(['id', 'calendar_id', 'summary', 'is_recurring', 'read_only'])
            ->keyBy('id');
```

`Services/EventService.php`: at the very start of `update()`, before `$occurrenceScope = ...`, add:

```php
        if (array_key_exists('all_day', $input) && !array_key_exists('start', $input)) {
            throw ValidationException::withMessages(['start' => __('Send the start and the end when switching between all-day and timed.')]);
        }
```

`Services/TimeInput.php`:

1. In `normalize()`, replace the timed end check's message:

```php
        if ($endTime < $startTime) {
            throw ValidationException::withMessages(['end' => __('The end can\'t be before the start.')]);
        }
```

2. Replace `date()` with:

```php
    private static function date(string $value, string $key): DateTimeImmutable
    {
        // A date, optionally followed by the time an ISO client sends along; all-day events ignore that time.
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value, $match)) {
            throw ValidationException::withMessages([$key => __('Invalid date.')]);
        }
        $day = $match[1];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day || !self::yearInRange((int) substr($day, 0, 4))) {
            throw ValidationException::withMessages([$key => __('Invalid date.')]);
        }

        return $date;
    }
```

`Services/FieldValues.php`, in `cast()`:

1. In the `multiselect` case, replace `return array_diff($list, $options) ? null : array_values($list);` with:

```php
                return array_diff($list, $options) ? null : array_values(array_unique($list));
```

2. Replace the `default` case with:

```php
            default: // text, source
                return is_scalar($value) && !is_bool($value) && mb_strlen((string) $value) <= 2000 ? (string) $value : null;
```

`Services/DashboardCard.php`:

1. Replace the `usort(...)` call with:

```php
        usort($rows, function (array $a, array $b) use ($tz) {
            return self::startsAt($a, $tz) <=> self::startsAt($b, $tz);
        });
```

2. Replace `$events = CalendarEvent::whereIn('id', array_column($rows, 'event_id'))->get()->keyBy('id');` with:

```php
        $events = CalendarEvent::whereIn('id', array_column($rows, 'event_id'))->get(['id', 'summary'])->keyBy('id');
```

3. Add this method to the class:

```php
    /** All-day rows hold a local date at 00:00 and timed rows a UTC time; both become a moment to compare. */
    private static function startsAt(array $row, DateTimeZone $tz): int
    {
        return $row['all_day']
            ? (new DateTimeImmutable(substr($row['starts_at'], 0, 10), $tz))->getTimestamp()
            : (new DateTimeImmutable($row['starts_at'], new DateTimeZone('UTC')))->getTimestamp();
    }
```

- [ ] **Step 4: Run the new tests, then the whole suite**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "sync_failed|never_loads_the_icalendar|switching_all_day_without|all_day_values_are_a_date|timed_end_before|reject_booleans|by_their_real_start"'`
Expected: 7 passing tests.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`. If an existing test asserted `last_error` on the agent calendar shape or the old end message, update that assertion to the new contract. That is the only kind of existing test change allowed here.

- [ ] **Step 5: Commit**

```bash
git add Policies/CalendarPolicy.php Http/EventPresenter.php Http/Controllers/Api/CalendarsController.php Http/Controllers/Api/EventsController.php Services/EventService.php Services/TimeInput.php Services/FieldValues.php Services/DashboardCard.php Tests
git commit -m "fix: tighten the agent API inputs and stop sending sync errors and iCalendar text to the event list"
```

---

### Task 2: Migration report: deduplication, pending items and skipping by an admin

The settings page (Task 4) shows the upgrade report and the v2 items that still block the upgrade, with a "Skip" button for each item (Task 3 adds the action). The report also stops growing on every retry.

**Files:**
- Modify: `Services/MigrationService.php`
- Test: `Tests/Unit/MigrationItemsTest.php`

**Interfaces:**
- Consumes: W1 `MigrationService` internals: `log()`, `flush()`, `fullItemContext()`, `display()`, `skippedItemIds()`, `SKIPPED_OPTION`, `REPORT_OPTION`.
- Produces:
  - `MigrationService::pendingItems(int $limit = 200): array`: a list of `['id' => int, 'title' => string, 'start' => string, 'calendar' => ?string]`. It holds the v2 items that are neither converted nor skipped, oldest id first, and returns `[]` once `calendar_items` is gone.
  - `MigrationService::skipItem(int $id, string $skippedBy): void`: reports the item as a warning with its full row plus `user`, then adds it to the skipped list. It throws a `ValidationException` with key `item` when the item is converted, already skipped or missing.
  - The report keeps identical entries (same level, message and context) once.

- [ ] **Step 1: Write the failing tests**

Add to `Tests/Unit/MigrationItemsTest.php` (class `MigrationItemsTest`):

```php
    public function test_identical_report_entries_are_kept_once(): void
    {
        $service = app(MigrationService::class);
        $service->convertItems();
        $entries = count($service->report());

        $service->convertItems();

        $this->assertSame($entries, count($service->report()));
    }

    public function test_pending_items_are_the_ones_that_still_block_the_upgrade(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $this->item(['title' => 'Survives', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());

        $service->convertItems();

        $pending = $service->pendingItems();
        $this->assertSame([$failing], array_column($pending, 'id'));
        $this->assertSame(['Explodes', '2026-10-20 08:00:00', $this->calendar->name], [$pending[0]['title'], $pending[0]['start'], $pending[0]['calendar']]);
    }

    public function test_an_admin_can_skip_a_pending_item_which_is_then_reported_with_its_full_row(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'location' => 'Room 4', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());
        $service->convertItems();
        $baseline = count($service->report());

        $service->skipItem($failing, 'Lars Admin');

        $this->assertSame([$failing], $service->skippedItemIds());
        $this->assertSame([], $service->pendingItems());
        $entries = $this->entriesFor($service, $baseline, 'Explodes');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertSame(['Lars Admin', 'Room 4'], [$entries[0]['context']['user'], $entries[0]['context']['location']]);
        $this->assertTrue($service->isComplete());

        foreach ([$failing, 99999999] as $id) {
            try {
                $service->skipItem($id, 'Lars Admin');
                $this->fail('Skipped item ' . $id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('item', $e->errors());
            }
        }
    }

    public function test_a_skip_whose_report_write_fails_is_not_recorded(): void
    {
        $failing = $this->item(['title' => 'Explodes', 'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00']);
        $service = new MigrationService($this->explodingOccurrences());
        $service->convertItems();
        \App\Option::saving(function (\App\Option $option) {
            if ($option->name === MigrationService::REPORT_OPTION) {
                throw new \RuntimeException('report write failed');
            }
        });
        try {
            $service->skipItem($failing, 'Lars Admin');
            $this->fail('The failing report write should have stopped the skip.');
        } catch (\RuntimeException $e) {
            $this->assertSame('report write failed', $e->getMessage());
        } finally {
            app('events')->forget('eloquent.saving: ' . \App\Option::class);
        }

        $this->assertNotContains($failing, app(MigrationService::class)->skippedItemIds());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "kept_once|still_block_the_upgrade|admin_can_skip|skip_whose_report_write_fails_is_not_recorded"'`
Expected: 4 failures (a duplicate "0 events were converted" entry; undefined methods `pendingItems` and `skipItem`).

- [ ] **Step 3: Implement**

In `Services/MigrationService.php`:

1. Add `use Illuminate\Validation\ValidationException;` to the imports.

2. Add these two public methods after `skippedItemIds()`:

```php
    /** v2 items that still block the upgrade: neither converted nor skipped. At most $limit, oldest id first. */
    public function pendingItems(int $limit = 200): array
    {
        if (!Schema::hasTable('calendar_items')) {
            return [];
        }
        $done = array_flip(array_merge(
            array_map('strval', CalendarEvent::whereNotNull('legacy_id')->pluck('legacy_id')->all()),
            array_map('strval', $this->skippedItemIds())
        ));
        $names = DB::table('calendars')->pluck('name', 'id')->all();
        $pending = [];
        foreach (DB::table('calendar_items')->orderBy('id')->get(['id', 'calendar_id', 'title', 'start']) as $item) {
            if (isset($done[(string) $item->id])) {
                continue;
            }
            $pending[] = [
                'id'       => (int) $item->id,
                'title'    => self::display($item->title),
                'start'    => self::display($item->start),
                'calendar' => isset($names[$item->calendar_id]) ? (string) $names[$item->calendar_id] : null,
            ];
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * The escape hatch for an item that keeps failing: it is reported with its full row, so an admin can recreate
     * it by hand, and then counts as handled, like the permanent skips in convertItems().
     */
    public function skipItem(int $id, string $skippedBy): void
    {
        $item = Schema::hasTable('calendar_items') ? DB::table('calendar_items')->where('id', $id)->first() : null;
        if (!$item || in_array($id, $this->skippedItemIds(), true) || CalendarEvent::where('legacy_id', (string) $id)->exists()) {
            throw ValidationException::withMessages(['item' => __('This event can\'t be skipped: it was converted or skipped already, or it no longer exists.')]);
        }
        $this->log('warning', 'Event ":title" (#:id) was skipped by :user and not converted; it is listed here with its data.', self::fullItemContext($item) + ['user' => $skippedBy]);
        // Report first: a crash between the two writes leaves the item pending and reported, never skipped unreported.
        $this->flush();
        Option::set(self::SKIPPED_OPTION, array_values(array_unique(array_merge($this->skippedItemIds(), [$id]))));
    }
```

3. Replace `flush()` with this version, and add `entryKey()` below it:

```php
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        // Identical entries (the same retry error, "0 events were converted") are kept once, so retries don't grow the report.
        $report = $this->report();
        $seen = [];
        foreach ($report as $entry) {
            $seen[self::entryKey($entry)] = true;
        }
        foreach ($this->buffer as $entry) {
            $key = self::entryKey($entry);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $report[] = $entry;
            }
        }
        Option::set(self::REPORT_OPTION, $report);
        $this->buffer = [];
    }

    private static function entryKey(array $entry): string
    {
        return (string) json_encode([$entry['level'] ?? '', $entry['message'] ?? '', $entry['context'] ?? []]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass, then the migration tests and the rehearsal**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "Migration"'`
Expected: `OK`. All migration tests pass, the four new ones included.

Run the migration rehearsal on the three masked dumps, exactly as in `CLAUDE.md` > "Tests and migration rehearsal":
- Copy each dump with `install -m 600` and capture the output with `2>&1` into the private folder.
- `rm -f` the copy right after each run.
- Drop `freescout_rehearsal` at the end.

Expected per dump:
- `finished` is true
- calendars 8, 7, 6
- `events + skipped` 114, 598, 5
- `timed events with a changed start: 0`
- no ERROR lines

Report the counts only, never data from the dumps.

- [ ] **Step 5: Commit**

```bash
git add Services/MigrationService.php Tests/Unit/MigrationItemsTest.php
git commit -m "feat: list pending v2 items, let admins skip them, and keep identical report entries once"
```

---

### Task 3: Settings pages and their form actions (replace the admin JSON API)

Settings > Calendar becomes server-rendered FreeScout forms. Their actions replace W1's `/calendar/api/admin/*` endpoints and their test. The validation rules of W1 stay, and only the input shape changes to what an HTML form sends. Task 4 adds the small script that enhances these pages; everything here works without JavaScript.

**Files:**
- Create: `Http/CalendarForm.php`
- Create: `Http/SettingsPage.php`
- Create: `Http/Controllers/Settings/CalendarsController.php`
- Delete: `Http/Controllers/Admin/CalendarsController.php`
- Modify: `Http/routes.php`
- Modify: `Http/FrontendConfig.php` (remove `settings()`, which only the JSON settings shell used)
- Modify: `Providers/LJPcCalendarModuleServiceProvider.php` (`settings.section_params` filter)
- Modify: `Resources/views/settings.blade.php`
- Create: `Resources/views/settings/report.blade.php`, `list.blade.php`, `new.blade.php`, `edit.blade.php`, `fields.blade.php`, `field_row.blade.php`, `permissions.blade.php`
- Delete: `Tests/Feature/AdminApiTest.php`
- Create: `Tests/Feature/SettingsFormTest.php`, `Tests/Feature/SettingsPageTest.php`
- Modify: `Tests/Feature/IntegrationHooksTest.php` (the two settings tests)

**Interfaces:**
- Consumes: `MigrationService::skipItem()` (Task 2), `CalendarPolicy::team()`, `FieldValues::TYPES`, `TitleTemplate::fieldIds()`, `Calendar::newToken()`.
- Produces:
  - Named routes, all `POST`, admin only:
    - `ljpccalendarmodule.settings.calendars.store`: `/calendar/settings/calendars`
    - `ljpccalendarmodule.settings.calendars.update`: `/calendar/settings/calendars/{id}`
    - `ljpccalendarmodule.settings.calendars.delete`: `/calendar/settings/calendars/{id}/delete`
    - `ljpccalendarmodule.settings.calendars.tokens`: `/calendar/settings/calendars/{id}/tokens`, with `which=full|busy`
    - `ljpccalendarmodule.settings.migration.skip`: `/calendar/settings/migration/items/{id}/skip`
  - `SettingsPage::url($calendar = null): string`. It returns `route('settings', ['section' => 'calendar'])`, plus `?calendar=<id or "new">` when an argument is given.
  - `SettingsPage::vars(Request): array`. It supplies the section's template variables: `mode` (`list|new|edit`), `migration`, plus `calendars` and `missing` (list mode) or `calendar`, `subjects` and `rights` (edit mode).
  - The markup hooks Task 4's script uses:
    - field builder: `data-ljpc-fields`, `data-ljpc-field-rows`, `data-ljpc-field-row`, `data-ljpc-field-type`, `data-ljpc-field-options`, `data-ljpc-field-remove`, `template[data-ljpc-field-template]` with `__KEY__`, `data-ljpc-add-field` (rendered `hidden`)
    - select-all: `data-ljpc-select-all="<type>:<right>"` (rendered `hidden`), `data-ljpc-right="<type>:<right>"`
    - title template: `data-ljpc-template-input`, `data-ljpc-insert="{{...}}"`, `data-ljpc-template-preview`
    - copy buttons: `data-ljpc-copy="<input id>"` with `data-ljpc-copied`
    - confirmation: `form[data-ljpc-confirm]`
    - root: `.ljpc-settings`
  - **The form contract**, which Task 4 renders:
    - General fields: `name`, `color` (`#rrggbb`), `enabled` (a hidden `0` followed by a checkbox `1`), `display_priority` (0 to 9999), `timezone` (an IANA identifier, or empty for the default), `default_reminder_minutes` (0 to 40320, or empty for none), `title_template`.
    - Field rows: `fields[<row key>][id|name|type|required|options|remove]`. `options` is newline-separated. Rows are processed in posted order. A row with a ticked `remove` is dropped. A row without an id, a name and options is ignored.
    - Permission checkboxes: `permissions[user|team|mailbox][<id>][show_in_dashboard|can_view|can_create|can_edit]=1`.
    - `form_complete=1`, as the very last field of the form.
  - Error keys:
    - `name`, `color`, `display_priority`, `timezone`, `default_reminder_minutes`, `title_template`
    - `fields.<row key>.name`, `fields.<row key>.options`
    - `permissions.<type>.<id>`
    - `form` (the form was cut off), `calendar` (the calendar was not upgraded yet), `item` (for skip)
  - Flash messages use FreeScout's `flash_success_floating`, with static strings only, because that key is rendered as raw HTML.

- [ ] **Step 1: Write the failing tests**

Create `Tests/Feature/SettingsFormTest.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SettingsFormTest extends TestCase
{
    /** What the edit form posts for a calendar, with one new dropdown field and no permissions. */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name'                     => 'Team agenda ' . uniqid(),
            'color'                    => '#3498db',
            'enabled'                  => '1',
            'display_priority'         => '100',
            'timezone'                 => '',
            'default_reminder_minutes' => '',
            'title_template'           => '',
            'fields'                   => ['new0' => ['id' => '', 'name' => 'Kind', 'type' => 'dropdown', 'options' => "Call\nVisit", 'required' => '1']],
            'form_complete'            => '1',
        ];
    }

    private function local(array $attributes = []): Calendar
    {
        return Calendar::create($attributes + ['name' => 'Cal ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
    }

    private function update(Calendar $calendar): string
    {
        return route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]);
    }

    public function test_agents_cannot_use_the_settings_actions(): void
    {
        $user = $this->makeUser();
        $calendar = $this->local();
        $id = ['id' => $calendar->id];

        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.store'), ['name' => 'X ' . uniqid(), 'color' => '#000000'])->assertStatus(403);
        $this->actingAs($user)->post($this->update($calendar), $this->form(['name' => 'Hacked']))->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.tokens', $id), ['which' => 'full'])->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.calendars.delete', $id))->assertStatus(403);
        $this->actingAs($user)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => 1]))->assertStatus(403);

        $this->assertSame($calendar->name, $calendar->fresh()->name);
    }

    public function test_creates_a_local_calendar_and_opens_its_settings(): void
    {
        $suffix = uniqid();

        $response = $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.store'), ['name' => '<b>Sales</b> ' . $suffix, 'color' => '#e74c3c']);

        $calendar = Calendar::where('name', 'Sales ' . $suffix)->firstOrFail();
        $response->assertRedirect(SettingsPage::url($calendar->id));
        $this->assertSame([Calendar::PROVIDER_LOCAL, '#e74c3c'], [$calendar->provider, $calendar->color]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $calendar->export_token);
    }

    public function test_store_rejects_taken_empty_and_unsafe_values(): void
    {
        $admin = $this->makeAdmin();
        $this->local(['name' => 'Taken X']);
        $before = Calendar::count();

        foreach ([
            ['name', ['name' => '<b>Taken X</b>', 'color' => '#000000']],
            ['name', ['name' => '<i></i>  ', 'color' => '#000000']],
            ['color', ['name' => 'Fine ' . uniqid(), 'color' => 'red; background:url(x)']],
        ] as [$key, $input]) {
            $this->actingAs($admin)->from(SettingsPage::url('new'))->post(route('ljpccalendarmodule.settings.calendars.store'), $input)
                ->assertRedirect(SettingsPage::url('new'))
                ->assertSessionHasErrors([$key]);
        }
        $this->assertSame($before, Calendar::count());
    }

    public function test_update_saves_settings_fields_and_permissions(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeUser();
        $mailbox = $this->makeMailbox();
        $calendar = $this->local(['fields' => [
            ['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []],
            ['id' => 172604180331, 'name' => 'Old', 'type' => 'text', 'required' => false, 'options' => []],
        ]]);

        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $this->form([
            'enabled'                  => '0',
            'display_priority'         => '5',
            'timezone'                 => 'Europe/Amsterdam',
            'default_reminder_minutes' => '30',
            'title_template'           => '{{title}} ({{field:172604180330}})',
            'fields'                   => [
                'f172604180330' => ['id' => '172604180330', 'name' => 'Type', 'type' => 'dropdown', 'options' => "Call\n<b>Visit</b>\nCall\n"],
                'f172604180331' => ['id' => '172604180331', 'name' => 'Old', 'type' => 'text', 'remove' => '1'],
                'new0'          => ['id' => '', 'name' => 'Paid', 'type' => 'boolean', 'required' => '1'],
                'new1'          => ['id' => '', 'name' => '', 'type' => 'text', 'options' => ''],
            ],
            'permissions'              => [
                'user'    => [$agent->id => ['can_view' => '1', 'can_create' => '1']],
                'mailbox' => [$mailbox->id => ['show_in_dashboard' => '1']],
            ],
        ]))->assertRedirect(SettingsPage::url($calendar->id))->assertSessionMissing('errors');

        $calendar = $calendar->fresh();
        $this->assertSame([false, 5, 'Europe/Amsterdam', 30], [$calendar->enabled, $calendar->display_priority, $calendar->timezone, $calendar->default_reminder_minutes]);
        $this->assertSame('{{title}} ({{field:172604180330}})', $calendar->title_template);
        $this->assertCount(2, $calendar->fields);
        $this->assertSame([172604180330, 'Type', 'dropdown', ['Call', 'Visit']], [$calendar->fields[0]['id'], $calendar->fields[0]['name'], $calendar->fields[0]['type'], $calendar->fields[0]['options']]);
        $this->assertSame(['Paid', 'boolean', true, []], [$calendar->fields[1]['name'], $calendar->fields[1]['type'], $calendar->fields[1]['required'], $calendar->fields[1]['options']]);
        $rows = CalendarPermission::where('calendar_id', $calendar->id)->orderBy('subject_type')->get()->map(function (CalendarPermission $row) {
            return [$row->subject_type, (int) $row->subject_id, (bool) $row->show_in_dashboard, (bool) $row->can_view, (bool) $row->can_create];
        })->all();
        $this->assertSame([['mailbox', $mailbox->id, true, false, false], ['user', $agent->id, false, true, true]], $rows);
    }

    public function test_update_validation_reports_each_problem_and_saves_nothing(): void
    {
        $admin = $this->makeAdmin();
        $deleted = $this->makeUser(['status' => \App\User::STATUS_DELETED]);
        $agent = $this->makeUser();
        $calendar = $this->local(['fields' => [['id' => 172604180330, 'name' => 'Kind', 'type' => 'text', 'required' => false, 'options' => []]]]);

        foreach ([
            'name'                            => ['name' => '<b></b>'],
            'color'                           => ['color' => '#12345g'],
            'timezone'                        => ['timezone' => 'Mars/Olympus'],
            'display_priority'                => ['display_priority' => '-1'],
            'title_template'                  => ['title_template' => '{{field:999999}}'],
            'fields.k.options'                => ['fields' => ['k' => ['id' => '', 'name' => 'Tags', 'type' => 'multiselect', 'options' => '']]],
            'fields.k.name'                   => ['fields' => ['k' => ['id' => '172604180330', 'name' => '<i></i>', 'type' => 'text']]],
            'permissions.user.' . $deleted->id => ['permissions' => ['user' => [$deleted->id => ['can_view' => '1']]]],
            'permissions.team.' . $agent->id  => ['permissions' => ['team' => [$agent->id => ['can_view' => '1']]]],
        ] as $key => $overrides) {
            $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $this->form($overrides))
                ->assertRedirect(SettingsPage::url($calendar->id))
                ->assertSessionHasErrors([$key]);
        }

        $fresh = $calendar->fresh();
        $this->assertSame([$calendar->name, 'Kind'], [$fresh->name, $fresh->fields[0]['name']]);
        $this->assertSame(0, CalendarPermission::where('calendar_id', $calendar->id)->count());
    }

    public function test_a_form_cut_off_by_max_input_vars_is_refused(): void
    {
        $calendar = $this->local();
        $form = $this->form(['name' => 'Changed ' . uniqid()]);
        unset($form['form_complete']);

        $this->actingAs($this->makeAdmin())->from(SettingsPage::url($calendar->id))->post($this->update($calendar), $form)->assertSessionHasErrors(['form']);

        $this->assertSame($calendar->name, $calendar->fresh()->name);
    }

    public function test_new_duplicate_small_zero_padded_and_malformed_field_ids_get_unique_ids(): void
    {
        $calendar = $this->local();

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['fields' => [
            'a' => ['id' => '5', 'name' => 'A', 'type' => 'text'],
            'b' => ['id' => '172604180330', 'name' => 'B', 'type' => 'text'],
            'c' => ['id' => '0172604180330', 'name' => 'C', 'type' => 'text'],
            'd' => ['id' => '1234567890123456789012', 'name' => 'D', 'type' => 'text'],
            'e' => ['id' => 'bad id!', 'name' => 'E', 'type' => 'text'],
        ]]))->assertSessionMissing('errors');

        $ids = array_column($calendar->fresh()->fields, 'id');
        $this->assertCount(5, array_unique($ids));
        $this->assertSame(172604180330, $ids[1]);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertGreaterThanOrEqual(1000, $id);
        }
    }

    public function test_editing_an_external_calendar_keeps_its_provider(): void
    {
        $calendar = $this->local(['provider' => Calendar::PROVIDER_CALDAV]);

        $this->actingAs($this->makeAdmin())->post($this->update($calendar), $this->form(['provider' => Calendar::PROVIDER_LOCAL]))->assertSessionMissing('errors');

        $this->assertSame(Calendar::PROVIDER_CALDAV, $calendar->fresh()->provider);
    }

    public function test_regenerating_a_token_invalidates_the_old_url(): void
    {
        $calendar = $this->local();
        $old = '/calendar/' . $calendar->id . '/export/' . $calendar->export_token . '.ics';
        $this->get($old)->assertStatus(200);

        $this->actingAs($this->makeAdmin())->post(route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $calendar->id]), ['which' => 'full'])
            ->assertRedirect(SettingsPage::url($calendar->id));

        $this->get($old)->assertStatus(404);
        $this->get('/calendar/' . $calendar->id . '/export/' . $calendar->fresh()->export_token . '.ics')->assertStatus(200);
    }

    public function test_a_calendar_the_upgrade_has_not_converted_can_only_be_deleted(): void
    {
        $admin = $this->makeAdmin();
        $stuck = $this->local(['name' => 'Stuck ' . uniqid()]);
        // export_token is the conversion's done-marker: setting it would stop the upgrade from retrying this calendar.
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        foreach (['full', 'busy'] as $which) {
            $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $stuck->id]), ['which' => $which])->assertSessionHasErrors(['calendar']);
        }
        $this->actingAs($admin)->post($this->update($stuck), $this->form(['name' => 'Renamed ' . uniqid()]))->assertSessionHasErrors(['calendar']);
        $row = DB::table('calendars')->where('id', $stuck->id)->first();
        $this->assertSame([null, null, $stuck->name], [$row->export_token, $row->export_busy_token, $row->name]);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.delete', ['id' => $stuck->id]))->assertRedirect(SettingsPage::url());
        $this->assertNull(Calendar::find($stuck->id));
    }

    public function test_deleting_a_calendar_removes_its_events_and_occurrences(): void
    {
        $admin = $this->makeAdmin();
        $calendar = $this->local();
        app(OccurrenceService::class)->moveWindow(new DateTimeImmutable('2026-10-05', new DateTimeZone('UTC')));
        app(EventService::class)->create($calendar, $admin, ['title' => 'Gone', 'start' => '2026-10-20T10:00']);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.calendars.delete', ['id' => $calendar->id]))->assertRedirect(SettingsPage::url());

        $this->assertSame(0, DB::table('calendar_events')->where('calendar_id', $calendar->id)->count());
        $this->assertSame(0, DB::table('calendar_occurrences')->where('calendar_id', $calendar->id)->count());
    }

    public function test_team_rows_need_a_real_team(): void
    {
        if (!class_exists('Team')) {
            $this->markTestSkipped('The Teams module is not active.');
        }
        $admin = $this->makeAdmin();
        $team = $this->makeTeam([$admin->id]);
        $calendar = $this->local();

        $this->actingAs($admin)->post($this->update($calendar), $this->form(['permissions' => ['user' => [$team->id => ['can_view' => '1']]]]))
            ->assertSessionHasErrors(['permissions.user.' . $team->id]);
        $this->actingAs($admin)->post($this->update($calendar), $this->form(['permissions' => ['team' => [$team->id => ['can_view' => '1']]]]))
            ->assertSessionMissing('errors');

        $this->assertSame([['team', $team->id]], CalendarPermission::where('calendar_id', $calendar->id)->get()->map(function (CalendarPermission $row) {
            return [$row->subject_type, (int) $row->subject_id];
        })->all());
    }

    public function test_an_admin_skips_a_pending_v2_item_from_the_report(): void
    {
        $admin = $this->makeAdmin();
        $calendar = $this->local();
        $id = DB::table('calendar_items')->insertGetId([
            'calendar_id' => $calendar->id, 'author_id' => $admin->id, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => 'Stuck item', 'body' => '', 'state' => 'published', 'location' => null,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-01-01 10:00:00',
        ]);

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]))
            ->assertRedirect(SettingsPage::url())->assertSessionMissing('errors');
        $this->assertContains($id, app(MigrationService::class)->skippedItemIds());

        $this->actingAs($admin)->post(route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]))->assertSessionHasErrors(['item']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter SettingsFormTest'`
Expected: every test fails or errors, because the route names and `SettingsPage` don't exist yet.

- [ ] **Step 3: Implement**

Create `Http/SettingsPage.php` with `url()` only for now (Step 8 replaces it with the full class):

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

/** Settings > Calendar: one FreeScout settings section that shows the calendar list, the new form or one calendar. */
final class SettingsPage
{
    /** The section's URL; with a calendar id (or "new") it opens that calendar's form. */
    public static function url($calendar = null): string
    {
        $url = route('settings', ['section' => 'calendar']);

        return $calendar === null ? $url : $url . '?calendar=' . rawurlencode((string) $calendar);
    }
}
```

Create `Http/CalendarForm.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Mailbox;
use App\User;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\FieldValues;
use Modules\LJPcCalendarModule\Services\TitleTemplate;

/**
 * The calendar settings form posts a whole calendar at once (general settings, custom fields, permission matrix);
 * this validates it and turns it into the calendar's columns and permission rows.
 */
final class CalendarForm
{
    public const SUBJECT_TYPES = [CalendarPermission::SUBJECT_USER, CalendarPermission::SUBJECT_TEAM, CalendarPermission::SUBJECT_MAILBOX];

    /** Name and color of a new local calendar. */
    public static function validateNew(Request $request): array
    {
        self::cleanName($request);

        return $request->validate([
            'name'  => ['required', 'string', 'max:191', Rule::unique('calendars', 'name')],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'],
        ]);
    }

    /** @return array the calendar's columns plus "permissions", the rows for syncPermissions() */
    public static function validate(Request $request, Calendar $calendar): array
    {
        // PHP drops input beyond max_input_vars without an error. This field comes last, so it is missing then.
        if ($request->input('form_complete') !== '1') {
            throw ValidationException::withMessages(['form' => __('The server cut this form off before it was complete, so nothing was saved. Ask your host to raise PHP\'s max_input_vars, then save again.')]);
        }
        self::cleanName($request);
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:191', Rule::unique('calendars', 'name')->ignore($calendar->id)],
            'color'                    => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'],
            'display_priority'         => 'required|integer|min:0|max:9999',
            'timezone'                 => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
            'default_reminder_minutes' => 'nullable|integer|min:0|max:40320',
            'title_template'           => 'nullable|string|max:191',
            'fields'                   => 'nullable|array|max:50',
            'fields.*.type'            => ['required', Rule::in(FieldValues::TYPES)],
            'permissions'              => 'nullable|array',
        ]);

        $fields = self::fields((array) $request->input('fields', []));
        $template = trim((string) ($data['title_template'] ?? ''));
        if (array_diff(TitleTemplate::fieldIds($template), array_map('strval', array_column($fields, 'id')))) {
            throw ValidationException::withMessages(['title_template' => __('The title template uses a field that does not exist.')]);
        }

        return [
            'name'                     => $data['name'],
            'color'                    => $data['color'],
            'enabled'                  => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN),
            'display_priority'         => (int) $data['display_priority'],
            'timezone'                 => $data['timezone'] ?? null,
            'default_reminder_minutes' => isset($data['default_reminder_minutes']) ? (int) $data['default_reminder_minutes'] : null,
            'title_template'           => $template === '' ? null : $template,
            'fields'                   => $fields,
            'permissions'              => self::permissionRows((array) $request->input('permissions', [])),
        ];
    }

    public static function syncPermissions(Calendar $calendar, array $rows): void
    {
        CalendarPermission::where('calendar_id', $calendar->id)->delete();
        foreach ($rows as $row) {
            CalendarPermission::create(['calendar_id' => $calendar->id] + $row);
        }
    }

    private static function cleanName(Request $request): void
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim(strip_tags($request->input('name')))]);
        }
    }

    /** Rows in posted order. Ticked "remove" rows and the empty row for a new field are dropped. */
    private static function fields(array $rows): array
    {
        $result = [];
        $used = [];
        $errors = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $name = trim(strip_tags((string) ($row['name'] ?? '')));
            $options = self::options((string) ($row['options'] ?? ''));
            if ($id === '' && $name === '' && !$options) {
                continue;
            }
            $type = (string) $row['type'];
            $hasOptions = in_array($type, ['dropdown', 'multiselect'], true);
            if ($name === '' || mb_strlen($name) > 100) {
                $errors["fields.$key.name"] = __('Give this field a name of at most 100 characters.');
                continue;
            }
            if ($hasOptions && (!$options || count($options) > 100 || max(array_map('mb_strlen', $options)) > 100)) {
                $errors["fields.$key.options"] = __('A dropdown or multiple choice field needs 1 to 100 options of at most 100 characters, one per line.');
                continue;
            }
            $result[] = [
                'id'       => self::fieldId($id, $used),
                'name'     => $name,
                'type'     => $type,
                'required' => !empty($row['required']),
                'options'  => $hasOptions ? $options : [],
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    private static function options(string $text): array
    {
        $options = array_map(function (string $line) {
            return trim(strip_tags($line));
        }, preg_split('/\r\n|\r|\n/', $text));

        return array_values(array_unique(array_filter($options, 'strlen')));
    }

    /**
     * Keeps an existing id. A missing, malformed, duplicate or small (v2-looking) id gets a fresh one. Leading zeros
     * are dropped first, because "01000" and "1000" would collide once stored as a number.
     */
    private static function fieldId(string $id, array &$used)
    {
        $normalized = ctype_digit($id) ? ltrim($id, '0') : $id;
        $usable = $normalized !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $normalized)
            && !(ctype_digit($normalized) && ((int) $normalized < 1000 || strlen($normalized) > 18))
            && !isset($used[$normalized]);
        while (!$usable) {
            $normalized = (string) ((int) (microtime(true) * 1000) + random_int(0, 999) + count($used));
            $usable = !isset($used[$normalized]);
        }
        $used[$normalized] = true;

        return ctype_digit($normalized) ? (int) $normalized : $normalized;
    }

    /** permissions[<type>][<id>][<right>] = 1 into rows; a subject without any right gets no row. */
    private static function permissionRows(array $input): array
    {
        $rows = [];
        $errors = [];
        foreach (self::SUBJECT_TYPES as $type) {
            foreach ((array) ($input[$type] ?? []) as $id => $rights) {
                $row = ['subject_type' => $type, 'subject_id' => (int) $id];
                foreach (CalendarPermission::RIGHTS as $right) {
                    $row[$right] = is_array($rights) && !empty($rights[$right]);
                }
                if (!array_filter(array_intersect_key($row, array_flip(CalendarPermission::RIGHTS)))) {
                    continue;
                }
                if (!ctype_digit((string) $id) || !self::subjectExists($type, (int) $id)) {
                    $errors["permissions.$type.$id"] = __('This user, team or mailbox does not exist.');
                    continue;
                }
                $rows[] = $row;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    private static function subjectExists(string $type, int $id): bool
    {
        if ($type === CalendarPermission::SUBJECT_MAILBOX) {
            return Mailbox::where('id', $id)->exists();
        }
        if ($type === CalendarPermission::SUBJECT_TEAM) {
            return CalendarPolicy::team($id) !== null;
        }

        return User::where('id', $id)->where('type', User::TYPE_USER)->where('status', '!=', User::STATUS_DELETED)->exists();
    }
}
```

Create `Http/Controllers/Settings/CalendarsController.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Http\CalendarForm;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\MigrationService;

/** Form actions of Settings > Calendar. Each one redirects to a settings page with a flash message or the errors. */
class CalendarsController extends Controller
{
    public function store(Request $request)
    {
        $data = CalendarForm::validateNew($request);
        $calendar = Calendar::create(['provider' => Calendar::PROVIDER_LOCAL, 'name' => $data['name'], 'color' => $data['color']]);

        return redirect(SettingsPage::url($calendar->id))->with('flash_success_floating', __('Calendar created. Choose who can use it below.'));
    }

    public function update(Request $request, $id)
    {
        $calendar = Calendar::findOrFail($id);
        self::assertConverted($calendar);
        $data = CalendarForm::validate($request, $calendar);
        DB::transaction(function () use ($calendar, $data) {
            $calendar->update(array_diff_key($data, ['permissions' => true]));
            CalendarForm::syncPermissions($calendar, $data['permissions']);
        });

        return redirect(SettingsPage::url($calendar->id))->with('flash_success_floating', __('Calendar saved.'));
    }

    public function destroy($id)
    {
        Calendar::findOrFail($id)->delete();

        return redirect(SettingsPage::url())->with('flash_success_floating', __('Calendar deleted.'));
    }

    public function regenerateToken(Request $request, $id)
    {
        $request->validate(['which' => 'required|in:full,busy']);
        $calendar = Calendar::findOrFail($id);
        self::assertConverted($calendar);
        $column = $request->input('which') === 'full' ? 'export_token' : 'export_busy_token';
        $calendar->update([$column => Calendar::newToken()]);

        return redirect(SettingsPage::url($calendar->id))->with('flash_success_floating', __('A new link was made. The old link no longer works.'));
    }

    public function skipItem(Request $request, MigrationService $migration, $id)
    {
        $migration->skipItem((int) $id, $request->user()->getFullName());

        return redirect(SettingsPage::url())->with('flash_success_floating', __('The event was skipped. Its data stays in the report.'));
    }

    /**
     * export_token is the v2 conversion's done-marker. A token or an edit on a calendar whose conversion failed would
     * stop the upgrade from retrying it, or be overwritten by that retry.
     */
    private static function assertConverted(Calendar $calendar): void
    {
        if ($calendar->export_token === null) {
            throw ValidationException::withMessages(['calendar' => __('This calendar has not been upgraded to version 3 yet. See the upgrade report above, then run the upgrade again.')]);
        }
    }
}
```

`Http/routes.php`: delete the seven `/calendar/api/admin/...` route lines. Add these lines in their place, inside the same `web` group:

```php
    Route::post('/calendar/settings/calendars', $admin + ['uses' => 'Settings\CalendarsController@store'])->name('ljpccalendarmodule.settings.calendars.store');
    Route::post('/calendar/settings/calendars/{id}', $admin + ['uses' => 'Settings\CalendarsController@update'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.update');
    Route::post('/calendar/settings/calendars/{id}/delete', $admin + ['uses' => 'Settings\CalendarsController@destroy'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.delete');
    Route::post('/calendar/settings/calendars/{id}/tokens', $admin + ['uses' => 'Settings\CalendarsController@regenerateToken'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.calendars.tokens');
    Route::post('/calendar/settings/migration/items/{id}/skip', $admin + ['uses' => 'Settings\CalendarsController@skipItem'])->where('id', '[0-9]+')->name('ljpccalendarmodule.settings.migration.skip');
```

Then:
- Delete `Http/Controllers/Admin/CalendarsController.php` and `Tests/Feature/AdminApiTest.php`.
- In `Http/FrontendConfig.php`, delete the `settings()` method.
- `grep -rn "admin/calendars\|admin.calendars\|admin.subjects\|admin.migration\|FrontendConfig::settings" .`, excluding `vendor` and `docs`, must print nothing except `Resources/views/settings.blade.php` and the settings tests in `Tests/Feature/IntegrationHooksTest.php`. Steps 6 to 8 rewrite both.

- [ ] **Step 4: Run the form tests to verify they pass**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter SettingsFormTest'`
Expected: `OK`. One test is skipped when the Teams module is not active.

- [ ] **Step 5: Commit the form actions**

The settings section itself still renders W1's JSON shell, which now points at removed routes. Steps 6 to 8 replace it in this same task, so commit the actions but keep going:

```bash
git add Http Tests/Feature/SettingsFormTest.php
git rm Http/Controllers/Admin/CalendarsController.php Tests/Feature/AdminApiTest.php
git commit -m "feat: replace the admin JSON API with settings form actions"
```

- [ ] **Step 6: Write the failing page tests**

Create `Tests/Feature/SettingsPageTest.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Option;
use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class SettingsPageTest extends TestCase
{
    private function html(string $url): string
    {
        return $this->actingAs($this->makeAdmin())->get($url)->assertStatus(200)->getContent();
    }

    private function updateAction(Calendar $calendar): string
    {
        return 'action="' . route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]) . '"';
    }

    public function test_only_admins_see_the_settings(): void
    {
        $this->actingAs($this->makeUser())->get(SettingsPage::url())->assertStatus(403);
        $this->actingAs($this->makeUser())->get(SettingsPage::url('new'))->assertStatus(403);
    }

    public function test_list_shows_calendars_escaped_with_their_status(): void
    {
        $xss = Calendar::create(['name' => '<img src=x onerror=alert(1)> ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $stuck = Calendar::create(['name' => 'Stuck ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        $html = $this->html(SettingsPage::url());

        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('href="' . e(SettingsPage::url($xss->id)) . '"', $html);
        $this->assertStringContainsString('href="' . e(SettingsPage::url('new')) . '"', $html);
        $this->assertStringContainsString(e(__('Not upgraded yet')), $html);
    }

    public function test_new_calendar_form_posts_to_the_store_action(): void
    {
        $html = $this->html(SettingsPage::url('new'));

        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.calendars.store') . '"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="color"', $html);
    }

    public function test_edit_form_shows_settings_fields_permissions_and_links(): void
    {
        $agent = $this->makeUser();
        $mailbox = $this->makeMailbox();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL, 'fields' => [
            ['id' => 172604180330, 'name' => 'Kind <b>', 'type' => 'dropdown', 'required' => true, 'options' => ['Call', 'Visit']],
        ]]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id, 'can_view' => true]);

        $html = $this->html(SettingsPage::url($calendar->id));

        $this->assertStringContainsString($this->updateAction($calendar), $html);
        $this->assertStringContainsString('name="fields[f172604180330][name]" value="Kind &lt;b&gt;"', $html);
        $this->assertMatchesRegularExpression('/name="permissions\[user\]\[' . $agent->id . '\]\[can_view\]" value="1"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="permissions\[user\]\[' . $agent->id . '\]\[can_edit\]" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('name="permissions[mailbox][' . $mailbox->id . '][can_view]"', $html);
        $this->assertStringContainsString(e(route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $calendar->export_token])), $html);
        $this->assertStringContainsString('data-ljpc-insert="{{title}}"', $html);
        $this->assertStringContainsString('data-ljpc-insert="{{field:172604180330}}"', $html);

        // The completion marker is the form's last field, so a form cut off by max_input_vars misses it.
        $form = substr($html, strpos($html, $this->updateAction($calendar)));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertMatchesRegularExpression('/name="form_complete" value="1">\s*$/', $form);
    }

    public function test_a_failed_save_shows_the_errors_and_keeps_what_was_typed(): void
    {
        $admin = $this->makeAdmin();
        $calendar = Calendar::create(['name' => 'Edit ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $suffix = uniqid();

        $this->actingAs($admin)->from(SettingsPage::url($calendar->id))->post(route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]), [
            'name' => 'Kept ' . $suffix, 'color' => '#000000', 'enabled' => '1', 'display_priority' => '100',
            'title_template' => '{{field:999999}}', 'fields' => ['new7' => ['id' => '', 'name' => 'Typed field', 'type' => 'text']],
            'form_complete' => '1',
        ]);
        $html = $this->actingAs($admin)->get(SettingsPage::url($calendar->id))->getContent();

        $this->assertStringContainsString(e(__('The title template uses a field that does not exist.')), $html);
        $this->assertStringContainsString('value="Kept ' . $suffix . '"', $html);
        $this->assertStringContainsString('name="fields[new7][name]" value="Typed field"', $html);
    }

    public function test_a_calendar_the_upgrade_has_not_converted_shows_a_notice_and_only_delete(): void
    {
        $stuck = Calendar::create(['name' => 'Stuck ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        DB::table('calendars')->where('id', $stuck->id)->update(['export_token' => null, 'export_busy_token' => null]);

        $html = $this->html(SettingsPage::url($stuck->id));

        $this->assertStringNotContainsString($this->updateAction($stuck), $html);
        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.calendars.delete', ['id' => $stuck->id]) . '"', $html);
        $this->assertStringContainsString(e(__('This calendar has not been upgraded to version 3 yet, so it can\'t be changed. The upgrade report above shows why. You can still delete it.')), $html);
    }

    public function test_an_unfinished_upgrade_shows_the_report_and_the_pending_items(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'running');
        $calendar = Calendar::create(['name' => 'Cal ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        $id = DB::table('calendar_items')->insertGetId([
            'calendar_id' => $calendar->id, 'author_id' => 1, 'is_all_day' => 0, 'is_private' => 0, 'is_read_only' => 0,
            'title' => '<b>Stuck</b> item', 'body' => '', 'state' => 'published', 'location' => null,
            'start' => '2026-10-20 08:00:00', 'end' => '2026-10-20 09:00:00', 'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-01-01 10:00:00',
        ]);

        $html = $this->html(SettingsPage::url());

        $this->assertStringContainsString(e(__('The upgrade to version 3 has not finished')), $html);
        $this->assertStringContainsString('&lt;b&gt;Stuck&lt;/b&gt; item', $html);
        $this->assertStringContainsString('action="' . route('ljpccalendarmodule.settings.migration.skip', ['id' => $id]) . '"', $html);
        $this->assertStringContainsString('href="' . route('system.tools') . '"', $html);
    }

    public function test_a_finished_upgrade_shows_the_report_only_when_it_has_warnings_or_errors(): void
    {
        Option::set(MigrationService::STATE_OPTION, 'complete');
        Option::set(MigrationService::REPORT_OPTION, [['level' => 'info', 'message' => 'The upgrade to version :version is complete.', 'context' => ['version' => '3'], 'at' => '2026-10-06 10:00:00']]);
        $this->assertStringNotContainsString(e(__('Upgrade report')), $this->html(SettingsPage::url()));

        Option::set(MigrationService::REPORT_OPTION, [['level' => 'warning', 'message' => 'Event ":title" (#:id) was skipped by :user and not converted; it is listed here with its data.', 'context' => ['id' => 7, 'title' => '<i>Old</i>', 'user' => 'Lars', 'body' => 'Notes'], 'at' => '2026-10-06 10:00:00']]);
        $html = $this->html(SettingsPage::url());
        $this->assertStringContainsString(e(__('Upgrade report')), $html);
        $this->assertStringContainsString('&lt;i&gt;Old&lt;/i&gt;', $html);
        $this->assertStringContainsString('body: Notes', $html);
    }

    public function test_connection_secrets_never_reach_the_page(): void
    {
        $connection = new CalendarConnection(['provider' => 'caldav', 'account' => 'lars']);
        $connection->setCredentials(['password' => 'hunter2']);
        $connection->save();
        $calendar = Calendar::create(['name' => 'Remote ' . uniqid(), 'provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $connection->id]);

        $this->assertStringNotContainsString('hunter2', $this->html(SettingsPage::url()));
        $this->assertStringNotContainsString('hunter2', $this->html(SettingsPage::url($calendar->id)));
    }
}
```

In `Tests/Feature/IntegrationHooksTest.php`:
- Delete the test method `test_settings_view_is_a_shell_with_admin_routes()`.
- In `test_settings_section_renders_through_core_for_admins_and_is_forbidden_for_users()`, replace the assertion on `id="ljpc-calendar-settings-config"` with:

```php
        $this->assertStringContainsString('class="ljpc-settings"', $response->getContent());
```

- [ ] **Step 7: Run the page tests to verify they fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "SettingsPageTest|settings_section_renders"'`
Expected: failures. The section still renders the old shell, and `SettingsPage::vars()` doesn't exist.

- [ ] **Step 8: Implement the settings page**

Replace `Http/SettingsPage.php` with the full version:

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Mailbox;
use App\User;
use Illuminate\Http\Request;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\MigrationService;

/** Settings > Calendar: one FreeScout settings section that shows the calendar list, the new form or one calendar. */
final class SettingsPage
{
    public const PROVIDERS = [
        Calendar::PROVIDER_LOCAL     => 'FreeScout calendar',
        Calendar::PROVIDER_ICS       => 'ICS feed',
        Calendar::PROVIDER_CALDAV    => 'CalDAV',
        Calendar::PROVIDER_GOOGLE    => 'Google Calendar',
        Calendar::PROVIDER_MICROSOFT => 'Microsoft 365',
    ];

    /** v2's color swatches, offered by the native color picker. */
    public const SWATCHES = ['#3498db', '#e74c3c', '#e67e22', '#1abc9c', '#9b59b6', '#2ecc71', '#f1c40f', '#833471', '#9980fa'];

    /** The section's URL; with a calendar id (or "new") it opens that calendar's form. */
    public static function url($calendar = null): string
    {
        $url = route('settings', ['section' => 'calendar']);

        return $calendar === null ? $url : $url . '?calendar=' . rawurlencode((string) $calendar);
    }

    /** Template variables for the settings.section_params filter. */
    public static function vars(Request $request): array
    {
        $vars = ['mode' => 'list', 'migration' => self::migration()];
        $param = (string) $request->query('calendar', '');
        if ($param === 'new') {
            return ['mode' => 'new'] + $vars;
        }
        $calendar = ctype_digit($param) ? Calendar::with('permissionRows')->find((int) $param) : null;
        if ($calendar) {
            return ['mode' => 'edit', 'calendar' => $calendar, 'subjects' => self::subjects(), 'rights' => self::rights($calendar)] + $vars;
        }

        return ['calendars' => Calendar::orderBy('display_priority')->orderBy('name')->get(), 'missing' => $param !== ''] + $vars;
    }

    /** A title template placeholder: {{title}}, or {{field:<id>}} for a custom field. */
    public static function token($fieldId = null): string
    {
        return $fieldId === null ? '{{title}}' : '{{field:' . $fieldId . '}}';
    }

    /** A stored color for a style attribute; anything that isn't #rrggbb falls back to the default blue. */
    public static function color(?string $color): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}\z/', $color) ? $color : '#3498db';
    }

    /** Whether the form shows posted values: after a failed save Laravel flashes the input, marker included. */
    public static function posted(): bool
    {
        return old('form_complete') !== null;
    }

    /** Field rows to render, keyed by row key: the posted rows after a failed save, else the fields plus one empty row. */
    public static function fieldRows(Calendar $calendar): array
    {
        $rows = [];
        if (self::posted()) {
            foreach ((array) old('fields', []) as $key => $row) {
                $rows[(string) $key] = [
                    'id'       => (string) ($row['id'] ?? ''),
                    'name'     => (string) ($row['name'] ?? ''),
                    'type'     => (string) ($row['type'] ?? 'text'),
                    'required' => !empty($row['required']),
                    'options'  => (string) ($row['options'] ?? ''),
                    'remove'   => !empty($row['remove']),
                ];
            }

            return $rows;
        }
        foreach ($calendar->fieldDefinitions() as $field) {
            $rows['f' . $field['id']] = [
                'id'       => (string) $field['id'],
                'name'     => (string) $field['name'],
                'type'     => (string) $field['type'],
                'required' => !empty($field['required']),
                'options'  => implode("\n", (array) ($field['options'] ?? [])),
                'remove'   => false,
            ];
        }
        $rows['new0'] = ['id' => '', 'name' => '', 'type' => 'text', 'required' => false, 'options' => '', 'remove' => false];

        return $rows;
    }

    /** Whether a permission box is ticked: the posted form after a failed save, else the stored rows. */
    public static function ticked(array $rights, string $type, int $id, string $right): bool
    {
        if (self::posted()) {
            return (bool) old("permissions.$type.$id.$right");
        }

        return !empty($rights["$type:$id"][$right]);
    }

    public static function fieldTypes(): array
    {
        return [
            'text'        => __('Text'),
            'number'      => __('Number'),
            'dropdown'    => __('Dropdown'),
            'multiselect' => __('Multiple choice'),
            'boolean'     => __('Yes or no'),
            'date'        => __('Date'),
            'email'       => __('Email address'),
            'source'      => __('Source'),
        ];
    }

    /** The same choices as the event form's reminder (Resources/frontend/src/shared/format.ts). */
    public static function reminderOptions(): array
    {
        return [
            5     => __('5 minutes before'),
            10    => __('10 minutes before'),
            15    => __('15 minutes before'),
            30    => __('30 minutes before'),
            60    => __('1 hour before'),
            120   => __('2 hours before'),
            1440  => __('1 day before'),
            2880  => __('2 days before'),
            10080 => __('1 week before'),
        ];
    }

    public static function rightLabels(): array
    {
        return [
            'show_in_dashboard' => __('Dashboard'),
            'can_view'          => __('View'),
            'can_create'        => __('Create'),
            'can_edit'          => __('Edit'),
        ];
    }

    private static function migration(): array
    {
        $service = app(MigrationService::class);
        $entries = array_map(function (array $entry) {
            $level = (string) ($entry['level'] ?? 'info');
            $context = (array) ($entry['context'] ?? []);

            return [
                'level' => $level,
                'text'  => __((string) ($entry['message'] ?? ''), array_map('strval', array_filter($context, 'is_scalar'))),
                // Warnings and errors carry the item's data, so an admin can recreate a skipped event by hand.
                'data'  => $level === 'info' ? [] : array_map(function ($value) {
                    return is_scalar($value) ? (string) $value : (string) json_encode($value);
                }, $context),
            ];
        }, $service->report());
        $finished = $service->isFinished();

        return [
            'finished' => $finished,
            'entries'  => $entries,
            'problems' => count(array_filter($entries, function (array $entry) {
                return $entry['level'] !== 'info';
            })),
            'pending'  => $finished ? [] : $service->pendingItems(),
        ];
    }

    private static function subjects(): array
    {
        $teams = CalendarPolicy::teamsActive()
            ? User::where('email', 'like', 'fsteam%')->orderBy('first_name')->get()->filter(function (User $team) {
                return \Team::isTeam($team);
            })
            : collect();

        return [
            CalendarPermission::SUBJECT_USER    => ['title' => __('Users'), 'items' => User::where('type', User::TYPE_USER)
                ->where('status', '!=', User::STATUS_DELETED)->orderBy('first_name')->get()
                ->map(function (User $user) {
                    return ['id' => (int) $user->id, 'name' => $user->getFullName()];
                })->all()],
            CalendarPermission::SUBJECT_TEAM    => ['title' => __('Teams'), 'items' => $teams->map(function (User $team) {
                return ['id' => (int) $team->id, 'name' => (string) $team->first_name];
            })->values()->all()],
            CalendarPermission::SUBJECT_MAILBOX => ['title' => __('Mailboxes'), 'items' => Mailbox::orderBy('name')->get(['id', 'name'])
                ->map(function (Mailbox $mailbox) {
                    return ['id' => (int) $mailbox->id, 'name' => (string) $mailbox->name];
                })->all()],
        ];
    }

    /** The stored rows as "type:id" => rights. */
    private static function rights(Calendar $calendar): array
    {
        $rights = [];
        foreach ($calendar->permissionRows as $row) {
            $rights[$row->subject_type . ':' . $row->subject_id] = $row->only(CalendarPermission::RIGHTS);
        }

        return $rights;
    }
}
```

In `Providers/LJPcCalendarModuleServiceProvider.php` (PSR-12 since Task 0), inside `hooks()`, add this filter directly after the existing `settings.view` filter. This file must keep parsing on PHP 7.1, so use no arrow functions:

```php
        // The section's view data: list, new or edit mode, plus the upgrade report.
        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== 'calendar') {
                return $params;
            }
            $params['template_vars'] = \Modules\LJPcCalendarModule\Http\SettingsPage::vars(request());

            return $params;
        }, 20, 2);
```

Replace `Resources/views/settings.blade.php` with:

```blade
<div class="ljpc-settings">
    @include('calendar::settings.report')
    @if ($mode === 'edit')
        @include('calendar::settings.edit')
    @elseif ($mode === 'new')
        @include('calendar::settings.new')
    @else
        @include('calendar::settings.list')
    @endif
</div>
```

Create `Resources/views/settings/report.blade.php`:

```blade
@if (!$migration['finished'] || $migration['problems'])
    <div class="panel {{ $migration['finished'] ? 'panel-default' : 'panel-warning' }} ljpc-report">
        <div class="panel-heading">
            <strong>{{ $migration['finished'] ? __('Upgrade report') : __('The upgrade to version 3 has not finished') }}</strong>
        </div>
        <div class="panel-body">
            @if (!$migration['finished'])
                <p>{{ __('Agents can\'t use the calendar until the upgrade has finished. Solve or skip the events below, then run the upgrade again with "Migrate DB" under System tools.') }}</p>
                <p><a href="{{ route('system.tools') }}" class="btn btn-default">{{ __('Open System tools') }}</a></p>
            @endif

            @if ($errors->has('item'))
                <div class="alert alert-danger">{{ $errors->first('item') }}</div>
            @endif

            @if ($migration['pending'])
                <h4>{{ __('Events still waiting to be converted') }}</h4>
                <p class="text-help">{{ __('Skipping an event leaves it out of version 3. Its data stays in this report, so you can add it again by hand.') }}</p>
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('Title') }}</th>
                            <th>{{ __('Start') }}</th>
                            <th>{{ __('Calendar') }}</th>
                            <th><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($migration['pending'] as $item)
                            <tr>
                                <td>{{ $item['id'] }}</td>
                                <td>{{ $item['title'] }}</td>
                                <td>{{ $item['start'] }}</td>
                                <td>{{ $item['calendar'] ?? __('A deleted calendar') }}</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('ljpccalendarmodule.settings.migration.skip', ['id' => $item['id']]) }}" data-ljpc-confirm="{{ __('Skip this event? It won\'t be in the calendar.') }}">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-default btn-xs">{{ __('Skip') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <details {{ $migration['finished'] ? '' : 'open' }}>
                <summary>{{ __('Report entries (:count)', ['count' => count($migration['entries'])]) }}</summary>
                <ul class="list-unstyled ljpc-report-entries">
                    @foreach ($migration['entries'] as $entry)
                        <li>
                            <span class="label label-{{ ['error' => 'danger', 'warning' => 'warning'][$entry['level']] ?? 'default' }}">{{ ['error' => __('Error'), 'warning' => __('Warning')][$entry['level']] ?? __('Info') }}</span>
                            {{ $entry['text'] }}
                            @if ($entry['data'])
                                <details>
                                    <summary>{{ __('Data') }}</summary>
                                    <pre>@foreach ($entry['data'] as $key => $value){{ $key }}: {{ $value }}
@endforeach</pre>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>
    </div>
@endif
```

Create `Resources/views/settings/list.blade.php`:

```blade
@php $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class; @endphp
@if ($missing)
    <div class="alert alert-warning">{{ __('This calendar no longer exists.') }}</div>
@endif
<p><a href="{{ $page::url('new') }}" class="btn btn-primary">{{ __('New calendar') }}</a></p>
@if ($calendars->isEmpty())
    <p class="text-help">{{ __('There are no calendars yet. Create one, then choose who can use it.') }}</p>
@else
    <table class="table table-striped ljpc-calendars">
        <thead>
            <tr>
                <th>{{ __('Calendar') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($calendars as $item)
                <tr>
                    <td>
                        <span class="ljpc-swatch" style="background-color: {{ $page::color($item->color) }}" aria-hidden="true"></span>
                        <a href="{{ $page::url($item->id) }}">{{ $item->name }}</a>
                    </td>
                    <td>{{ __($page::PROVIDERS[$item->provider] ?? $item->provider) }}</td>
                    <td>
                        @if ($item->export_token === null)
                            <span class="text-warning">{{ __('Not upgraded yet') }}</span>
                        @elseif (!$item->enabled)
                            {{ __('Off') }}
                        @elseif (trim((string) $item->last_error) !== '')
                            <span class="text-warning">{{ __('Synchronization failed') }}</span>
                        @else
                            {{ __('On') }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
```

Create `Resources/views/settings/new.blade.php`:

```blade
@php $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class; @endphp
<p><a href="{{ $page::url() }}">{{ __('All calendars') }}</a></p>
<form class="form-horizontal margin-top" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.store') }}">
    {{ csrf_field() }}
    <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
        <label for="ljpc-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
        <div class="col-sm-6">
            <input id="ljpc-name" type="text" class="form-control input-sized" name="name" value="{{ old('name') }}" maxlength="191" required autofocus>
            @include('partials/field_error', ['field' => 'name'])
        </div>
    </div>
    <div class="form-group{{ $errors->has('color') ? ' has-error' : '' }}">
        <label for="ljpc-color" class="col-sm-2 control-label">{{ __('Color') }}</label>
        <div class="col-sm-6">
            <input id="ljpc-color" type="color" class="form-control ljpc-color" name="color" value="{{ $page::color(old('color', $page::SWATCHES[0])) }}" list="ljpc-swatches" required>
            <datalist id="ljpc-swatches">
                @foreach ($page::SWATCHES as $swatch)
                    <option value="{{ $swatch }}"></option>
                @endforeach
            </datalist>
            @include('partials/field_error', ['field' => 'color'])
        </div>
    </div>
    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Create calendar') }}</button>
            <a href="{{ $page::url() }}" class="btn btn-link">{{ __('Cancel') }}</a>
        </div>
    </div>
</form>
```

Create `Resources/views/settings/edit.blade.php`:

```blade
@php
    $page = \Modules\LJPcCalendarModule\Http\SettingsPage::class;
    $posted = $page::posted();
    $enabled = $posted ? (bool) old('enabled') : (bool) $calendar->enabled;
@endphp
<p><a href="{{ $page::url() }}">{{ __('All calendars') }}</a></p>
<h3 class="ljpc-title">
    <span class="ljpc-swatch" style="background-color: {{ $page::color($calendar->color) }}" aria-hidden="true"></span>
    {{ $calendar->name }}
    <small>{{ __($page::PROVIDERS[$calendar->provider] ?? $calendar->provider) }}</small>
</h3>

@foreach (['calendar', 'form'] as $general)
    @if ($errors->has($general))
        <div class="alert alert-danger">{{ $errors->first($general) }}</div>
    @endif
@endforeach

@if ($calendar->export_token === null)
    <div class="alert alert-warning">{{ __('This calendar has not been upgraded to version 3 yet, so it can\'t be changed. The upgrade report above shows why. You can still delete it.') }}</div>
@else
    <form class="form-horizontal margin-top" method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.update', ['id' => $calendar->id]) }}">
        {{ csrf_field() }}

        <h4 class="ljpc-section">{{ __('General') }}</h4>
        <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
            <label for="ljpc-name" class="col-sm-2 control-label">{{ __('Name') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-name" type="text" class="form-control input-sized" name="name" value="{{ old('name', $calendar->name) }}" maxlength="191" required>
                @include('partials/field_error', ['field' => 'name'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('color') ? ' has-error' : '' }}">
            <label for="ljpc-color" class="col-sm-2 control-label">{{ __('Color') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-color" type="color" class="form-control ljpc-color" name="color" value="{{ $page::color(old('color', $calendar->color)) }}" list="ljpc-swatches" required>
                <datalist id="ljpc-swatches">
                    @foreach ($page::SWATCHES as $swatch)
                        <option value="{{ $swatch }}"></option>
                    @endforeach
                </datalist>
                @include('partials/field_error', ['field' => 'color'])
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <input type="hidden" name="enabled" value="0">
                <label class="checkbox-inline"><input type="checkbox" name="enabled" value="1" @if ($enabled) checked @endif> {{ __('Show this calendar to agents') }}</label>
            </div>
        </div>
        <div class="form-group{{ $errors->has('display_priority') ? ' has-error' : '' }}">
            <label for="ljpc-priority" class="col-sm-2 control-label">{{ __('Display order') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-priority" type="number" class="form-control input-sized" name="display_priority" min="0" max="9999" value="{{ old('display_priority', $calendar->display_priority) }}" required>
                <p class="help-block">{{ __('Lower numbers come first in the all-day row and in the list of calendars.') }}</p>
                @include('partials/field_error', ['field' => 'display_priority'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('timezone') ? ' has-error' : '' }}">
            <label for="ljpc-timezone" class="col-sm-2 control-label">{{ __('Time zone') }}</label>
            <div class="col-sm-6">
                @php $timezone = $posted ? (string) old('timezone') : (string) $calendar->timezone; @endphp
                <select id="ljpc-timezone" class="form-control input-sized" name="timezone">
                    <option value="">{{ __('FreeScout default (:timezone)', ['timezone' => config('app.timezone')]) }}</option>
                    @foreach (\DateTimeZone::listIdentifiers() as $identifier)
                        <option value="{{ $identifier }}" @if ($timezone === $identifier) selected @endif>{{ $identifier }}</option>
                    @endforeach
                </select>
                <p class="help-block">{{ __('Used for events that have no time zone of their own.') }}</p>
                @include('partials/field_error', ['field' => 'timezone'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('default_reminder_minutes') ? ' has-error' : '' }}">
            <label for="ljpc-reminder" class="col-sm-2 control-label">{{ __('Default reminder') }}</label>
            <div class="col-sm-6">
                @php $reminder = (string) ($posted ? old('default_reminder_minutes') : $calendar->default_reminder_minutes); @endphp
                <select id="ljpc-reminder" class="form-control input-sized" name="default_reminder_minutes">
                    <option value="">{{ __('No reminder') }}</option>
                    @foreach ($page::reminderOptions() as $minutes => $label)
                        <option value="{{ $minutes }}" @if ($reminder === (string) $minutes) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
                @include('partials/field_error', ['field' => 'default_reminder_minutes'])
            </div>
        </div>
        <div class="form-group{{ $errors->has('title_template') ? ' has-error' : '' }}">
            <label for="ljpc-title-template" class="col-sm-2 control-label">{{ __('Title template') }}</label>
            <div class="col-sm-6">
                <input id="ljpc-title-template" type="text" class="form-control" name="title_template" maxlength="191" value="{{ old('title_template', $calendar->title_template) }}" data-ljpc-template-input>
                <div class="ljpc-chips margin-top-10">
                    <button type="button" class="btn btn-default btn-xs" data-ljpc-insert="{{ $page::token() }}">{{ __('Title') }}</button>
                    @foreach ($calendar->fieldDefinitions() as $field)
                        <button type="button" class="btn btn-default btn-xs" data-ljpc-insert="{{ $page::token($field['id']) }}">{{ $field['name'] }}</button>
                    @endforeach
                </div>
                <p class="help-block ljpc-template-preview" data-ljpc-template-preview aria-live="polite"></p>
                <p class="help-block">{{ __('Optional. Builds every event title from the typed title and custom fields; click a button to insert it. Leave empty to use the typed title as it is. Save new fields first to use them here.') }}</p>
                @include('partials/field_error', ['field' => 'title_template'])
            </div>
        </div>

        <h4 class="ljpc-section">{{ __('Custom fields') }}</h4>
        @include('calendar::settings.fields')

        <h4 class="ljpc-section">{{ __('Who can use this calendar') }}</h4>
        @include('calendar::settings.permissions')

        <div class="form-group margin-top">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Save calendar') }}</button>
            </div>
        </div>
        {{-- Must stay the form's last field: PHP drops fields beyond max_input_vars, and CalendarForm refuses a form without it. --}}
        <input type="hidden" name="form_complete" value="1">
    </form>

    <h4 class="ljpc-section">{{ __('Subscribe from other apps') }}</h4>
    <p class="text-help">{{ __('Anyone with one of these links can read the calendar, so share them with care. Making a new link stops the old one.') }}</p>
    @foreach (['full' => [__('All details'), $calendar->export_token], 'busy' => [__('Only free and busy'), $calendar->export_busy_token]] as $which => [$label, $token])
        <div class="form-group ljpc-export">
            <label for="ljpc-export-{{ $which }}" class="control-label">{{ $label }}</label>
            <div class="input-group">
                <input id="ljpc-export-{{ $which }}" type="text" class="form-control" readonly value="{{ route('ljpccalendarmodule.export', ['id' => $calendar->id, 'token' => $token]) }}">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default" data-ljpc-copy="ljpc-export-{{ $which }}" data-ljpc-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
                </span>
            </div>
            <form method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.tokens', ['id' => $calendar->id]) }}" data-ljpc-confirm="{{ __('Make a new link? Apps that use the old link stop receiving updates.') }}">
                {{ csrf_field() }}
                <input type="hidden" name="which" value="{{ $which }}">
                <button type="submit" class="btn btn-link ljpc-new-link">{{ __('Make a new link') }}</button>
            </form>
        </div>
    @endforeach
@endif

<h4 class="ljpc-section">{{ __('Delete calendar') }}</h4>
<form method="POST" action="{{ route('ljpccalendarmodule.settings.calendars.delete', ['id' => $calendar->id]) }}" data-ljpc-confirm="{{ __('Delete this calendar and all its events? This can\'t be undone.') }}">
    {{ csrf_field() }}
    <p class="text-help">{{ __('Deletes the calendar and all its events. Conversations keep their notes about events.') }}</p>
    <button type="submit" class="btn btn-danger">{{ __('Delete calendar') }}</button>
</form>
```

Create `Resources/views/settings/fields.blade.php`:

```blade
@php $rows = $page::fieldRows($calendar); @endphp
<div class="ljpc-fields" data-ljpc-fields>
    <table class="table table-condensed ljpc-fields-table">
        <thead>
            <tr>
                <th><span class="sr-only">{{ __('Order') }}</span></th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Required') }}</th>
                <th>{{ __('Options, one per line') }}</th>
                <th>{{ __('Remove') }}</th>
            </tr>
        </thead>
        <tbody data-ljpc-field-rows>
            @foreach ($rows as $key => $row)
                @include('calendar::settings.field_row', ['key' => $key, 'row' => $row])
            @endforeach
        </tbody>
    </table>
    <template data-ljpc-field-template>
        @include('calendar::settings.field_row', ['key' => '__KEY__', 'row' => ['id' => '', 'name' => '', 'type' => 'text', 'required' => false, 'options' => '', 'remove' => false]])
    </template>
    <button type="button" class="btn btn-default hidden" data-ljpc-add-field>{{ __('Add field') }}</button>
    <p class="help-block">{{ __('Fill in the empty row to add a field. Drag a row by its handle to change the order. Renaming a field keeps its values.') }}</p>
</div>
```

Create `Resources/views/settings/field_row.blade.php`:

```blade
<tr data-ljpc-field-row>
    <td><span class="ljpc-drag-handle glyphicon glyphicon-menu-hamburger" aria-hidden="true"></span></td>
    <td class="{{ $errors->has("fields.$key.name") ? 'has-error' : '' }}">
        <input type="hidden" name="fields[{{ $key }}][id]" value="{{ $row['id'] }}">
        <input type="text" class="form-control" name="fields[{{ $key }}][name]" value="{{ $row['name'] }}" maxlength="100" aria-label="{{ __('Name') }}">
        @include('partials/field_error', ['field' => "fields.$key.name"])
    </td>
    <td>
        <select class="form-control" name="fields[{{ $key }}][type]" data-ljpc-field-type aria-label="{{ __('Type') }}">
            @foreach ($page::fieldTypes() as $value => $label)
                <option value="{{ $value }}" @if ($row['type'] === $value) selected @endif>{{ $label }}</option>
            @endforeach
        </select>
    </td>
    <td><input type="checkbox" name="fields[{{ $key }}][required]" value="1" aria-label="{{ __('Required') }}" @if ($row['required']) checked @endif></td>
    <td class="{{ $errors->has("fields.$key.options") ? 'has-error' : '' }}" data-ljpc-field-options>
        <textarea class="form-control" name="fields[{{ $key }}][options]" rows="2" aria-label="{{ __('Options, one per line') }}">{{ $row['options'] }}</textarea>
        @include('partials/field_error', ['field' => "fields.$key.options"])
    </td>
    <td><input type="checkbox" name="fields[{{ $key }}][remove]" value="1" data-ljpc-field-remove aria-label="{{ __('Remove') }}" @if ($row['remove']) checked @endif></td>
</tr>
```

Create `Resources/views/settings/permissions.blade.php`:

```blade
<p class="help-block">{{ __('Rights add up: a person gets every right from their own row, their teams and the mailboxes they can open. Administrators always have every right. Edit includes deleting. Dashboard shows the next events on the person\'s dashboard, also without View.') }}</p>
@foreach ($errors->getMessages() as $key => $messages)
    @if (strpos($key, 'permissions.') === 0)
        <div class="alert alert-danger">{{ $messages[0] }}</div>
    @endif
@endforeach
@foreach ($subjects as $type => $group)
    @if ($group['items'])
        <table class="table table-condensed ljpc-matrix">
            <thead>
                <tr>
                    <th>{{ $group['title'] }}</th>
                    @foreach ($page::rightLabels() as $right => $label)
                        <th>
                            {{ $label }}
                            <input type="checkbox" class="hidden" data-ljpc-select-all="{{ $type }}:{{ $right }}" aria-label="{{ __('Select all: :right', ['right' => $label]) }}">
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($group['items'] as $subject)
                    <tr>
                        <td>{{ $subject['name'] }}</td>
                        @foreach ($page::rightLabels() as $right => $label)
                            <td><input type="checkbox" name="permissions[{{ $type }}][{{ $subject['id'] }}][{{ $right }}]" value="1" data-ljpc-right="{{ $type }}:{{ $right }}" aria-label="{{ $subject['name'] }}: {{ $label }}" @if ($page::ticked($rights, $type, $subject['id'], $right)) checked @endif></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
```

The placeholders come from `SettingsPage::token()` because Blade source can't hold a literal `{{field:` followed by an echo: Blade's echo pattern stops at the first `}}`.

- [ ] **Step 9: Run all settings tests, then the whole suite**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "SettingsFormTest|SettingsPageTest|IntegrationHooksTest"'`
Expected: `OK`. Some tests may be skipped when the Teams module is not active.

Run the whole suite. Expected: `OK`.

- [ ] **Step 10: Check the pages in a browser**

Log in to https://freescout.ddev.site as the dev admin (`dev-calendar@example.com`) and go to Settings > Calendar. Check each of the following, and keep screenshots for the report:
- the list
- "New calendar": create one and land on its form
- the edit form:
  - change the name, color, display order and time zone, then save
  - add a dropdown field with options in the empty row, save, then remove it and save again
  - tick a few rights, save, and confirm they are still ticked
- the two export links
- "Make a new link"
- delete

Without JavaScript (it arrives in Task 4), the drag handles do nothing and the select-all boxes stay hidden. Everything else works.

- [ ] **Step 11: Commit**

```bash
git add Http Providers/LJPcCalendarModuleServiceProvider.php Resources/views Tests/Feature
git commit -m "feat: render the calendar settings server-side with the upgrade report"
```

---

### Task 4: Frontend toolchain and the settings enhancements

This task sets up the Vite and TypeScript project in `Resources/frontend` with its first entry, `settings`. That entry is a small script that enhances Task 3's pages:
- add field rows
- drag rows to reorder them
- show the options box only where it applies
- select all per permission column
- insert title template placeholders with a preview
- copy buttons
- confirm before destructive actions

The task also adds the PHP helper that prints the asset tags, and the CI check that the committed build is current.

**Files:**
- Create: `Resources/frontend/package.json`, `Resources/frontend/package-lock.json` (by npm), `Resources/frontend/tsconfig.json`, `Resources/frontend/vite.config.ts`, `Resources/frontend/vitest.config.ts`, `Resources/frontend/src/env.d.ts`
- Create: `Resources/frontend/src/settings/main.ts`, `field-builder.ts`, `select-all.ts`, `template-chips.ts`, `copy.ts`, `confirm.ts`, `settings.css`
- Create: `Resources/frontend/tests/settings.test.ts`
- Create: `Http/Assets.php`, `Tests/Unit/AssetsTest.php`
- Create (generated, committed): `Public/build/settings.js`, `Public/build/settings.css`, `Public/build/chunks/*`
- Modify: `Resources/views/settings.blade.php`, `.gitignore`, `.github/workflows/release.yml`, `CLAUDE.md`

**Interfaces:**
- Consumes: the markup hooks from Task 3.
- Produces:
  - `Assets::script(string $entry): string` returns `<script type="module" src=".../modules/ljpccalendarmodule/build/<entry>.js?v=<mtime>" nonce="...">`.
  - `Assets::style(string $entry): string` returns `<link rel="stylesheet" href=".../build/<entry>.css?v=<mtime>">`.
  - In `Resources/frontend`:
    - `npm run check` runs vue-tsc and Vitest.
    - `npm run build` runs vue-tsc and writes `Public/build`.
    - `npm test` runs Vitest only.
  - `vite.config.ts` has one `input` map. Tasks 6 and 7 add the `calendar` and `conversation` entries to it.

- [ ] **Step 1: Create the project files and install**

`Resources/frontend/package.json`:

```json
{
  "name": "ljpc-calendar-frontend",
  "private": true,
  "type": "module",
  "engines": {
    "node": ">=22.12"
  },
  "scripts": {
    "build": "vue-tsc --noEmit && vite build",
    "check": "vue-tsc --noEmit && vitest run",
    "test": "vitest run"
  },
  "dependencies": {
    "@fullcalendar/vue3": "7.1.1",
    "sortablejs": "1.15.7",
    "temporal-polyfill": "1.0.5",
    "vue": "3.5.43"
  },
  "devDependencies": {
    "@types/sortablejs": "1.15.9",
    "@vitejs/plugin-vue": "6.0.9",
    "jsdom": "30.1.2",
    "typescript": "6.0.3",
    "vite": "8.3.3",
    "vitest": "5.0.3",
    "vue-tsc": "3.3.12"
  }
}
```

`Resources/frontend/tsconfig.json`:

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "jsx": "preserve",
    "lib": ["ES2022", "DOM", "DOM.Iterable"],
    "types": ["vite/client"],
    "skipLibCheck": true,
    "isolatedModules": true,
    "verbatimModuleSyntax": true,
    "noEmit": true
  },
  "include": ["src/**/*.ts", "src/**/*.vue", "tests/**/*.ts", "vite.config.ts", "vitest.config.ts"]
}
```

`Resources/frontend/vite.config.ts`:

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Entries load as <script type="module"> from /modules/ljpccalendarmodule/build/ (Http/Assets.php adds ?v=<mtime>),
// so entry and CSS names are stable; shared chunks carry a content hash. CI rebuilds and fails when Public/build
// differs from the commit, so the build must stay deterministic.
export default defineConfig({
  plugins: [vue()],
  base: './',
  build: {
    outDir: '../../Public/build',
    emptyOutDir: true,
    target: 'es2022',
    cssCodeSplit: true,
    rollupOptions: {
      input: {
        settings: 'src/settings/main.ts',
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
})
```

`Resources/frontend/vitest.config.ts`:

```ts
import { defineConfig } from 'vitest/config'
export default defineConfig({ test: { include: ['tests/**/*.test.ts'] } })
```

`Resources/frontend/src/env.d.ts`:

```ts
declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

interface Window {
  showFloatingAlert?: (type: 'success' | 'error', message: string, noAutohide?: boolean) => void
}
```

Add to `.gitignore`:

```
/Resources/frontend/node_modules/
```

Install (the shell sets `NODE_ENV=production` and npm `omit=dev`, so dev dependencies need `--include=dev`):

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module/Resources/frontend
npm install --include=dev
```

Expected: npm writes `package-lock.json`, and `node_modules/.bin` contains `vite`, `vue-tsc` and `vitest`. Afterwards, `npx vue-tsc --version` prints `Version 3.3.12`.

- [ ] **Step 2: Write the failing tests**

`Resources/frontend/tests/settings.test.ts`:

```ts
// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest'
import { initFieldBuilder } from '../src/settings/field-builder'
import { initSelectAll } from '../src/settings/select-all'
import { initTemplateChips } from '../src/settings/template-chips'

const row = (key: string, type = 'text') => `<tr data-ljpc-field-row><td><span class="ljpc-drag-handle"></span></td>
  <td><input type="text" name="fields[${key}][name]"></td>
  <td><select name="fields[${key}][type]" data-ljpc-field-type><option value="text"${type === 'text' ? ' selected' : ''}>text</option><option value="dropdown"${type === 'dropdown' ? ' selected' : ''}>dropdown</option></select></td>
  <td data-ljpc-field-options><textarea name="fields[${key}][options]"></textarea></td>
  <td><input type="checkbox" name="fields[${key}][remove]" value="1" data-ljpc-field-remove></td></tr>`

describe('settings enhancements', () => {
  beforeEach(() => {
    document.body.innerHTML = `<div class="ljpc-settings">
      <div data-ljpc-fields><table><tbody data-ljpc-field-rows>${row('a')}${row('b', 'dropdown')}</tbody></table>
        <template data-ljpc-field-template>${row('__KEY__')}</template><button type="button" class="hidden" data-ljpc-add-field>Add</button></div>
      <table><tr><th><input type="checkbox" class="hidden" data-ljpc-select-all="user:can_view"></th></tr>
        <tr><td><input type="checkbox" data-ljpc-right="user:can_view" checked></td></tr>
        <tr><td><input type="checkbox" data-ljpc-right="user:can_view"></td></tr></table>
      <input data-ljpc-template-input value="{{field:12}} {{title}}">
      <button type="button" data-ljpc-insert="{{title}}">Title</button><button type="button" data-ljpc-insert="{{field:12}}">Customer</button>
      <p data-ljpc-template-preview></p></div>`
  })

  it('shows options only for dropdown and multiselect fields', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    const options = document.querySelectorAll('[data-ljpc-field-options]')
    expect(options[0]?.classList.contains('hidden')).toBe(true)
    expect(options[1]?.classList.contains('hidden')).toBe(false)
  })

  it('shows the add button and adds rows with a fresh key from the template', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    expect(document.querySelector('[data-ljpc-add-field]')?.classList.contains('hidden')).toBe(false)
    ;(document.querySelector('[data-ljpc-add-field]') as HTMLButtonElement).click()
    const rows = document.querySelectorAll('[data-ljpc-field-rows] [data-ljpc-field-row]')
    expect(rows).toHaveLength(3)
    expect(rows[2]?.querySelector('input[type="text"]')?.getAttribute('name')).toMatch(/^fields\[new[a-z0-9]+\]\[name\]$/)
  })

  it('marks rows ticked for removal', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    const remove = document.querySelector('[data-ljpc-field-remove]') as HTMLInputElement
    remove.checked = true
    remove.dispatchEvent(new Event('change', { bubbles: true }))
    expect(document.querySelector('[data-ljpc-field-row]')?.classList.contains('is-removed')).toBe(true)
  })

  it('selects a whole permission column and reflects partial selections', () => {
    initSelectAll(document)
    const header = document.querySelector('[data-ljpc-select-all]') as HTMLInputElement
    expect(header.classList.contains('hidden')).toBe(false)
    expect(header.indeterminate).toBe(true)
    header.checked = true
    header.dispatchEvent(new Event('change'))
    expect(Array.from(document.querySelectorAll<HTMLInputElement>('[data-ljpc-right]')).every((cell) => cell.checked)).toBe(true)
  })

  it('inserts placeholders and previews them with field names', () => {
    initTemplateChips(document)
    expect(document.querySelector('[data-ljpc-template-preview]')?.textContent).toBe('[Customer] [Title]')
    const input = document.querySelector('[data-ljpc-template-input]') as HTMLInputElement
    input.value = ''
    ;(document.querySelector('[data-ljpc-insert="{{title}}"]') as HTMLButtonElement).click()
    expect(input.value).toBe('{{title}}')
  })
})
```

`Tests/Unit/AssetsTest.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Http\Assets;
use Modules\LJPcCalendarModule\Tests\TestCase;

class AssetsTest extends TestCase
{
    public function test_entries_load_as_versioned_modules_with_the_csp_nonce(): void
    {
        $this->assertMatchesRegularExpression(
            '#^<script type="module" src="[^"]*/modules/ljpccalendarmodule/build/settings\.js\?v=\d+" nonce="[^"]+"></script>$#',
            Assets::script('settings')
        );
        $this->assertMatchesRegularExpression(
            '#^<link rel="stylesheet" href="[^"]*/modules/ljpccalendarmodule/build/settings\.css\?v=\d+">$#',
            Assets::style('settings')
        );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd Resources/frontend && npx vitest run`
Expected: FAIL. The `../src/settings/*` modules don't exist yet.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter AssetsTest'`
Expected: an error, because class `Assets` is not found.

- [ ] **Step 4: Implement the settings entry**

`Resources/frontend/src/settings/field-builder.ts`:

```ts
import Sortable from 'sortablejs'

const WITH_OPTIONS = ['dropdown', 'multiselect']

/** Shows the options box only for dropdown and multiselect fields (it stays visible without JavaScript). */
function syncOptions(row: HTMLElement): void {
  const type = row.querySelector<HTMLSelectElement>('[data-ljpc-field-type]')?.value ?? ''
  row.querySelector<HTMLElement>('[data-ljpc-field-options]')?.classList.toggle('hidden', !WITH_OPTIONS.includes(type))
}

function syncRemoved(row: HTMLElement): void {
  const removed = row.querySelector<HTMLInputElement>('[data-ljpc-field-remove]')?.checked ?? false
  row.classList.toggle('is-removed', removed)
}

function newKey(): string {
  return `new${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

/**
 * Enhances the server-rendered custom field table: add rows from the template, drag rows to reorder (the form
 * submits rows in document order), hide options for types without them, and mark rows ticked for removal.
 */
export function initFieldBuilder(root: HTMLElement): void {
  const rows = root.querySelector<HTMLElement>('[data-ljpc-field-rows]')
  const template = root.querySelector<HTMLTemplateElement>('template[data-ljpc-field-template]')
  if (!rows || !template) {
    return
  }

  rows.querySelectorAll<HTMLElement>('[data-ljpc-field-row]').forEach((row) => {
    syncOptions(row)
    syncRemoved(row)
  })
  rows.addEventListener('change', (event) => {
    const row = (event.target as Element).closest<HTMLElement>('[data-ljpc-field-row]')
    if (row) {
      syncOptions(row)
      syncRemoved(row)
    }
  })

  const add = root.querySelector<HTMLButtonElement>('[data-ljpc-add-field]')
  // Without JavaScript the server's empty row adds a field; the button only makes sense with this script.
  add?.classList.remove('hidden')
  add?.addEventListener('click', () => {
    const html = template.innerHTML.split('__KEY__').join(newKey())
    rows.insertAdjacentHTML('beforeend', html)
    const row = rows.lastElementChild as HTMLElement
    syncOptions(row)
    row.querySelector<HTMLInputElement>('input[type="text"]')?.focus()
  })

  Sortable.create(rows, { handle: '.ljpc-drag-handle', animation: 150, draggable: '[data-ljpc-field-row]' })
}
```

`Resources/frontend/src/settings/select-all.ts`:

```ts
/** "Select all" per permission column: a header checkbox data-ljpc-select-all="<group>:<right>" drives the column. */
export function initSelectAll(root: ParentNode): void {
  root.querySelectorAll<HTMLInputElement>('[data-ljpc-select-all]').forEach((header) => {
    const column = header.dataset.ljpcSelectAll ?? ''
    const cells = (): HTMLInputElement[] => Array.from(root.querySelectorAll<HTMLInputElement>(`[data-ljpc-right="${column}"]`))
    const sync = (): void => {
      const all = cells()
      const checked = all.filter((cell) => cell.checked).length
      header.checked = all.length > 0 && checked === all.length
      header.indeterminate = checked > 0 && checked < all.length
    }

    header.classList.remove('hidden')
    header.addEventListener('change', () => {
      cells().forEach((cell) => {
        cell.checked = header.checked
      })
    })
    cells().forEach((cell) => cell.addEventListener('change', sync))
    sync()
  })
}
```

`Resources/frontend/src/settings/template-chips.ts`:

```ts
/** Placeholder buttons insert {{title}} or {{field:<id>}} at the cursor, and a preview shows the field names. */
export function initTemplateChips(root: ParentNode): void {
  const input = root.querySelector<HTMLInputElement>('[data-ljpc-template-input]')
  const preview = root.querySelector<HTMLElement>('[data-ljpc-template-preview]')
  if (!input) {
    return
  }
  const labels = new Map<string, string>()
  root.querySelectorAll<HTMLButtonElement>('[data-ljpc-insert]').forEach((button) => {
    const token = button.dataset.ljpcInsert ?? ''
    labels.set(token, (button.textContent ?? '').trim())
    button.addEventListener('click', () => {
      const start = input.selectionStart ?? input.value.length
      const end = input.selectionEnd ?? start
      input.value = input.value.slice(0, start) + token + input.value.slice(end)
      input.focus()
      input.setSelectionRange(start + token.length, start + token.length)
      render()
    })
  })

  function render(): void {
    if (!preview || !input) {
      return
    }
    preview.textContent = input.value.replace(/\{\{\s*(title|field:[^}\s]+)\s*\}\}/g, (match: string, name: string) => {
      const label = labels.get(`{{${name}}}`)

      return label ? `[${label}]` : match
    })
  }

  input.addEventListener('input', render)
  render()
}
```

`Resources/frontend/src/settings/copy.ts`:

```ts
/** Copy buttons for the export links: data-ljpc-copy="<input id>", data-ljpc-copied="<translated 'Copied'>". */
export function initCopyButtons(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-ljpc-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const input = document.getElementById(button.dataset.ljpcCopy ?? '') as HTMLInputElement | null
      if (!input) {
        return
      }
      try {
        await navigator.clipboard.writeText(input.value)
      } catch {
        input.select()
        return
      }
      const label = button.textContent
      button.textContent = button.dataset.ljpcCopied ?? label
      window.setTimeout(() => {
        button.textContent = label
      }, 1500)
    })
  })
}
```

`Resources/frontend/src/settings/confirm.ts`:

```ts
/** Destructive forms carry data-ljpc-confirm="<translated question>"; the browser's confirm dialog guards them. */
export function initConfirm(root: ParentNode): void {
  root.querySelectorAll<HTMLFormElement>('form[data-ljpc-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.ljpcConfirm ?? '')) {
        event.preventDefault()
      }
    })
  })
}
```

`Resources/frontend/src/settings/main.ts`:

```ts
import './settings.css'
import { initConfirm } from './confirm'
import { initCopyButtons } from './copy'
import { initFieldBuilder } from './field-builder'
import { initSelectAll } from './select-all'
import { initTemplateChips } from './template-chips'

// Module scripts run after the document is parsed, so the server-rendered settings are in place.
const root = document.querySelector<HTMLElement>('.ljpc-settings')
if (root) {
  root.querySelectorAll<HTMLElement>('[data-ljpc-fields]').forEach(initFieldBuilder)
  initSelectAll(root)
  initTemplateChips(root)
  initCopyButtons(root)
  initConfirm(root)
}
```

`Resources/frontend/src/settings/settings.css`:

```css
.ljpc-settings .ljpc-swatch {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 3px;
  vertical-align: middle;
}

.ljpc-settings .ljpc-drag-handle {
  color: #93a1af;
  cursor: grab;
}

.ljpc-settings .is-removed input[type='text'],
.ljpc-settings .is-removed select,
.ljpc-settings .is-removed textarea {
  opacity: 0.5;
  text-decoration: line-through;
}

.ljpc-settings .ljpc-matrix th,
.ljpc-settings .ljpc-matrix td {
  text-align: center;
}

.ljpc-settings .ljpc-matrix th:first-child,
.ljpc-settings .ljpc-matrix td:first-child {
  text-align: left;
}

.ljpc-settings .ljpc-template-preview {
  color: #4f5d6b;
}

.ljpc-settings .ljpc-chips .btn {
  margin: 0 4px 4px 0;
}

.ljpc-settings .ljpc-report pre {
  max-height: 200px;
  overflow: auto;
  white-space: pre-wrap;
}
```

`Http/Assets.php`:

```php
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
```

Append to `Resources/views/settings.blade.php`:

```blade
{!! \Modules\LJPcCalendarModule\Http\Assets::style('settings') !!}
{!! \Modules\LJPcCalendarModule\Http\Assets::script('settings') !!}
```

- [ ] **Step 5: Run the checks and build**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module/Resources/frontend
npm run check
npm run build
```

Expected:
- `check`: no type errors and `Tests 5 passed (5)`.
- `build`: `vite v8.3.3`, writing `../../Public/build/settings.js`, `settings.css` and a `chunks/` file for SortableJS.

Then build a second time and run `git status --porcelain -- ../../Public/build`. It must show only the new untracked files and no changed hashes, which proves the build is deterministic.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "AssetsTest|SettingsPageTest"'`
Expected: `OK`.

- [ ] **Step 6: Make CI rebuild and compare**

In `.github/workflows/release.yml`, insert these steps after the "Check for packages FreeScout already ships" step:

```yaml
      - name: Set up Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: Resources/frontend/package-lock.json

      # Public/build is committed so installs need no Node; the release must ship exactly what the sources build.
      - name: Check, build and compare the frontend
        working-directory: Resources/frontend
        run: |
          npm ci --include=dev
          npm run check
          npm run build
          git diff --exit-code -- ../../Public/build
          test -z "$(git status --porcelain -- ../../Public/build)"
```

- [ ] **Step 7: Document the frontend workflow in CLAUDE.md**

Add this section to `CLAUDE.md`, after "Tests and migration rehearsal":

```markdown
## Frontend

- Sources live in `Resources/frontend` (Vite, TypeScript, Vue for the calendar page and the conversation modal, a plain script for the settings pages). The build goes to `Public/build/`, which is committed: installs have no Node.
- This shell sets `NODE_ENV=production` and npm `omit=dev`. Always install with `npm ci --include=dev` (or `npm install --include=dev`), or the build tools are missing.
- `npm run check` (vue-tsc and Vitest) and `npm run build` in `Resources/frontend`. Commit `Public/build` with the sources; CI rebuilds and fails when they differ.
- TypeScript stays on 6.x: vue-tsc 3.3 does not run on TypeScript 7 yet.
- Bundles load through `Http/Assets.php` as ES modules with the CSP nonce. Never add them to the `javascripts` filter.
- Never style FullCalendar 7 through `.fc-*` class names: they are hashed. Use the `--fc-classic-*` variables and our class hooks (`eventClass`, `popoverClass`).
- No `<style>` blocks in Vue components: each entry imports one CSS file, so the build emits exactly one CSS file per entry.
```

- [ ] **Step 8: Check in a browser**

Open the settings pages from Task 3 on https://freescout.ddev.site and confirm each of these:
- "Add field" appears and adds a row.
- Dragging a row by its handle and saving keeps the new order.
- The options box shows only for Dropdown and Multiple choice.
- The select-all boxes appear in the matrix headers and tick a whole column.
- The title template buttons insert placeholders and the preview shows the field names.
- "Copy" copies an export link.
- "Make a new link" and "Delete calendar" ask for confirmation.
- The browser console stays free of errors and CSP violations.

- [ ] **Step 9: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add .gitignore .github/workflows/release.yml CLAUDE.md Http/Assets.php Tests/Unit/AssetsTest.php Resources/views/settings.blade.php Resources/frontend Public/build
git commit -m "feat: add the Vite TypeScript toolchain and enhance the settings pages"
```

---

### Task 5: Shared frontend foundation, strings and page config

This task builds the TypeScript and Vue building blocks that the calendar page (Task 6) and the conversation modal (Task 7) both use:
- the API client
- translations
- time conversions in the agent's timezone (Temporal)
- the recurrence helpers
- the event form model
- the formatters
- a Bootstrap 3 modal
- the custom field input
- the start and end inputs

On the PHP side it adds the translated string dictionary with a check that keeps it in sync with the sources, and it extends the page config with the agent's language, clock format and strings.

**Files:**
- Create in `Resources/frontend/src/shared/`: `types.ts`, `config.ts`, `i18n.ts`, `storage.ts`, `alerts.ts`, `api.ts`, `time.ts`, `recurrence.ts`, `form-model.ts`, `format.ts`, `Modal.vue`, `FieldInput.vue`, `TimeFields.vue`, `shared.css`
- Create in `Resources/frontend/tests/`: `time.test.ts`, `recurrence.test.ts`, `form-model.test.ts`, `shared.test.ts`
- Create: `Http/FrontendStrings.php`, `Tests/Unit/FrontendStringsTest.php`
- Modify: `Http/FrontendConfig.php`, `Tests/Feature/IntegrationHooksTest.php`

**Interfaces:**
- Consumes:
  - The agent API shapes from W1 and Task 1, mirrored in `types.ts`.
  - `SettingsPage::url()` from Task 3.
- Produces, in TypeScript:
  - `readConfig<T>(id)` and `BaseConfig` (`locale`, `rtl`, `timezone`, `hour12`, `csrf`, `strings`).
  - `setStrings()` and `t(key, replace)`.
  - `createApi(csrf): Api` with `get`, `post`, `patch` and `delete`. Failures throw `ApiError`, which carries `status` and `fieldErrors()`.
  - `routeWith(route, id)`.
  - Time helpers: `toParts`, `fromParts`, `addDays`, `daysBetween`, `nextFullHour`, `addMinutes`, `minutesBetween`, `shift`, `dateOf`, `isDate`.
  - Recurrence helpers: `WEEKDAYS`, `weekdayCode`, `defaultRecurrence`, `weekdayPosition`, `shiftRecurrence`, `describeRecurrence`.
  - The form model: `FormState`, `Scope`, `initialFieldValues`, `emptyForm`, `formFromRange`, `formFromEvent`, `withAllDay`, `withStart`, `apiTimes`, `toCreatePayload`, `toSeriesPatch`, `toOccurrencePatch`.
  - Formatters: `formatWhen`, `displayFieldValue`, `reminderOptions`, `reminderLabel`.
  - Storage and notices: `readStored`, `writeStored`, `notify`.
  - Components: `Modal.vue` (props `title`, `size`; emits `close`; slots default and `footer`), `FieldInput.vue` (props `field`, `modelValue`, `error`, `idPrefix`) and `TimeFields.vue` (props `state`, `timeZone`, `errors`, `idPrefix`; emits `change`).
- Produces, in PHP:
  - `FrontendStrings::KEYS` and `FrontendStrings::all(): array<string, string>`.
  - `FrontendConfig::page(User)` returns the base fields plus `user`, `routes` (`calendars`, `events`, `event` with `__ID__`, and `settings` for admins or `null`) and `migration_complete`.

- [ ] **Step 1: Write the failing tests**

`Resources/frontend/tests/time.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { addDays, addMinutes, dateOf, daysBetween, fromParts, minutesBetween, shift, toParts } from '../src/shared/time'

const zero = { years: 0, months: 0, days: 0, milliseconds: 0 }

describe('time', () => {
  it('splits an API instant into form parts in the agent zone, not the browser zone', () => {
    expect(toParts('2026-10-06T08:00:00Z', 'Europe/Amsterdam')).toEqual({ date: '2026-10-06', time: '10:00' })
    expect(toParts('2026-10-06T23:30:00+00:00', 'Australia/Sydney')).toEqual({ date: '2026-10-07', time: '10:30' })
  })

  it('keeps dates as dates', () => {
    expect(toParts('2026-10-06', 'Europe/Amsterdam')).toEqual({ date: '2026-10-06', time: '' })
  })

  it('builds offset-free timed values that the server reads in the agent zone', () => {
    expect(fromParts('2026-10-06', '09:15')).toBe('2026-10-06T09:15')
  })

  it('counts days and adds them', () => {
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01')
    expect(daysBetween('2026-10-06', '2026-10-09')).toBe(3)
  })

  it('adds wall-clock minutes across the autumn DST change', () => {
    expect(addMinutes('2026-10-25', '01:30', 120, 'Europe/Amsterdam')).toEqual({ date: '2026-10-25', time: '02:30' })
    expect(minutesBetween({ date: '2026-10-25', time: '00:00' }, { date: '2026-10-25', time: '04:00' }, 'Europe/Amsterdam')).toBe(300)
  })

  it('shifts a series start by a drag delta and keeps the wall clock over DST', () => {
    expect(shift('2026-10-20T10:00:00+02:00', { ...zero, days: 7 }, 'Europe/Amsterdam')).toBe('2026-10-27T10:00:00+01:00')
    expect(shift('2026-10-20T10:00:00+02:00', { ...zero, milliseconds: 5_400_000 }, 'Europe/Amsterdam')).toBe('2026-10-20T11:30:00+02:00')
    expect(shift('2026-10-30', { ...zero, days: 3 }, 'Europe/Amsterdam')).toBe('2026-11-02')
  })

  it('finds the date of an instant in the agent zone', () => {
    expect(dateOf('2026-10-06T23:30:00Z', 'Europe/Amsterdam')).toBe('2026-10-07')
    expect(dateOf('2026-10-06', 'Asia/Tokyo')).toBe('2026-10-06')
  })
})
```

`Resources/frontend/tests/recurrence.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { defaultRecurrence, describeRecurrence, shiftRecurrence, weekdayPosition } from '../src/shared/recurrence'

describe('recurrence', () => {
  it('anchors new rules on the start date', () => {
    expect(defaultRecurrence('WEEKLY', '2026-10-07')).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['WE'] })
    expect(defaultRecurrence('MONTHLY', '2026-10-07')).toEqual({ freq: 'MONTHLY', interval: 1, bymonthday: 7 })
    expect(defaultRecurrence('DAILY', '2026-10-07')).toEqual({ freq: 'DAILY', interval: 1 })
  })

  it('names the position of a weekday in its month, with the fifth as the last', () => {
    expect(weekdayPosition('2026-10-05')).toBe(1)
    expect(weekdayPosition('2026-10-26')).toBe(4)
    expect(weekdayPosition('2026-10-29')).toBe(-1)
  })

  it('moves weekly weekdays along with a dragged series', () => {
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'] }, '2026-10-05', '2026-10-06').byday).toEqual(['TU', 'TH'])
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['SU'] }, '2026-10-11', '2026-10-12').byday).toEqual(['MO'])
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['MO'] }, '2026-10-05', '2026-10-02').byday).toEqual(['FR'])
  })

  it('moves monthly days and weekday positions to the new start', () => {
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, bymonthday: 5 }, '2026-10-05', '2026-10-07').bymonthday).toBe(7)
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['MO'], bysetpos: 1 }, '2026-10-05', '2026-10-13')).toEqual({ freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: 2 })
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['MO'], bysetpos: -1 }, '2026-10-26', '2026-10-27').bysetpos).toBe(-1)
  })

  it('leaves the rule alone when the date does not change', () => {
    const rule = { freq: 'WEEKLY' as const, interval: 2, byday: ['MO'] }
    expect(shiftRecurrence(rule, '2026-10-05', '2026-10-05')).toBe(rule)
  })

  it('describes rules in plain language', () => {
    expect(describeRecurrence({ freq: 'WEEKLY', interval: 2, byday: ['MO', 'WE'] }, 'en')).toBe('Every 2 weeks on Monday, Wednesday')
    expect(describeRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: -1, count: 5 }, 'en')).toBe('Every month on the last Tuesday, 5 times')
    expect(describeRecurrence({ freq: 'DAILY', interval: 1, until: '2026-12-31' }, 'en')).toBe('Every day, until Dec 31, 2026')
  })
})
```

`Resources/frontend/tests/form-model.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { emptyForm, formFromEvent, formFromRange, toCreatePayload, toOccurrencePatch, toSeriesPatch, withAllDay, withStart } from '../src/shared/form-model'
import type { EventDetail, FieldDefinition } from '../src/shared/types'

const tz = 'Europe/Amsterdam'
const fields: FieldDefinition[] = [
  { id: 1001, name: 'Email', type: 'email', required: false, options: [] },
  { id: 1002, name: 'Paid', type: 'boolean', required: false, options: [] },
]
const event: EventDetail = {
  id: 7, calendar_id: 1, uid: 'u7', title: 'Standup', title_input: 'Standup', location: '', description: '',
  all_day: false, start: '2026-10-05T09:00:00+02:00', end: '2026-10-05T09:15:00+02:00',
  recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] }, recurrence_custom: false, is_recurring: true,
  field_values: { 1001: 'a@example.test' }, reminder_minutes: null, author: null, conversation: null, editable: true,
}

describe('form model', () => {
  it('starts a new event one hour long with booleans off', () => {
    const state = emptyForm(1, { date: '2026-10-06', time: '23:30' }, tz, fields)
    expect([state.endDate, state.endTime]).toEqual(['2026-10-07', '00:30'])
    expect(state.fields).toEqual({ 1001: '', 1002: false })
  })

  it('turns an all-day selection into inclusive end dates', () => {
    const state = formFromRange(1, '2026-10-06', '2026-10-08', tz)
    expect([state.allDay, state.startDate, state.endDate]).toEqual([true, '2026-10-06', '2026-10-07'])
  })

  it('reads a timed selection in the agent zone', () => {
    const state = formFromRange(1, '2026-10-06T10:00:00+02:00', '2026-10-06T11:30:00+02:00', tz)
    expect([state.startTime, state.endTime]).toEqual(['10:00', '11:30'])
  })

  it('creates with exclusive all-day ends and the chosen calendar fields only', () => {
    const state = { ...formFromRange(1, '2026-10-06', '2026-10-07', tz, fields), title: 'Day off', fields: { 1001: 'x@example.test', 1002: true, 9: 'stale' } }
    expect(toCreatePayload(state, fields)).toEqual({
      calendar_id: 1, title: 'Day off', all_day: true, start: '2026-10-06', end: '2026-10-07', location: '', description: '',
      field_values: { 1001: 'x@example.test', 1002: true }, reminder_minutes: null,
    })
  })

  it('keeps the length when the start moves', () => {
    const state = withStart(emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), '2026-10-07', '15:00', tz)
    expect([state.endDate, state.endTime]).toEqual(['2026-10-07', '16:00'])
  })

  it('switches between all-day and timed sensibly', () => {
    const timed = emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz)
    const allDay = withAllDay(timed, true, tz)
    expect([allDay.allDay, allDay.startDate, allDay.endDate]).toEqual([true, '2026-10-06', '2026-10-06'])
    expect(withAllDay(allDay, false, tz).startTime).toBe('10:00')
  })

  it('sends only what changed for a series, plus the rule of a repeating event', () => {
    const original = formFromEvent(event, tz, fields)
    expect(toSeriesPatch(original, { ...original, title: 'Daily standup' }, fields, true)).toEqual({
      scope: 'series', title: 'Daily standup', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] },
    })
  })

  it('sends times, fields and the calendar when they change', () => {
    const original = formFromEvent(event, tz, fields)
    const changed = { ...original, calendarId: 2, startTime: '10:00', endTime: '10:15' }
    expect(toSeriesPatch(original, changed, fields, true)).toMatchObject({
      all_day: false, start: '2026-10-05T10:00', end: '2026-10-05T10:15', calendar_id: 2, field_values: { 1001: 'a@example.test', 1002: false },
    })
  })

  it('never sends a custom rule this form cannot show', () => {
    const original = { ...formFromEvent(event, tz, fields), recurrence: null, recurrenceCustom: true }
    expect(toSeriesPatch(original, { ...original, title: 'x' }, fields, true)).toEqual({ scope: 'series', title: 'x' })
  })

  it('keeps series keys out of an occurrence edit', () => {
    const original = formFromEvent(event, tz, fields)
    const patch = toOccurrencePatch(original, { ...original, title: 'Moved', startTime: '11:00', endTime: '11:15', reminder: 5, calendarId: 2 }, '20261005T070000Z')
    expect(patch).toEqual({ scope: 'occurrence', recurrence_id: '20261005T070000Z', title: 'Moved', all_day: false, start: '2026-10-05T11:00', end: '2026-10-05T11:15' })
  })
})
```

`Resources/frontend/tests/shared.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, createApi, routeWith } from '../src/shared/api'
import { setStrings, t } from '../src/shared/i18n'

afterEach(() => {
  vi.unstubAllGlobals()
  setStrings({})
})

describe('i18n', () => {
  it('translates and fills Laravel-style placeholders, longest name first', () => {
    setStrings({ 'Every :count weeks': 'Elke :count weken', ':counter of :count': ':counter van :count' })
    expect(t('Every :count weeks', { count: 2 })).toBe('Elke 2 weken')
    expect(t(':counter of :count', { count: 5, counter: 3 })).toBe('3 van 5')
    expect(t('Untranslated')).toBe('Untranslated')
  })
})

describe('api', () => {
  it('sends JSON with the CSRF token and returns the body', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    await expect(createApi('token').post('/x', { a: 1 })).resolves.toEqual({ ok: true })
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.method).toBe('POST')
    expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('token')
    expect(init.body).toBe('{"a":1}')
  })

  it('turns validation errors into field messages', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'The given data was invalid.', errors: { 'recurrence.until': ['Invalid end date.'] } }), { status: 422 })))
    const error = await createApi('t').patch('/x', {}).catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).message).toBe('Invalid end date.')
    expect((error as ApiError).fieldErrors()).toEqual({ 'recurrence.until': 'Invalid end date.' })
  })

  it('reports a network failure as status 0', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline') }))
    await expect(createApi('t').get('/x')).rejects.toMatchObject({ status: 0 })
  })

  it('fills route placeholders', () => {
    expect(routeWith('/calendar/api/events/__ID__', 'e12')).toBe('/calendar/api/events/e12')
    expect(routeWith('/calendar/api/events/__ID__', 'a@b/c')).toBe('/calendar/api/events/a%40b%2Fc')
  })
})
```

`Tests/Unit/FrontendStringsTest.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Unit;

use Modules\LJPcCalendarModule\Http\FrontendStrings;
use Modules\LJPcCalendarModule\Tests\TestCase;

class FrontendStringsTest extends TestCase
{
    /** Every t('...') literal in the frontend sources must be listed, and every listed key must still be used. */
    public function test_the_key_list_matches_the_frontend_sources(): void
    {
        $used = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../Resources/frontend/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!preg_match('/\.(ts|vue)$/', $file->getFilename())) {
                continue;
            }
            preg_match_all('/\bt\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $literal) {
                $used[] = stripcslashes(substr($literal, 1, -1));
            }
        }
        $used = array_values(array_unique($used));

        $this->assertSame([], array_values(array_diff($used, FrontendStrings::KEYS)), 'Used in the frontend but missing from FrontendStrings::KEYS');
        $this->assertSame([], array_values(array_diff(FrontendStrings::KEYS, $used)), 'Listed in FrontendStrings::KEYS but no longer used');
        $this->assertSame(count(FrontendStrings::KEYS), count(array_unique(FrontendStrings::KEYS)), 'FrontendStrings::KEYS has duplicates');
    }

    public function test_every_key_is_translated_with_the_key_as_fallback(): void
    {
        $strings = FrontendStrings::all();

        $this->assertSame(FrontendStrings::KEYS, array_keys($strings));
        $this->assertSame('Every :count weeks', $strings['Every :count weeks']);
    }
}
```

Add to `Tests/Feature/IntegrationHooksTest.php`. It also needs `use Modules\LJPcCalendarModule\Http\SettingsPage;`.

```php
    public function test_page_config_follows_the_agents_language_clock_and_role(): void
    {
        $agent = $this->makeUser(['locale' => 'nl', 'time_format' => \App\User::TIME_FORMAT_12]);
        app()->setLocale('nl');

        $config = FrontendConfig::page($agent);

        $this->assertSame(['nl', true, null], [$config['locale'], $config['hour12'], $config['routes']['settings']]);
        $this->assertFalse($config['rtl']);
        $this->assertArrayHasKey('Every :count weeks', $config['strings']);
        $this->assertStringContainsString('__ID__', $config['routes']['event']);
        $this->assertSame(SettingsPage::url(), FrontendConfig::page($this->makeAdmin())['routes']['settings']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd Resources/frontend && npx vitest run`
Expected: the four new test files fail, because the `../src/shared/*` modules are missing. The settings tests still pass.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "FrontendStringsTest|page_config_follows"'`
Expected: errors. `FrontendStrings` doesn't exist and the config has no `hour12`.

- [ ] **Step 3: Implement the shared TypeScript modules**

`Resources/frontend/src/shared/types.ts`:

```ts
export type Provider = 'local' | 'ics' | 'caldav' | 'google' | 'microsoft'

export type FieldType = 'text' | 'number' | 'dropdown' | 'boolean' | 'multiselect' | 'date' | 'email' | 'source'

export interface FieldDefinition {
  id: string | number
  name: string
  type: FieldType
  required: boolean
  options: string[]
}

export interface Rights {
  show_in_dashboard: boolean
  can_view: boolean
  can_create: boolean
  can_edit: boolean
}

/** GET /calendar/api/calendars */
export interface CalendarInfo {
  id: number
  name: string
  color: string
  provider: Provider
  display_priority: number
  fields: FieldDefinition[]
  has_title_template: boolean
  rights: Rights
  writable: boolean
  sync_failed: boolean
}

/** GET /calendar/api/events: one row per occurrence, times in the agent's timezone. */
export interface Occurrence {
  id: string
  event_id: number
  calendar_id: number
  recurrence_id: string | null
  title: string
  start: string
  end: string
  all_day: boolean
  status: string
  is_recurring: boolean
  editable: boolean
}

export type Frequency = 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY'

export interface Recurrence {
  freq: Frequency
  interval: number
  byday?: string[]
  bysetpos?: number
  bymonthday?: number
  until?: string
  count?: number
}

export type FieldValue = string | number | boolean | string[]

/** GET /calendar/api/events/{ref} and the create/update responses. */
export interface EventDetail {
  id: number
  calendar_id: number
  uid: string
  title: string
  title_input: string | null
  location: string
  description: string
  all_day: boolean
  start: string
  end: string
  recurrence: Recurrence | null
  recurrence_custom: boolean
  is_recurring: boolean
  field_values: Record<string, FieldValue>
  reminder_minutes: number | null
  author: { id: number; name: string } | null
  conversation: { id: number; number: number; url: string } | null
  editable: boolean
}
```

`Resources/frontend/src/shared/config.ts`:

```ts
/** Reads the server config from a non-executed JSON script tag (the server never prints Blade inside JavaScript). */
export function readConfig<T>(id: string): T {
  const element = document.getElementById(id)
  if (!element || !element.textContent) {
    throw new Error(`Missing config element #${id}`)
  }

  return JSON.parse(element.textContent) as T
}

/** Fields every bundle receives (see Http/FrontendConfig.php). */
export interface BaseConfig {
  locale: string
  rtl: boolean
  timezone: string
  hour12: boolean
  csrf: string
  strings: Record<string, string>
}
```

`Resources/frontend/src/shared/i18n.ts`:

```ts
let dictionary: Record<string, string> = {}

export function setStrings(strings: Record<string, string>): void {
  dictionary = strings
}

/** Translates an English key with the server's dictionary. Placeholders use Laravel's ":name" form. */
export function t(key: string, replace: Record<string, string | number> = {}): string {
  let text = dictionary[key] ?? key
  for (const name of Object.keys(replace).sort((a, b) => b.length - a.length)) {
    text = text.split(`:${name}`).join(String(replace[name]))
  }

  return text
}
```

`Resources/frontend/src/shared/storage.ts`:

```ts
/** Per-browser preferences. Storage can be blocked (private mode, policies), so every access is guarded. */
export function readStored<T>(key: string, fallback: T): T {
  try {
    const raw = window.localStorage.getItem(key)

    return raw === null ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

export function writeStored(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // ponytail: a preference that can't be stored is simply not remembered
  }
}
```

`Resources/frontend/src/shared/alerts.ts`:

```ts
/** Uses FreeScout's own floating alerts (main.js escapes the message), so notices look like the rest of the app. */
export function notify(type: 'success' | 'error', message: string): void {
  if (typeof window.showFloatingAlert === 'function') {
    window.showFloatingAlert(type, message)
  } else {
    window.alert(message)
  }
}
```

`Resources/frontend/src/shared/api.ts`:

```ts
import { t } from './i18n'

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** First validation message per field, keyed like the server ("title", "recurrence.until", "field_values.12"). */
  fieldErrors(): Record<string, string> {
    const result: Record<string, string> = {}
    for (const [key, messages] of Object.entries(this.errors)) {
      if (messages[0]) {
        result[key] = messages[0]
      }
    }

    return result
  }
}

export interface Api {
  get<T>(url: string, query?: Record<string, string>): Promise<T>
  post<T>(url: string, body: unknown): Promise<T>
  patch<T>(url: string, body: unknown): Promise<T>
  delete<T>(url: string, body?: unknown): Promise<T>
}

interface ErrorBody {
  message?: string
  errors?: Record<string, string[]>
}

export function createApi(csrf: string): Api {
  async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    let response: Response
    try {
      response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
    } catch {
      throw new ApiError(0, t('The server could not be reached. Check your connection and try again.'))
    }
    const data = (await response.json().catch(() => null)) as unknown
    if (!response.ok) {
      const error = (data ?? {}) as ErrorBody
      throw new ApiError(response.status, messageFor(response.status, error), error.errors ?? {})
    }

    return data as T
  }

  return {
    get: (url, query) => request('GET', query ? `${url}?${new URLSearchParams(query).toString()}` : url),
    post: (url, body) => request('POST', url, body),
    patch: (url, body) => request('PATCH', url, body),
    delete: (url, body) => request('DELETE', url, body),
  }
}

function messageFor(status: number, body: ErrorBody): string {
  switch (status) {
    case 422: {
      const first = Object.values(body.errors ?? {})[0]?.[0]

      return first ?? body.message ?? t('Check the highlighted fields.')
    }
    case 403:
      return t('You don\'t have permission to do this.')
    case 404:
      return t('This event or calendar no longer exists, or you don\'t have access to it.')
    case 419:
      return t('Your session has expired. Reload the page and try again.')
    case 409:
    case 502:
    case 503:
      return body.message ?? t('Something went wrong. Try again.')
    default:
      return t('Something went wrong. Try again.')
  }
}

/** Fills the "__ID__" placeholder of a route from the config. */
export function routeWith(route: string, id: string | number): string {
  return route.replace('__ID__', encodeURIComponent(String(id)))
}
```

`Resources/frontend/src/shared/time.ts`:

```ts
import { Temporal } from 'temporal-polyfill'

/** FullCalendar's Duration (drop/resize deltas). */
export interface Delta {
  years: number
  months: number
  days: number
  milliseconds: number
}

const DATE = /^\d{4}-\d{2}-\d{2}$/

export function isDate(value: string): boolean {
  return DATE.test(value)
}

/** Splits an API value into form parts in the agent's timezone (never the browser's). */
export function toParts(value: string, timeZone: string): { date: string; time: string } {
  if (isDate(value)) {
    return { date: value, time: '' }
  }
  const zoned = Temporal.Instant.from(value).toZonedDateTimeISO(timeZone)

  return { date: zoned.toPlainDate().toString(), time: zoned.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/** A timed form value for the API: no offset, so the server reads it in the agent's FreeScout timezone. */
export function fromParts(date: string, time: string): string {
  return `${date}T${time}`
}

export function addDays(date: string, days: number): string {
  return Temporal.PlainDate.from(date).add({ days }).toString()
}

/** Whole days from a to b (dates), for all-day lengths. */
export function daysBetween(a: string, b: string): number {
  return Temporal.PlainDate.from(a).until(Temporal.PlainDate.from(b), { largestUnit: 'days' }).days
}

export function todayIn(timeZone: string): string {
  return Temporal.Now.plainDateISO(timeZone).toString()
}

/** The next full hour in the agent's timezone, as form parts: the default start of a new event. */
export function nextFullHour(timeZone: string): { date: string; time: string } {
  const now = Temporal.Now.zonedDateTimeISO(timeZone)
  const next = now.round({ smallestUnit: 'hour', roundingMode: 'ceil' })
  const start = Temporal.ZonedDateTime.compare(next, now) === 0 ? next.add({ hours: 1 }) : next

  return { date: start.toPlainDate().toString(), time: start.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/** Adds minutes to a form date and time (wall clock, so it's DST-safe in the agent's zone). */
export function addMinutes(date: string, time: string, minutes: number, timeZone: string): { date: string; time: string } {
  const zoned = Temporal.PlainDateTime.from(`${date}T${time}`).toZonedDateTime(timeZone).add({ minutes })

  return { date: zoned.toPlainDate().toString(), time: zoned.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/** Minutes between two form date-times in the agent's zone. */
export function minutesBetween(start: { date: string; time: string }, end: { date: string; time: string }, timeZone: string): number {
  const a = Temporal.PlainDateTime.from(`${start.date}T${start.time}`).toZonedDateTime(timeZone)
  const b = Temporal.PlainDateTime.from(`${end.date}T${end.time}`).toZonedDateTime(timeZone)

  return Math.round(a.until(b, { largestUnit: 'hours' }).total({ unit: 'minutes' }))
}

/** Shifts an API value (ISO with offset, or a date) by a FullCalendar delta, keeping the wall clock in the agent's zone. */
export function shift(value: string, delta: Delta, timeZone: string): string {
  const calendarPart = { years: delta.years, months: delta.months, days: delta.days }
  if (isDate(value)) {
    return Temporal.PlainDate.from(value).add(calendarPart).toString()
  }

  return Temporal.Instant.from(value)
    .toZonedDateTimeISO(timeZone)
    .add(calendarPart)
    .add({ milliseconds: delta.milliseconds })
    .toString({ timeZoneName: 'never', smallestUnit: 'second' })
}

/** The date (in the agent's zone) an API value falls on. */
export function dateOf(value: string, timeZone: string): string {
  return isDate(value) ? value : Temporal.Instant.from(value).toZonedDateTimeISO(timeZone).toPlainDate().toString()
}

/** ISO weekday 1 (Monday) to 7 (Sunday) of a date. */
export function weekdayOf(date: string): number {
  return Temporal.PlainDate.from(date).dayOfWeek
}
```

`Resources/frontend/src/shared/recurrence.ts`:

```ts
import { Temporal } from 'temporal-polyfill'
import { t } from './i18n'
import { daysBetween } from './time'
import type { Frequency, Recurrence } from './types'

/** RRULE weekday codes in ISO order (Monday first), matching Temporal's dayOfWeek - 1. */
export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const

export function weekdayCode(date: string): string {
  return WEEKDAYS[Temporal.PlainDate.from(date).dayOfWeek - 1] as string
}

/** Defaults when the agent picks a frequency, anchored on the event's start date. */
export function defaultRecurrence(freq: Frequency, startDate: string): Recurrence {
  if (freq === 'WEEKLY') {
    return { freq, interval: 1, byday: [weekdayCode(startDate)] }
  }
  if (freq === 'MONTHLY') {
    return { freq, interval: 1, bymonthday: Temporal.PlainDate.from(startDate).day }
  }

  return { freq, interval: 1 }
}

/** The position of a date's weekday in its month: 1-4, or -1 for the last one. */
export function weekdayPosition(date: string): number {
  const day = Temporal.PlainDate.from(date)
  const position = Math.ceil(day.day / 7)

  return position > 4 ? -1 : position
}

/**
 * Keeps a series' rule on the same pattern when its start moves from one date to another (dragging a series):
 * weekly weekdays move along, monthly day or weekday positions follow the new start.
 */
export function shiftRecurrence(rule: Recurrence, fromDate: string, toDate: string): Recurrence {
  const days = daysBetween(fromDate, toDate)
  if (days === 0) {
    return rule
  }
  const next: Recurrence = { ...rule }
  if (rule.freq === 'WEEKLY' && rule.byday?.length) {
    const offset = ((days % 7) + 7) % 7
    next.byday = rule.byday
      .map((code) => WEEKDAYS[(WEEKDAYS.indexOf(code as (typeof WEEKDAYS)[number]) + offset) % 7] as string)
      .sort((a, b) => WEEKDAYS.indexOf(a as (typeof WEEKDAYS)[number]) - WEEKDAYS.indexOf(b as (typeof WEEKDAYS)[number]))
  }
  if (rule.freq === 'MONTHLY') {
    const date = Temporal.PlainDate.from(toDate)
    if (rule.bymonthday !== undefined) {
      next.bymonthday = date.day
    }
    if (rule.bysetpos !== undefined) {
      next.byday = [weekdayCode(toDate)]
      next.bysetpos = rule.bysetpos === -1 && date.day + 7 > date.daysInMonth ? -1 : weekdayPosition(toDate)
    }
  }

  return next
}

function weekdayName(code: string, locale: string): string {
  // 2024-01-01 is a Monday, so index i is WEEKDAYS[i].
  const index = WEEKDAYS.indexOf(code as (typeof WEEKDAYS)[number])
  const date = new Date(Date.UTC(2024, 0, 1 + index))

  return new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(date)
}

/** A one-line, translated summary such as "Every 2 weeks on Monday, Wednesday, until 31 Dec 2026". */
export function describeRecurrence(rule: Recurrence, locale: string): string {
  const every: Record<Frequency, (count: number) => string> = {
    DAILY: (count) => (count > 1 ? t('Every :count days', { count }) : t('Every day')),
    WEEKLY: (count) => (count > 1 ? t('Every :count weeks', { count }) : t('Every week')),
    MONTHLY: (count) => (count > 1 ? t('Every :count months', { count }) : t('Every month')),
    YEARLY: (count) => (count > 1 ? t('Every :count years', { count }) : t('Every year')),
  }
  const parts = [every[rule.freq](rule.interval)]

  if (rule.freq === 'WEEKLY' && rule.byday?.length) {
    parts.push(t('on :days', { days: rule.byday.map((code) => weekdayName(code, locale)).join(', ') }))
  }
  if (rule.freq === 'MONTHLY' && rule.bymonthday !== undefined) {
    parts.push(t('on day :day', { day: rule.bymonthday }))
  }
  if (rule.freq === 'MONTHLY' && rule.bysetpos !== undefined && rule.byday?.[0]) {
    const positions: Record<number, string> = { 1: t('first'), 2: t('second'), 3: t('third'), 4: t('fourth'), [-1]: t('last') }
    parts.push(t('on the :position :weekday', { position: positions[rule.bysetpos] ?? '', weekday: weekdayName(rule.byday[0], locale) }))
  }

  let text = parts.join(' ')
  if (rule.until) {
    const until = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${rule.until}T00:00:00Z`))
    text += ', ' + t('until :date', { date: until })
  } else if (rule.count) {
    text += ', ' + t(':count times', { count: rule.count })
  }

  return text
}
```

`Resources/frontend/src/shared/form-model.ts`:

```ts
import { addDays, addMinutes, daysBetween, fromParts, isDate, minutesBetween, toParts } from './time'
import type { EventDetail, FieldDefinition, FieldValue, Recurrence } from './types'

/** What the event form edits. All-day end dates are inclusive here; the API's are exclusive. */
export interface FormState {
  calendarId: number | null
  title: string
  allDay: boolean
  startDate: string
  startTime: string
  endDate: string
  endTime: string
  location: string
  description: string
  recurrence: Recurrence | null
  recurrenceCustom: boolean
  reminder: number | null
  fields: Record<string, FieldValue>
}

export type Scope = 'series' | 'occurrence'

const DEFAULT_TIME = '09:00'

/** Values a new event starts with: booleans off, everything else empty. */
export function initialFieldValues(fields: FieldDefinition[], prefill: Partial<Record<string, FieldValue>> = {}): Record<string, FieldValue> {
  const values: Record<string, FieldValue> = {}
  for (const field of fields) {
    const id = String(field.id)
    const given = prefill[id]
    if (given !== undefined) {
      values[id] = given
    } else if (field.type === 'boolean') {
      values[id] = false
    } else if (field.type === 'multiselect') {
      values[id] = []
    } else {
      values[id] = ''
    }
  }

  return values
}

export function emptyForm(calendarId: number | null, start: { date: string; time: string }, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const end = addMinutes(start.date, start.time, 60, timeZone)

  return {
    calendarId,
    title: '',
    allDay: false,
    startDate: start.date,
    startTime: start.time,
    endDate: end.date,
    endTime: end.time,
    location: '',
    description: '',
    recurrence: null,
    recurrenceCustom: false,
    reminder: null,
    fields: initialFieldValues(fields),
  }
}

/** A selection on the calendar (FullCalendar start/end strings: dates with an exclusive end, or ISO with offset). */
export function formFromRange(calendarId: number | null, start: string, end: string, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const state = emptyForm(calendarId, { date: start.slice(0, 10), time: DEFAULT_TIME }, timeZone, fields)
  if (isDate(start)) {
    return { ...state, allDay: true, startDate: start, startTime: '', endDate: addDays(end, -1), endTime: '' }
  }
  const from = toParts(start, timeZone)
  const to = toParts(end, timeZone)

  return { ...state, startDate: from.date, startTime: from.time, endDate: to.date, endTime: to.time }
}

export function formFromEvent(event: EventDetail, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const start = toParts(event.start, timeZone)
  const end = toParts(event.end, timeZone)

  return {
    calendarId: event.calendar_id,
    title: event.title_input ?? event.title,
    allDay: event.all_day,
    startDate: start.date,
    startTime: start.time,
    endDate: event.all_day ? addDays(end.date, -1) : end.date,
    endTime: end.time,
    location: event.location,
    description: event.description,
    recurrence: event.recurrence,
    recurrenceCustom: event.recurrence_custom,
    reminder: event.reminder_minutes,
    fields: initialFieldValues(fields, event.field_values),
  }
}

/** Switching all-day on or off keeps the dates and picks sensible times. */
export function withAllDay(state: FormState, allDay: boolean, timeZone: string): FormState {
  if (allDay === state.allDay) {
    return state
  }
  if (allDay) {
    const endDate = state.endDate < state.startDate ? state.startDate : state.endDate

    return { ...state, allDay, endDate }
  }
  const startTime = state.startTime || DEFAULT_TIME
  const end = addMinutes(state.startDate, startTime, 60, timeZone)
  const endDate = state.endDate > state.startDate ? state.endDate : end.date

  return { ...state, allDay, startTime, endDate, endTime: state.endTime || end.time }
}

/** Moving the start keeps the event's length, like every calendar app does. */
export function withStart(state: FormState, date: string, time: string, timeZone: string): FormState {
  if (state.allDay) {
    const length = Math.max(0, daysBetween(state.startDate, state.endDate))

    return { ...state, startDate: date, endDate: addDays(date, length) }
  }
  const minutes = Math.max(0, minutesBetween({ date: state.startDate, time: state.startTime }, { date: state.endDate, time: state.endTime }, timeZone))
  const end = addMinutes(date, time, minutes, timeZone)

  return { ...state, startDate: date, startTime: time, endDate: end.date, endTime: end.time }
}

export function apiTimes(state: FormState): { all_day: boolean; start: string; end: string } {
  if (state.allDay) {
    return { all_day: true, start: state.startDate, end: addDays(state.endDate, 1) }
  }

  return { all_day: false, start: fromParts(state.startDate, state.startTime), end: fromParts(state.endDate, state.endTime) }
}

/** Only the fields of the chosen calendar are sent. */
function fieldValuesFor(state: FormState, fields: FieldDefinition[]): Record<string, FieldValue> {
  const values: Record<string, FieldValue> = {}
  for (const field of fields) {
    const id = String(field.id)
    if (state.fields[id] !== undefined) {
      values[id] = state.fields[id] as FieldValue
    }
  }

  return values
}

export function toCreatePayload(state: FormState, fields: FieldDefinition[]): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    calendar_id: state.calendarId,
    title: state.title,
    ...apiTimes(state),
    location: state.location,
    description: state.description,
    field_values: fieldValuesFor(state, fields),
    reminder_minutes: state.reminder,
  }
  if (state.recurrence) {
    payload.recurrence = state.recurrence
  }

  return payload
}

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

function timesChanged(original: FormState, state: FormState): boolean {
  return !same(apiTimes(original), apiTimes(state))
}

/**
 * A whole-series save sends only what changed, so an unchanged series keeps its exceptions. The repeat rule is
 * always sent for a repeating event (unless it is a custom rule this form can't show), so an all-day switch
 * rewrites its end date in the right form.
 */
export function toSeriesPatch(original: FormState, state: FormState, fields: FieldDefinition[], wasRecurring: boolean): Record<string, unknown> {
  const payload: Record<string, unknown> = { scope: 'series' }
  for (const [key, name] of [['title', 'title'], ['location', 'location'], ['description', 'description']] as const) {
    if (state[key] !== original[key]) {
      payload[name] = state[key]
    }
  }
  if (timesChanged(original, state)) {
    Object.assign(payload, apiTimes(state))
  }
  if (!state.recurrenceCustom && (wasRecurring || state.recurrence !== null)) {
    payload.recurrence = state.recurrence
  }
  const moved = state.calendarId !== original.calendarId
  if (moved) {
    payload.calendar_id = state.calendarId
  }
  if (moved || !same(fieldValuesFor(state, fields), fieldValuesFor(original, fields))) {
    payload.field_values = fieldValuesFor(state, fields)
  }
  if (state.reminder !== original.reminder) {
    payload.reminder_minutes = state.reminder
  }

  return payload
}

/** One occurrence: never the series' calendar, rule, fields or reminder (the server rejects those). */
export function toOccurrencePatch(original: FormState, state: FormState, recurrenceId: string): Record<string, unknown> {
  const payload: Record<string, unknown> = { scope: 'occurrence', recurrence_id: recurrenceId }
  for (const key of ['title', 'location', 'description'] as const) {
    if (state[key] !== original[key]) {
      payload[key] = state[key]
    }
  }
  if (timesChanged(original, state)) {
    Object.assign(payload, apiTimes(state))
  }

  return payload
}
```

`Resources/frontend/src/shared/format.ts`:

```ts
import { t } from './i18n'
import { addDays } from './time'
import type { FieldDefinition, FieldValue } from './types'

/** "Tuesday 6 October 2026, 10:00 - 11:00" in the agent's zone, or the dates of an all-day event (inclusive end). */
export function formatWhen(start: string, end: string, allDay: boolean, locale: string, timeZone: string, hour12: boolean): string {
  if (allDay) {
    const format = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: 'UTC' })
    const last = addDays(end, -1)
    const from = new Date(`${start}T00:00:00Z`)

    return last <= start ? format.format(from) : format.formatRange(from, new Date(`${last}T00:00:00Z`))
  }
  const format = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeStyle: 'short', timeZone, hour12 })

  return format.formatRange(new Date(start), new Date(end))
}

export function displayFieldValue(field: FieldDefinition, value: FieldValue | undefined): string {
  if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
    return ''
  }
  if (Array.isArray(value)) {
    return value.join(', ')
  }
  if (field.type === 'boolean') {
    return value === true ? t('Yes') : t('No')
  }

  return String(value)
}

/** Minutes before the start; null means the calendar's default and -1 means no reminder (API contract). */
export function reminderOptions(): { value: number | null; label: string }[] {
  return [
    { value: null, label: t('Calendar default') },
    { value: -1, label: t('No reminder') },
    { value: 5, label: t('5 minutes before') },
    { value: 10, label: t('10 minutes before') },
    { value: 15, label: t('15 minutes before') },
    { value: 30, label: t('30 minutes before') },
    { value: 60, label: t('1 hour before') },
    { value: 120, label: t('2 hours before') },
    { value: 1440, label: t('1 day before') },
    { value: 2880, label: t('2 days before') },
    { value: 10080, label: t('1 week before') },
  ]
}

export function reminderLabel(minutes: number | null): string {
  return reminderOptions().find((option) => option.value === minutes)?.label ?? t(':count minutes before', { count: minutes ?? 0 })
}
```

- [ ] **Step 4: Implement the shared components and styles**

`Resources/frontend/src/shared/Modal.vue`:

```vue
<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from './i18n'

/** A Bootstrap 3 modal driven by Vue (FreeScout's look, without Bootstrap's jQuery plugin). */
defineProps<{ title: string; size?: 'sm' | 'lg' }>()
const emit = defineEmits<{ close: [] }>()
const content = ref<HTMLElement | null>(null)
let previousFocus: HTMLElement | null = null

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.body.classList.add('modal-open')
  document.addEventListener('keydown', onKeydown)
  void nextTick(() => content.value?.focus())
})

onBeforeUnmount(() => {
  document.body.classList.remove('modal-open')
  document.removeEventListener('keydown', onKeydown)
  previousFocus?.focus()
})
</script>

<template>
  <Teleport to="body">
    <div class="ljpc-calendar ljpc-modal">
      <div class="modal-backdrop fade in"></div>
      <div class="modal fade in ljpc-modal-shown" role="dialog" aria-modal="true" :aria-label="title" @mousedown.self="emit('close')">
        <div class="modal-dialog" :class="size ? `modal-${size}` : ''">
          <div ref="content" class="modal-content" tabindex="-1">
            <div class="modal-header">
              <button type="button" class="close" :aria-label="t('Close')" @click="emit('close')"><span aria-hidden="true">&times;</span></button>
              <h4 class="modal-title">{{ title }}</h4>
            </div>
            <div class="modal-body"><slot /></div>
            <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
```

`Resources/frontend/src/shared/FieldInput.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { t } from './i18n'
import type { FieldDefinition, FieldValue } from './types'

const props = defineProps<{ field: FieldDefinition; modelValue: FieldValue | undefined; error?: string; idPrefix: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: FieldValue] }>()

const inputId = computed(() => `${props.idPrefix}-field-${props.field.id}`)
const inputType = computed(() => ({ number: 'number', date: 'date', email: 'email' } as Record<string, string>)[props.field.type] ?? 'text')
const selected = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []))

function setValue(event: Event): void {
  const input = event.target as HTMLInputElement
  emit('update:modelValue', props.field.type === 'number' && input.value !== '' ? Number(input.value) : input.value)
}

function toggleOption(option: string, checked: boolean): void {
  const next = selected.value.filter((value) => value !== option)
  emit('update:modelValue', checked ? [...next, option] : next)
}
</script>

<template>
  <div class="form-group" :class="{ 'has-error': error }">
    <div v-if="field.type === 'boolean'" class="checkbox">
      <label>
        <input type="checkbox" :checked="modelValue === true" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
        {{ field.name }}<span v-if="field.required" aria-hidden="true"> *</span>
      </label>
    </div>
    <template v-else>
      <label :for="field.type === 'multiselect' ? undefined : inputId" class="control-label">
        {{ field.name }}<span v-if="field.required" aria-hidden="true"> *</span>
      </label>
      <select
        v-if="field.type === 'dropdown'"
        :id="inputId"
        class="form-control"
        :value="modelValue ?? ''"
        :required="field.required"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">{{ t('Choose an option') }}</option>
        <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
      </select>
      <div v-else-if="field.type === 'multiselect'" role="group" :aria-label="field.name">
        <div v-for="option in field.options" :key="option" class="checkbox">
          <label>
            <input type="checkbox" :checked="selected.includes(option)" @change="toggleOption(option, ($event.target as HTMLInputElement).checked)">
            {{ option }}
          </label>
        </div>
      </div>
      <input
        v-else
        :id="inputId"
        :type="inputType"
        class="form-control"
        :value="modelValue ?? ''"
        :required="field.required"
        :step="field.type === 'number' ? 'any' : undefined"
        @input="setValue"
      >
    </template>
    <p v-if="error" class="help-block">{{ error }}</p>
  </div>
</template>
```

`Resources/frontend/src/shared/TimeFields.vue`:

```vue
<script setup lang="ts">
import { t } from './i18n'
import { withAllDay, withStart, type FormState } from './form-model'

/** All-day switch plus start and end, typed in the agent's FreeScout timezone (never the browser's). */
const props = defineProps<{ state: FormState; timeZone: string; errors: Record<string, string>; idPrefix: string }>()
const emit = defineEmits<{ change: [state: FormState] }>()

function patch(values: Partial<FormState>): void {
  emit('change', { ...props.state, ...values })
}

function setStartDate(date: string): void {
  if (date) {
    emit('change', withStart(props.state, date, props.state.startTime, props.timeZone))
  }
}

function setStartTime(time: string): void {
  if (time) {
    emit('change', withStart(props.state, props.state.startDate, time, props.timeZone))
  }
}
</script>

<template>
  <div class="ljpc-time-fields">
    <div class="checkbox">
      <label>
        <input type="checkbox" :checked="state.allDay" @change="emit('change', withAllDay(state, ($event.target as HTMLInputElement).checked, timeZone))">
        {{ t('All day') }}
      </label>
    </div>
    <div class="ljpc-time-row">
      <div class="form-group" :class="{ 'has-error': errors.start }">
        <label :for="`${idPrefix}-start-date`" class="control-label">{{ t('Starts') }}</label>
        <div class="ljpc-date-time">
          <input :id="`${idPrefix}-start-date`" type="date" class="form-control" required :value="state.startDate" @change="setStartDate(($event.target as HTMLInputElement).value)">
          <input v-if="!state.allDay" type="time" class="form-control" required :aria-label="t('Start time')" :value="state.startTime" @change="setStartTime(($event.target as HTMLInputElement).value)">
        </div>
        <p v-if="errors.start" class="help-block">{{ errors.start }}</p>
      </div>
      <div class="form-group" :class="{ 'has-error': errors.end }">
        <label :for="`${idPrefix}-end-date`" class="control-label">{{ t('Ends') }}</label>
        <div class="ljpc-date-time">
          <input :id="`${idPrefix}-end-date`" type="date" class="form-control" required :min="state.startDate" :value="state.endDate" @change="patch({ endDate: ($event.target as HTMLInputElement).value })">
          <input v-if="!state.allDay" type="time" class="form-control" required :aria-label="t('End time')" :value="state.endTime" @change="patch({ endTime: ($event.target as HTMLInputElement).value })">
        </div>
        <p v-if="errors.end" class="help-block">{{ errors.end }}</p>
      </div>
    </div>
  </div>
</template>
```

`Resources/frontend/src/shared/shared.css`:

```css
/* Shared by the calendar page and the conversation modal. FreeScout's Bootstrap 3 does the base styling. */
.ljpc-modal .ljpc-modal-shown {
  display: block;
  overflow-y: auto;
}

.ljpc-modal .modal-backdrop.in {
  opacity: 0.5;
}

.ljpc-details {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 6px 16px;
  margin: 0;
}

.ljpc-details dt {
  color: #4f5d6b;
  font-weight: normal;
}

.ljpc-details dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.ljpc-description {
  white-space: pre-wrap;
}

.ljpc-swatch {
  display: inline-block;
  flex: none;
  width: 12px;
  height: 12px;
  border-radius: 3px;
  vertical-align: middle;
}

.ljpc-confirm {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

.ljpc-confirm span {
  margin-right: auto;
  text-align: left;
}

.ljpc-time-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.ljpc-date-time {
  display: flex;
  gap: 8px;
}

.ljpc-recurrence {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}

.ljpc-recurrence .form-inline .form-control.ljpc-input-narrow {
  width: 80px;
  margin: 0 6px;
}

.ljpc-weekdays .btn {
  min-width: 44px;
}

.ljpc-label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

.ljpc-loading {
  padding: 24px 0;
  color: #4f5d6b;
  text-align: center;
}

@media (max-width: 767px) {
  .ljpc-time-row,
  .ljpc-details {
    grid-template-columns: 1fr;
  }
}

.ljpc-weekdays .btn.active {
  border-color: #0078d7;
  background-color: #0078d7;
  color: #fff;
}
```

- [ ] **Step 5: Implement the PHP side**

`Http/FrontendStrings.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

/**
 * Every string the frontend shows, as English keys translated with __() for the bundle's config. The list must match
 * the t('...') calls in Resources/frontend/src exactly (Tests/Unit/FrontendStringsTest.php).
 */
final class FrontendStrings
{
    public const KEYS = [
        'Choose an option',
        'Close',
        'All day',
        'Starts',
        'Start time',
        'Ends',
        'End time',
        'The server could not be reached. Check your connection and try again.',
        'Check the highlighted fields.',
        'You don\'t have permission to do this.',
        'This event or calendar no longer exists, or you don\'t have access to it.',
        'Your session has expired. Reload the page and try again.',
        'Something went wrong. Try again.',
        'Yes',
        'No',
        'Calendar default',
        'No reminder',
        '5 minutes before',
        '10 minutes before',
        '15 minutes before',
        '30 minutes before',
        '1 hour before',
        '2 hours before',
        '1 day before',
        '2 days before',
        '1 week before',
        ':count minutes before',
        'Every :count days',
        'Every day',
        'Every :count weeks',
        'Every week',
        'Every :count months',
        'Every month',
        'Every :count years',
        'Every year',
        'on :days',
        'on day :day',
        'first',
        'second',
        'third',
        'fourth',
        'last',
        'on the :position :weekday',
        'until :date',
        ':count times',
    ];

    public static function all(): array
    {
        $strings = [];
        foreach (self::KEYS as $key) {
            $strings[$key] = __($key);
        }

        return $strings;
    }
}
```

Replace `Http/FrontendConfig.php` with:

```php
<?php

namespace Modules\LJPcCalendarModule\Http;

use App\User;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\TimeInput;

/** Server data for the frontend bundles, passed as JSON in a non-executed script tag (never Blade inside JavaScript). */
final class FrontendConfig
{
    public static function page(User $user): array
    {
        return self::base($user) + [
            'user'               => ['id' => $user->id, 'is_admin' => $user->isAdmin()],
            'routes'             => [
                'calendars' => route('ljpccalendarmodule.api.calendars'),
                'events'    => route('ljpccalendarmodule.api.events'),
                'event'     => route('ljpccalendarmodule.api.events.show', ['ref' => '__ID__']),
                'settings'  => $user->isAdmin() ? SettingsPage::url() : null,
            ],
            'migration_complete' => app(MigrationService::class)->isFinished(),
        ];
    }

    public static function json(array $config): string
    {
        return json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** What every bundle needs: the agent's own language (not the install's), timezone and clock, and the strings. */
    private static function base(User $user): array
    {
        return [
            'locale'   => app()->getLocale(),
            'rtl'      => \Helper::isLocaleRtl(),
            'timezone' => TimeInput::userTimezone($user),
            'hour12'   => (int) $user->time_format === User::TIME_FORMAT_12,
            'csrf'     => csrf_token(),
            'strings'  => FrontendStrings::all(),
        ];
    }
}
```

- [ ] **Step 6: Run the checks**

Run in `Resources/frontend`: `npm run check`
Expected: vue-tsc passes, which also type-checks the three components, and Vitest reports `Tests 33 passed (33)`.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`. `test_calendar_page_is_a_shell_with_html_safe_json_config` still passes, because the config still carries `"timezone"`.

`npm run build` must leave `Public/build` unchanged: nothing imports the new shared files yet. Check it with `git status --porcelain -- Public/build`.

- [ ] **Step 7: Commit**

```bash
git add Resources/frontend/src/shared Resources/frontend/tests Http/FrontendStrings.php Http/FrontendConfig.php Tests/Unit/FrontendStringsTest.php Tests/Feature/IntegrationHooksTest.php
git commit -m "feat: add the shared frontend foundation, translated strings and the agent's page config"
```

---

### Task 6: The calendar page

This task builds the agent's calendar on FullCalendar 7:
- **Views:** day, week, month, year and list. Mobile phones open on the list.
- **Calendar list:** a sidebar with visibility toggles that can be collapsed. Both states are remembered per browser.
- **Event order:** the 2.7.x ordering by display priority.
- **Read-only parts:** the details view with "copy link" and "duplicate", and permalinks (`#e<id>`, v2 ids, UIDs).
- **Editing:** the event form with repeat options, and edits of one occurrence or the whole series.
- **Direct manipulation:** select a range to create an event, and drag or resize events. For repeating events this asks "this occurrence or the whole series"; moving a series also moves its weekdays or its day of the month.
- **States:** a sync-failure banner, and loading, empty, error and "upgrade in progress" states.

**Files:**
- Create in `Resources/frontend/src/calendar/`: `page-config.ts`, `events.ts`, `locales.ts`, `permalink.ts`, `RecurrenceEditor.vue`, `ScopeDialog.vue`, `CalendarSidebar.vue`, `EventDetails.vue`, `EventForm.vue`, `App.vue`, `main.ts`, `calendar.css`
- Create: `Resources/frontend/tests/calendar-events.test.ts`
- Modify: `Resources/frontend/vite.config.ts` (the `calendar` entry), `Resources/views/index.blade.php`, `Http/FrontendStrings.php`, `Tests/Feature/IntegrationHooksTest.php`
- Regenerate: `Public/build/`

**Interfaces:**
- Consumes:
  - Everything from Task 5.
  - The agent API:
    - `GET calendars`
    - `GET events?start&end`
    - `GET events/{ref}?recurrence_id`
    - `POST events`
    - `PATCH events/{id}` with `scope` and `recurrence_id`
    - `DELETE events/{id}` with a body `{scope, recurrence_id}`
  - The `#ljpc-calendar-config` JSON from `FrontendConfig::page()`.
- Produces:
  - `/calendar` renders the app.
  - Permalinks have the form `/calendar#e<id>`. Opening an event sets the hash and closing it clears it, and `hashchange` opens the linked event.
  - Each browser stores its preferences in `localStorage`: `ljpc-calendar.hidden`, `ljpc-calendar.sidebar-collapsed`, `ljpc-calendar.view` and `ljpc-calendar.last-calendar`. Task 7 reads the last one.

- [ ] **Step 1: Write the failing tests**

`Resources/frontend/tests/calendar-events.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { compareEvents, toEventInput } from '../src/calendar/events'
import { localeCode } from '../src/calendar/locales'
import { permalink, refFromHash } from '../src/calendar/permalink'
import type { CalendarInfo } from '../src/shared/types'

const calendar = { id: 1, color: '#e74c3c', display_priority: 3 } as CalendarInfo

describe('calendar events', () => {
  it('maps an occurrence to a FullCalendar event with the calendar color and priority', () => {
    const input = toEventInput({ id: '7:1', event_id: 7, calendar_id: 1, recurrence_id: '1', title: 'A', start: '2026-10-06', end: '2026-10-07', all_day: true, status: 'cancelled', is_recurring: true, editable: false }, calendar)
    expect(input).toMatchObject({ id: '7:1', allDay: true, color: '#e74c3c', editable: false, extendedProps: { eventId: 7, recurrenceId: '1', status: 'cancelled', priority: 3 } })
  })

  it('puts all-day and multi-day rows first, by priority and then start', () => {
    const allDayLate = { allDay: true, start: 2, duration: 86_400_000, priority: 1 }
    const allDayEarly = { allDay: true, start: 1, duration: 86_400_000, priority: 5 }
    const timed = { allDay: false, start: 0, duration: 3_600_000, priority: 0 }
    expect([timed, allDayEarly, allDayLate].sort(compareEvents)).toEqual([allDayLate, allDayEarly, timed])
  })

  it('keeps timed events chronological and lets priority break ties', () => {
    const a = { allDay: false, start: 10, duration: 1, priority: 9 }
    const b = { allDay: false, start: 10, duration: 1, priority: 1 }
    const c = { allDay: false, start: 5, duration: 1, priority: 50 }
    expect([a, b, c].sort(compareEvents)).toEqual([c, b, a])
  })

  it('maps FreeScout locales to FullCalendar locales', () => {
    expect(localeCode('pt-BR')).toBe('pt-br')
    expect(localeCode('pt-PT')).toBe('pt')
    expect(localeCode('no')).toBe('nb')
    expect(localeCode('kz')).toBe('kk')
    expect(localeCode('de')).toBe('de')
    expect(localeCode('en')).toBeNull()
  })

  it('reads permalinks from the hash', () => {
    expect(refFromHash('#e12')).toBe('e12')
    expect(refFromHash('#300')).toBe('300')
    expect(refFromHash('#abc%40example.org')).toBe('abc@example.org')
    expect(refFromHash('')).toBeNull()
    expect(refFromHash('#%E0%A4%A')).toBeNull()
    expect(permalink(12, { origin: 'https://help.example.test', pathname: '/calendar', search: '' })).toBe('https://help.example.test/calendar#e12')
  })
})
```

In `Tests/Feature/IntegrationHooksTest.php`, add these assertions at the end of `test_calendar_page_is_a_shell_with_html_safe_json_config()`:

```php
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/build/calendar\.js\?v=\d+" nonce="[^"]+"></script>#', $response->getContent());
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/build/calendar\.css\?v=\d+">#', $response->getContent());
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd Resources/frontend && npx vitest run tests/calendar-events.test.ts`
Expected: FAIL, because `../src/calendar/events`, `locales` and `permalink` are missing.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter test_calendar_page_is_a_shell'`
Expected: FAIL. The page has no script tag yet.

- [ ] **Step 3: Implement the calendar helpers**

`Resources/frontend/src/calendar/page-config.ts`:

```ts
import type { BaseConfig } from '../shared/config'

/** #ljpc-calendar-config (Http/FrontendConfig::page). */
export interface PageConfig extends BaseConfig {
  user: { id: number; is_admin: boolean }
  routes: {
    calendars: string
    events: string
    /** Show (with an "e<id>" or legacy ref), update and delete (with the numeric id); "__ID__" is the placeholder. */
    event: string
    settings: string | null
  }
  migration_complete: boolean
}
```

`Resources/frontend/src/calendar/events.ts`:

```ts
import type { EventInput } from '@fullcalendar/vue3'
import type { CalendarInfo, Occurrence } from '../shared/types'

/** extendedProps of every event on the calendar. */
export interface EventProps {
  eventId: number
  calendarId: number
  recurrenceId: string | null
  status: string
  isRecurring: boolean
  priority: number
}

export function toEventInput(occurrence: Occurrence, calendar: CalendarInfo | undefined): EventInput {
  const props: EventProps = {
    eventId: occurrence.event_id,
    calendarId: occurrence.calendar_id,
    recurrenceId: occurrence.recurrence_id,
    status: occurrence.status,
    isRecurring: occurrence.is_recurring,
    priority: calendar?.display_priority ?? 100,
  }

  return {
    id: occurrence.id,
    title: occurrence.title,
    start: occurrence.start,
    end: occurrence.end,
    allDay: occurrence.all_day,
    color: calendar?.color || '#3498db',
    editable: occurrence.editable,
    extendedProps: props,
  }
}

/** What FullCalendar 7.1 hands to an eventOrder comparator: the event's fields with extendedProps spread in. */
export interface OrderSubject {
  allDay: boolean | number
  start: number
  duration: number
  priority?: number
}

const DAY_MS = 86_400_000

function isRow(subject: OrderSubject): boolean {
  return Boolean(subject.allDay) || subject.duration >= DAY_MS
}

/**
 * The 2.7.x order: all-day and multi-day rows first, sorted by the calendar's display priority and then start;
 * timed events stay chronological and priority only breaks ties.
 */
export function compareEvents(a: OrderSubject, b: OrderSubject): number {
  const rowA = isRow(a)
  const rowB = isRow(b)
  if (rowA !== rowB) {
    return rowA ? -1 : 1
  }
  const byPriority = (a.priority ?? 100) - (b.priority ?? 100)
  const byStart = a.start - b.start

  return rowA ? byPriority || byStart : byStart || byPriority
}
```

`Resources/frontend/src/calendar/locales.ts`:

```ts
import type { LocaleInput } from '@fullcalendar/vue3'

// One small chunk per language FreeScout offers; English is built into FullCalendar.
const loaders: Record<string, () => Promise<{ default: LocaleInput }>> = {
  ar: () => import('@fullcalendar/vue3/locales/ar'),
  az: () => import('@fullcalendar/vue3/locales/az'),
  ca: () => import('@fullcalendar/vue3/locales/ca'),
  cs: () => import('@fullcalendar/vue3/locales/cs'),
  da: () => import('@fullcalendar/vue3/locales/da'),
  de: () => import('@fullcalendar/vue3/locales/de'),
  es: () => import('@fullcalendar/vue3/locales/es'),
  fa: () => import('@fullcalendar/vue3/locales/fa'),
  fi: () => import('@fullcalendar/vue3/locales/fi'),
  fr: () => import('@fullcalendar/vue3/locales/fr'),
  he: () => import('@fullcalendar/vue3/locales/he'),
  hr: () => import('@fullcalendar/vue3/locales/hr'),
  hu: () => import('@fullcalendar/vue3/locales/hu'),
  it: () => import('@fullcalendar/vue3/locales/it'),
  ja: () => import('@fullcalendar/vue3/locales/ja'),
  kk: () => import('@fullcalendar/vue3/locales/kk'),
  ko: () => import('@fullcalendar/vue3/locales/ko'),
  nb: () => import('@fullcalendar/vue3/locales/nb'),
  nl: () => import('@fullcalendar/vue3/locales/nl'),
  pl: () => import('@fullcalendar/vue3/locales/pl'),
  pt: () => import('@fullcalendar/vue3/locales/pt'),
  'pt-br': () => import('@fullcalendar/vue3/locales/pt-br'),
  ro: () => import('@fullcalendar/vue3/locales/ro'),
  ru: () => import('@fullcalendar/vue3/locales/ru'),
  sk: () => import('@fullcalendar/vue3/locales/sk'),
  sl: () => import('@fullcalendar/vue3/locales/sl'),
  sv: () => import('@fullcalendar/vue3/locales/sv'),
  tr: () => import('@fullcalendar/vue3/locales/tr'),
  uk: () => import('@fullcalendar/vue3/locales/uk'),
  'zh-cn': () => import('@fullcalendar/vue3/locales/zh-cn'),
  'zh-tw': () => import('@fullcalendar/vue3/locales/zh-tw'),
}

// FreeScout codes that FullCalendar names differently.
const ALIASES: Record<string, string> = { kz: 'kk', no: 'nb', 'pt-pt': 'pt' }

/** The FullCalendar locale for a FreeScout locale, or null for FullCalendar's built-in English. */
export function localeCode(freescoutLocale: string): string | null {
  const code = freescoutLocale.toLowerCase()
  const mapped = ALIASES[code] ?? code
  if (mapped in loaders) {
    return mapped
  }
  const base = mapped.split('-')[0] ?? ''

  return base in loaders ? base : null
}

export async function loadLocale(freescoutLocale: string): Promise<LocaleInput | null> {
  const code = localeCode(freescoutLocale)
  const loader = code ? loaders[code] : undefined

  return loader ? (await loader()).default : null
}
```

`Resources/frontend/src/calendar/permalink.ts`:

```ts
/** Permalinks: "#e<id>" is a v3 event; any other ref (a v2 item id or a UID) is resolved by the server. */
export function refFromHash(hash: string): string | null {
  let ref: string
  try {
    ref = decodeURIComponent(hash.replace(/^#/, '')).trim()
  } catch {
    return null
  }

  return ref === '' || ref.length > 191 ? null : ref
}

export function eventRef(id: number): string {
  return `e${id}`
}

export function permalink(id: number, location: Pick<Location, 'origin' | 'pathname' | 'search'>): string {
  return `${location.origin}${location.pathname}${location.search}#${eventRef(id)}`
}
```

Run: `npx vitest run tests/calendar-events.test.ts`
Expected: `Tests 5 passed (5)`.

- [ ] **Step 4: Implement the components**

`Resources/frontend/src/calendar/RecurrenceEditor.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { t } from '../shared/i18n'
import { WEEKDAYS, defaultRecurrence, weekdayCode, weekdayPosition } from '../shared/recurrence'
import type { Frequency, Recurrence } from '../shared/types'

/** The repeat options from the spec; every one maps onto RRULE and Microsoft Graph. */
const props = defineProps<{ modelValue: Recurrence | null; startDate: string; locale: string; errors: Record<string, string>; idPrefix: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: Recurrence | null] }>()

const rule = computed(() => props.modelValue)
const monthlyMode = computed(() => (rule.value?.bysetpos !== undefined ? 'weekday' : 'day'))
const endMode = computed(() => (rule.value?.until ? 'until' : rule.value?.count ? 'count' : 'never'))
const weekdayNames = computed(() => WEEKDAYS.map((code, index) => ({
  code,
  short: new Intl.DateTimeFormat(props.locale, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + index))),
  long: new Intl.DateTimeFormat(props.locale, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + index))),
})))
const positionLabel = computed(() => {
  const labels: Record<number, string> = { 1: t('first'), 2: t('second'), 3: t('third'), 4: t('fourth'), [-1]: t('last') }
  const day = weekdayNames.value.find((weekday) => weekday.code === weekdayCode(props.startDate))

  return t('On the :position :weekday', { position: labels[weekdayPosition(props.startDate)] ?? '', weekday: day?.long ?? '' })
})

function update(values: Partial<Recurrence>, remove: (keyof Recurrence)[] = []): void {
  if (!rule.value) {
    return
  }
  const next: Recurrence = { ...rule.value, ...values }
  for (const key of remove) {
    delete next[key]
  }
  emit('update:modelValue', next)
}

function setFrequency(value: string): void {
  emit('update:modelValue', value === '' ? null : defaultRecurrence(value as Frequency, props.startDate))
}

function toggleWeekday(code: string, checked: boolean): void {
  const days = new Set(rule.value?.byday ?? [])
  if (checked) {
    days.add(code)
  } else {
    days.delete(code)
  }
  update({ byday: WEEKDAYS.filter((day) => days.has(day)) })
}

function setMonthlyMode(mode: string): void {
  if (mode === 'weekday') {
    update({ byday: [weekdayCode(props.startDate)], bysetpos: weekdayPosition(props.startDate) }, ['bymonthday'])
  } else {
    update({ bymonthday: Number(props.startDate.slice(8, 10)) }, ['byday', 'bysetpos'])
  }
}

function setEndMode(mode: string): void {
  if (mode === 'until') {
    update({ until: props.startDate }, ['count'])
  } else if (mode === 'count') {
    update({ count: 10 }, ['until'])
  } else {
    update({}, ['until', 'count'])
  }
}
</script>

<template>
  <fieldset class="ljpc-recurrence">
    <div class="form-group" :class="{ 'has-error': errors['recurrence.freq'] }">
      <label :for="`${idPrefix}-repeat`" class="control-label">{{ t('Repeat') }}</label>
      <select :id="`${idPrefix}-repeat`" class="form-control" :value="rule?.freq ?? ''" @change="setFrequency(($event.target as HTMLSelectElement).value)">
        <option value="">{{ t('Does not repeat') }}</option>
        <option value="DAILY">{{ t('Daily') }}</option>
        <option value="WEEKLY">{{ t('Weekly') }}</option>
        <option value="MONTHLY">{{ t('Monthly') }}</option>
        <option value="YEARLY">{{ t('Yearly') }}</option>
      </select>
      <p v-if="errors['recurrence.freq']" class="help-block">{{ errors['recurrence.freq'] }}</p>
    </div>

    <template v-if="rule">
      <div class="form-group form-inline" :class="{ 'has-error': errors['recurrence.interval'] }">
        <label :for="`${idPrefix}-interval`">{{ t('Repeat every') }}</label>
        <input
          :id="`${idPrefix}-interval`"
          type="number"
          class="form-control ljpc-input-narrow"
          min="1"
          max="999"
          :value="rule.interval"
          @change="update({ interval: Math.max(1, Number(($event.target as HTMLInputElement).value) || 1) })"
        >
        <span>{{ { DAILY: t('day(s)'), WEEKLY: t('week(s)'), MONTHLY: t('month(s)'), YEARLY: t('year(s)') }[rule.freq] }}</span>
        <p v-if="errors['recurrence.interval']" class="help-block">{{ errors['recurrence.interval'] }}</p>
      </div>

      <div v-if="rule.freq === 'WEEKLY'" class="form-group" :class="{ 'has-error': errors['recurrence.byday'] }">
        <span class="control-label ljpc-label">{{ t('On') }}</span>
        <div class="btn-group ljpc-weekdays" role="group">
          <label v-for="day in weekdayNames" :key="day.code" class="btn btn-default" :class="{ active: rule.byday?.includes(day.code) }" :title="day.long">
            <input type="checkbox" class="sr-only" :checked="rule.byday?.includes(day.code)" @change="toggleWeekday(day.code, ($event.target as HTMLInputElement).checked)">
            {{ day.short }}
          </label>
        </div>
        <p v-if="errors['recurrence.byday']" class="help-block">{{ errors['recurrence.byday'] }}</p>
      </div>

      <div v-if="rule.freq === 'MONTHLY'" class="form-group" :class="{ 'has-error': errors['recurrence.bysetpos'] || errors['recurrence.bymonthday'] }">
        <div class="radio">
          <label><input type="radio" value="day" :checked="monthlyMode === 'day'" @change="setMonthlyMode('day')"> {{ t('On day :day', { day: Number(startDate.slice(8, 10)) }) }}</label>
        </div>
        <div class="radio">
          <label><input type="radio" value="weekday" :checked="monthlyMode === 'weekday'" @change="setMonthlyMode('weekday')"> {{ positionLabel }}</label>
        </div>
        <p v-if="errors['recurrence.bysetpos'] || errors['recurrence.bymonthday']" class="help-block">{{ errors['recurrence.bysetpos'] ?? errors['recurrence.bymonthday'] }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors['recurrence.until'] || errors['recurrence.count'] }">
        <span class="control-label ljpc-label">{{ t('Ends') }}</span>
        <div class="radio">
          <label><input type="radio" :checked="endMode === 'never'" @change="setEndMode('never')"> {{ t('Never') }}</label>
        </div>
        <div class="radio form-inline">
          <label><input type="radio" :checked="endMode === 'until'" @change="setEndMode('until')"> {{ t('On') }}</label>
          <input v-if="endMode === 'until'" type="date" class="form-control" :aria-label="t('End date of the series')" :min="startDate" :value="rule.until" @change="update({ until: ($event.target as HTMLInputElement).value })">
        </div>
        <div class="radio form-inline">
          <label><input type="radio" :checked="endMode === 'count'" @change="setEndMode('count')"> {{ t('After') }}</label>
          <template v-if="endMode === 'count'">
            <input type="number" class="form-control ljpc-input-narrow" min="1" max="999" :aria-label="t('Number of times')" :value="rule.count" @change="update({ count: Math.max(1, Number(($event.target as HTMLInputElement).value) || 1) })">
            <span>{{ t('times') }}</span>
          </template>
        </div>
        <p v-if="errors['recurrence.until'] || errors['recurrence.count']" class="help-block">{{ errors['recurrence.until'] ?? errors['recurrence.count'] }}</p>
      </div>
    </template>
  </fieldset>
</template>
```

`Resources/frontend/src/calendar/ScopeDialog.vue`:

```vue
<script setup lang="ts">
import Modal from '../shared/Modal.vue'
import { t } from '../shared/i18n'
import type { Scope } from '../shared/form-model'

defineProps<{ question: string }>()
const emit = defineEmits<{ choose: [scope: Scope | null] }>()
</script>

<template>
  <Modal :title="t('Repeating event')" size="sm" @close="emit('choose', null)">
    <p>{{ question }}</p>
    <template #footer>
      <button type="button" class="btn btn-default" @click="emit('choose', null)">{{ t('Cancel') }}</button>
      <button type="button" class="btn btn-default" @click="emit('choose', 'series')">{{ t('Whole series') }}</button>
      <button type="button" class="btn btn-primary" @click="emit('choose', 'occurrence')">{{ t('This occurrence') }}</button>
    </template>
  </Modal>
</template>
```

`Resources/frontend/src/calendar/CalendarSidebar.vue`:

```vue
<script setup lang="ts">
import { t } from '../shared/i18n'
import type { CalendarInfo } from '../shared/types'

defineProps<{ calendars: CalendarInfo[]; hidden: number[]; collapsed: boolean; canCreate: boolean; settingsUrl: string | null }>()
const emit = defineEmits<{ toggle: [id: number]; toggleCollapsed: []; newEvent: [] }>()
</script>

<template>
  <aside class="ljpc-sidebar" :class="{ 'is-collapsed': collapsed }">
    <button v-if="canCreate" type="button" class="btn btn-primary ljpc-new-event" :title="t('New event')" @click="emit('newEvent')">
      <i class="glyphicon glyphicon-plus" aria-hidden="true"></i><span class="ljpc-sidebar-text"> {{ t('New event') }}</span>
    </button>
    <button type="button" class="btn btn-link ljpc-sidebar-toggle" :aria-expanded="!collapsed" aria-controls="ljpc-calendar-list" @click="emit('toggleCollapsed')">
      <i class="glyphicon" :class="collapsed ? 'glyphicon-menu-right' : 'glyphicon-menu-left'" aria-hidden="true"></i>
      <span class="ljpc-sidebar-text">{{ collapsed ? t('Show calendars') : t('Hide calendars') }}</span>
      <span v-if="collapsed" class="sr-only">{{ t('Show calendars') }}</span>
    </button>
    <template v-if="!collapsed">
      <ul id="ljpc-calendar-list" class="ljpc-calendar-list">
        <li v-for="calendar in calendars" :key="calendar.id">
          <label :title="calendar.name">
            <input type="checkbox" :checked="!hidden.includes(calendar.id)" @change="emit('toggle', calendar.id)">
            <span class="ljpc-swatch" :style="{ backgroundColor: calendar.color }" aria-hidden="true"></span>
            <span class="ljpc-calendar-name">{{ calendar.name }}</span>
            <span v-if="!calendar.writable || (!calendar.rights.can_create && !calendar.rights.can_edit)" class="ljpc-muted">{{ t('Read-only') }}</span>
          </label>
        </li>
      </ul>
      <a v-if="settingsUrl" :href="settingsUrl" class="ljpc-manage">{{ t('Manage calendars') }}</a>
    </template>
  </aside>
</template>
```

`Resources/frontend/src/calendar/EventDetails.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Modal from '../shared/Modal.vue'
import { ApiError, routeWith, type Api } from '../shared/api'
import { notify } from '../shared/alerts'
import { displayFieldValue, formatWhen, reminderLabel } from '../shared/format'
import { t } from '../shared/i18n'
import { describeRecurrence } from '../shared/recurrence'
import type { Scope } from '../shared/form-model'
import type { CalendarInfo, EventDetail } from '../shared/types'
import { permalink } from './permalink'
import type { PageConfig } from './page-config'

const props = defineProps<{ reference: string; recurrenceId: string | null; calendars: CalendarInfo[]; config: PageConfig; api: Api; canDuplicate: boolean }>()
const emit = defineEmits<{ close: []; loaded: [event: EventDetail]; edit: [event: EventDetail, scope: Scope]; duplicate: [event: EventDetail]; deleted: [] }>()

const event = ref<EventDetail | null>(null)
const error = ref('')
const confirming = ref(false)
const busy = ref(false)

const calendar = computed(() => props.calendars.find((item) => item.id === event.value?.calendar_id))
const fieldRows = computed(() => (calendar.value?.fields ?? [])
  .map((field) => ({ field, text: displayFieldValue(field, event.value?.field_values[String(field.id)]) }))
  .filter((row) => row.text !== ''))
const isOccurrence = computed(() => Boolean(event.value?.is_recurring && props.recurrenceId))

onMounted(async () => {
  try {
    const query: Record<string, string> = props.recurrenceId ? { recurrence_id: props.recurrenceId } : {}
    event.value = await props.api.get<EventDetail>(routeWith(props.config.routes.event, props.reference), query)
    emit('loaded', event.value)
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  }
})

async function copyLink(): Promise<void> {
  if (!event.value) {
    return
  }
  const url = permalink(event.value.id, window.location)
  try {
    await navigator.clipboard.writeText(url)
    notify('success', t('Link copied.'))
  } catch {
    window.prompt(t('Copy this link:'), url)
  }
}

async function remove(scope: Scope): Promise<void> {
  if (!event.value) {
    return
  }
  busy.value = true
  try {
    const body = scope === 'occurrence' ? { scope, recurrence_id: props.recurrenceId } : { scope }
    await props.api.delete(routeWith(props.config.routes.event, event.value.id), body)
    notify('success', t('Event deleted.'))
    emit('deleted')
  } catch (caught) {
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="event?.title ?? t('Event')" @close="emit('close')">
    <p v-if="error" class="alert alert-danger">{{ error }}</p>
    <div v-else-if="!event" class="ljpc-loading" role="status">{{ t('Loading…') }}</div>
    <dl v-else class="ljpc-details">
      <dt>{{ t('Calendar') }}</dt>
      <dd><span class="ljpc-swatch" :style="{ backgroundColor: calendar?.color }" aria-hidden="true"></span> {{ calendar?.name }}</dd>
      <dt>{{ t('When') }}</dt>
      <dd>{{ formatWhen(event.start, event.end, event.all_day, config.locale, config.timezone, config.hour12) }}</dd>
      <template v-if="event.recurrence || event.recurrence_custom">
        <dt>{{ t('Repeats') }}</dt>
        <dd>{{ event.recurrence ? describeRecurrence(event.recurrence, config.locale) : t('With a custom rule') }}</dd>
      </template>
      <template v-if="event.location">
        <dt>{{ t('Location') }}</dt>
        <dd>{{ event.location }}</dd>
      </template>
      <template v-if="event.description">
        <dt>{{ t('Description') }}</dt>
        <dd class="ljpc-description">{{ event.description }}</dd>
      </template>
      <template v-for="row in fieldRows" :key="row.field.id">
        <dt>{{ row.field.name }}</dt>
        <dd>{{ row.text }}</dd>
      </template>
      <dt>{{ t('Reminder') }}</dt>
      <dd>{{ reminderLabel(event.reminder_minutes) }}</dd>
      <template v-if="event.author">
        <dt>{{ t('Created by') }}</dt>
        <dd>{{ event.author.name }}</dd>
      </template>
      <template v-if="event.conversation">
        <dt>{{ t('Conversation') }}</dt>
        <dd><a :href="event.conversation.url">#{{ event.conversation.number }}</a></dd>
      </template>
    </dl>

    <template #footer>
      <div v-if="event && confirming" class="ljpc-confirm" role="alert">
        <span>{{ isOccurrence ? t('Delete only this occurrence, or the whole series?') : t('Delete this event?') }}</span>
        <button type="button" class="btn btn-default" :disabled="busy" @click="confirming = false">{{ t('Cancel') }}</button>
        <template v-if="isOccurrence">
          <button type="button" class="btn btn-danger" :disabled="busy" @click="remove('series')">{{ t('Whole series') }}</button>
          <button type="button" class="btn btn-danger" :disabled="busy" @click="remove('occurrence')">{{ t('This occurrence') }}</button>
        </template>
        <button v-else type="button" class="btn btn-danger" :disabled="busy" @click="remove('series')">{{ t('Delete') }}</button>
      </div>
      <template v-else-if="event">
        <button type="button" class="btn btn-link pull-left" @click="copyLink">{{ t('Copy link') }}</button>
        <button v-if="canDuplicate" type="button" class="btn btn-default" @click="emit('duplicate', event)">{{ t('Duplicate') }}</button>
        <template v-if="event.editable">
          <button type="button" class="btn btn-default" @click="confirming = true">{{ t('Delete') }}</button>
          <template v-if="isOccurrence">
            <button type="button" class="btn btn-default" @click="emit('edit', event, 'series')">{{ t('Edit series') }}</button>
            <button type="button" class="btn btn-primary" @click="emit('edit', event, 'occurrence')">{{ t('Edit this occurrence') }}</button>
          </template>
          <button v-else type="button" class="btn btn-primary" @click="emit('edit', event, 'series')">{{ t('Edit') }}</button>
        </template>
      </template>
      <button v-else type="button" class="btn btn-default" @click="emit('close')">{{ t('Close') }}</button>
    </template>
  </Modal>
</template>
```

`Resources/frontend/src/calendar/EventForm.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import FieldInput from '../shared/FieldInput.vue'
import Modal from '../shared/Modal.vue'
import TimeFields from '../shared/TimeFields.vue'
import { ApiError, routeWith, type Api } from '../shared/api'
import { reminderOptions } from '../shared/format'
import { initialFieldValues, toCreatePayload, toOccurrencePatch, toSeriesPatch, type FormState } from '../shared/form-model'
import { t } from '../shared/i18n'
import type { CalendarInfo, EventDetail } from '../shared/types'
import RecurrenceEditor from './RecurrenceEditor.vue'
import type { PageConfig } from './page-config'

/** create: a new event; series: the whole event; occurrence: one occurrence of a repeating event. */
const props = defineProps<{
  mode: 'create' | 'series' | 'occurrence'
  initial: FormState
  event: EventDetail | null
  recurrenceId: string | null
  calendars: CalendarInfo[]
  config: PageConfig
  api: Api
}>()
const emit = defineEmits<{ close: []; saved: [event: EventDetail] }>()

const form = ref<FormState>(structuredClone(props.initial))
const errors = ref<Record<string, string>>({})
const generalError = ref('')
const saving = ref(false)
const formId = `ljpc-event-form-${props.mode}`

const choices = computed(() => props.calendars.filter((calendar) =>
  (calendar.writable && calendar.rights.can_create) || calendar.id === props.event?.calendar_id))
const calendar = computed(() => props.calendars.find((item) => item.id === form.value.calendarId))
const fields = computed(() => calendar.value?.fields ?? [])
const title = computed(() => ({
  create: t('New event'),
  series: props.event?.is_recurring ? t('Edit series') : t('Edit event'),
  occurrence: t('Edit this occurrence'),
})[props.mode])

function setCalendar(id: number): void {
  const next = props.calendars.find((item) => item.id === id)
  form.value = { ...form.value, calendarId: id, fields: initialFieldValues(next?.fields ?? [], form.value.fields) }
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  generalError.value = ''
  try {
    let saved: EventDetail
    if (props.mode === 'create') {
      saved = await props.api.post<EventDetail>(props.config.routes.events, toCreatePayload(form.value, fields.value))
    } else {
      const payload = props.mode === 'occurrence'
        ? toOccurrencePatch(props.initial, form.value, props.recurrenceId ?? '')
        : toSeriesPatch(props.initial, form.value, fields.value, props.event?.is_recurring ?? false)
      saved = await props.api.patch<EventDetail>(routeWith(props.config.routes.event, props.event?.id ?? 0), payload)
    }
    emit('saved', saved)
  } catch (caught) {
    if (caught instanceof ApiError && caught.status === 422) {
      errors.value = caught.fieldErrors()
    }
    generalError.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="title" size="lg" @close="emit('close')">
    <form :id="formId" class="ljpc-event-form" @submit.prevent="save">
      <p v-if="generalError" class="alert alert-danger" role="alert">{{ generalError }}</p>

      <div class="form-group" :class="{ 'has-error': errors.calendar_id }">
        <label :for="`${formId}-calendar`" class="control-label">{{ t('Calendar') }}</label>
        <select
          :id="`${formId}-calendar`"
          class="form-control"
          :disabled="mode === 'occurrence' || choices.length < 2"
          :value="form.calendarId ?? ''"
          @change="setCalendar(Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="choice in choices" :key="choice.id" :value="choice.id">{{ choice.name }}</option>
        </select>
        <p v-if="errors.calendar_id" class="help-block">{{ errors.calendar_id }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors.title }">
        <label :for="`${formId}-title`" class="control-label">{{ t('Title') }}</label>
        <input :id="`${formId}-title`" v-model="form.title" type="text" class="form-control" maxlength="500" :required="!calendar?.has_title_template">
        <p v-if="calendar?.has_title_template && mode !== 'occurrence'" class="help-block">{{ t('This calendar builds the title from a template that can use this text.') }}</p>
        <p v-if="errors.title" class="help-block">{{ errors.title }}</p>
      </div>

      <TimeFields :state="form" :time-zone="config.timezone" :errors="errors" :id-prefix="formId" @change="form = $event" />

      <template v-if="mode !== 'occurrence'">
        <p v-if="form.recurrenceCustom" class="alert alert-info">{{ t('This event repeats with a rule that can only be changed in the app it came from. It is kept as it is.') }}</p>
        <RecurrenceEditor v-else v-model="form.recurrence" :start-date="form.startDate" :locale="config.locale" :errors="errors" :id-prefix="formId" />
      </template>

      <div class="form-group" :class="{ 'has-error': errors.location }">
        <label :for="`${formId}-location`" class="control-label">{{ t('Location') }}</label>
        <input :id="`${formId}-location`" v-model="form.location" type="text" class="form-control" maxlength="500">
        <p v-if="errors.location" class="help-block">{{ errors.location }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors.description }">
        <label :for="`${formId}-description`" class="control-label">{{ t('Description') }}</label>
        <textarea :id="`${formId}-description`" v-model="form.description" class="form-control" rows="4" maxlength="20000"></textarea>
        <p v-if="errors.description" class="help-block">{{ errors.description }}</p>
      </div>

      <template v-if="mode !== 'occurrence'">
        <div class="form-group" :class="{ 'has-error': errors.reminder_minutes }">
          <label :for="`${formId}-reminder`" class="control-label">{{ t('Reminder') }}</label>
          <select
            :id="`${formId}-reminder`"
            class="form-control"
            :value="form.reminder === null ? '' : String(form.reminder)"
            @change="form.reminder = ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value)"
          >
            <option v-for="option in reminderOptions()" :key="String(option.value)" :value="option.value === null ? '' : String(option.value)">{{ option.label }}</option>
          </select>
          <p v-if="errors.reminder_minutes" class="help-block">{{ errors.reminder_minutes }}</p>
        </div>

        <FieldInput
          v-for="field in fields"
          :key="field.id"
          :field="field"
          :model-value="form.fields[String(field.id)]"
          :error="errors[`field_values.${field.id}`]"
          :id-prefix="formId"
          @update:model-value="form.fields[String(field.id)] = $event"
        />
      </template>
      <p v-else class="text-help">{{ t('The repeat rule, the reminder and the custom fields belong to the whole series.') }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-default" @click="emit('close')">{{ t('Cancel') }}</button>
      <button type="submit" class="btn btn-primary" :form="formId" :disabled="saving">{{ saving ? t('Saving…') : t('Save') }}</button>
    </template>
  </Modal>
</template>
```

- [ ] **Step 5: Implement the page**

`Resources/frontend/src/calendar/App.vue`:

```vue
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import type { CalendarOptions, DateSelectInfo, EventClickInfo, EventDropInfo, EventInput, EventResizeDoneInfo, EventSourceFuncInfo, LocaleInput } from '@fullcalendar/vue3'
import classicTheme from '@fullcalendar/vue3/themes/classic'
import dayGrid from '@fullcalendar/vue3/daygrid'
import timeGrid from '@fullcalendar/vue3/timegrid'
import list from '@fullcalendar/vue3/list'
import multiMonth from '@fullcalendar/vue3/multimonth'
import interaction from '@fullcalendar/vue3/interaction'
import { ApiError, createApi, routeWith } from '../shared/api'
import { notify } from '../shared/alerts'
import { emptyForm, formFromEvent, formFromRange, type FormState, type Scope } from '../shared/form-model'
import { t } from '../shared/i18n'
import { shiftRecurrence } from '../shared/recurrence'
import { readStored, writeStored } from '../shared/storage'
import { dateOf, nextFullHour, shift } from '../shared/time'
import type { CalendarInfo, EventDetail, Occurrence } from '../shared/types'
import CalendarSidebar from './CalendarSidebar.vue'
import EventDetails from './EventDetails.vue'
import EventForm from './EventForm.vue'
import ScopeDialog from './ScopeDialog.vue'
import { compareEvents, toEventInput, type EventProps } from './events'
import { refFromHash, eventRef } from './permalink'
import type { PageConfig } from './page-config'

const props = defineProps<{ config: PageConfig; locale: LocaleInput | null }>()
const api = createApi(props.config.csrf)
const tz = props.config.timezone

const STORAGE = { hidden: 'ljpc-calendar.hidden', collapsed: 'ljpc-calendar.sidebar-collapsed', view: 'ljpc-calendar.view', lastCalendar: 'ljpc-calendar.last-calendar' }
const mobile = window.matchMedia('(max-width: 767px)').matches

const calendars = ref<CalendarInfo[]>([])
const loadState = ref<'loading' | 'ready' | 'failed'>('loading')
const hidden = ref<number[]>(readStored<number[]>(STORAGE.hidden, []))
const collapsed = ref<boolean>(readStored<boolean>(STORAGE.collapsed, mobile))
const busy = ref(false)

type Details = { reference: string; recurrenceId: string | null }
type Form = { mode: 'create' | 'series' | 'occurrence'; initial: FormState; event: EventDetail | null; recurrenceId: string | null }
const details = ref<Details | null>(null)
const form = shallowRef<Form | null>(null)
const scopeQuestion = ref<{ question: string; resolve: (scope: Scope | null) => void } | null>(null)
const calendarRef = ref<InstanceType<typeof FullCalendar> | null>(null)

const byId = computed(() => new Map(calendars.value.map((calendar) => [calendar.id, calendar])))
const creatable = computed(() => calendars.value.filter((calendar) => calendar.writable && calendar.rights.can_create))
const failing = computed(() => calendars.value.filter((calendar) => calendar.sync_failed && !hidden.value.includes(calendar.id)))

// The last response is kept per range: toggling a calendar re-filters without a request, and a failed request
// keeps the events already shown (2.7.x behavior) instead of blanking the calendar.
let visibleRange = { start: '', end: '' }
let cachedRange = ''
let cachedRows: Occurrence[] = []

async function fetchEvents(info: EventSourceFuncInfo): Promise<EventInput[]> {
  const range = `${info.startStr}|${info.endStr}`
  if (range !== cachedRange) {
    try {
      cachedRows = await api.get<Occurrence[]>(props.config.routes.events, { start: info.startStr, end: info.endStr })
      cachedRange = range
    } catch (caught) {
      notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
    }
  }

  return cachedRows
    .filter((row) => !hidden.value.includes(row.calendar_id))
    .map((row) => toEventInput(row, byId.value.get(row.calendar_id)))
}

function refresh(): void {
  cachedRange = ''
  calendarRef.value?.getApi().refetchEvents()
}

function askScope(question: string): Promise<Scope | null> {
  return new Promise((resolve) => {
    scopeQuestion.value = { question, resolve }
  })
}

function chooseScope(scope: Scope | null): void {
  scopeQuestion.value?.resolve(scope)
  scopeQuestion.value = null
}

async function onChange(info: EventDropInfo | EventResizeDoneInfo): Promise<void> {
  const item = info.event.extendedProps as EventProps
  let scope: Scope = 'series'
  if (item.isRecurring) {
    const choice = await askScope(t('Move only this occurrence, or the whole series?'))
    if (!choice) {
      info.revert()

      return
    }
    scope = choice
  }
  busy.value = true
  try {
    const url = routeWith(props.config.routes.event, item.eventId)
    const times = { all_day: info.event.allDay, start: info.event.startStr, end: info.event.endStr }
    if (scope === 'occurrence') {
      await api.patch(url, { scope, recurrence_id: item.recurrenceId, ...times })
    } else if (!item.isRecurring) {
      await api.patch(url, { scope, ...times })
    } else {
      // A series moves by the same amount from its own first start; its weekdays or month day move along.
      const master = await api.get<EventDetail>(routeWith(props.config.routes.event, eventRef(item.eventId)))
      const startDelta = 'delta' in info ? info.delta : info.startDelta
      const endDelta = 'delta' in info ? info.delta : info.endDelta
      const start = shift(master.start, startDelta, tz)
      const body: Record<string, unknown> = { scope, all_day: master.all_day, start, end: shift(master.end, endDelta, tz) }
      if (master.recurrence && !master.recurrence_custom) {
        body.recurrence = shiftRecurrence(master.recurrence, dateOf(master.start, tz), dateOf(start, tz))
      }
      await api.patch(url, body)
    }
    refresh()
  } catch (caught) {
    info.revert()
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  } finally {
    busy.value = false
  }
}

function defaultCalendarId(): number | null {
  const last = readStored<number | null>(STORAGE.lastCalendar, null)

  return creatable.value.find((calendar) => calendar.id === last)?.id ?? creatable.value[0]?.id ?? null
}

function openCreate(state?: FormState): void {
  const calendarId = state?.calendarId ?? defaultCalendarId()
  const fields = byId.value.get(calendarId ?? 0)?.fields ?? []
  form.value = { mode: 'create', initial: state ?? emptyForm(calendarId, nextFullHour(tz), tz, fields), event: null, recurrenceId: null }
}

function onSelect(info: DateSelectInfo): void {
  calendarRef.value?.getApi().unselect()
  const calendarId = defaultCalendarId()
  openCreate(formFromRange(calendarId, info.startStr, info.endStr, tz, byId.value.get(calendarId ?? 0)?.fields ?? []))
}

function onEventClick(info: EventClickInfo): void {
  info.jsEvent.preventDefault()
  const item = info.event.extendedProps as EventProps
  openDetails(eventRef(item.eventId), item.isRecurring ? item.recurrenceId : null)
}

function openDetails(reference: string, recurrenceId: string | null): void {
  form.value = null
  details.value = { reference, recurrenceId }
}

function onDetailsLoaded(event: EventDetail): void {
  const hash = `#${eventRef(event.id)}`
  if (window.location.hash !== hash) {
    history.replaceState(null, '', hash)
  }
  showDate(dateOf(event.start, tz))
}

/** Jumps to a date that is outside the visible range, so an opened or saved event is on screen. */
function showDate(date: string): void {
  if (date < visibleRange.start || date >= visibleRange.end) {
    calendarRef.value?.getApi().gotoDate(date)
  }
}

function closeDetails(): void {
  details.value = null
  history.replaceState(null, '', window.location.pathname + window.location.search)
}

async function onEdit(event: EventDetail, scope: Scope): Promise<void> {
  const recurrenceId = details.value?.recurrenceId ?? null
  details.value = null
  const fields = byId.value.get(event.calendar_id)?.fields ?? []
  if (scope === 'occurrence') {
    form.value = { mode: 'occurrence', initial: formFromEvent(event, tz, fields), event, recurrenceId }

    return
  }
  // The details of an occurrence show its own times; the series form needs the series' first start.
  try {
    const master = recurrenceId ? await api.get<EventDetail>(routeWith(props.config.routes.event, eventRef(event.id))) : event
    form.value = { mode: 'series', initial: formFromEvent(master, tz, fields), event: master, recurrenceId: null }
  } catch (caught) {
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  }
}

function onDuplicate(event: EventDetail): void {
  details.value = null
  const fields = byId.value.get(event.calendar_id)?.fields ?? []
  const state = formFromEvent(event, tz, fields)
  const writable = creatable.value.some((calendar) => calendar.id === event.calendar_id)
  openCreate({ ...state, calendarId: writable ? event.calendar_id : defaultCalendarId() })
}

function onSaved(event: EventDetail): void {
  const created = form.value?.mode === 'create'
  writeStored(STORAGE.lastCalendar, event.calendar_id)
  form.value = null
  notify('success', t('Event saved.'))
  refresh()
  // A new event may be on a date that isn't shown; an edited one stays where the agent was looking.
  if (created) {
    showDate(dateOf(event.start, tz))
  }
}

function onDeleted(): void {
  closeDetails()
  refresh()
}

function toggleCalendar(id: number): void {
  hidden.value = hidden.value.includes(id) ? hidden.value.filter((item) => item !== id) : [...hidden.value, id]
  writeStored(STORAGE.hidden, hidden.value)
  calendarRef.value?.getApi().refetchEvents()
}

function toggleCollapsed(): void {
  collapsed.value = !collapsed.value
  writeStored(STORAGE.collapsed, collapsed.value)
}

function openFromHash(): void {
  const reference = refFromHash(window.location.hash)
  if (reference && details.value?.reference !== reference) {
    openDetails(reference, null)
  }
}

const views = 'timeGridDay,timeGridWeek,dayGridMonth,multiMonthYear,listWeek'
const options: CalendarOptions = {
  plugins: [classicTheme, dayGrid, timeGrid, list, multiMonth, interaction],
  timeZone: tz,
  ...(props.locale ? { locale: props.locale } : {}),
  direction: props.config.rtl ? 'rtl' : 'ltr',
  firstDay: 1,
  initialView: mobile ? 'listWeek' : readStored<string>(STORAGE.view, 'timeGridWeek'),
  headerToolbar: mobile ? { start: 'prev,next', center: 'title', end: 'today' } : { start: 'prev,next today', center: 'title', end: views },
  ...(mobile ? { footerToolbar: { center: 'timeGridDay,listWeek,dayGridMonth' } } : {}),
  height: mobile ? 'auto' : '100%',
  nowIndicator: true,
  navLinks: true,
  scrollTime: '08:00:00',
  dayMaxEvents: true,
  // The all-day row above the time grid shows every event (2.7.x); only month cells fold into "+N more".
  views: { timeGrid: { dayMaxEvents: false } },
  selectable: true,
  selectMirror: true,
  forceEventDuration: true,
  eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: props.config.hour12 },
  slotHeaderFormat: { hour: '2-digit', minute: '2-digit', hour12: props.config.hour12 },
  // FullCalendar 7.1.1 types eventOrder as its parsed form; the runtime takes comparators and field names.
  eventOrder: [compareEvents, '-duration', 'title'] as unknown as CalendarOptions['eventOrder'],
  events: fetchEvents,
  eventDidMount: (info) => info.el.setAttribute('title', info.event.title),
  eventClass: (info) => (info.event.extendedProps.status === 'cancelled' ? 'ljpc-event-cancelled' : ''),
  popoverClass: 'ljpc-popover',
  // A repeating event changes between all-day and timed in the form, where the whole rule is visible.
  eventAllow: (span, moving) => !(moving?.extendedProps.isRecurring && span.allDay !== moving.allDay),
  selectAllow: () => creatable.value.length > 0,
  select: onSelect,
  eventClick: onEventClick,
  eventDrop: (info) => void onChange(info),
  eventResize: (info) => void onChange(info),
  datesSet: (info) => {
    visibleRange = { start: dateOf(info.startStr, tz), end: dateOf(info.endStr, tz) }
    if (!mobile) {
      writeStored(STORAGE.view, info.view.type)
    }
  },
}

onMounted(async () => {
  window.addEventListener('hashchange', openFromHash)
  if (!props.config.migration_complete) {
    return
  }
  try {
    calendars.value = await api.get<CalendarInfo[]>(props.config.routes.calendars)
    loadState.value = 'ready'
    openFromHash()
  } catch (caught) {
    loadState.value = 'failed'
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  }
})

onBeforeUnmount(() => window.removeEventListener('hashchange', openFromHash))

function reload(): void {
  window.location.reload()
}
</script>

<template>
  <div class="ljpc-page" :class="{ 'is-busy': busy, 'is-mobile': mobile }">
    <div v-if="!config.migration_complete" class="ljpc-state">
      <h2>{{ t('The calendar is being upgraded') }}</h2>
      <p>{{ t('The calendar opens again as soon as the upgrade to version 3 has finished.') }}</p>
      <p v-if="config.routes.settings"><a :href="config.routes.settings">{{ t('Open the upgrade report') }}</a></p>
    </div>
    <div v-else-if="loadState === 'loading'" class="ljpc-state" role="status">{{ t('Loading…') }}</div>
    <div v-else-if="loadState === 'failed'" class="ljpc-state">
      <p>{{ t('The calendars could not be loaded.') }}</p>
      <button type="button" class="btn btn-default" @click="reload">{{ t('Try again') }}</button>
    </div>
    <div v-else-if="calendars.length === 0" class="ljpc-state">
      <h2>{{ t('No calendars yet') }}</h2>
      <p v-if="config.routes.settings">{{ t('Create a calendar and choose who can use it.') }} <a :href="config.routes.settings">{{ t('Manage calendars') }}</a></p>
      <p v-else>{{ t('An administrator can give you access to a calendar.') }}</p>
    </div>
    <template v-else>
      <CalendarSidebar
        :calendars="calendars"
        :hidden="hidden"
        :collapsed="collapsed"
        :can-create="creatable.length > 0"
        :settings-url="config.routes.settings"
        @toggle="toggleCalendar"
        @toggle-collapsed="toggleCollapsed"
        @new-event="openCreate()"
      />
      <main class="ljpc-main">
        <div v-if="failing.length" class="alert alert-warning ljpc-banner" role="status">
          {{ t('These calendars could not be synchronized, so their events may be out of date: :names', { names: failing.map((calendar) => calendar.name).join(', ') }) }}
          <a v-if="config.routes.settings" :href="config.routes.settings">{{ t('Manage calendars') }}</a>
        </div>
        <div class="ljpc-grid">
          <FullCalendar ref="calendarRef" :options="options" />
        </div>
      </main>
    </template>

    <EventDetails
      v-if="details"
      :key="`${details.reference}|${details.recurrenceId}`"
      :reference="details.reference"
      :recurrence-id="details.recurrenceId"
      :calendars="calendars"
      :config="config"
      :api="api"
      :can-duplicate="creatable.length > 0"
      @close="closeDetails"
      @loaded="onDetailsLoaded"
      @edit="onEdit"
      @duplicate="onDuplicate"
      @deleted="onDeleted"
    />
    <EventForm
      v-if="form"
      :mode="form.mode"
      :initial="form.initial"
      :event="form.event"
      :recurrence-id="form.recurrenceId"
      :calendars="calendars"
      :config="config"
      :api="api"
      @close="form = null"
      @saved="onSaved"
    />
    <ScopeDialog v-if="scopeQuestion" :question="scopeQuestion.question" @choose="chooseScope" />
  </div>
</template>
```

`Resources/frontend/src/calendar/main.ts`:

```ts
import { createApp } from 'vue'
import '@fullcalendar/vue3/skeleton.css'
import '@fullcalendar/vue3/themes/classic/theme.css'
import '@fullcalendar/vue3/themes/classic/palette.css'
import './calendar.css'
import { readConfig } from '../shared/config'
import { setStrings } from '../shared/i18n'
import App from './App.vue'
import { loadLocale } from './locales'
import type { PageConfig } from './page-config'

const config = readConfig<PageConfig>('ljpc-calendar-config')
setStrings(config.strings)
const locale = await loadLocale(config.locale)
createApp(App, { config, locale }).mount('#ljpc-calendar-app')
```

`Resources/frontend/src/calendar/calendar.css`:

```css
@import '../shared/shared.css';

/* FreeScout's palette on FullCalendar's classic theme. FullCalendar 7 hashes its class names, so it is styled
   only through its --fc-classic-* variables and our own class hooks (eventClass, popoverClass). */
.ljpc-calendar {
  --fc-classic-button: #fff;
  --fc-classic-button-border: #c4c8cc;
  --fc-classic-button-strong: #deecf9;
  --fc-classic-button-strong-border: #0078d7;
  --fc-classic-button-outline: #0078d766;
  --fc-classic-button-foreground: #253540;
  --fc-classic-primary: #0078d7;
  --fc-classic-primary-foreground: #fff;
  --fc-classic-highlight: #deecf9aa;
  --fc-classic-today: #deecf966;
  --fc-classic-now: #e74c3c;
  --fc-classic-foreground: #253540;
  --fc-classic-muted-foreground: #4f5d6b;
  --fc-classic-faint-foreground: #93a1af;
  --fc-classic-border: #d6dde3;
  --fc-classic-strong-border: #a5b2bd;
}

.ljpc-page {
  display: flex;
  gap: 16px;
  box-sizing: border-box;
  height: calc(100vh - 52px);
  padding: 16px;
}

.ljpc-page.is-mobile {
  display: block;
  height: auto;
  padding: 8px 16px;
}

.ljpc-sidebar {
  display: flex;
  flex: 0 0 220px;
  flex-direction: column;
  gap: 8px;
  overflow-y: auto;
}

.ljpc-sidebar.is-collapsed {
  flex-basis: 44px;
}

.ljpc-sidebar.is-collapsed .ljpc-sidebar-text {
  display: none;
}

.is-mobile .ljpc-sidebar {
  flex-direction: row;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.ljpc-sidebar-toggle {
  padding-left: 0;
  text-align: left;
}

.ljpc-calendar-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.is-mobile .ljpc-calendar-list {
  flex-basis: 100%;
}

.ljpc-calendar-list label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  padding: 4px 0;
  font-weight: normal;
  cursor: pointer;
}

.ljpc-calendar-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ljpc-muted {
  color: #4f5d6b;
  font-size: 12px;
}

.ljpc-main {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-width: 0;
}

.ljpc-grid {
  flex: 1 1 auto;
  min-height: 0;
}

.is-busy .ljpc-grid {
  opacity: 0.7;
}

.ljpc-banner {
  margin-bottom: 8px;
}

.ljpc-state {
  max-width: 560px;
  margin: 48px auto;
  text-align: center;
}

.ljpc-event-cancelled {
  opacity: 0.6;
  text-decoration: line-through;
}

/* "+N more": the popover grows with its events and only scrolls beyond the viewport (2.7.x behavior). */
.ljpc-popover {
  max-height: calc(100vh - 32px);
  overflow-y: auto;
}
```

In `Resources/frontend/vite.config.ts`, make the `input` map:

```ts
      input: {
        calendar: 'src/calendar/main.ts',
        settings: 'src/settings/main.ts',
      },
```

Replace `Resources/views/index.blade.php` with:

```blade
@extends('layouts.app')

@section('title', __('Calendar'))
@section('content_class', 'content-full')

@section('stylesheets')
    {!! \Modules\LJPcCalendarModule\Http\Assets::style('calendar') !!}
@endsection

@section('content')
    <div id="ljpc-calendar-app" class="ljpc-calendar"></div>
    <script type="application/json" id="ljpc-calendar-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json($config) !!}</script>
@endsection

@section('javascripts')
    {!! \Modules\LJPcCalendarModule\Http\Assets::script('calendar') !!}
@endsection
```

In `Http/FrontendStrings.php`, append these keys to `KEYS`, after the Task 5 keys:

```php
        'Move only this occurrence, or the whole series?',
        'Event saved.',
        'The calendar is being upgraded',
        'The calendar opens again as soon as the upgrade to version 3 has finished.',
        'Open the upgrade report',
        'Loading…',
        'The calendars could not be loaded.',
        'Try again',
        'No calendars yet',
        'Create a calendar and choose who can use it.',
        'Manage calendars',
        'An administrator can give you access to a calendar.',
        'These calendars could not be synchronized, so their events may be out of date: :names',
        'New event',
        'Show calendars',
        'Hide calendars',
        'Read-only',
        'Link copied.',
        'Copy this link:',
        'Event deleted.',
        'Event',
        'Calendar',
        'When',
        'Repeats',
        'With a custom rule',
        'Location',
        'Description',
        'Reminder',
        'Created by',
        'Conversation',
        'Delete only this occurrence, or the whole series?',
        'Delete this event?',
        'Cancel',
        'Whole series',
        'This occurrence',
        'Delete',
        'Copy link',
        'Duplicate',
        'Edit series',
        'Edit this occurrence',
        'Edit',
        'Edit event',
        'Title',
        'This calendar builds the title from a template that can use this text.',
        'This event repeats with a rule that can only be changed in the app it came from. It is kept as it is.',
        'The repeat rule, the reminder and the custom fields belong to the whole series.',
        'Saving…',
        'Save',
        'On the :position :weekday',
        'Repeat',
        'Does not repeat',
        'Daily',
        'Weekly',
        'Monthly',
        'Yearly',
        'Repeat every',
        'day(s)',
        'week(s)',
        'month(s)',
        'year(s)',
        'On',
        'On day :day',
        'Never',
        'End date of the series',
        'After',
        'Number of times',
        'times',
        'Repeating event',
```

- [ ] **Step 6: Run the checks and build**

In `Resources/frontend`: `npm run check`, then `npm run build`.

Expected:
- `check`: no type errors and `Tests 38 passed (38)`.
- `build` writes:
  - `Public/build/calendar.js` (about 350 KB, 98 KB gzipped)
  - `calendar.css` (about 19 KB)
  - a shared chunk (about 140 KB, 51 KB gzipped)
  - one small chunk per FullCalendar locale
- Exactly two CSS files exist in `Public/build`: `calendar.css` and `settings.css`. List them with `ls Public/build/*.css`.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`. That includes `FrontendStringsTest` with the calendar keys.

- [ ] **Step 7: Verify in a real browser**

Prepare the data on the local ddev install:
1. Log in as the dev admin. Under Settings > Calendar, create two calendars:
   - "Support", with a dropdown field and a boolean field
   - "Read only", where a second agent only has View
2. In that second agent's FreeScout profile, set the time zone to America/New_York and the time format to 12-hour.
3. Run the browser with time zone Asia/Tokyo. With Playwright, use `timezoneId: 'Asia/Tokyo'`.

Then check each of these:

- **Views.** Week, Day, Month, Year and List all render. Year uses three columns on a wide screen. The Dutch UI (profile language `nl`) shows Dutch FullCalendar buttons.
- **Creating.**
  - "New event" opens the form at the next full hour in the profile's time zone.
  - Save a timed event at 10:00. It shows at 10:00 in the grid, and the details say 10:00, even though the browser zone is Tokyo.
- **Repeating events.**
  - Create a weekly event on two weekdays with "after 5 times".
  - "Edit this occurrence" changes only that occurrence.
  - "Edit series" changes all of them.
  - "Delete" offers "This occurrence" or "Whole series".
- **Drag and resize.**
  - Dragging a single event works.
  - Dragging a repeating event asks for the scope. "Whole series" moves the weekdays along.
  - Resizing works.
  - A repeating event can't be dragged between the all-day row and the time grid.
- **Selecting.**
  - Selecting a range in the week view opens the form with that range.
  - Selecting days in the month view creates an all-day event with the right inclusive end date.
- **Permalinks.**
  - `/calendar#e<id>` opens the details and jumps to the event's date.
  - "Copy link" copies that URL.
  - A v2 item id (`/calendar#<n>`) opens its migrated event.
  - An unknown ref shows the "no longer exists" message.
- **Sidebar.**
  - Toggling a calendar hides its events without a page load.
  - Collapsing the sidebar survives a reload.
- **Read-only agent.** The second agent sees "Read-only" on that calendar, can't drag its events, and gets no Edit or Delete buttons.
- **Mobile.** At 375 px wide the page opens on the list view, the sidebar is collapsed, and nothing scrolls horizontally.
- **States.**
  - With the browser offline, navigating shows an error and keeps the events already shown.
  - Setting a calendar's `last_error` in the dev database shows the sync banner.
  - The browser console has no errors and no CSP violations.

Keep screenshots of the week view, the form, the details and the mobile list for the report.

- [ ] **Step 8: Commit**

```bash
git add Resources/frontend Resources/views/index.blade.php Http/FrontendStrings.php Tests/Feature/IntegrationHooksTest.php Public/build
git commit -m "feat: calendar page on FullCalendar 7 with the event form, recurrence, permalinks and drag and drop"
```

---

### Task 7: "Add to calendar" on conversations

This task adds a calendar button to the conversation toolbar that opens a Vue modal. The modal offers:
- the calendar
- the title, prefilled as "[Assignee] Subject" like v2, and hidden when the calendar builds titles from a template
- all-day, start and end
- the reminder
- the custom fields, with email fields prefilled with the customer's address and booleans that work

Saving creates the event and the conversation line item through W1's endpoint. The modal then confirms and links to the event. It does not reload the page, because the agent may be typing a reply.

**Files:**
- Create in `Resources/frontend/src/conversation/`: `conversation-config.ts`, `prefill.ts`, `AddToCalendar.vue`, `main.ts`, `conversation.css`
- Create: `Resources/frontend/tests/prefill.test.ts`
- Create: `Resources/views/partials/conversation_button.blade.php`
- Create: `Tests/Feature/ConversationModalTest.php`
- Modify: `Resources/frontend/vite.config.ts` (the `conversation` entry), `Http/FrontendConfig.php` (`conversation()`), `Http/FrontendStrings.php`, `Providers/LJPcCalendarModuleServiceProvider.php`
- Regenerate: `Public/build/`

**Interfaces:**
- Consumes:
  - From Task 5: `TimeFields`, `FieldInput`, `Modal`, `api`, `form-model`, `format`, `storage`.
  - The agent API: `GET calendars` and `POST conversations/{id}/events`.
  - The localStorage key `ljpc-calendar.last-calendar`, which Task 6 writes.
- Produces:
  - The `conversation.action_buttons` hook prints the button, `#ljpc-conversation-config` and the bundle's tags. It does so only when the migration has finished and the agent may create in at least one calendar, and it reports a failure instead of breaking the page.
  - `FrontendConfig::conversation(User, Conversation): array` returns the base fields plus `routes` (`calendars`, `conversation_events` as this conversation's own URL, and `page`) and `conversation` (`id`, `subject`, `customer_email`, `assignee`).

- [ ] **Step 1: Write the failing tests**

`Resources/frontend/tests/prefill.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { conversationFieldValues, conversationTitle } from '../src/conversation/prefill'

describe('conversation prefill', () => {
  it('names the assignee like v2 did', () => {
    expect(conversationTitle('Printer broken', 'Kim')).toBe('[Kim] Printer broken')
    expect(conversationTitle('Printer broken', null)).toBe('Printer broken')
  })

  it('puts the customer email in email fields and switches booleans off', () => {
    const fields = [
      { id: 1, name: 'Email', type: 'email' as const, required: false, options: [] },
      { id: 2, name: 'Paid', type: 'boolean' as const, required: false, options: [] },
      { id: 3, name: 'Note', type: 'text' as const, required: false, options: [] },
    ]
    expect(conversationFieldValues(fields, 'c@example.test')).toEqual({ 1: 'c@example.test', 2: false, 3: '' })
  })
})
```

`Tests/Feature/ConversationModalTest.php`:

```php
<?php

namespace Modules\LJPcCalendarModule\Tests\Feature;

use App\Conversation;
use App\Option;
use App\User;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\MigrationService;
use Modules\LJPcCalendarModule\Services\OccurrenceService;
use Modules\LJPcCalendarModule\Tests\TestCase;

class ConversationModalTest extends TestCase
{
    private function conversationFor(User $user): Conversation
    {
        return factory(Conversation::class)->create([
            'mailbox_id'     => $this->makeMailbox([$user->id])->id,
            'status'         => Conversation::STATUS_ACTIVE,
            'subject'        => '</script><script>alert(1)</script> Printer',
            'customer_email' => 'customer@example.test',
            'user_id'        => $user->id,
        ]);
    }

    private function calendarWith(User $user, array $rights): Calendar
    {
        $calendar = Calendar::create(['name' => 'Conv ' . uniqid(), 'provider' => Calendar::PROVIDER_LOCAL]);
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $user->id] + $rights);

        return $calendar;
    }

    /** What the toolbar prints, through the same hook core's conversation view calls (rendering that view fails under test on PHP 8.3). */
    private function toolbar(User $user, Conversation $conversation): string
    {
        $this->actingAs($user);
        ob_start();
        \Eventy::action('conversation.action_buttons', $conversation, $conversation->mailbox);

        return (string) ob_get_clean();
    }

    public function test_agents_who_can_create_somewhere_get_the_button_and_a_safe_config(): void
    {
        $user = $this->makeUser(['first_name' => 'Kim', 'last_name' => 'Agent']);
        $this->calendarWith($user, ['can_view' => true, 'can_create' => true]);
        $conversation = $this->conversationFor($user);

        $html = $this->toolbar($user, $conversation);

        $this->assertStringContainsString('ljpc-add-to-calendar', $html);
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/build/conversation\.js\?v=\d+" nonce="[^"]+"></script>#', $html);
        $this->assertSame(1, preg_match('#<script type="application/json" id="ljpc-conversation-config">(.*?)</script>#s', $html, $match));
        $config = json_decode($match[1], true);
        $this->assertSame([$conversation->id, 'customer@example.test', 'Kim Agent'], [$config['conversation']['id'], $config['conversation']['customer_email'], $config['conversation']['assignee']]);
        $this->assertStringContainsString('alert(1)', $config['conversation']['subject']);
        $this->assertStringEndsWith('/calendar/api/conversations/' . $conversation->id . '/events', $config['routes']['conversation_events']);
    }

    public function test_no_button_without_a_calendar_to_create_in_or_while_the_upgrade_runs(): void
    {
        $user = $this->makeUser();
        $calendar = $this->calendarWith($user, ['can_view' => true]);
        $conversation = $this->conversationFor($user);
        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));

        CalendarPermission::where('calendar_id', $calendar->id)->update(['can_create' => true]);
        Option::set(MigrationService::STATE_OPTION, 'running');
        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));
    }

    public function test_a_failing_check_never_breaks_the_conversation_page(): void
    {
        $user = $this->makeUser();
        $conversation = $this->conversationFor($user);
        app()->instance(MigrationService::class, new class(app(OccurrenceService::class)) extends MigrationService {
            public function isFinished(): bool
            {
                throw new \RuntimeException('broken calendar');
            }
        });

        $this->assertStringNotContainsString('ljpc-add-to-calendar', $this->toolbar($user, $conversation));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd Resources/frontend && npx vitest run tests/prefill.test.ts`
Expected: FAIL, because `../src/conversation/prefill` is missing.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter ConversationModalTest'`
Expected: the first test fails because no button is printed. The other two pass already, since nothing prints anything yet.

- [ ] **Step 3: Implement the modal**

`Resources/frontend/src/conversation/conversation-config.ts`:

```ts
import type { BaseConfig } from '../shared/config'

/** #ljpc-conversation-config (Http/FrontendConfig::conversation). */
export interface ConversationConfig extends BaseConfig {
  /** conversation_events is this conversation's own create URL. */
  routes: { calendars: string; conversation_events: string; page: string }
  conversation: { id: number; subject: string; customer_email: string | null; assignee: string | null }
}
```

`Resources/frontend/src/conversation/prefill.ts`:

```ts
import { initialFieldValues } from '../shared/form-model'
import type { FieldDefinition, FieldValue } from '../shared/types'

/** v2 behavior: "[Assignee] Subject" when the conversation is assigned. */
export function conversationTitle(subject: string, assignee: string | null): string {
  return assignee ? `[${assignee}] ${subject}` : subject
}

/** Email fields start with the customer's address; the rest starts empty (booleans off). */
export function conversationFieldValues(fields: FieldDefinition[], customerEmail: string | null): Record<string, FieldValue> {
  const prefill: Record<string, FieldValue> = {}
  if (customerEmail) {
    for (const field of fields) {
      if (field.type === 'email') {
        prefill[String(field.id)] = customerEmail
      }
    }
  }

  return initialFieldValues(fields, prefill)
}
```

`Resources/frontend/src/conversation/AddToCalendar.vue`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import FieldInput from '../shared/FieldInput.vue'
import Modal from '../shared/Modal.vue'
import TimeFields from '../shared/TimeFields.vue'
import { ApiError, createApi } from '../shared/api'
import { reminderOptions } from '../shared/format'
import { emptyForm, toCreatePayload, type FormState } from '../shared/form-model'
import { t } from '../shared/i18n'
import { readStored, writeStored } from '../shared/storage'
import { nextFullHour } from '../shared/time'
import type { CalendarInfo, EventDetail } from '../shared/types'
import type { ConversationConfig } from './conversation-config'
import { conversationFieldValues, conversationTitle } from './prefill'

const props = defineProps<{ config: ConversationConfig }>()
const emit = defineEmits<{ close: [] }>()
const api = createApi(props.config.csrf)
const formId = 'ljpc-conversation-event'
const LAST_CALENDAR = 'ljpc-calendar.last-calendar'

const calendars = ref<CalendarInfo[] | null>(null)
const form = ref<FormState | null>(null)
const errors = ref<Record<string, string>>({})
const generalError = ref('')
const saving = ref(false)
const saved = ref<EventDetail | null>(null)

const calendar = computed(() => calendars.value?.find((item) => item.id === form.value?.calendarId))
const fields = computed(() => calendar.value?.fields ?? [])

function startForm(calendarId: number): void {
  const next = calendars.value?.find((item) => item.id === calendarId)
  const base = form.value ?? emptyForm(calendarId, nextFullHour(props.config.timezone), props.config.timezone)
  const { subject, assignee, customer_email } = props.config.conversation
  form.value = {
    ...base,
    calendarId,
    title: base.title || conversationTitle(subject, assignee),
    fields: conversationFieldValues(next?.fields ?? [], customer_email),
  }
}

onMounted(async () => {
  try {
    const all = await api.get<CalendarInfo[]>(props.config.routes.calendars)
    calendars.value = all.filter((item) => item.writable && item.rights.can_create)
    const last = readStored<number | null>(LAST_CALENDAR, null)
    const first = calendars.value.find((item) => item.id === last) ?? calendars.value[0]
    if (first) {
      startForm(first.id)
    }
  } catch (caught) {
    calendars.value = []
    generalError.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  }
})

async function save(): Promise<void> {
  if (!form.value) {
    return
  }
  saving.value = true
  errors.value = {}
  generalError.value = ''
  try {
    saved.value = await api.post<EventDetail>(props.config.routes.conversation_events, toCreatePayload(form.value, fields.value))
    writeStored(LAST_CALENDAR, saved.value.calendar_id)
  } catch (caught) {
    if (caught instanceof ApiError && caught.status === 422) {
      errors.value = caught.fieldErrors()
    }
    generalError.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('Add to calendar')" size="lg" @close="emit('close')">
    <div v-if="saved" class="ljpc-saved" role="status">
      <p>{{ t('The event was added to the calendar. The conversation shows it the next time it is opened.') }}</p>
    </div>
    <div v-else-if="calendars === null" class="ljpc-loading" role="status">{{ t('Loading…') }}</div>
    <p v-else-if="!form" class="alert alert-info">{{ generalError || t('You can\'t add events to any calendar. An administrator can give you access.') }}</p>
    <form v-else :id="formId" @submit.prevent="save">
      <p v-if="generalError" class="alert alert-danger" role="alert">{{ generalError }}</p>
      <div class="form-group" :class="{ 'has-error': errors.calendar_id }">
        <label :for="`${formId}-calendar`" class="control-label">{{ t('Calendar') }}</label>
        <select :id="`${formId}-calendar`" class="form-control" :value="form.calendarId ?? ''" @change="startForm(Number(($event.target as HTMLSelectElement).value))">
          <option v-for="choice in calendars" :key="choice.id" :value="choice.id">{{ choice.name }}</option>
        </select>
        <p v-if="errors.calendar_id" class="help-block">{{ errors.calendar_id }}</p>
      </div>
      <!-- A calendar with a title template builds the title itself; the prefilled text still fills {{title}}. -->
      <div v-if="!calendar?.has_title_template" class="form-group" :class="{ 'has-error': errors.title }">
        <label :for="`${formId}-title`" class="control-label">{{ t('Title') }}</label>
        <input :id="`${formId}-title`" v-model="form.title" type="text" class="form-control" maxlength="500" required>
        <p v-if="errors.title" class="help-block">{{ errors.title }}</p>
      </div>
      <TimeFields :state="form" :time-zone="config.timezone" :errors="errors" :id-prefix="formId" @change="form = $event" />
      <div class="form-group" :class="{ 'has-error': errors.reminder_minutes }">
        <label :for="`${formId}-reminder`" class="control-label">{{ t('Reminder') }}</label>
        <select
          :id="`${formId}-reminder`"
          class="form-control"
          :value="form.reminder === null ? '' : String(form.reminder)"
          @change="form.reminder = ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in reminderOptions()" :key="String(option.value)" :value="option.value === null ? '' : String(option.value)">{{ option.label }}</option>
        </select>
      </div>
      <FieldInput
        v-for="field in fields"
        :key="field.id"
        :field="field"
        :model-value="form.fields[String(field.id)]"
        :error="errors[`field_values.${field.id}`]"
        :id-prefix="formId"
        @update:model-value="form.fields[String(field.id)] = $event"
      />
    </form>

    <template #footer>
      <template v-if="saved">
        <a class="btn btn-default" :href="`${config.routes.page}#e${saved.id}`" target="_blank" rel="noopener">{{ t('Open in calendar') }}</a>
        <button type="button" class="btn btn-primary" @click="emit('close')">{{ t('Close') }}</button>
      </template>
      <template v-else>
        <button type="button" class="btn btn-default" @click="emit('close')">{{ t('Cancel') }}</button>
        <button v-if="form" type="submit" class="btn btn-primary" :form="formId" :disabled="saving">{{ saving ? t('Saving…') : t('Add to calendar') }}</button>
      </template>
    </template>
  </Modal>
</template>
```

`Resources/frontend/src/conversation/main.ts`:

```ts
import { createApp, h, ref } from 'vue'
import './conversation.css'
import { readConfig } from '../shared/config'
import { setStrings } from '../shared/i18n'
import AddToCalendar from './AddToCalendar.vue'
import type { ConversationConfig } from './conversation-config'

const config = readConfig<ConversationConfig>('ljpc-conversation-config')
setStrings(config.strings)

const open = ref(false)
const mount = document.createElement('div')
document.body.appendChild(mount)
createApp({ render: () => (open.value ? h(AddToCalendar, { config, onClose: () => (open.value = false) }) : null) }).mount(mount)

function isButton(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest('.ljpc-add-to-calendar') !== null
}

document.addEventListener('click', (event) => {
  if (isButton(event.target)) {
    open.value = true
  }
})
document.addEventListener('keydown', (event) => {
  if ((event.key === 'Enter' || event.key === ' ') && isButton(event.target)) {
    event.preventDefault()
    open.value = true
  }
})
```

`Resources/frontend/src/conversation/conversation.css`:

```css
@import '../shared/shared.css';

.ljpc-saved {
  padding: 8px 0;
}
```

In `Resources/frontend/vite.config.ts`, make the `input` map:

```ts
      input: {
        calendar: 'src/calendar/main.ts',
        conversation: 'src/conversation/main.ts',
        settings: 'src/settings/main.ts',
      },
```

In `Http/FrontendStrings.php`, append to `KEYS`:

```php
        'Add to calendar',
        'The event was added to the calendar. The conversation shows it the next time it is opened.',
        'You can\'t add events to any calendar. An administrator can give you access.',
        'Open in calendar',
```

- [ ] **Step 4: Implement the server side**

Add to `Http/FrontendConfig.php`. Also add `use App\Conversation;`.

```php
    public static function conversation(User $user, Conversation $conversation): array
    {
        return self::base($user) + [
            'routes'       => [
                'calendars'           => route('ljpccalendarmodule.api.calendars'),
                'conversation_events' => route('ljpccalendarmodule.api.conversations.events.store', ['id' => $conversation->id]),
                'page'                => route('ljpccalendarmodule.index'),
            ],
            'conversation' => [
                'id'             => $conversation->id,
                'subject'        => (string) $conversation->getSubject(),
                'customer_email' => $conversation->customer_email ?: null,
                'assignee'       => $conversation->user ? $conversation->user->getFullName() : null,
            ],
        ];
    }
```

Create `Resources/views/partials/conversation_button.blade.php`:

```blade
<span class="conv-action glyphicon glyphicon-calendar ljpc-add-to-calendar" role="button" tabindex="0" data-toggle="tooltip" data-placement="bottom" title="{{ __('Add to calendar') }}" aria-label="{{ __('Add to calendar') }}"></span>
<script type="application/json" id="ljpc-conversation-config">{!! \Modules\LJPcCalendarModule\Http\FrontendConfig::json(\Modules\LJPcCalendarModule\Http\FrontendConfig::conversation($user, $conversation)) !!}</script>
{!! \Modules\LJPcCalendarModule\Http\Assets::style('conversation') !!}
{!! \Modules\LJPcCalendarModule\Http\Assets::script('conversation') !!}
```

In `Providers/LJPcCalendarModuleServiceProvider.php`, inside `hooks()`, add the action below. No arrow functions, because this file must parse on PHP 7.1.

```php
        // "Add to calendar" on the conversation toolbar. A failure here must never break the conversation page.
        \Eventy::addAction('conversation.action_buttons', function ($conversation, $mailbox) {
            try {
                $user = auth()->user();
                if (!$user || !app(\Modules\LJPcCalendarModule\Services\MigrationService::class)->isFinished()) {
                    return;
                }
                $canCreate = \Modules\LJPcCalendarModule\Policies\CalendarPolicy::viewableCalendars($user)->contains(function ($calendar) use ($user) {
                    return $user->can('create', $calendar);
                });
                if ($canCreate) {
                    echo View::make('calendar::partials/conversation_button', ['conversation' => $conversation, 'user' => $user])->render();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }, 20, 2);
```

- [ ] **Step 5: Run the checks and build**

In `Resources/frontend`, run `npm run check` and then `npm run build`.

Expected:
- `npm run check` reports `Tests 40 passed (40)`.
- `Public/build` now also has `conversation.js` (about 5 KB) and `conversation.css`. The conversation bundle shares the Vue and Temporal chunk with the calendar page.

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`.

- [ ] **Step 6: Verify in a browser**

Do these checks as the agent from Task 6, on a conversation in a mailbox that agent can open:
- **Calendar icon.** It appears in the toolbar with a tooltip, and Enter or Space on it opens the modal.
- **Prefilling.**
  - The title reads "[Assignee] Subject". It is hidden when the chosen calendar has a title template.
  - The start is the next full hour in the agent's profile zone.
  - Email fields hold the customer's address.
  - A boolean field can be switched on and saved.
- **Saving.**
  - A missing required field shows its error under the field.
  - A valid save shows the confirmation. "Open in calendar" opens `/calendar#e<id>` in a new tab with the details. After a reload, the conversation shows the line item.
- **No access.** An agent without a calendar they can create in sees no icon.
- **Console.** It has no errors and no CSP violations.

- [ ] **Step 7: Commit**

```bash
git add Resources/frontend Resources/views/partials/conversation_button.blade.php Http/FrontendConfig.php Http/FrontendStrings.php Providers/LJPcCalendarModuleServiceProvider.php Tests/Feature/ConversationModalTest.php Public/build
git commit -m "feat: add events from conversations in a modal with prefilled customer fields"
```

---

### Task 8: Dashboard card restyle and the module stylesheet

The card keeps its data (W1 `DashboardCard`) but loses v2's inline styles. Each event links to its permalink and shows its calendar color. The card's styles come from a small module stylesheet loaded through FreeScout's `stylesheets` filter. The unused `Public/css/default.css` goes away.

**Files:**
- Modify: `Services/DashboardCard.php` (add `id` and a safe color per item)
- Modify: `Resources/views/partials/dash_card.blade.php`, `Resources/views/partials/dash_card_event.blade.php`
- Create: `Public/css/module.css`
- Delete: `Public/css/default.css`
- Modify: `Providers/LJPcCalendarModuleServiceProvider.php` (the `stylesheets` filter)
- Test: `Tests/Feature/IntegrationHooksTest.php`

**Interfaces:**
- Consumes: `SettingsPage::color()` from Task 3.
- Produces: dashboard items `{id, title, start: {time, diff, full}, calendar: {id, name, color}}`.

- [ ] **Step 1: Write the failing tests**

Add to `Tests/Feature/IntegrationHooksTest.php`:

```php
    public function test_dashboard_card_links_each_event_to_its_permalink_without_inline_layout(): void
    {
        $user = $this->dashboardUser('Linked ' . uniqid(), 'Linked event');
        $event = CalendarEvent::where('title_input', 'Linked event')->orderBy('id', 'desc')->firstOrFail();

        $html = app(DashboardCard::class)->render($user);

        $this->assertStringContainsString(e(route('ljpccalendarmodule.index')) . '#e' . $event->id . '"', $html);
        $this->assertStringNotContainsString('style="display: flex', $html);
        $this->assertStringNotContainsString('ljpc_move_dash_card', $html);
    }

    public function test_the_module_stylesheet_is_added_to_freescout_pages(): void
    {
        $this->assertContains(\Module::getPublicPath(LJPC_CALENDARS_MODULE) . '/css/module.css', \Eventy::filter('stylesheets', []));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit --filter "permalink_without_inline_layout|module_stylesheet"'`
Expected: 2 failures.

- [ ] **Step 3: Implement**

In `Services/DashboardCard.php`, inside `render()`, replace the whole `$items[] = [...]` statement with:

```php
            $items[] = [
                'id'       => (int) $row['event_id'],
                'title'    => $row['summary'] ?? $events[$row['event_id']]->summary,
                'start'    => [
                    'time' => $row['all_day'] ? __('All day') : $start->format('H:i'),
                    'diff' => Carbon::createFromTimestamp($start->getTimestamp(), $timezone)->diffForHumans(),
                    'full' => $start->format(__('Y-m-d H:i')),
                ],
                'calendar' => ['id' => $calendar->id, 'name' => $calendar->name, 'color' => \Modules\LJPcCalendarModule\Http\SettingsPage::color($calendar->color)],
            ];
```

Replace `Resources/views/partials/dash_card.blade.php` with:

```blade
<div class="dash-card dash-card-calendar">
    <div class="dash-card-content">
        <h3 class="text-wrap-break"><a href="{{ route('ljpccalendarmodule.index') }}" class="mailbox-name">{{ __('Calendar') }}</a></h3>
        <div class="dash-card-link text-truncate"><span class="text-help">{{ __('Next 7 days') }}</span></div>
        <ul class="dash-card-list ljpc-dash-list">
            @foreach ($events as $event)
                @include('calendar::partials.dash_card_event', ['event' => $event])
            @endforeach
        </ul>
    </div>
    <div class="dash-card-footer">
        <div class="btn-group btn-group-justified btn-group-rounded">
            <a href="{{ route('ljpccalendarmodule.index') }}" class="btn btn-trans" data-toggle="tooltip" title="{{ __('Open calendar') }}" aria-label="{{ __('Open calendar') }}"><i class="glyphicon glyphicon-arrow-right" aria-hidden="true"></i></a>
        </div>
    </div>
</div>
```

Replace `Resources/views/partials/dash_card_event.blade.php` with:

```blade
<li class="ljpc-dash-item" title="{{ $event['start']['full'] }}">
    <a href="{{ route('ljpccalendarmodule.index') }}#e{{ $event['id'] }}" class="ljpc-dash-title">{{ $event['title'] }}</a>
    <span class="ljpc-dash-meta">
        <span class="ljpc-dash-calendar"><span class="ljpc-dash-swatch" style="background-color: {{ $event['calendar']['color'] }}" aria-hidden="true"></span> {{ $event['calendar']['name'] }}</span>
        <span class="ljpc-dash-when">{{ $event['start']['time'] }}, {{ $event['start']['diff'] }}</span>
    </span>
</li>
```

Create `Public/css/module.css`:

```css
/* Calendar module styles on FreeScout's own pages (loaded through the "stylesheets" filter): the dashboard card. */
.dash-card-calendar .ljpc-dash-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.dash-card-calendar .ljpc-dash-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-bottom: 9px;
}

.dash-card-calendar .ljpc-dash-title {
    overflow: hidden;
    color: #253540;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dash-card-calendar .ljpc-dash-meta {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    color: #4f5d6b;
    font-size: 12px;
}

.dash-card-calendar .ljpc-dash-calendar {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dash-card-calendar .ljpc-dash-swatch {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

.dash-card-calendar .ljpc-dash-when {
    flex: none;
}
```

In `Providers/LJPcCalendarModuleServiceProvider.php`, inside `hooks()`, add (no arrow functions):

```php
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(LJPC_CALENDARS_MODULE) . '/css/module.css';

            return $styles;
        });
```

Delete `Public/css/default.css`. Afterwards `grep -rn "default.css" --include=*.php --include=*.blade.php .`, excluding `vendor`, must print nothing.

- [ ] **Step 4: Run the tests**

Run: `ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`
Expected: `OK`. The existing dashboard tests still find the escaped titles and calendar names.

- [ ] **Step 5: Verify in a browser**

Do these checks on the FreeScout dashboard as the agent from Task 6, who has "Dashboard" on a calendar with an event in the next 7 days:
- The card lists the event with a color swatch, its calendar, its time and how far away it is.
- Clicking the title opens `/calendar#e<id>` with the details.
- Long titles end in an ellipsis instead of wrapping.
- The card matches the other dashboard cards at the 3-card and 1-card widths.

- [ ] **Step 6: Commit**

```bash
git add Services/DashboardCard.php Resources/views/partials Public/css/module.css Providers/LJPcCalendarModuleServiceProvider.php Tests/Feature/IntegrationHooksTest.php
git rm Public/css/default.css
git commit -m "feat: restyle the dashboard card and link its events to their permalinks"
```

---

### Task 9: End-to-end suite on the local FreeScout

Spec section 20 asks for Playwright end-to-end tests on the local ddev FreeScout. This task covers:
- creating, editing, dragging and deleting events
- repeating edits
- every view
- permalinks
- the conversation modal
- the settings pages
- the phone layout
- a browser time zone that differs from every profile time zone, across the US daylight saving change

A seed script prepares fixed users, a mailbox, a conversation and calendars in the dev database. It refuses to run outside the ddev project.

**Files:**
- Create: `Tests/e2e/seed.sh`
- Create: `Resources/frontend/playwright.config.ts`
- Create in `Resources/frontend/e2e/`: `helpers.ts`, `calendar.spec.ts`, `recurring.spec.ts`, `permalink.spec.ts`, `conversation.spec.ts`, `settings.spec.ts`, `mobile.spec.ts`
- Modify: `Resources/frontend/package.json` (adds `@playwright/test@1.63.0`, `@types/node@22.20.5` and an `e2e` script), `Resources/frontend/tsconfig.json` (Node types; includes `e2e` and the config), `.gitignore`, `CLAUDE.md`

**Interfaces:**
- Consumes: everything from Tasks 3 to 8, through the browser only.
- Produces: `npm run e2e` in `Resources/frontend`, after `ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh`.

- [ ] **Step 1: Add Playwright**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module/Resources/frontend
npm install --include=dev --save-dev --save-exact @playwright/test@1.63.0 @types/node@22.20.5
npx playwright install chromium
```

In `package.json`, add the script `"e2e": "playwright test"`.

In `tsconfig.json`, set `"types": ["vite/client", "node"]`. The specs and the Playwright config use Node's `fs` and `process`. Then extend `include` to:

```json
  "include": ["src/**/*.ts", "src/**/*.vue", "tests/**/*.ts", "e2e/**/*.ts", "vite.config.ts", "vitest.config.ts", "playwright.config.ts"]
```

Add to `.gitignore`:

```
/Resources/frontend/e2e/.seed.json
/Resources/frontend/test-results/
/Resources/frontend/playwright-report/
```

`Resources/frontend/playwright.config.ts`:

```ts
import { defineConfig, devices } from '@playwright/test'

// Runs against the local ddev FreeScout; seed it first (Tests/e2e/seed.sh). The browser's zone (Tokyo) differs from
// every profile zone on purpose: any use of the browser's timezone shows up as a wrong time.
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://freescout.ddev.site',
    ignoreHTTPSErrors: true,
    locale: 'en-US',
    timezoneId: 'Asia/Tokyo',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], locale: 'en-US', timezoneId: 'Asia/Tokyo' } }],
})
```

- [ ] **Step 2: Write the seed script**

`Tests/e2e/seed.sh`:

```bash
#!/usr/bin/env bash
# Prepares the local ddev FreeScout for the end-to-end suite (Resources/frontend/e2e). It creates users with a known
# password, so it only runs inside the local ddev project:
#   ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh
set -euo pipefail
if [ "${DDEV_PROJECT:-}" != "freescout" ]; then
    echo "Run this inside the local ddev project only." >&2
    exit 1
fi
cd /var/www/html
php artisan config:clear >/dev/null

output=$(php artisan tinker <<'PHP'
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventService;

$password = 'e2e-calendar-pass';
$person = function (string $email, string $lastName, int $role, string $timezone, int $clock) use ($password) {
    $user = User::where('email', $email)->first() ?: new User();
    $user->forceFill([
        'email' => $email, 'first_name' => 'E2E', 'last_name' => $lastName, 'type' => User::TYPE_USER, 'status' => User::STATUS_ACTIVE,
        'role' => $role, 'timezone' => $timezone, 'time_format' => $clock, 'locale' => 'en', 'password' => \Hash::make($password),
    ]);
    $user->save();

    return $user;
};
$admin = $person('e2e-admin@example.test', 'Admin', User::ROLE_ADMIN, 'Europe/Amsterdam', User::TIME_FORMAT_24);
$agent = $person('e2e-agent@example.test', 'Agent', User::ROLE_USER, 'America/New_York', User::TIME_FORMAT_12);

$mailbox = Mailbox::where('email', 'e2e-mailbox@example.test')->first()
    ?: factory(Mailbox::class)->create(['name' => 'E2E mailbox', 'email' => 'e2e-mailbox@example.test']);
$mailbox->users()->syncWithoutDetaching([$agent->id]);
$customer = Customer::create('e2e-customer@example.test', ['first_name' => 'E2E', 'last_name' => 'Customer']);
$conversation = Conversation::where('mailbox_id', $mailbox->id)->where('subject', 'E2E printer is broken')->first();
if (!$conversation) {
    $conversation = factory(Conversation::class)->create([
        'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id, 'customer_email' => 'e2e-customer@example.test',
        'subject' => 'E2E printer is broken', 'status' => Conversation::STATUS_ACTIVE, 'type' => Conversation::TYPE_EMAIL,
        'user_id' => $agent->id, 'created_by_user_id' => $admin->id,
    ]);
    factory(Thread::class)->create([
        'conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'to' => 'e2e-mailbox@example.test',
        'body' => 'The printer on the second floor is broken.',
    ]);
    $conversation->updateFolder();
    $conversation->save();
}

$calendar = function (string $name, array $fields, array $rights) use ($agent) {
    $calendar = Calendar::where('name', $name)->first() ?: Calendar::create(['name' => $name, 'provider' => Calendar::PROVIDER_LOCAL, 'color' => '#2ecc71']);
    $calendar->update(['enabled' => true, 'fields' => $fields, 'title_template' => null]);
    CalendarEvent::where('calendar_id', $calendar->id)->delete();
    CalendarPermission::where('calendar_id', $calendar->id)->delete();
    CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id] + $rights);

    return $calendar;
};
$support = $calendar('E2E Support', [
    ['id' => 172604180001, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call', 'Visit']],
    ['id' => 172604180002, 'name' => 'Paid', 'type' => 'boolean', 'required' => false, 'options' => []],
    ['id' => 172604180003, 'name' => 'Customer email', 'type' => 'email', 'required' => false, 'options' => []],
], ['show_in_dashboard' => true, 'can_view' => true, 'can_create' => true, 'can_edit' => true]);
$readOnly = $calendar('E2E Read only', [], ['can_view' => true]);
$fixed = app(EventService::class)->create($readOnly, $admin, ['title' => 'E2E fixed', 'start' => '2026-11-20T12:00']);
Calendar::where('name', 'like', 'E2E settings %')->get()->each->delete();

$url = $conversation->url();
file_put_contents(base_path('Modules/freescout-calendar-module/Resources/frontend/e2e/.seed.json'), json_encode([
    'password'         => $password,
    'admin'            => $admin->email,
    'agent'            => $agent->email,
    'conversationPath' => parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : ''),
    'readOnlyEventId'  => $fixed->id,
], JSON_PRETTY_PRINT));
echo "e2e seed ready\n";
PHP
)
echo "$output"
grep -q "e2e seed ready" <<<"$output" || { echo "Seeding failed." >&2; exit 1; }
```

Run: `ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh`
Expected: `e2e seed ready`, and `Resources/frontend/e2e/.seed.json` exists. Run it again and the output must be the same: the script is idempotent.

- [ ] **Step 3: Write the specs**

`Resources/frontend/e2e/helpers.ts`:

```ts
import { expect, type Locator, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'

export interface Seed {
  password: string
  admin: string
  agent: string
  conversationPath: string
  readOnlyEventId: number
}

/** Written by Tests/e2e/seed.sh inside ddev. */
export const seed = JSON.parse(readFileSync(new URL('./.seed.json', import.meta.url), 'utf8')) as Seed

export async function login(page: Page, email: string): Promise<void> {
  await page.goto('/login')
  await page.fill('#email', email)
  await page.fill('#password', seed.password)
  await page.click('button[type="submit"]')
  await expect(page).not.toHaveURL(/\/login/)
}

export async function openCalendar(page: Page): Promise<void> {
  await page.goto('/calendar')
  await expect(page.locator('.ljpc-sidebar')).toBeVisible()
}

/** Sets the open form's start date and time (and end time); the inputs react on change, hence the Tab. */
export async function fillTimes(page: Page, date: string, start: string, end?: string): Promise<void> {
  const modal = page.locator('.modal')
  await modal.locator('input[type="date"]').first().fill(date)
  await modal.locator('input[type="date"]').first().press('Tab')
  await modal.getByLabel('Start time').fill(start)
  await modal.getByLabel('Start time').press('Tab')
  if (end) {
    await modal.getByLabel('End time').fill(end)
    await modal.getByLabel('End time').press('Tab')
  }
}

export async function save(page: Page): Promise<void> {
  await page.locator('.modal-footer').getByRole('button', { name: 'Save' }).click()
  await expect(page.locator('.modal')).toHaveCount(0)
}

/** Creates a timed event through "New event"; the page then shows the event's week. */
export async function createEvent(page: Page, title: string, date: string, start: string): Promise<void> {
  await page.getByRole('button', { name: 'New event' }).click()
  await page.locator('.modal').getByLabel('Title').fill(title)
  await fillTimes(page, date, start)
  await save(page)
}

/** Events on the grid: eventDidMount gives every event element its full title. */
export function onGrid(page: Page, title: string): Locator {
  return page.locator(`.ljpc-grid [title="${title}"]`)
}

/** The date (from the week view's column headers) of each event with this title, in document order. */
async function columnDates(page: Page, title: string): Promise<string[]> {
  const columns = await page.locator('[role="columnheader"][data-date]').evaluateAll((elements) => elements.map((element) => {
    const box = element.getBoundingClientRect()

    return { date: element.getAttribute('data-date') ?? '', left: box.left, right: box.right }
  }))

  return onGrid(page, title).evaluateAll((elements, all) => elements.map((element) => {
    const box = element.getBoundingClientRect()
    const x = box.left + box.width / 2

    return all.find((column) => x >= column.left && x <= column.right)?.date ?? '?'
  }), columns)
}

/** The dates of the events with this title in the week view, sorted. */
export async function datesOf(page: Page, title: string): Promise<string[]> {
  return (await columnDates(page, title)).sort()
}

/** The event with this title on a date of the week view. */
export async function eventOn(page: Page, title: string, date: string): Promise<Locator> {
  const index = (await columnDates(page, title)).indexOf(date)
  if (index < 0) {
    throw new Error(`No "${title}" on ${date}`)
  }

  return onGrid(page, title).nth(index)
}

/** The horizontal distance between two days' column headers in the week view. */
export async function daysApart(page: Page, from: string, to: string): Promise<number> {
  const a = await page.locator(`[role="columnheader"][data-date="${from}"]`).boundingBox()
  const b = await page.locator(`[role="columnheader"][data-date="${to}"]`).boundingBox()
  if (!a || !b) {
    throw new Error(`No column for ${from} or ${to}`)
  }

  return b.x - a.x
}

/** Drags in small steps, the way FullCalendar's interaction plugin needs pointer moves. */
export async function dragBy(page: Page, target: Locator, dx: number, dy: number): Promise<void> {
  const box = await target.boundingBox()
  if (!box) {
    throw new Error('Nothing to drag')
  }
  const x = box.x + Math.min(12, box.width / 2)
  const y = box.y + Math.min(6, box.height / 2)
  await page.mouse.move(x, y)
  await page.mouse.down()
  await page.mouse.move(x + dx, y + dy, { steps: 15 })
  await page.mouse.up()
}
```

`Resources/frontend/e2e/calendar.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { createEvent, daysApart, dragBy, login, onGrid, openCalendar, save, seed } from './helpers'

test.describe('calendar page (agent in New York, browser in Tokyo, 12-hour clock)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, seed.agent)
    await openCalendar(page)
  })

  test('shows every view without errors', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (error) => errors.push(error.message))
    for (const view of ['Day', 'Month', 'Year', 'List', 'Week']) {
      await page.getByRole('tab', { name: new RegExp(`^${view}$`, 'i') }).click()
      await expect(page.locator('.ljpc-grid')).toBeVisible()
    }
    expect(errors).toEqual([])
  })

  test('creates an event at the profile time, then edits and deletes it', async ({ page }) => {
    await createEvent(page, 'E2E standup', '2026-11-16', '10:00')
    await expect(onGrid(page, 'E2E standup')).toContainText('10:00 AM')

    await onGrid(page, 'E2E standup').click()
    await expect(page.locator('.ljpc-details')).toContainText('Monday, November 16, 2026')
    await expect(page.locator('.ljpc-details')).toContainText('10:00 AM')
    await expect(page).toHaveURL(/#e\d+$/)

    await page.locator('.modal-footer').getByRole('button', { name: 'Edit', exact: true }).click()
    await page.locator('.modal').getByLabel('Title').fill('E2E standup moved')
    await page.locator('.modal').getByLabel('Start time').fill('11:30')
    await page.locator('.modal').getByLabel('Start time').press('Tab')
    await save(page)
    await expect(onGrid(page, 'E2E standup moved')).toContainText('11:30 AM')

    await onGrid(page, 'E2E standup moved').click()
    await page.locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
    await page.locator('.ljpc-confirm').getByRole('button', { name: 'Delete' }).click()
    await expect(onGrid(page, 'E2E standup moved')).toHaveCount(0)
  })

  test('moves an event by dragging it to another day', async ({ page }) => {
    await createEvent(page, 'E2E drag', '2026-11-17', '14:00')

    await dragBy(page, onGrid(page, 'E2E drag'), await daysApart(page, '2026-11-17', '2026-11-19'), 0)

    await expect(onGrid(page, 'E2E drag')).toBeVisible()
    await onGrid(page, 'E2E drag').click()
    await expect(page.locator('.ljpc-details')).toContainText('Thursday, November 19, 2026')
    await expect(page.locator('.ljpc-details')).toContainText('2:00 PM')
  })

  test('shows a read-only calendar without edit actions', async ({ page }) => {
    await page.goto(`/calendar#e${seed.readOnlyEventId}`)
    await expect(page.locator('.modal-title')).toHaveText('E2E fixed')
    await expect(page.locator('.modal-footer').getByRole('button', { name: 'Edit' })).toHaveCount(0)
    await expect(page.locator('.modal-footer').getByRole('button', { name: 'Delete' })).toHaveCount(0)
  })
})
```

`Resources/frontend/e2e/recurring.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { datesOf, daysApart, dragBy, eventOn, fillTimes, login, onGrid, openCalendar, save, seed } from './helpers'

async function createWeekly(page: import('@playwright/test').Page, title: string, date: string, time: string, extraDay: string | null, times: number): Promise<void> {
  await page.getByRole('button', { name: 'New event' }).click()
  const modal = page.locator('.modal')
  await modal.getByLabel('Title').fill(title)
  await fillTimes(page, date, time)
  await modal.getByLabel('Repeat', { exact: true }).selectOption('WEEKLY')
  if (extraDay) {
    await modal.locator(`.ljpc-weekdays label[title="${extraDay}"]`).click()
  }
  await modal.getByText('After', { exact: true }).click()
  await modal.getByLabel('Number of times').fill(String(times))
  await modal.getByLabel('Number of times').press('Tab')
  await save(page)
}

test.describe('repeating events (agent in New York, browser in Tokyo)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, seed.agent)
    await openCalendar(page)
  })

  test('edits one occurrence or the whole series and keeps 10:00 across the US clock change', async ({ page }) => {
    // Mondays and Wednesdays from 26 October; the US leaves daylight saving time on 1 November 2026.
    await createWeekly(page, 'E2E weekly', '2026-10-26', '10:00', 'Wednesday', 4)
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-10-26', '2026-10-28'])

    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-11-02', '2026-11-04'])
    await expect(onGrid(page, 'E2E weekly').first()).toContainText('10:00 AM')

    await (await eventOn(page, 'E2E weekly', '2026-11-04')).click()
    await page.locator('.modal-footer').getByRole('button', { name: 'Edit this occurrence' }).click()
    await page.locator('.modal').getByLabel('Title').fill('E2E weekly special')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-11-02'])

    await (await eventOn(page, 'E2E weekly', '2026-11-02')).click()
    await page.locator('.modal-footer').getByRole('button', { name: 'Edit series' }).click()
    await page.locator('.modal').getByLabel('Title').fill('E2E weekly renamed')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E weekly renamed')).toEqual(['2026-11-02'])
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])

    await (await eventOn(page, 'E2E weekly renamed', '2026-11-02')).click()
    await page.locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
    await page.locator('.ljpc-confirm').getByRole('button', { name: 'This occurrence' }).click()
    await expect.poll(() => datesOf(page, 'E2E weekly renamed')).toEqual([])
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])
  })

  test('dragging a series moves its weekday along', async ({ page }) => {
    await createWeekly(page, 'E2E series drag', '2026-11-23', '09:00', null, 3)
    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-11-23'])

    await dragBy(page, await eventOn(page, 'E2E series drag', '2026-11-23'), await daysApart(page, '2026-11-23', '2026-11-24'), 0)
    await page.locator('.modal-footer').getByRole('button', { name: 'Whole series' }).click()

    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-11-24'])
    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-12-01'])
  })
})
```

`Resources/frontend/e2e/permalink.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { createEvent, login, onGrid, openCalendar, seed } from './helpers'

test('opens events from permalinks and explains unknown ones', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)
  await createEvent(page, 'E2E permalink', '2026-11-18', '15:00')
  await onGrid(page, 'E2E permalink').click()
  const hash = await page.evaluate(() => window.location.hash)
  expect(hash).toMatch(/^#e\d+$/)

  await page.goto('/calendar')
  await page.goto(`/calendar${hash}`)
  await expect(page.locator('.modal-title')).toHaveText('E2E permalink')
  await expect(onGrid(page, 'E2E permalink')).toBeVisible()

  await page.goto('/calendar#e999999999')
  await expect(page.locator('.modal .alert-danger')).toContainText('no longer exists')
})
```

`Resources/frontend/e2e/conversation.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { fillTimes, login, seed } from './helpers'

test('adds an event from a conversation with the customer prefilled', async ({ page }) => {
  await login(page, seed.agent)
  await page.goto(seed.conversationPath)
  await page.locator('.ljpc-add-to-calendar').click()

  const modal = page.locator('.modal')
  await expect(modal.getByLabel('Title')).toHaveValue('[E2E Agent] E2E printer is broken')
  await expect(modal.getByLabel('Customer email')).toHaveValue('e2e-customer@example.test')
  await fillTimes(page, '2026-11-19', '13:00')
  await modal.getByLabel('Kind').selectOption('Visit')
  await page.locator('.modal-footer').getByRole('button', { name: 'Add to calendar' }).click()
  await expect(modal).toContainText('The event was added to the calendar')

  const href = await page.locator('.modal-footer a', { hasText: 'Open in calendar' }).getAttribute('href')
  expect(href).toMatch(/#e\d+$/)
  await page.goto(href ?? '')
  await expect(page.locator('.ljpc-details')).toContainText('Visit')
  await expect(page.locator('.ljpc-details')).toContainText('1:00 PM')
  await expect(page.locator('.ljpc-details a', { hasText: '#' })).toBeVisible()
})
```

`Resources/frontend/e2e/settings.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { login, seed } from './helpers'

test('creates, configures and deletes a calendar', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept())
  await login(page, seed.admin)
  const name = `E2E settings ${Date.now()}`

  await page.goto('/app-settings/calendar')
  await page.getByRole('link', { name: 'New calendar' }).click()
  await page.fill('#ljpc-name', name)
  await page.getByRole('button', { name: 'Create calendar' }).click()
  await expect(page.locator('h3.ljpc-title')).toContainText(name)

  // A dropdown in the empty row, a boolean through "Add field" (options hide for it).
  const rows = page.locator('[data-ljpc-field-row]')
  await rows.last().locator('input[type="text"]').fill('Kind')
  await rows.last().locator('[data-ljpc-field-type]').selectOption('dropdown')
  await rows.last().locator('textarea').fill('Call\nVisit')
  await page.getByRole('button', { name: 'Add field' }).click()
  await rows.last().locator('input[type="text"]').fill('Paid')
  await rows.last().locator('[data-ljpc-field-type]').selectOption('boolean')
  await expect(rows.last().locator('[data-ljpc-field-options]')).toBeHidden()

  await page.locator('[data-ljpc-select-all="user:can_view"]').check()
  await page.getByRole('button', { name: 'Save calendar' }).click()
  await expect(page.locator('input[name$="[name]"][value="Kind"]')).toHaveCount(1)
  await expect(page.locator('input[name$="[name]"][value="Paid"]')).toHaveCount(1)
  await expect(page.locator('[data-ljpc-right="user:can_view"]:not(:checked)')).toHaveCount(0)

  // Drag "Paid" above "Kind" by its handle, in small steps for SortableJS.
  const paid = await rows.filter({ has: page.locator('input[value="Paid"]') }).locator('.ljpc-drag-handle').boundingBox()
  const kind = await rows.filter({ has: page.locator('input[value="Kind"]') }).locator('.ljpc-drag-handle').boundingBox()
  if (!paid || !kind) {
    throw new Error('Drag handles not found')
  }
  await page.mouse.move(paid.x + paid.width / 2, paid.y + paid.height / 2)
  await page.mouse.down()
  await page.mouse.move(kind.x + kind.width / 2, kind.y + 2, { steps: 15 })
  await page.mouse.up()
  await page.getByRole('button', { name: 'Save calendar' }).click()
  await expect(rows.first().locator('input[type="text"]')).toHaveValue('Paid')

  const before = await page.locator('#ljpc-export-full').inputValue()
  await page.locator('.ljpc-new-link').first().click()
  await expect(page.locator('#ljpc-export-full')).not.toHaveValue(before)

  await page.getByRole('button', { name: 'Delete calendar' }).click()
  await expect(page.getByRole('link', { name })).toHaveCount(0)
})
```

`Resources/frontend/e2e/mobile.spec.ts`:

```ts
import { expect, test } from '@playwright/test'
import { login, openCalendar, seed } from './helpers'

test.use({ viewport: { width: 375, height: 800 } })

test('opens on the list view with the calendars folded away and no sideways scrolling', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)

  await expect(page.locator('.ljpc-sidebar')).toHaveClass(/is-collapsed/)
  await expect(page.getByRole('tab', { name: /^list$/i })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0)
})
```

- [ ] **Step 4: Run the suite**

```bash
ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh
cd ~/Projects/freescout/Modules/freescout-calendar-module/Resources/frontend
npm run check
npm run e2e
```

Expected:
- `npm run check`: vue-tsc also type-checks the specs.
- `npm run e2e`: all tests pass.

If a spec fails, open the trace (`npx playwright show-trace test-results/.../trace.zip`) and decide which side is wrong:
- If the selector or the timing is wrong, fix the spec.
- If the behavior is wrong, fix the application, add a Vitest or PHPUnit test for that bug, then rerun.

Never weaken an assertion about times or time zones to make it pass.

Run the seed and the suite twice in a row. The second run must also pass, which proves both are idempotent.

- [ ] **Step 5: Document the suite in CLAUDE.md**

Add to the "Frontend" section of `CLAUDE.md`:

```markdown
- End-to-end tests (Playwright, against https://freescout.ddev.site): `ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh`, then `npm run e2e` in `Resources/frontend`. The seed creates `e2e-admin@example.test` and `e2e-agent@example.test` (password in the script) in the dev database only; it refuses to run outside ddev. The browser runs in Asia/Tokyo and the agent's profile in America/New_York, so any use of the browser's timezone fails the suite.
```

- [ ] **Step 6: Commit**

```bash
cd ~/Projects/freescout/Modules/freescout-calendar-module
git add .gitignore CLAUDE.md Tests/e2e/seed.sh Resources/frontend/package.json Resources/frontend/package-lock.json Resources/frontend/tsconfig.json Resources/frontend/playwright.config.ts Resources/frontend/e2e
git commit -m "test: add the end-to-end suite for the calendar, conversation modal and settings"
```

---

## Self-review (done while writing this plan)

**Spec coverage for W2 (spec sections 11, 12, 20 and 23, with the decisions of 2026-10-06):**

| Spec item | Where |
|---|---|
| One Vite project, TypeScript, stable entry names, committed `Public/build`, CI rebuild check | Task 4 |
| Modules load as `type="module"` with the CSP nonce, never through the `javascripts` filter; config as JSON | Tasks 4, 6, 7 (`Assets`, `FrontendConfig::json`) |
| FullCalendar 7.1.x pinned; classic theme tuned with `--fc-classic-*`; module CSS scoped | Tasks 4, 6 |
| Day, week, month, year, list; mobile opens on list; week starts Monday; locale from the agent; RTL for ar, he, fa | Task 6 (`App.vue`, `locales.ts`), Task 5 (`FrontendConfig::base`) |
| Agent timezone, never the browser's; 12- or 24-hour clock from the profile | Tasks 5, 6; checked end to end in Task 9 (Tokyo browser, New York profile, US DST change) |
| Event form: calendar, title, all-day, start/end, location, description, recurrence, reminder, custom fields | Task 5 (`form-model.ts`, `TimeFields`, `FieldInput`), Task 6 (`EventForm`, `RecurrenceEditor`) |
| "This occurrence" or "whole series" for edits, drags and deletes | Task 6 (`EventDetails`, `ScopeDialog`, `App.onChange`) |
| Details view: creator, conversation, copy permalink, duplicate, edit and delete by rights | Task 6 (`EventDetails`) |
| Permalinks `#e<id>`, bare number = v2 id, then UID | Task 6 (`permalink.ts`, `App.openFromHash`) |
| 2.7.x parity: priority order, full title on hover, collapsible list in localStorage, growing "+N" popover, all-day row shows every event | Task 6 (`compareEvents`, `eventDidMount`, `CalendarSidebar`, `popoverClass`, `views.timeGrid.dayMaxEvents`) |
| Sync error banner | Task 6 (from Task 1's `sync_failed`) |
| Settings: calendars, fields (drag to order), permission matrix with user, team and mailbox rows and select-all, export links, migration report | Tasks 2, 3, 4 (server-rendered by decision) |
| Conversation "Add to calendar": calendar, title (hidden with a template), times, all-day, fields with the customer email prefilled, booleans, reminder | Task 7 |
| Dashboard card | Task 8 (data from W1, restyled, permalinks) |
| Security items of section 18 that touch the UI: no Blade in JS, JSON config with the four `JSON_HEX_*` flags, no `innerHTML`/`v-html` with data, `strip_tags` on names | Tasks 3, 5, 6, 7 (Vue escapes; settings HTML comes only from a server `<template>`) |
| Playwright end to end on the local ddev FreeScout | Task 9 |

**Not in W2, by plan:**
- the Connections settings and the screens that add external calendars (W3, decision of 2026-10-06)
- the attendee fields in the event form and the conversation modal, and attendee status in the details (W4, invites)
- sending reminders (W5; the reminder fields are stored from W2 on)
- translating the new strings (W5)

**W1 ledger carry-over for W2, all covered:**
- **Stuck migration:**
  - the report on the settings page, with retry instructions and "Skip" → Tasks 2 and 3
  - unconverted calendars read-only, with only delete → Task 3
  - report dedupe → Task 2
- **Event form and API contract:**
  - occurrence edits send no series keys → Task 5 `toOccurrencePatch`
  - series saves send only changed keys, plus the rule → Task 5 `toSeriesPatch`
  - v3 events requested as `e<id>` and `#e<id>` parsed → Task 6
- **Validation:**
  - field id normalization (leading zeros) and empty names → Task 3 `CalendarForm::fieldId()` and `fields()`
  - multiselect dedupe, booleans in text fields → Task 1
  - the M9 nits (all-day without a start, the end message, strict all-day dates, dashboard order) → Task 1
- **Data sent to agents and performance:**
  - `last_error` no longer sent to agents → Task 1 `sync_failed`
  - no `ics` in the event list or the card; one calendars query in the event list → Task 1
- **Decision:** the dashboard right stays independent of View → explained in the Task 3 matrix
- **Style:** the provider in PSR-12 (M14) → Task 0

**Deliberately left out (ponytail):**
- **Per-row team and mailbox lookups in `CalendarPolicy::rights()`.** These are the N+1 from the W1 ledger. They are not cached: installs have a handful of calendars and rows, and the cost is a few queries per request. Add a per-request cache when an install shows it in a profile.
- **Uncached `Option::get` for the occurrence window in `OccurrenceService::refresh()`.** This is W1 ledger M7. It is only hit by saves and the daily job.
- **The conversation modal does not reload the page.** The line item appears on the next load (see the deviations above).

**Checks done while writing:**
- **Frontend.** All TypeScript and Vue code in Tasks 4 to 9 (sources, unit tests, the Playwright specs and the config) was type-checked with vue-tsc 3.3.12 and TypeScript 6.0.3 against these exact package versions.
- **Unit tests.** The 40 Vitest tests passed.
- **Build.** The build produced exactly one CSS file per entry, and two builds gave identical hashes.
- **Calendar page.** The page and the conversation modal ran in headless Chromium, with a mocked API and a browser timezone different from the profile:
  - permalink → details
  - series save sending only the title
  - create at the next full hour in the profile zone
  - dragging a weekly series moving its weekday
  - the "+N" popover
  - the all-day row showing every event
- **Settings script.** It ran against a static page.
- **PHP.** The PHP code was not executed while planning. It follows W1's code and tests, and each task's TDD steps run it.
- **Placeholders and names.** There are no placeholders. Every class, method, route name, data attribute and string key a later task uses is defined in an earlier task (for example: `SettingsPage::url()`, Task 3 → Tasks 5, 8; `viewableCalendars()`, Task 1 → Task 7; `pendingItems()`/`skipItem()`, Task 2 → Task 3; the `data-ljpc-*` hooks, Task 3 → Task 4; `FrontendStrings::KEYS`, Task 5 → Tasks 6, 7).

**Execution notes for the controller:**
- **Order.** Run the tasks in order. Tasks 1 and 2 are independent of each other, but both come before Task 3.
- **Model choice.** The plan contains the complete code, so most implementers transcribe and verify. Use a mid-tier model for Tasks 0, 1, 2, 4 and 8, and a stronger one for Tasks 3, 5, 6, 7 and 9: those are large, touch the browser, and may need judgment when a real FreeScout page differs from the mocked one.
- **Browser checks.** The browser checks in Tasks 3, 4, 6, 7 and 8 must be done for real, with screenshots in the report. The Task 9 suite then repeats the core flows automatically.
