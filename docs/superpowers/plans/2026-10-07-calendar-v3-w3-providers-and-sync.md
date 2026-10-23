# Calendar module v3, W3: providers and sync, implementation plan

> **How this plan was executed:** every task was implemented for real, test first, and reviewed (a code review on opus, then fix rounds until it was clean) on a draft branch that started at `af41f8c`, W2's last commit. Plan sections with every line of code were then generated from those commits and checked against them by script. On 2026-10-15 Lars decided to fast-forward `v3` onto those commits instead of re-transcribing a plan of about 2 MB, and to finish W3 with one review of the whole branch and one fix round. This file is the compact plan of record: what each task builds, its interfaces, its deviations and how it was verified. The commits are the code; this file never repeats it.

**Goal:** Connect external calendars to the v3 module: read-only ICS feeds, and two-way CalDAV, Google Calendar and Microsoft 365 calendars, kept up to date by a cron-driven sync engine. Add the Connections settings, OAuth with PKCE, connection health alerts, and the remaining W1 and W2 time-zone carry-overs.

**Architecture:**
- **Providers:** every calendar type sits behind one `CalendarProvider` interface (`CalendarProviders/`), resolved per calendar by `ProviderRegistry`. Two optional interfaces extend it without changing the contract: `ConfirmsDeletions` (the engine asks before it sweeps) and `ExplainsRecurrenceRefusal` (why a repeat rule can't be stored).
- **Pull:** `SyncService`, run by `calendar:sync` every minute from FreeScout's scheduler (never the queue), pulls a `ChangeSet` per due calendar under an owned lease and a time budget. `EventImporter` turns each pulled event into a stored VCALENDAR plus derived columns and occurrences. Full listings may span runs and are swept by generation.
- **Push:** writes stay synchronous. `EventService` asks the calendar's provider to create, update or delete first and saves locally only after the provider confirmed. A conflict syncs that calendar at once, then the agent gets the 409.
- **HTTP:** all HTTP goes through core's Guzzle 8 via one `HttpClientFactory` (core's proxy, timeout and TLS options, the SSRF host check on every request and redirect hop). Google and Microsoft share one request loop, `OAuthApi`. Tests replace the factory with `Tests\Support\FakeHttp`.
- **Health:** `HealthService` runs after each sync (at most every 15 minutes), mails each new problem once to the administrators and feeds the banners in the settings and on the calendar page.

**Tech stack:** PHP 8.2+ on FreeScout 1.8.243+ (Laravel 5.5), Guzzle 8 (core's), sabre/vobject 4.6, sabre/xml through sabre/dav 4.7 (CalDAV XML), doctrine/dbal (core's, for one index check), FreeScout's Blade settings forms with the settings TypeScript bundle, the Vue 3 calendar page (small changes), PHPUnit with Guzzle's `MockHandler`, a Radicale 3 container for CalDAV integration tests and the e2e suite, Playwright.

**Context:**
- W1 (`d408497`) and W2 (`af41f8c`) were complete. W3 is `af41f8c..3a559be`: 16 commits, listed per task under "Tasks".
- The spec is `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`; Task 15 rewrote its sections 8 (providers and sync) and 9 (OAuth and connections) from the code. Its decision log (section 25) is authoritative.
- Provider API research with sources is in `.git/sdd/w3-research.md`, and the full per-task sections with code in `.git/sdd/w3-plan/`; both are local to the checkout and never committed.
- Commits after `3a559be` (the fixes for Lars's 2026-10-15 decisions and the fix round of the whole-branch review) are not part of this plan.

---

## Global constraints

**Platform, identity, data** (the W1 and W2 constraints still apply):
- PHP `^8.2`, FreeScout `1.8.243`+, Laravel 5.5 APIs only (no `Cache::lock`, no `Str::uuid`, no Laravel 6+ helpers).
- Module identity never changes: alias `ljpccalendarmodule`, namespace `Modules\LJPcCalendarModule`, view namespace `calendar::`, constant `LJPC_CALENDARS_MODULE`, line-item `action_type` `186`, route name `ljpccalendarmodule.index`.
- UTC `dateTime` columns as `Y-m-d H:i:s`; never Eloquent date casting for `starts_at`/`ends_at`.
- Query builder or Eloquent only, no raw SQL. PSR-12 for PHP.
- Every file in `Database/Migrations`, the service provider and `start.php` must parse on PHP 7.1 (no arrow functions, typed properties, `mixed`, `never`, nullsafe, match, named arguments).
- No new Composer or npm dependency. Never bundle a package that core ships.

**HTTP and security** (spec sections 3, 18, 19):
- Every provider request goes through `CalendarProviders\HttpClientFactory`: core's Guzzle 8 with `\Helper::setGuzzleDefaultOptions()` (timeout, proxy, TLS verify).
- A URL an admin typed (ICS feed, CalDAV server, every redirect hop) must pass `\Helper::checkUrlHost($url, true)` before the request: http(s) only, private hosts blocked unless `APP_REMOTE_HOST_WHITE_LIST` allows them. Google and Microsoft hosts are fixed and skip the check.
- Never use `\Helper::getRemoteFileContents()`: it logs the full URL on failure, and an ICS URL can be a secret.
- Never log or store secrets, tokens, passwords, `Authorization` headers or full ICS URLs. Logs carry calendar id, provider, HTTP status and at most 500 characters of response body with tokens removed. An ICS URL is logged as its host only.
- `last_error` holds our own translated message plus the HTTP status, never a raw provider body. Agents never receive its text, only `sync_failed`.
- Secrets are stored with `encrypt()`: connection credentials (JSON) and the app client secrets (`Option`). They are write-only in the UI: the form shows "saved" and an empty field.
- OAuth always uses `state` and PKCE (`S256`), verified on the callback. CSRF stays on for every POST.
- No swallowed exceptions. A failing calendar never affects another calendar, the dashboard or outgoing mail.

**Behavior:**
- The provider always wins on pull. Local writes happen during the request, before the local save, with preconditions (`If-Match` or the provider's equivalent).
- Provider errors toward agents:
  - a precondition failure answers HTTP 409 `{ "code": "changed_elsewhere", "message": "This event was changed elsewhere. Reload and try again." }` (never `drops_exceptions`, which W2 uses);
  - a network or provider error answers HTTP 502 `{ "code": "provider_unavailable", "message": ... }`;
  - a connection that needs reauthorization answers HTTP 502 `{ "code": "connection_needs_reauth", "message": "This calendar's connection needs to be re-authorized by an administrator." }`;
  - nothing is saved locally in any of these cases.
- The sync command never runs while the v2 migration is unfinished (`MigrationService::isFinished()`).

**Frontend and copy:** the W2 frontend constraints still apply: strings through `t()` and `FrontendStrings::KEYS`, Blade through `__()`, sentence case, no em or en dashes in our copy, `Public/build` committed and deterministic, no `<style>` blocks, Bootstrap 3 markup and FreeScout's palette, a label above every control and errors under it.

**Process:**
- Conventional commits with a single-line message and no body or trailer. Never push `v3`.
- PHP tests: `cd ~/Projects/freescout && ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`.
- Frontend: `npm run check` and `npm run build` in `Resources/frontend`.
- CalDAV integration tests need Radicale (Task 4 installs it) and skip with a message saying how to start it. Every full-suite count in this plan is with Radicale running.
- UI work is verified in a real browser (Playwright, browser in Asia/Tokyo).

**Refined while drafting** (details under "Deviations"):
- The host check runs on `scheme://host/` only, because core's `checkUrlHost()` refuses every URL with a port or a username.
- Writes go through Eloquent, so `updated_at` protects them from a running listing's sweep.
- `ConnectionService::accessToken()` is never called inside a database transaction.
- Anything that changes `calendars.timezone` calls `OccurrenceService::refreshFloating()`; rules are compared and shown in `EventIcs::ruleZone()`.
- A calendar's zone is stored only when `VTimezone::isNamedZone()` accepts it.
- Flash messages that carry a name or a provider's text are escaped with `e()` (core prints floating flashes through `safe_raw_html()`).

---

## Decisions

The spec's decision log (section 25 of `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`) is authoritative; this list says when and by whom each W3 decision was taken.

**Lars, 2026-10-07 (refinement before planning):**
- One W3 plan, not split.
- Google and Microsoft are built against their documentation with hand-made fixtures, without test accounts; Lars tests both by hand from the checklist Task 15 writes.
- CalDAV integration tests (later also the e2e suite) run against Radicale, a ddev service for local development only, never shipped.
- Health: one email per problem to every administrator through core's system alert (`\MailHelper::sendAlertMail()`), not repeated while it persists, plus a banner in the settings and on the calendar page; agents only see that a calendar is not up to date. No notification bell (core's bell skips notifications without a `thread_id`).
- Settings: tabs "Calendars" and "Connections"; "New calendar" asks the type first (FreeScout calendar, ICS feed, from a connected account); the Connections tab holds the Google app, the Microsoft app, CalDAV connect and the accounts with their actions.
- Deleting a connection deletes its calendars and their events in FreeScout after a confirmation that names them; nothing is deleted at the provider.
- A move to a calendar of another provider or account creates in the target first and deletes from the source second; if the delete fails, the agent is told the event is now in both calendars.
- A whole-series copy across zones is created in the series' own zone; the form keeps showing the agent's times.

**Lars, 2026-10-09 (during drafting):**
- A same-account CalDAV move on a server that allows a UID once per account (Cyrus as at Fastmail, maybe iCloud) gives the event a new UID; accepted and documented.
- On a provider conflict the server syncs that calendar right away with a short budget before it answers 409, so "Reload and try again" is true at once; when a run holds the lease, `requestSync()` instead.
- Google: every event type is imported, all but `default` read-only; no `eventTypes` filter.
- No readable block on Google and Microsoft events that have attendees at the provider (those providers share or mail the organizer's description); events FreeScout creates keep it; CalDAV is unaffected.
- A monthly rule on day 29, 30 or 31 is refused on Microsoft calendars with a clear message; Outlook's own "last day" rules are still read and written.

**Lars, 2026-10-15 (finishing W3):**
- Fast-forward `v3` onto the draft commits, commit this compact plan, then one whole-branch review (`af41f8c..HEAD`, opus), one fix round and a browser check. No re-transcription.
- The health decision-log row is confirmed as built (status `error` counts as reauthorization; an account's problem covers its calendars; agents' `sync_failed` includes a broken account; a never-synced failing calendar shows to agents at once and to administrators after a day); "to confirm" is removed.
- A CalDAV 401 (password refused) marks the connection `needs_reauth` right away, like Google and Microsoft.
- A solved health problem is forgotten only after 24 hours without recurrence, so a flapping problem is not mailed again; the banner still shows it while present.
- Core's `checkUrlHost()` over-blocking is not reported upstream.
- The three behaviour changes above and the controller's health lock (below) are built in the fix round after `3a559be`, which also updates the decision log.

**Controller decisions:**
- 2026-10-07 (research): the Microsoft tenant field is required and takes a tenant id or domain (`organizations` only works for multi-tenant apps).
- 2026-10-07: Google scopes `calendar.calendarlist.readonly` plus `calendar.events`; the wiki guides Workspace admins to an Internal app (Testing-mode tokens expire after 7 days).
- 2026-10-07: Graph is listed per calendar by event id and changeKey plus `/instances` over the window (per-calendar delta is beta only).
- 2026-10-07: ICS feeds never go through `getRemoteFileContents()`; an empty first result never deletes local data (Zoho's empty first sync).
- 2026-10-07: `assertAllowed()` checks `scheme://host/`, because core's `checkUrlHost()` wraps `host:port` and `user@host` in IPv6 brackets and refuses them (verified on core 1.8.245).
- 2026-10-07: the Radicale whitelist needs the container's address range as well as its name (core checks the resolved address).
- 2026-10-09: a series whose rule the provider can't write is locked at series level (`canWriteSeries()`, 403 `series_not_writable`); its occurrences stay editable (reconciles Task 2 with spec section 7).
- 2026-10-09: every write goes through Eloquent, so the sweep's `updated_at` guard protects it.
- 2026-10-09: migration 000007 checks the unique index separately from its column.
- 2026-10-09: the W1 item "2 CalDAV line items without `event_uid`" is accepted; they show their stored text.
- 2026-10-09: a copy of a floating series is written in its calendar's zone; a later zone change moves the original, not the copy.
- 2026-10-09: a picked calendar with `RemoteCalendar::$writable` false gets `calendars.remote_read_only` (migration 000008); it is not writable and the matrix offers only Dashboard and View.
- 2026-10-09: Graph's offset paging (review C1) is solved by an engine hook: a provider that can confirm deletions checks the sweep candidates first.
- 2026-10-09: a room- or resource-only attendee also removes the readable block (the safe direction); kept on 2026-10-15, on the checklist (G13, M21).
- 2026-10-09 hand-offs: Task 13 fills an app form from `old()` only when `old('app')` names it, uses the error bags `google` and `microsoft`, and checks a discovered CalDAV zone with `isNamedZone()`; Tasks 9 and 11 call `refreshFloating()` when they change `calendars.timezone`, never call `accessToken()` in a transaction, and compare rules in `ruleZone()`.
- 2026-10-15: `HealthService::check()` takes a lock, so two concurrent `calendar:sync` runs never mail a new problem twice (fix round).
- Process note: Task 7's implementation and `WindowsZones` (Task 10) were written before their failing run, which was then recorded with the implementation set aside; every other implementation followed its failing run.

---

## Deviations from the spec text

### Decided while planning (2026-10-07)

- **CalDAV uses Guzzle, not sabre's HTTP client**: one HTTP stack with the same proxy, timeout, TLS and SSRF handling and one test mock for every provider; sabre/xml still handles the XML.
- **Microsoft tenant:** the field takes a tenant id or domain and is required; `organizations` and `common` are accepted for multi-tenant apps.
- **The Microsoft secret expiry date is an app `Option`**, shared by all Microsoft connections; `calendar_connections.secret_expires_at` stays unused.
- **Pulled events are matched by an indexed `remote_id_hash`** (`remote_id` is `text`).
- **Full listings are swept by generation**, across runs; an empty listing deletes only after two empty listings in a row.
- **Health checks run inside `calendar:sync`**, at most every 15 minutes.
- **Connections from the v2 migration stay** when their last calendar is deleted; the admin deletes them on the Connections tab.

### Made while drafting (changed the design)

**HTTP and engine:**
- The host check is a handler middleware that checks the first request and every redirect hop, on `scheme://host/` only; Guzzle 8's string timeouts are cast to float, and Guzzle 8 does digest itself.
- The sweep runs only on a completing full-listing round and spares events whose `updated_at` falls after the listing started (`_listing_started`, plus `_seen` for the empty-listing rule).
- Leases are owned and renewed (before each round, every 100 upserts); a run that lost its lease records nothing (`SyncLeaseLost`).
- `calendar:sync` runs `withoutOverlapping(5)->runInBackground()`; an unfinished pull is due again at once; `last_synced_at` moves only when a pull completes.
- An event that can't be read or stored keeps its stored copy and is stamped, so it is never swept.
- `ConfirmsDeletions` and `ExplainsRecurrenceRefusal`, optional provider interfaces; `OAuthApi`, one request loop for Google and Microsoft.

**Writes:**
- The series lock through `Calendar::canWriteSeries()` replaces the importer's read-only flag for unwritable rules; a rule the provider can't write is refused with 422 on create, series edit and move.
- A conflict runs `SyncService::syncNow()` (10-second budget) before the 409.
- A moved event starts at generation 0; a failed source delete triggers `SyncService::relist()`; warnings reach the agent through `EventService::lastWarning()`.
- `remote_read_only` makes a calendar the account may only read unwritable; the importer stops storing the calendar's writability on events.

**Time zones:**
- `PhpTimezoneFinder` makes sabre read every zone name PHP knows; `VTimezone::resolve()` resolves each TZID against the event's own VCALENDAR for the export and the CalDAV PUT.
- The presenter's `timezone` is the rule zone for every timed event (a floating one gets its calendar's zone), validated with `isNamedZone()`; all-day series read their rule in the calendar's zone.

**CalDAV:** the first listing is PROPFIND plus `calendar-multiget` with the token from `DAV:sync-token`, never an empty-token sync-collection; sync-collection uses `Depth: 0`; `no-uid-conflict` retries once under a new UID; answers are capped at 50 MB.

**Google:** series writes GET then PUT the whole event with `If-Match`; occurrence writes check `sameOccurrence()`; a changed stored series is read again whole with `events.instances`; `ConnectionService::renewAccessToken()` handles the retry after a 401.

**Microsoft:** Graph gets Windows zone names from a bundled CLDR map; updates send only changed fields and mark the changeKey `reread:`; deletions are confirmed with `$filter=iCalUId` (Deleted Items keeps the immutable id); a weekly pass re-reads every series.

**Settings and health:**
- `Calendar::SYNC_INTERVALS` keeps W1's full list (1 to 1440 minutes, 9 values), shared with `MigrationService`.
- Addresses with a username or password are refused; a feed address is stored as typed (`webcal://` kept).
- Health: status `error` counts as reauthorization, an account's problem covers its calendars, nothing counts before the v2 upgrade finished, `checkIfDue()` holds the throttle.
- A write to a calendar whose account must sign in again answers 502 `connection_needs_reauth` to whoever may write there.
- Radicale is reachable from the host (port 5232) with a rights file that opens one collection for the e2e ICS feed.

---

## File structure

From `git diff --stat af41f8c 3a559be -- . ':!Public/build'`: 197 files, 128 new and 69 changed. `Public/build/*` is rebuilt by every task that changes the frontend.

**Provider contract and HTTP (`CalendarProviders/`, all new):**
- `CalendarProvider.php`: the interface every provider implements.
- `ChangeSet.php`: the result of one pull round.
- `RemoteEvent.php`: one pulled event (a VCALENDAR for one UID, or a partial merge).
- `RemoteRef.php`: what a write returns (remote id, href, etag, provider UID).
- `RemoteCalendar.php`: one calendar of a connected account.
- `SyncBudget.php`: the time budget of a run.
- `ProviderRegistry.php`: provider name to instance, lazily resolved, swappable in tests.
- `HttpClientFactory.php`: Guzzle clients, the host check, log scrubbing.
- `LocalProvider.php`: FreeScout calendars; writes are no-ops.
- `ConfirmsDeletions.php`, `ExplainsRecurrenceRefusal.php`: the optional provider interfaces.
- `OAuthApi.php`: the shared Google and Microsoft request loop.
- `HtmlText.php`: HTML descriptions to plain text.
- `Exceptions/ProviderException.php`, `ProviderConflict.php`, `ProviderUnavailable.php`, `ConnectionNeedsReauth.php`: the provider errors and their answers.

**Providers (all new):**
- `Ics/IcsProvider.php`: reads, hashes and splits ICS feeds.
- `CalDav/CalDavClient.php`: WebDAV requests, multistatus parsing, auth and the size cap.
- `CalDav/CalDavDiscovery.php`: well-known, principal, calendar home, collections.
- `CalDav/CalDavProvider.php`: listings, sync-collection, ctag fallback, writes.
- `CalDav/CalDavRequestFailed.php`, `CalDav/CalDavAnswerTooLarge.php`: a refused request with its preconditions; an answer over 50 MB.
- `Google/GoogleApi.php`: Google's address, rate-limit test and messages on `OAuthApi`.
- `Google/GoogleEventMapper.php`: Google event JSON to VEVENT and back.
- `Google/GoogleProvider.php`: calendar list, sync tokens, series re-reads, checked writes.
- `Microsoft/GraphApi.php`: Graph's address, `Prefer` headers, throttling and messages on `OAuthApi`.
- `Microsoft/WindowsZones.php`: Windows zone names to IANA and back.
- `Microsoft/GraphRecurrence.php`: `patternedRecurrence` to RRULE and back.
- `Microsoft/GraphEventMapper.php`: Graph event JSON to VEVENT and back, deleted occurrences, changed fields.
- `Microsoft/MicrosoftProvider.php`: calendars and shared mailboxes, changeKey listings, instance writes, deletion checks.

**Services (`Services/`):**
- New: `EventImporter.php` (pulled VCALENDAR to stored event), `LegacyDescription.php` (v2 YAML in CalDAV descriptions), `SyncService.php` (the engine), `SyncLeaseLost.php`, `ConnectionService.php` (OAuth and app credentials), `HealthService.php` (problems, mails, banners), `CalendarPicker.php` (pick screen listing and adding), `PhpTimezoneFinder.php` (sabre finder for PHP's zone names), `SeriesNotWritable.php` (the 403 of a locked series).
- Changed: `EventService.php` (provider writes, moves, warnings, series lock, series-zone copies, sync after a conflict), `OccurrenceService.php` (`refreshFloating()`), `EventIcs.php` (`ruleZone()`, `exdateKeys()`, the unknown-TZID warning), `VTimezone.php` (resolution and named-zone checks), `RecurrenceRule.php` (local UNTIL, `canonical()`), `ReadableBlock.php` (`addTo()`), `ExportService.php` (block per VEVENT, public `reduceToBusy()`, per-event TZIDs), `FieldValues.php` (`valid()`), `MigrationService.php` (logins out of addresses, shared intervals).

**Console, config, provider, entities, migrations:**
- `Console/SyncCalendars.php` (new): `calendar:sync` and the health check after it.
- `Config/config.php`: the `oauth` block. `Providers/LJPcCalendarModuleServiceProvider.php`: two singletons, the timezone finder, the command and its schedule.
- `Entities/Calendar.php`: intervals, writability, series check. `Entities/CalendarConnection.php`: statuses, `calendars()`. `Entities/CalendarEvent.php`: remote id hash and lookups.
- `Database/Migrations/2026_10_07_000007_ljpc_calendar_v3_sync_columns.php` (new): `sync_failures`, `remote_id_hash` and its unique index, `sync_generation`, backfill.
- `Database/Migrations/2026_10_07_000008_ljpc_calendar_v3_remote_read_only.php` (new): `calendars.remote_read_only`.

**HTTP layer:**
- `Http/routes.php`: sync now, apps, CalDAV connect, add calendars, delete connection, OAuth.
- `Http/Controllers/OAuthController.php` (new): OAuth start and callback.
- `Http/Controllers/Settings/ConnectionsController.php` (new): app credentials, CalDAV connect and reauthorize, add calendars, delete.
- `Http/Controllers/Settings/CalendarsController.php`: ICS create, sync side effects, "Sync now", floating refresh.
- `Http/Controllers/Api/EventsController.php`, `ConversationEventsController.php`: provider errors, `warning`, `timezone`, series lock, the reauthorization 502.
- `Http/EventPresenter.php`: `timezone`, `series_writable`, `last_synced_at`, `sync_failed`. `Http/FrontendConfig.php`: `health`. `Http/FrontendStrings.php`: new strings.
- `Http/CalendarForm.php`: feed and sync fields, `hasLogin()`, `timezones()`, read-only rights. `Http/SettingsPage.php`: tabs, types, connections, pick screen, health, helpers.
- `Policies/CalendarPolicy.php`: imported return type.

**Views (`Resources/views/`):** `settings.blade.php` (banner, tabs, modes); new `settings/tabs`, `sync`, `connections`, `apps`, `pick` and `health` partials; changed `settings/new` (type chooser), `list` (sync status), `edit` (sync section), `permissions` (read-only rights).

**Frontend (`Resources/frontend/`):**
- `src/shared/`: `api.ts` (`asksToDropExceptions()`), `alerts.ts` (warning), `types.ts`, `form-model.ts` (rule zone), `time.ts` (`dateIn()`), `recurrence.ts` (`followStart()`).
- `src/calendar/`: `App.vue`, `EventDetails.vue`, `EventForm.vue`, `ScopeDialog.vue`, `events.ts`, `page-config.ts`, `calendar.css`, and the new `banners.ts`.
- `src/settings/`: new `type-chooser.ts`; `main.ts`, `settings.css`.
- `tests/`: new `banners.test.ts`; `shared`, `calendar-events`, `form-model`, `time` and `settings` tests extended.
- `e2e/`: new `caldav.spec.ts`, `ics.spec.ts`, `health.spec.ts`, `global-setup.ts`; `helpers.ts`, `calendar.spec.ts`, `recurring.spec.ts`, `settings.spec.ts`, `playwright.config.ts` changed.

**Test support and tests (`Tests/`):**
- New support: `Support/FakeHttp.php` (queued responses, request history), `FakeProvider.php` (scripted pulls and writes, `$listError`), `LogRecorder.php` (log records), `DavResponses.php` (CalDAV answers). `bootstrap.php`: dead `require` removed.
- Unit and feature tests per task (named in each task's Files); `Integration/CalDavServerTest.php` against Radicale.
- Fixtures: `fixtures/ics/` (4 feeds), `fixtures/caldav/` (8 XML answers), `fixtures/google/` (8 events), `fixtures/microsoft/` (7 answers).

**Development tooling (never shipped):** `Tests/caldav/docker-compose.radicale.yaml`, `config`, `users`, `rights`, `install.sh` (Radicale in ddev); `Tests/e2e/seed.sh` (the e2e seed); `.gitignore` (the seed marker).

**Docs:** `CLAUDE.md`; the spec (sections 4, 8, 9, 24 and 31 decision-log rows); `docs/w3-manual-test.md`; `docs/wiki/Google-Calendar.md`, `Microsoft-365.md`, `CalDAV.md`.

---

## Shared interfaces (as in the code at `3a559be`)

Every signature below was checked against the code at `3a559be`.

### Provider contract

```php
namespace Modules\LJPcCalendarModule\CalendarProviders;

interface CalendarProvider
{
    public function canWrite(): bool;
    public function writableRecurrence(VEvent $master): bool;
    /** @return RemoteCalendar[]; $owner is a Microsoft shared mailbox, null for the account itself */
    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array;
    /** $state is calendars.sync_state without the engine's keys (those start with "_") */
    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet;
    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef;
    /** $occurrenceKey is set for an occurrence-scope write (override added or changed, or an EXDATE added) */
    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef;
    /** 404 or 410 counts as success */
    public function delete(Calendar $calendar, CalendarEvent $event): void;
}
interface ConfirmsDeletions { public function confirmGone(Calendar $calendar, array $remoteIds, SyncBudget $budget): array; }  // the ids that are gone
interface ExplainsRecurrenceRefusal { public function recurrenceRefusal(VEvent $master): ?string; }

// Final value objects with public properties of the same names
final class ChangeSet      { public function __construct(array $upserts, array $deletes, array $state, bool $complete, bool $fullListing, array $unchanged = []); }
final class RemoteEvent    { public function __construct(string $remoteId, string $ics, ?string $href = null, ?string $etag = null, bool $readOnly = false, bool $partial = false, array $cancelledKeys = []); }
final class RemoteRef      { public function __construct(string $remoteId, ?string $href = null, ?string $etag = null, ?string $uid = null); }
final class RemoteCalendar { public function __construct(string $remoteId, string $name, ?string $color, ?string $timezone, bool $writable, bool $primary = false); }
final class SyncBudget     { public static function seconds(float $seconds): self; public function exhausted(): bool; public function remaining(): float; }

class ProviderRegistry   // singleton; PROVIDERS maps local, ics, caldav, google, microsoft to classes
{
    public function for(Calendar $calendar): CalendarProvider;
    public function forProvider(string $provider): CalendarProvider;   // InvalidArgumentException when unknown
    public function swap(string $provider, CalendarProvider $instance): void;
}
class HttpClientFactory  // singleton; MAX_REDIRECTS = 5, LOG_BODY_LENGTH = 500
{
    public function client(array $options = [], bool $checkHosts = false): \GuzzleHttp\Client;   // http_errors always false
    public function assertAllowed(string $url): void;
    public static function scrub(string $body): string;
    public static function logContext(array $context): array;
    public static function logFailure(string $message, array $context): void;
    protected function handlerStack(): \GuzzleHttp\HandlerStack;
}
abstract class ProviderException extends \RuntimeException
{
    public function __construct(string $userMessage, ?int $httpStatus = null, ?\Throwable $previous = null);
    abstract public function status(): int;    // 409 or 502
    abstract public function code(): string;   // changed_elsewhere | provider_unavailable | connection_needs_reauth
    public function userMessage(): string;
    public function httpStatus(): ?int;
    public function toResponse(): \Illuminate\Http\JsonResponse;
}
// ProviderConflict(?int $httpStatus = null, ?\Throwable $previous = null): 409 changed_elsewhere
// ProviderUnavailable(?string $message = null, ?int $httpStatus = null, ?\Throwable $previous = null): 502 provider_unavailable
// ConnectionNeedsReauth(?int $httpStatus = null, ?\Throwable $previous = null): 502 connection_needs_reauth

abstract class OAuthApi  // MAX_RETRIES = 3, MAX_WAIT_SECONDS = 15, INTERACTIVE_MAX_WAIT_SECONDS = 4 (request option max_wait)
{
    public function __construct(HttpClientFactory $http, ConnectionService $connections, ?callable $sleep = null);
    /** @return array [status, decoded JSON] */
    public function request(CalendarConnection $connection, string $method, string $path, array $options = [], array $accept = [], array $logContext = []): array;
    // hooks: provider(), failureLog(), url(), isRateLimit(), failure(), isLimited(), headers()
}
final class HtmlText { public static function toPlain(string $html): string; }
```

### Providers

```php
class Ics\IcsProvider            // __construct(HttpClientFactory $http); MAX_BYTES = 20 MB
{
    public static function feedUrl(string $address): string;    // webcal(s):// to https://
    public static function contentHash(string $body): string;   // sha1 without DTSTAMP lines
}
class CalDav\CalDavClient        // NS_DAV, NS_CALDAV, NS_CALSERVER, NS_APPLE; MAX_BYTES = 50 MB
{
    public function __construct(HttpClientFactory $http, string $username, string $password, array $logContext = []);
    public function propfind(string $url, int $depth, array $props): array;   // [href => ['status' => int, 'props' => [Clark name => value]]]
    public function report(string $url, string $xml, int $depth = 1): array;  // ['responses' => ..., 'sync_token' => ?string]
    public function get(string $url): ?array;                                 // ['ics', 'etag'], null when gone
    public function put(string $url, string $ics, ?string $etag, bool $create): ?string;
    public function delete(string $url, ?string $etag): void;
    public function exists(string $url): bool;
    // statics: propfindBody(), syncCollectionBody(), multigetBody(), text(), hrefs(), names(), components(), absolute(), sameResource()
}
class CalDav\CalDavDiscovery     { public function discover(CalDavClient $client, string $serverUrl): array; }   // RemoteCalendar[]
class CalDav\CalDavProvider      // __construct(HttpClientFactory $http, CalDavDiscovery $discovery); MULTIGET_BATCH = 50
class CalDav\CalDavRequestFailed extends ProviderUnavailable { public function __construct(?string $message, int $httpStatus, array $preconditions = []); public function preconditions(): array; }
class CalDav\CalDavAnswerTooLarge extends ProviderUnavailable { public function __construct(); }

class Google\GoogleApi extends OAuthApi   // BASE_URL = 'https://www.googleapis.com/calendar/v3'
final class Google\GoogleEventMapper
{
    public static function toVCalendar(array $master, array $exceptions, string $calendarTimezone): string;
    public static function overridesToVCalendar(array $exceptions, string $uid, string $calendarTimezone): string;
    public static function exceptionKey(array $instance, string $timezone): string;
    public static function fromVEvent(VEvent $vevent, bool $isMaster, string $floatingTimezone = 'UTC'): array;
    public static function isException(array $instance, array $master, string $calendarTimezone): bool;
    public static function sameOccurrence(array $instance, VCalendar $stored, string $uid, string $key, string $calendarTimezone): bool;
    public static function instanceId(string $masterId, string $occurrenceKey): string;
}
class Google\GoogleProvider      // __construct(GoogleApi $api, OccurrenceService $occurrences)
// PAGE_SIZE = 250, CALENDAR_PAGE_SIZE = 250, INSTANCE_PAGE_SIZE = 2500, MAX_INSTANCE_PAGES = 4, WRITE_ROLES = ['owner', 'writer', 'writerWithoutPrivateAccess']

class Microsoft\GraphApi extends OAuthApi   // BASE_URL = 'https://graph.microsoft.com/v1.0'
// PREFER = ['IdType="ImmutableId"', 'outlook.timezone="UTC"', 'outlook.body-content-type="text"']
class Microsoft\WindowsZones     // TO_IANA (139 names), TO_WINDOWS (596 names); protected intlIana(), intlWindows() as test seams
{
    public static function toIana(string $windowsName): ?string;
    public static function fromIana(string $iana): string;
}
final class Microsoft\GraphRecurrence
{
    public static function toRrule(array $recurrence, string $timezone, bool $allDay = false): ?string;
    public static function fromRrule(string $rrule, DateTimeImmutable $start, bool $allDay): ?array;
    public static function onLateMonthDay(string $rrule, DateTimeImmutable $start): bool;
}
final class Microsoft\GraphEventMapper
{
    public static function toVCalendar(array $master, array $instances, ?array $window, string $calendarTimezone): string;
    public static function fromVEvent(VEvent $vevent, bool $isMaster, string $timezone): array;
    public static function canWriteRule(VEvent $master): bool;
    public static function changedFields(array $current, array $changes, string $calendarTimezone): array;
    public static function occurrenceKey(array $instance, bool $allDay, string $zone): string;
    public static function missingKeys(VEvent $master, array $presentKeys, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): array;
    public static function zoneOf(array $event, string $calendarTimezone): string;
}
class Microsoft\MicrosoftProvider implements CalendarProvider, ConfirmsDeletions, ExplainsRecurrenceRefusal
// __construct(GraphApi $api, OccurrenceService $occurrences); PAGE_SIZE = 100, CALENDAR_PAGE_SIZE = 100,
// INSTANCE_PAGE_SIZE = 100, MAX_INSTANCE_PAGES = 50, REREAD_DAYS = 7, REREAD = 'reread:', MAX_SKIPPED = 500, MIN_CONFIRMATIONS = 5
```

`canWrite()` is false for ICS and true for the others; `writableRecurrence()` is false for ICS, true for CalDAV and Google, and `GraphEventMapper::canWriteRule()` for Microsoft.

### Services

```php
namespace Modules\LJPcCalendarModule\Services;

class EventImporter
{
    public function __construct(OccurrenceService $occurrences);
    public function upsert(Calendar $calendar, RemoteEvent $remote, int $generation): ?CalendarEvent;   // null: unreadable, stored copy kept
}
final class LegacyDescription { public static function parse(string $description, Calendar $calendar): ?array; }   // body, field_values, conversation_id, author_id
class SyncService   // LEASE_SECONDS = 120, RUN_SECONDS = 40, MAX_BACKOFF_INTERVALS = 6, LEASE_RENEW_UPSERTS = 100
{
    public function __construct(ProviderRegistry $providers, EventImporter $importer);
    public function runDue(SyncBudget $budget, ?int $calendarId = null, bool $force = false): array;   // synced, failed, skipped
    public function syncCalendar(Calendar $calendar, SyncBudget $budget): void;
    public function syncNow(Calendar $calendar, SyncBudget $budget): bool;   // false when a run holds the lease or it doesn't sync
    public function requestSync(Calendar $calendar): void;
    public function relist(Calendar $calendar): void;
    public static function backoffMinutes(int $interval, int $failures): int;
}
final class SyncLeaseLost extends \RuntimeException {}
class ConnectionService   // PROVIDERS = ['google', 'microsoft'], SESSION_KEY = 'ljpccalendarmodule.oauth', STATE_SECONDS = 600,
                          // REFRESH_SECONDS = 300, TIMEOUT_SECONDS = 15, GOOGLE_ACCOUNT_URL, MICROSOFT_ACCOUNT_URL
{
    public function __construct(HttpClientFactory $http);
    public function redirectUrl(string $provider): string;
    public function authorizationUrl(string $provider, ?CalendarConnection $reauthorize = null): string;
    public function completeAuthorization(string $provider, Request $request): CalendarConnection;
    public function accessToken(CalendarConnection $connection): string;   // never inside a transaction
    public function renewAccessToken(CalendarConnection $connection, string $rejected): string;
    public function appCredentials(string $provider): array;   // client_id, has_secret; Microsoft also tenant, secret_expires_at
    public function saveAppCredentials(string $provider, array $input): void;
}
class HealthService   // CHECK_MINUTES = 15, OPTION = 'ljpccalendarmodule.health', SECRET_DAYS = 30, FAILING_HOURS = 24
{
    public function __construct(MigrationService $migration, ConnectionService $connections);
    public function checkIfDue(): bool;
    public function check(): void;
    public function problems(): array;   // [['key' => 'connection:<id>:reauth' | 'microsoft:secret' | 'calendar:<id>:failing', 'text', 'url']]
}
class CalendarPicker
{
    public function __construct(ProviderRegistry $providers);
    public function list(CalendarConnection $connection, ?string $owner = null): array;   // [['remote' => RemoteCalendar, 'calendar' => ?Calendar]]
    public function add(CalendarConnection $connection, ?string $owner, array $remoteIds): array;   // Calendar[]
}
final class PhpTimezoneFinder implements \Sabre\VObject\TimezoneGuesser\TimezoneFinder { public function find(string $tzid, bool $failIfUncertain = false): ?DateTimeZone; }
class SeriesNotWritable extends \RuntimeException { public function __construct(); }

// Changed W1 and W2 services
class EventService   // CONFLICT_SYNC_SECONDS = 10
{
    public function __construct(OccurrenceService $occurrences, ProviderRegistry $providers, SyncService $sync);
    public function lastWarning(): ?string;
}
class OccurrenceService { public function refreshFloating(Calendar $calendar): int; }
class EventIcs
{
    public static function ruleZone(VEvent $master, string $calendarTimezone): DateTimeZone;
    public static function exdateKeys(VEvent $master, DateTimeZone $tz): array;
}
class VTimezone   // TZID_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE']
{
    public static function addForEvents(VCalendar $vcal): void;
    public static function addTo(VCalendar $vcal, string $tzid, int $fromYear, int $toYear, ?DateTimeZone $zone = null): void;
    public static function resolve(string $tzid, ?VCalendar $vcal = null): ?DateTimeZone;
    public static function isKnown(string $tzid, ?VCalendar $vcal = null): bool;
    public static function isNamedZone(string $tzid): bool;
    public static function warnUnknown(string $tzid, array $context = []): void;
}
class RecurrenceRule
{
    public static function toRrule(array $input, bool $allDay, string $timezone, bool $floating = false): string;
    public static function canonical(string $rrule, bool $allDay, string $timezone): ?string;
}
// ReadableBlock::addTo(VEvent $vevent, CalendarEvent $event): void; ExportService::reduceToBusy(VEvent $vevent): void (static);
// FieldValues::valid(Calendar $calendar, array $input): array
```

### Entities, HTTP and data

```php
class Entities\Calendar
{
    public const SYNC_INTERVALS = ['1 minute' => 1, '5 minutes' => 5, '15 minutes' => 15, '30 minutes' => 30, '1 hour' => 60,
        '2 hours' => 120, '6 hours' => 360, '12 hours' => 720, 'daily' => 1440];
    public function usesConnection(): bool;   // caldav, google, microsoft
    public function isReadOnly(): bool;       // remote_read_only, or a provider that can't write
    public function isWritable(): bool;       // not remote_read_only, connection ok when used, provider can write
    public function canWriteSeries(VEvent $master): bool;
}
// CalendarConnection: STATUS_OK = 'ok', STATUS_NEEDS_REAUTH = 'needs_reauth', STATUS_ERROR = 'error' (default ok); calendars()
// CalendarEvent: setRemoteIdAttribute(?string): void (sets remote_id_hash, '' stored as NULL);
//   static findByRemoteId(int $calendarId, string $remoteId): ?self; static byRemoteIds(int $calendarId, array $remoteIds): array
// EventsController: static withWarning(array $event, EventService $events): array; static calendarFor(User $user, $id, string $ability): Calendar
// CalendarForm: WRITE_RIGHTS = ['can_create', 'can_edit']; static timezones(Calendar $calendar): array; static hasLogin(string $url): bool
// SettingsPage: WIKI_URL, SWATCHES; static connectionsUrl(?int $connectionId = null, ?string $owner = null), wikiUrl(string $page),
//   accountUrl(CalendarConnection), reauthorizeUrl(CalendarConnection), newTypes(), postedProvider(), syncIntervals(), time(?string $utc),
//   connectionStatus(string), host(?string), isColor($color), rightLabels(bool $readOnly = false), deleteQuestion(CalendarConnection)
```

**Columns:** `calendars.sync_failures` (unsigned integer, default 0), `calendars.remote_read_only` (boolean, default false), `calendar_events.remote_id_hash` (char 40, nullable, unique with `calendar_id` as `ljpc_cal_events_remote_unique`), `calendar_events.sync_generation` (unsigned integer, default 0).

**`calendars.sync_state`:** the engine's keys `_generation`, `_listing`, `_seen`, `_listing_started`, `_empty_listings` (providers never see them). Provider keys: ICS `etag`, `last_modified`, `hash`; CalDAV `sync_token`, `supports_sync`, `ctag`, `pending`, `pending_deletes`, `retry`, `more`, and `listing` (`after`, `sync_token`, `ctag`, `supports_sync`, `unreadable`) during a listing; Google `sync_token`, plus `page_token` and `listed` during a listing; Microsoft `next_link` and `rereading` during a listing, `reread_from` and `skipped` between listings.

**Credentials** (`calendar_connections.credentials`, encrypted JSON): OAuth `{ "refresh_token", "access_token", "expires_at" (unix seconds), "scopes" }`; CalDAV `{ "username", "password" }`. Accounts are stored lowercased.

**Options (`\Option`, read without core's option cache):** `ljpccalendarmodule.google_client_id`, `.google_client_secret` (encrypted), `.microsoft_client_id`, `.microsoft_client_secret` (encrypted), `.microsoft_tenant`, `.microsoft_secret_expires_at` (`Y-m-d`), `.health` (`{ "checked_at", "notified": { key: time } }`).

**Config (`config('ljpccalendarmodule.oauth...')`, `.env` overrides for a later relay):** `google.authorize_url` (`CALENDAR_GOOGLE_AUTHORIZE_URL`), `google.token_url` (`CALENDAR_GOOGLE_TOKEN_URL`), `google.scopes`; `microsoft.authority` (`CALENDAR_MICROSOFT_AUTHORITY`; URLs `{authority}/{tenant}/oauth2/v2.0/authorize|token`), `microsoft.scopes` (`offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared`).

**Routes** (module web group, admin only):
- POST `/calendar/settings/calendars/{id}/sync` `ljpccalendarmodule.settings.calendars.sync` (`CalendarsController::sync`).
- POST `/calendar/settings/apps/{provider}` `ljpccalendarmodule.settings.apps` (`google|microsoft`, `ConnectionsController::saveApp`).
- POST `/calendar/settings/connections/caldav` `ljpccalendarmodule.settings.connections.caldav` (`ConnectionsController::caldav`; with `connection` it reauthorizes).
- POST `/calendar/settings/connections/{id}/calendars` `ljpccalendarmodule.settings.connections.calendars` (`addCalendars`).
- POST `/calendar/settings/connections/{id}/delete` `ljpccalendarmodule.settings.connections.delete` (`destroy`).
- GET `/calendar/oauth/{provider}/start` `ljpccalendarmodule.oauth.start` (`?connection=<id>` reauthorizes) and `/calendar/oauth/{provider}/callback` `ljpccalendarmodule.oauth.callback`.
- Settings URLs: `?tab=connections`, `?calendar=new&type=local|ics|account`, `?tab=connections&connection=<id>&pick=1[&owner=<address>]`.

**Agent API answers added in W3:** 409 `changed_elsewhere`, 502 `provider_unavailable` and 502 `connection_needs_reauth` on store, update, destroy and the conversation store; 403 `series_not_writable` ("This series can only be changed one occurrence at a time."); 422 on `recurrence` ("This calendar can't store this repeat rule.", or the provider's reason) and on `calendar_id` for a move ("That calendar can't store the repeat rule of this series.", or the reason); `warning` on store and update; events carry `series_writable` and `timezone`; calendars carry `sync_failed` and `last_synced_at` (ISO 8601 UTC or null), never `last_error`; the page config carries `health` (administrators only).

**Frontend exports:** `asksToDropExceptions(caught)` (`shared/api.ts`); `notify('success' | 'error' | 'warning', message)` (`shared/alerts.ts`); `formFromSeries(master, timeZone, fields)`, `ruleStartDate(state, timeZone)` (`shared/form-model.ts`); `dateIn(date, time, fromZone, toZone)` (`shared/time.ts`); `followStart(rule, fromDate, toDate)` (`shared/recurrence.ts`); `seriesDragPatch(master, startDelta, endDelta, timeZone)` (`calendar/events.ts`); `HealthProblem`, `STALE_HOURS = 24`, `PageBanner`, `staleCalendars()`, `pageBanner()` (`calendar/banners.ts`); `initTypeChooser(root)` (`settings/type-chooser.ts`).

---

## Tasks

| # | Task | Final commit(s) | Depends on |
|---|---|---|---|
| 1 | Provider contract, HTTP factory, registry, schema columns, test support | `ff87448` | |
| 2 | Event importer and sync engine, `calendar:sync` | `9353230` | 1 |
| 3 | ICS provider | `6b0de95` | 1, 2 |
| 4 | CalDAV client, discovery and provider, Radicale, migrated URL credentials | `74ffe71` | 1, 2 |
| 5 | Provider writes in `EventService`, provider errors in the API, cross-calendar moves | `e7620f5` | 1, 4 |
| 6 | Time-zone correctness for foreign events and series-zone copies | `a088fcc` | 5 |
| 7 | OAuth: `ConnectionService`, app credentials, start and callback | `e1d7cbe` | 1 |
| 8 | Google event mapper | `2169f01` | 1 |
| 9 | Google provider | `9b37f36` | 2, 5, 7, 8 |
| 10 | Microsoft time zones, recurrence and event mapper | `7b7af5f` | 1 |
| 11 | Microsoft provider, shared mailboxes | `54b6575` | 2, 5, 7, 10 |
| 12 | Settings: tabs, new-calendar types, ICS form, sync section | `1638261` | 2, 3 |
| 13 | Settings: Connections tab, CalDAV connect, OAuth connect, pick calendars, delete | `0f899aa` | 4, 7, 9, 11, 12 |
| 14 | Connection health: alerts and banners | `bb7cb4e` | 2, 7, 13 |
| 15 | End-to-end suite, manual checklist, docs and spec | `4806c5b`, `3a559be` | all |

Counts are final, after every follow-up. The filter run uses the task's own test filter; the full suite always ran with Radicale. W2's baseline at `af41f8c`: 240 tests with 1101 assertions, Vitest 79, e2e 14. Reviews ran per pair of tasks (1 to 3 together); a review line names what concerned that task.

---

### Task 1: Provider contract, HTTP factory, registry, schema columns, test support

**Commit:** `ff87448` feat(sync): add the provider contract, http client factory, provider registry and sync columns

**Files:**
- Create: `CalendarProviders/` `CalendarProvider`, `ChangeSet`, `RemoteEvent`, `RemoteRef`, `RemoteCalendar`, `SyncBudget`, `ProviderRegistry`, `HttpClientFactory`, `LocalProvider`, `Exceptions/{ProviderException,ProviderConflict,ProviderUnavailable,ConnectionNeedsReauth}`; migration `2026_10_07_000007_ljpc_calendar_v3_sync_columns.php`; `Tests/Support/FakeHttp.php`, `FakeProvider.php`, `LogRecorder.php`
- Modify: `Entities/Calendar.php`, `CalendarConnection.php`, `CalendarEvent.php`, `Providers/LJPcCalendarModuleServiceProvider.php` (two singletons), `Tests/bootstrap.php`, `Tests/Unit/CalendarPolicyTest.php` and `Tests/Feature/AgentApiTest.php` (a read-only fake for `ics` until Task 3)
- Test: `Tests/Unit/HttpClientFactoryTest.php`, `ProviderRegistryTest.php`, `SyncColumnsMigrationTest.php`, `SchemaTest.php`

**Interfaces:**
- Consumes: `Calendar::PROVIDER_*`, `calendarConnection()`; `CalendarConnection::credentials()`/`setCredentials()`; `CalendarEvent::findByUid()`; core's `\Helper::setGuzzleDefaultOptions()` and `checkUrlHost()`; Guzzle's `Client`, `HandlerStack`, `MockHandler`.
- Produces: the provider contract, value objects, registry, factory, exceptions and `LocalProvider` ("Shared interfaces"); `Calendar::isWritable()`, `usesConnection()`; `CalendarConnection::STATUS_*`, `calendars()`; `CalendarEvent::setRemoteIdAttribute()`, `findByRemoteId()`; the three columns; `FakeHttp` (`install()`, `queue()`, `history()`, `pending()`), `FakeProvider` (`onPull()`, `onCreate()`, `onUpdate()`, `onDelete()`, `calls()`), `LogRecorder` (`install()`, `records()`).

**What it builds:**
- The provider contract and its final value objects, in the shapes every later task uses.
- `ProviderRegistry` resolves each provider class from the container on first use, so later providers need not exist yet; `forProvider()` throws `InvalidArgumentException` for an unknown name; `swap()` serves tests.
- `LocalProvider`: writes allowed, no calendars, a complete empty pull, writes return an empty remote id.
- `client()` merges core's Guzzle defaults, casts timeouts to float and sets `http_errors` false. With `$checkHosts` a handler middleware, pushed last, calls `assertAllowed()` on every request before it is sent; at most 5 redirects, http and https only, no Referer.
- `assertAllowed()` checks `scheme://host/` through `checkUrlHost($url, true)`: a blocked host gives "This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST.", an address without an http(s) scheme or host "This is not a valid http or https address.".
- `scrub()` cuts bodies to 500 characters and masks bearer tokens, `access_token`, `refresh_token`, `id_token`, `client_secret`, `password` and `Authorization` values; `logFailure()` logs calendar, connection, provider, status, host (`[address]` when none), scrubbed body and error, never a URL.
- The exceptions with their statuses, codes and default messages; `toResponse()` answers `{ code, message }`.
- Migration 000007 (PHP 7.1, idempotent): `sync_failures`, `sync_generation`, `remote_id_hash` with its unique index, checked on its own through Doctrine's schema manager before a chunked backfill; `down()` drops the index only when present.
- `isWritable()`: local true, ICS false, connected providers only with an `ok` connection. Connections default to `ok`; an empty remote id is stored as NULL.
- `LogRecorder` replaces `Log::spy()`, which core's Mockery 1.1 can't build on PHP 8.3.

**Deviations:** a middleware instead of `on_redirect`; `scheme://host/` only; float timeouts; `logContext()` and `httpStatus()` added. `SyncColumnsMigrationTest` rebuilds both tables with `ALTER TABLE ... FORCE` before every `down()`, because MariaDB's instant drops keep dropped columns in the row format ("Row size too large" after about fifteen runs).

**Verification:**
- Failing run (filter `HttpClientFactoryTest|ProviderRegistryTest|SyncColumnsMigrationTest|SchemaTest|CalendarPolicyTest|AgentApiTest`): `Tests: 56, Assertions: 126, Errors: 25, Failures: 2.`
- Filter `OK (56 tests, 251 assertions)`; full `OK (265 tests, 1224 assertions)`; ten full runs in a row green after the row-size fix. Dev migrate and PHP 7.1 lint clean.
- Mutation checks: each important fix broken on purpose failed its test (the index repair included). Browser: no UI.

**Review:** with Tasks 2 and 3, 0 Critical and 2 Important, then 3 Minor and a test gap, all fixed; follow-ups added the separate index check and the table rebuild.

---

### Task 2: Event importer and sync engine, `calendar:sync`

**Commit:** `9353230` feat(sync): add the event importer, the sync engine and the calendar:sync command

**Files:**
- Create: `Services/LegacyDescription.php`, `EventImporter.php`, `SyncService.php`, `SyncLeaseLost.php`, `Console/SyncCalendars.php`
- Modify: `Services/FieldValues.php` (`valid()`), `Services/ExportService.php` (`reduceToBusy()` public static), the service provider (command and schedule)
- Test: `Tests/Unit/LegacyDescriptionTest.php`, `EventImporterTest.php`, `SyncServiceTest.php`, `Tests/Feature/SyncCommandTest.php`

**Interfaces:**
- Consumes: Task 1's registry, contract, exceptions, `findByRemoteId()`, `isWritable()`, `FakeProvider`, `LogRecorder`; W1's `EventIcs` (`master()`, `derive()`, `recurrenceKey()`, `removeOverride()`, `addExdate()`), `ReadableBlock::strip()`, `OccurrenceService::refresh()`, `MigrationService::isFinished()`, `FieldValues`, `findByUid()`, `LineItemText`.
- Produces: `EventImporter::upsert()`, `SyncService` (`runDue()`, `syncCalendar()`, `requestSync()`, `backoffMinutes()`, constants), `LegacyDescription::parse()`, `FieldValues::valid()`, public `reduceToBusy()`, the engine keys, `calendar:sync`. For Task 5: events saved through Eloquent during a listing survive its sweep.

**What it builds:**
- `LegacyDescription::parse()` accepts only text that starts with `body:` and has a top-level `custom_fields:` line. The body is rebuilt from the raw text (JSON-decoded; `-` is empty), dallgoot parses only from `custom_fields:` on, unknown field ids are matched by name through `custom_fields_mapping`, dates become `Y-m-d`, values pass `FieldValues::valid()`. Failure returns null.
- `upsert()` parses (failure: logged, null, stored copy untouched), finds the master UID (orphan overrides become read-only), matches by remote id, then UID.
- Partial merges: a pulled master replaces the stored one, overrides replace by recurrence key or are added, cancelled keys remove their override and add an EXDATE. A cancel-only partial finds the event by remote id; a partial for an unknown event without a master is skipped and logged.
- Every VEVENT: v2 YAML is read (fields, conversation and author only when the stored event has none), else the readable block is stripped. Private and confidential events (also private overrides) are reduced to busy with CLASS kept, stored private and read-only.
- Local fields survive (author, conversation, fields, reminder, legacy id); `title_input` clears only when the pulled SUMMARY differs from the stored `summary`. Everything is written in one transaction; a DTSTAMP-only change only records the generation.
- `runDue()` picks enabled non-local calendars with an `ok` connection (or none) that are due (all with `$force`), nulls first; leases are taken atomically through the query builder, renewed before every round and every 100 upserts, and released only by their owner; `SyncLeaseLost` counts as skipped.
- `syncCalendar()` pulls while budget and provider allow. A full-listing round starts a listing (generation + 1, `_listing`, `_listing_started`, `_seen`), stamps upserts, stamps unchanged ids through one query-builder update, applies deletes and saves the merged state after every round. A single event that fails keeps its stored copy and is stamped; the first failure is reported, the rest counted.
- The sweep runs when a full-listing round completes: a listing that saw nothing while events exist counts in `_empty_listings` and deletes only on the second; otherwise it deletes older generations with `updated_at` before the listing started.
- Success: `last_synced_at`, cleared error and failures, next sync after the interval ±10 % (at least a minute); an unfinished pull is due now. `ProviderException`: its message plus " (HTTP n)" once, failures + 1, backoff `min(interval × 2^(failures − 1), interval × 6)`; `ConnectionNeedsReauth` marks the connection. Other errors: "Syncing failed because of an unexpected error. See the FreeScout log." and `report()`.
- `calendar:sync {--force} {--calendar=}`: refuses (exit 0) during the v2 upgrade, refuses a non-numeric id (exit 1), honours due times unless `--force`, prints `Synced N calendars, N failed, N skipped because another run holds them.`; scheduled `everyMinute()->withoutOverlapping(5)->runInBackground()`. No queue jobs.
- A v2 line item resolves a pulled event by UID through W1's `LineItemText` (tested).

**Deviations:** the `updated_at` guard and keys `_seen`, `_listing_started`; owned leases; the background schedule; per-event isolation; DTSTAMP-only changes ignored; `title_input` against the stored summary; `--calendar` honours due times. Task 5 later dropped the read-only flag for unwritable rules and Task 13 the calendar's writability on events.

**Verification:**
- Failing run (filter `LegacyDescriptionTest|EventImporterTest|SyncServiceTest|SyncCommandTest`): `Tests: 46, Assertions: 1, Errors: 45, Failures: 1.`
- Filter `OK (46 tests, 167 assertions)`; full `OK (311 tests, 1391 assertions)`. On the dev install `calendar:sync` prints `Synced 0 calendars, 0 failed, 0 skipped because another run holds them.`
- Mutation checks: each important fix broken on purpose failed its test. Browser: no UI.

**Review:** with Tasks 1 and 3 (see Task 1); unchanged by the follow-ups apart from its full-suite count.

---

### Task 3: ICS provider

**Commit:** `6b0de95` feat(sync): read ics feeds through the ics provider

**Files:**
- Create: `CalendarProviders/Ics/IcsProvider.php`, `Tests/fixtures/ics/google.ics`, `outlook.ics`, `tasks.ics`, `invalid.ics`
- Modify: `Tests/Unit/CalendarPolicyTest.php`, `Tests/Feature/AgentApiTest.php` (the real provider again)
- Test: `Tests/Unit/IcsProviderTest.php`

**Interfaces:**
- Consumes: Task 1's contract, factory (`client()`, `assertAllowed()`, `logFailure()`), `ProviderUnavailable`, `FakeHttp`, `LogRecorder`; Task 2's engine and importer; W1's `EventIcs::build()`, `OccurrenceService::moveWindow()`.
- Produces: `IcsProvider` (`MAX_BYTES`, `contentHash()`; Task 12 adds `feedUrl()`); state `{etag, last_modified, hash}`; UIDs as remote ids; read-only events.

**What it builds:**
- No writes: `canWrite()` and `writableRecurrence()` false, no calendars, writes throw `LogicException`.
- `pull()` checks the address before the request (a malformed one never reaches Guzzle), then GETs through a host-checked client with `If-None-Match` and `If-Modified-Since`.
- A 304, or content whose `contentHash()` (sha1 without DTSTAMP lines) is unchanged, completes without changes.
- Otherwise the feed is split by UID: all VEVENTs of a UID with only the VTIMEZONEs they use; to-dos and journals dropped; an event without UID gets `sha1(DTSTART . SUMMARY . position)`. One complete full-listing `ChangeSet` of read-only upserts.
- Over 20 MB is refused ("The calendar feed is too large (over 20 MB).") by a progress callback, `Content-Length` and the body length.
- Errors: 401/403 "The calendar feed refused access (HTTP n).", 404/410 "The calendar feed was not found (HTTP n).", others the generic message and status, a non-VCALENDAR "The calendar feed is not a valid iCalendar file.".
- Forgiving parsing (`OPTION_FORGIVING | OPTION_IGNORE_INVALID_LINES`).
- No URL is ever logged: host and status only, no error pages, and Guzzle's exception is not kept as previous.

**Deviations:** the pre-request address check; no error pages in logs; Guzzle's exception dropped; the DTSTAMP-free hash; the three-way size limit; forgiving parsing; `writableRecurrence()` false.

**Verification:**
- Failing run (filter `IcsProviderTest|CalendarPolicyTest|AgentApiTest`): `Tests: 39, Assertions: 108, Errors: 13, Failures: 1.`
- Filter `OK (39 tests, 181 assertions)`; full `OK (323 tests, 1462 assertions)`.
- Live check (2026-10-07): a public holiday feed over `webcal://` gave 200 events, the second pull was unchanged, the cloud metadata address was refused. Mutation checks as for Task 1. Browser: no UI.

**Review:** with Tasks 1 and 2 (see Task 1).

---

### Task 4: CalDAV client, discovery and provider, Radicale, migrated URL credentials

**Commit:** `74ffe71` feat(sync): sync caldav calendars through the caldav provider and keep logins out of migrated addresses

**Files:**
- Create: `CalendarProviders/CalDav/` `CalDavClient`, `CalDavRequestFailed`, `CalDavAnswerTooLarge`, `CalDavDiscovery`, `CalDavProvider`; `Tests/caldav/docker-compose.radicale.yaml`, `config`, `users`, `install.sh`; `Tests/Support/DavResponses.php`; `Tests/fixtures/caldav/` (8 XML answers)
- Modify: `CalendarProviders/HttpClientFactory.php` (strict and tracked redirects for callers), `Services/MigrationService.php` (logins out of migrated addresses)
- Test: `Tests/Unit/CalDavClientTest.php`, `CalDavDiscoveryTest.php`, `CalDavProviderTest.php`, `Tests/Integration/CalDavServerTest.php` (group `caldav-server`), `MigrationCalendarsTest.php`, `HttpClientFactoryTest.php`

**Interfaces:**
- Consumes: Task 1's contract, factory, exceptions, `credentials()`, `findByRemoteId()`, `FakeHttp`, `LogRecorder`; Task 2's importer and engine; W1's `VTimezone::addTo()`, `EventIcs::build()`.
- Produces: `CalDavClient`, `CalDavDiscovery`, `CalDavProvider`, `CalDavRequestFailed`, `CalDavAnswerTooLarge` ("Shared interfaces"); the CalDAV state keys; a `RemoteRef` with `uid` after a UID conflict; `install.sh` for Tasks 13 and 15; migrated addresses without logins.

**What it builds:**
- `CalDavClient` uses a host-checked client with basic auth, switching to Guzzle 8's digest after a challenge without Basic, and parses multistatus into absolute hrefs. 412 is a conflict; 401 "The CalDAV server refused the username or password."; a 403 with a DAV precondition "The CalDAV server refused this request (HTTP 403)."; 404/410 on a read "The CalDAV server has nothing at this address (HTTP n)."; a non-multistatus "The CalDAV server sent an answer FreeScout can't read."; network errors and 5xx `ProviderUnavailable`.
- A 50 MB cap per answer; an oversized multiget is halved down to single objects, and one too large alone is unreadable.
- After a cross-host redirect a 401 is retried once with the login, never from https to http (logged with hosts only).
- Discovery: `/.well-known/caldav` (a PROPFIND kept a PROPFIND through `strict` and `track_redirects`), principal, calendar home, collections with VEVENT, reading the name, Apple color (`#rrggbb`), `calendar-timezone` (IANA only) and privileges (`write-content` or `write`); a calendar URL is returned alone.
- Full listings (first sync, invalid token, changed ctag without sync-collection) are PROPFIND depth 1 of the members' ETags plus `calendar-multiget` in batches of 50, resuming after `listing.after`; the token comes from `DAV:sync-token` before the listing; an empty listing keeps no token or ctag.
- Incremental pulls use sync-collection (`Depth: 0`, `sync-level` 1); changes beyond the budget wait in `pending`, their deletes in `pending_deletes` until the last change is in; a 507 means "more" only when the token moved; `valid-sync-token` in a 403 or 409 starts a full listing. Without sync-collection, a changed `getctag` lists again.
- Only 404 and 410 mean gone; any other per-object status, or no calendar-data, is read with GET, and if that fails the object is unchanged (stamped) and retried (`retry`). Members without an ETag are always downloaded; to-dos skipped.
- `create()` PUTs `<collection>/<sha1(uid)>.ics` with `If-None-Match: *`, a VTIMEZONE per TZID and `SCHEDULE-AGENT=CLIENT` on attendees and organizer; the ETag is read back when missing (null on failure still succeeds); `no-uid-conflict` retries once under a new UID. `update()` PUTs with `If-Match`; `delete()` uses `If-Match`, 404/410 are done, a 412 checks existence.
- Radicale: `install.sh` copies the compose file and config into the core checkout's `.ddev/`, restarts ddev, creates the test collection and prints the whitelist line (the service name plus the network's range). Integration tests allow it through `config()` and skip with "Radicale is not running. Run Tests/caldav/install.sh.".
- `MigrationService` strips logins from migrated CalDAV and ICS addresses (the `custom_fields` copy too, `@` in passwords kept whole), uses them as the CalDAV login when v2's fields are empty, and warns naming the calendar only.

**Deviations:** listings by PROPFIND and multiget (Zoho), `Depth: 0`, resume by href, budgeted token reports, the unreadable-object rule, the 50 MB cap, no token after an empty listing, the `no-uid-conflict` retry (accepted 2026-10-09), Guzzle's digest, credentials resent after a cross-host redirect, specific error messages.

**Verification:**
- Failing run (filter `CalDav|MigrationCalendarsTest|HttpClientFactoryTest`): `Tests: 86, Assertions: 115, Errors: 50, Failures: 3.`
- Filter `OK (86 tests, 315 assertions)` (without Radicale 289 assertions, 3 skipped); group `caldav-server` `OK (3 tests, 26 assertions)`; full `OK (376 tests, 1665 assertions)` (without Radicale 1639 assertions, 3 skipped).
- Rehearsal on the three masked dumps: 0 skipped items, 0 errors, 21 calendars, 717 events, 1 CalDAV connection, 0 userinfo warnings, 0 changed starts; line items 455 resolved and 56 unresolved, as in W1.
- Mutation checks: strict redirects, the listing cursor, VTIMEZONE in the PUT body. Browser: in Task 5.

**Review:** with Task 5; fixed the multiget error that could sweep an event or lose a change, unbudgeted token downloads, the same-account move on `no-uid-conflict` servers, the 50 MB stall, deletes held until the last change, and a new listing after an unreadable object.

---

### Task 5: Provider writes in `EventService`, provider errors in the API, cross-calendar moves

**Commit:** `e7620f5` feat(sync): write events through their provider first, answer provider errors and warn about half-finished moves

**Files:**
- Create: `Services/SeriesNotWritable.php`
- Modify: `Services/EventService.php`, `SyncService.php` (`relist()`, `syncNow()`), `ReadableBlock.php`, `ExportService.php`, `EventImporter.php`; `Entities/Calendar.php` (`canWriteSeries()`); `Http/Controllers/Api/EventsController.php`, `ConversationEventsController.php`, `Http/EventPresenter.php`, `Http/FrontendStrings.php`, `Policies/CalendarPolicy.php`; frontend `shared/api.ts`, `alerts.ts`, `types.ts`, `calendar/App.vue`, `EventDetails.vue`, `EventForm.vue`, `ScopeDialog.vue`; `Public/build`
- Test: `Tests/Unit/EventServiceProviderTest.php`, `Tests/Feature/ProviderWritesApiTest.php`, `EventImporterTest.php`, `AgentApiTest.php`, `ConversationModalTest.php`; Vitest `shared`, `calendar-events`, `form-model`

**Interfaces:**
- Consumes: Task 1's registry, contract, `RemoteRef`, exceptions, `FakeProvider`; Task 2's importer, engine and sweep; W1 and W2's `EventService`, `DropsExceptions`, `EventIcs`, `FieldValues`, `CalendarPolicy`.
- Produces: `EventService`'s new constructor, `lastWarning()`, `CONFLICT_SYNC_SECONDS`; `SyncService::relist()`, `syncNow()`; `ReadableBlock::addTo()`; `Calendar::canWriteSeries()`; `SeriesNotWritable`; `EventsController::withWarning()`; `EventImporter::__construct(OccurrenceService)`; the API's provider errors, `warning`, `series_not_writable`, `series_writable`; frontend `asksToDropExceptions()`, `notify('warning')`. Tasks 9 and 11 get `$occurrenceKey` on occurrence updates and deletes.

**What it builds:**
- `create()` on a non-local calendar sends a clone with the readable block on every VEVENT (`ReadableBlock::addTo()`), then stores remote id, href and etag, and the provider's own UID when it assigned one. The stored description never has the block.
- `update()` writes to the provider before the save, with the occurrence key for occurrence scope; the caller's model stays untouched until confirmed. The returned etag is always stored (null too); id and href change only when the provider names new ones.
- `delete()` deletes at the provider first; an occurrence delete is a provider update with the key and the EXDATE.
- Moves between two local calendars are unchanged; otherwise target create, then source delete (skipped for local). A failed create changes nothing. A failed source delete keeps the event on the target, logs, `relist()`s the source and warns "The event was moved, but it could not be removed from ":calendar". It is now in both calendars.". Remote fields come from the target; the generation restarts at 0.
- `DropsExceptions` still comes first: no provider call before the agent confirmed a reset.
- Series lock: `canWriteSeries()` asks `writableRecurrence()`; a series-scope change of a locked series (moves too) answers 403 `series_not_writable`; occurrence edits and deletes stay allowed. Unwritable rules answer 422 on `recurrence` or `calendar_id`, before the reset question.
- A `ProviderConflict` on any write runs `syncNow()` within 10 s before the 409 (`requestSync()` when a run holds the lease); a failing sync goes to `last_error` and never changes the answer.
- The API answers `toResponse()` on store, update, destroy and the conversation store, and adds `warning` (`withWarning()`).
- Frontend: coded 403s show their message; `asksToDropExceptions()` keeps `changed_elsewhere` apart from the reset question; warnings are sticky alerts (core only knows success and error); a locked series offers only "Edit this occurrence" with a hint, and its occurrence drags offer only Cancel and "This occurrence" (`ScopeDialog` `allowSeries`).
- Ledger carry-overs: the "never loads ics" test counts its matches; the policy imports its return type; the conversation hook covers a read-only provider, a disabled calendar and an admin.
- Every write saves through Eloquent; a test proves a listing sweeps only the event FreeScout didn't touch meanwhile.

**Deviations:** the series lock instead of the importer's flag; 422 checks on create, series edit and move; `lastWarning()`; the untouched model; target-only remote fields and generation 0 after a move; `relist()` after a failed delete; the immediate sync after a conflict (Lars, 2026-10-09).

**Verification:**
- Failing run (filter `EventServiceProviderTest|ProviderWritesApiTest|EventImporterTest|ConversationModalTest|AgentApiTest`): `Tests: 74, Assertions: 220, Errors: 8, Failures: 21.`; vue-tsc failed on `series_writable`, `asksToDropExceptions` and `'warning'`.
- Filter `OK (74 tests, 293 assertions)`; full `OK (411 tests, 1791 assertions)`; Vitest 82; the build is stable; e2e `14 passed`.
- Mutation checks: the series lock, `updated_at` on saves, the immediate sync (three tests fail without it).
- Browser (Tokyo browser, New York agent, Radicale): creates and edits reach Radicale; an event changed in Radicale first gives "This event was changed elsewhere. Reload and try again." and a reload then shows the other version; a move from an unreachable source shows the sticky warning; a locked series offers only occurrences, and moving one writes an override.

**Review:** with Task 4; fixed the source generation kept on a move, unwritable rules checked only on moves, a 409 that didn't bring the sync forward, `relist()` on a stale row, and the rule check after the reset question.

---

### Task 6: Time-zone correctness for foreign events and series-zone copies

**Commit:** `a088fcc` fix(events): write series copies in the series zone and keep floating and foreign rules stable

**Files:**
- Create: `Services/PhpTimezoneFinder.php`, `Tests/Feature/EventZonesApiTest.php`
- Modify: the service provider (finder), `CalendarProviders/CalDav/CalDavProvider.php` (PUT body TZIDs), `Services/RecurrenceRule.php`, `VTimezone.php`, `EventIcs.php`, `ExportService.php`, `EventService.php`, `OccurrenceService.php`, `Http/EventPresenter.php`, `Api/EventsController.php`, `Settings/CalendarsController.php`; frontend `shared/types.ts`, `time.ts`, `recurrence.ts`, `form-model.ts`, `calendar/events.ts`, `App.vue`, `EventForm.vue`; `Public/build`
- Test: `Tests/Unit/RecurrenceRuleTest.php`, `VTimezoneTest.php`, `EventIcsTest.php`, `CalDavProviderTest.php`, `EventServiceTest.php`, `Tests/Feature/ExportTest.php`, `SettingsFormTest.php`, `EventZonesApiTest.php`; Vitest `form-model`, `time`, `calendar-events`

**Interfaces:**
- Consumes: Task 5's `EventService`, `SeriesNotWritable`, `canWriteSeries()`, `series_writable`; W1's `EventIcs`, `RecurrenceRule`, `VTimezone::addTo()`, `OccurrenceService::refresh()`, `TimeInput`; W2's `form-model.ts`, `recurrence.ts`; `LogRecorder`.
- Produces: `RecurrenceRule::toRrule(..., $floating)`, `canonical()`; `EventIcs::ruleZone()`; `VTimezone::resolve()`, `isKnown()`, `isNamedZone()`, `warnUnknown()`, `addTo(..., $zone)`; `PhpTimezoneFinder` (`ljpc_php`); `OccurrenceService::refreshFloating()`; the API's `timezone`; frontend `EventDetail.timezone`, `FormState.timezone`, `formFromSeries()`, `ruleStartDate()`, `dateIn()`, `followStart()`.

**What it builds:**
- The create API accepts `timezone` (a named zone, else 422) for a timed event with a rule: times are typed in the agent's zone and written, with UNTIL, in the series' zone. A Monday 08:00 Amsterdam series copied by a Los Angeles agent stays Monday 08:00 Amsterdam with `BYDAY=MO`.
- The presenter's `timezone` is `ruleZone()` for timed events (a floating event gets its calendar's zone), null for all-day and unnamed zones; a copy of a floating series is written in its calendar's zone.
- `FormState.timezone` is the rule's zone: set by `formFromSeries()` and `copyOfSeries()`, sent only by `toCreatePayload` with a rule, never by `toSeriesPatch`. The weekday check, `withStart()` and the editor's defaults (`ruleStartDate()`) read the start where the rule lives.
- `followStart()` moves a rule with its start and leaves deliberately offset weekdays alone; `withStart()` and `seriesDragPatch()` (dates in `master.timezone`) both use it.
- Floating events get a local UNTIL (`T235959`), read back in the given zone; all-day series read their rule in the calendar's zone, so a UTC UNTIL shows the same end date everywhere.
- `RecurrenceRule::canonical()` takes over BYDAY canonicalization; resent rules are compared in the rule zone, stored custom rules by string.
- An occurrence of a floating series switched to all-day and back stays floating.
- `refreshFloating()` re-derives timed events whose stored zone differs from the calendar's new effective zone, in the settings save's transaction; an unreadable event keeps its times and is logged.
- `PhpTimezoneFinder` lets sabre read the zone names PHP still knows (`Europe/Kiev`, `Asia/Rangoon` and others), registered in `boot()` after the guards (PHP 7.1 parse kept).
- `resolve()` answers what a TZID stands for (zone name, sabre map, `X-LIC-LOCATION`, a custom name the event's VTIMEZONE explains, a fixed offset), null otherwise; the export and the CalDAV PUT build VTIMEZONEs from it under the original name, and the export logs an unresolvable TZID once per render.
- `derive()` warns once per unknown TZID per request (`warnUnknown()`).

**Deviations:** `FormState.timezone` also for the series form; the presenter's zone for floating events (review I1); `isNamedZone()` instead of Laravel's `timezone` rule (offsets, `localtime` and `tzdata.zi` refused, `Europe/Kiev` accepted); all-day rules in the calendar's zone; `PhpTimezoneFinder`; per-event TZID resolution; `refreshFloating()` in `OccurrenceService`; the drag in the rule zone (2026-10-09 follow-up).

**Verification:**
- Failing run (filter `RecurrenceRuleTest|VTimezoneTest|EventIcsTest|ExportTest|CalDavProviderTest|EventServiceTest|SettingsFormTest|EventZonesApiTest`): `Tests: 121, Assertions: 609, Errors: 7, Failures: 12.`; vue-tsc failed on the new types; Vitest `Tests 7 failed | 82 passed (89)`. Three guard tests passed before.
- Filter `OK (121 tests, 700 assertions)`; full `OK (433 tests, 1933 assertions)`; Vitest 89; stable build; e2e `14 passed`; PHP 7.1 lint of the provider clean.
- Mutation checks: the rule zone in the comparison, the warn-once memo, the finder registration, a drag read in the agent's zone.
- Browser (Tokyo): a New York agent duplicates a Monday 05:00 Amsterdam series; the form shows Sunday 23:00 with Monday ticked; the payload carries `timezone: Europe/Amsterdam`; the copy is stored with `TZID=Europe/Amsterdam` and `BYDAY=MO`; the series form offers "On day 16" and "third Monday".

**Review:** with Task 7; fixed C1 (a renamed or fixed-offset TZID made the export answer 500), I1 (frozen weekdays of floating series), the CalDAV PUT of `CEST`, stale rows in `refreshFloating()` and uncaught sabre errors in `resolve()`; left one VTIMEZONE per TZID name and the second validation round.

---

### Task 7: OAuth: `ConnectionService`, app credentials, start and callback

**Commit:** `e1d7cbe` feat(sync): connect google and microsoft accounts through oauth with state and pkce, and refresh their tokens under a row lock

**Files:**
- Create: `Services/ConnectionService.php`, `Http/Controllers/OAuthController.php`, `Http/Controllers/Settings/ConnectionsController.php`
- Modify: `Config/config.php`, `Http/routes.php`, `Http/SettingsPage.php` (`connectionsUrl()`)
- Test: `Tests/Unit/ConnectionServiceTest.php`, `Tests/Feature/OAuthFlowTest.php`

**Interfaces:**
- Consumes: Task 1's `client()` (no host checks: fixed hosts), `logFailure()`, `ProviderUnavailable`, `ConnectionNeedsReauth`, `CalendarConnection` statuses and credentials, `FakeHttp`, `LogRecorder`.
- Produces: `ConnectionService` without `renewAccessToken()` (Task 9 adds it); the `oauth` config; routes `settings.apps`, `oauth.start`, `oauth.callback`; `connectionsUrl(?int $connectionId = null)`; the error bags `google` and `microsoft` with `old('app')`.

**What it builds:**
- `authorizationUrl()`: 64-hex `state` and PKCE verifier with an `S256` challenge, stored in the session with provider, connection id and time. Google adds `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`; Microsoft `prompt=select_account`, `response_mode=query`. Missing app credentials give a validation error.
- `completeAuthorization()`: the session entry must exist, match the provider, be under 10 minutes old and match `state` (`hash_equals`); it is always removed. A provider `error` becomes a validation message. The code exchange (with `code_verifier`) follows no redirects and waits at most 15 s.
- The answer needs a refresh token (a message per provider), and Google's `scope` must hold both calendar scopes ("Google did not grant access to the calendars. Connect the account again and allow every permission it asks for.").
- The account (Google's primary calendar id; Graph's `mail` or `userPrincipalName`) is stored lowercased. Reauthorizing must return the same account; a connection without one refuses an account another connection holds; the same provider and account update the existing connection.
- `accessToken()` returns a token valid for over 5 minutes, else locks the row, re-reads it, uses a token another process refreshed, or refreshes and stores token, expiry and a rotated refresh token. A fresh token is returned even when short-lived; a `needs_reauth` connection is never refreshed; never inside a transaction.
- Token errors: `invalid_grant` and `interaction_required` mark `needs_reauth` ("The account must be connected again.") and throw `ConnectionNeedsReauth` (a sign-in message during the callback); `invalid_client` and `unauthorized_client` give `ProviderUnavailable` about the app's credentials; others the status. Every refusal is logged scrubbed.
- App credentials: `client_id` required, the secret encrypted and kept when posted empty, Microsoft's tenant required with its own message, the expiry `Y-m-d`; options read without core's cache.
- `OAuthController`: `start()` validates the provider and an optional `connection`; `callback()` goes to the pick screen with ":account is connected. Choose the calendars FreeScout shows." or back to the tab with the error, escaped with `e()`.
- `saveApp()` returns to the Connections tab with errors in the provider's bag and old input plus `app`, never the secret.

**Deviations:** the granular-consent check; the token-error mapping; no refresh after `needs_reauth`; error bags and the `app` marker; no redirects and a 15 s timeout; lowercased accounts; escaped flashes.

**Verification:**
- Failing run (filter `ConnectionServiceTest|OAuthFlowTest`): `Tests: 36, Assertions: 3, Errors: 35.`
- Filter `OK (36 tests, 176 assertions)`; full `OK (469 tests, 2109 assertions)`, ten runs in a row.
- Mutation checks: any `state` accepted, the caller's instead of the locked row's credentials, a fresh short-lived token refused.
- Browser (Tokyo): the app saves; "start" redirects to Google with `S256`, offline access, consent and a 64-character state; a wrong state returns to the Connections tab with the reason; HTML in Google's error shows as text.

**Review:** with Task 6; fixed redirects and timeout, the `app` marker and its order, an account held by another connection, tests and casing; left M8 (no token revocation after a refused sign-in).

---

### Task 8: Google event mapper

**Commit:** `2169f01` feat(sync): map google calendar events and their exceptions to vcalendars and back

**Files:**
- Create: `CalendarProviders/Google/GoogleEventMapper.php`, `Tests/fixtures/google/` (`single-timed`, `all-day`, `recurring`, `instance-modified`, `instance-cancelled`, `private`, `attendees`, `offset-without-timezone`)
- Test: `Tests/Unit/GoogleEventMapperTest.php`

**Interfaces:**
- Consumes: `EventIcs::PRODID`, `addExdate()`, `endOf()`, the `recurrenceKey()` format; Task 6's `isNamedZone()`, `resolve()`, `addTo()`.
- Produces: the static `GoogleEventMapper` methods ("Shared interfaces"); fixtures around a weekly Monday 10:00 Amsterdam series with an EXDATE, an instance moved across the DST change and a cancelled instance.

**What it builds:**
- `toVCalendar()`: `iCalUID` as UID, summary, description, location, start and end (`dateTime` plus `timeZone` as TZID with VTIMEZONE, or `date` with an exclusive end), RRULE, EXDATE and RDATE lines, status, visibility as CLASS, sequence, organizer and attendees (PARTSTAT, CN, CUTYPE, ROLE), `updated` as LAST-MODIFIED and DTSTAMP, `transparency` as TRANSP.
- Exceptions: cancelled ones become EXDATEs, others overrides with RECURRENCE-ID in the master's zone; an exception of the other form (date versus time) is skipped and logged.
- A `dateTime` with an offset and no `timeZone` is read in the calendar's zone.
- `fromVEvent()`: `iCalUID` and `recurrence` for masters only, all-day `date`, timed `dateTime` with `timeZone` (an unknown zone such as `GMT+0530` as UTC), floating times read in `$floatingTimezone`, a floating UNTIL made UTC, `status`, `visibility`; missing fields are `null` so an update clears them; attendees are never written.
- Visibility round-trips: `default` none, `public` `CLASS:PUBLIC`, `private` and `confidential` their CLASS.
- HTML descriptions become text with line breaks and link targets kept, `&nbsp;` as spaces and trailing spaces removed, so the block's marker line is found again; plain text with `<` stays.
- Recurrence lines with a line break or another property are skipped; RDATE and EXDATE values get a line each, in the start's zone.
- `exceptionKey()`; `instanceId()` builds `<masterId>_<YYYYMMDDTHHMMSSZ>` or `<masterId>_<YYYYMMDD>`, the API's undocumented form.
- `overridesToVCalendar()` builds a stored series' overrides for partial merges.
- `isException()` classifies an instance against its series with Google's defaults; `sameOccurrence()` checks an instance against the stored occurrence (start, end, summary, location, status). Both compute ends as wall-clock time in the series' zone, as sabre does, so a night across DST is no false conflict.
- VTIMEZONEs span the year before the first start to two years ahead, so unchanged events map to the same text; unusable events throw `InvalidArgumentException`.

**Deviations:** the undocumented instance id (Task 9 falls back to the documented lookup); three extra methods for Task 9; `$floatingTimezone`; lossless visibility; HTML to text; TRANSP and attendee details; unsafe recurrence lines and other-form exceptions skipped.

**Verification:**
- Failing run (filter `GoogleEventMapperTest`): `Tests: 19, Assertions: 0, Errors: 19.`
- Filter `OK (19 tests, 108 assertions)`; full `OK (488 tests, 2217 assertions)`.
- Mutation checks (11): RECURRENCE-ID in UTC, offset times in UTC, cancelled exceptions ignored, HTML kept, no recurrence-line guard, an inclusive all-day end, a floating UNTIL kept, every end in UTC, other-form exceptions kept, an `isException()` that always says no, elapsed instead of wall-clock lengths. Browser: no UI.

**Review:** with Task 9; the second round's DST end arithmetic was fixed here.

---

### Task 9: Google provider

**Commit:** `9b37f36` feat(sync): sync google calendars both ways with sync tokens, series exceptions from their instances, checked instance writes and rate-limit backoff

**Files:**
- Create: `CalendarProviders/Google/GoogleApi.php`, `GoogleProvider.php`
- Modify: `Services/ConnectionService.php` (`renewAccessToken()`, a private `refresh()`)
- Test: `Tests/Unit/GoogleApiTest.php`, `GoogleProviderTest.php`, `ConnectionServiceTest.php` (two tests)

**Interfaces:**
- Consumes: Task 1's contract and factory; Task 2's merge, rounds and sweep; Task 5's writes and occurrence keys; Task 7's `accessToken()`; Task 8's mapper; `OccurrenceService::window()`, `EventIcs` helpers, `isNamedZone()`, `ReadableBlock::strip()`.
- Produces: `GoogleProvider` (registered as `google`), `GoogleApi::request()` (Task 11 moves its loop into `OAuthApi`), `ConnectionService::renewAccessToken()`.

**What it builds:**
- `GoogleApi`: a 401 renews the token once (`renewAccessToken()`, under the row lock unless another process stored a different valid token); a second 401 is `ConnectionNeedsReauth`. Rate limits (403 `rateLimitExceeded`/`userRateLimitExceeded`, 429) retry 3 times with backoff and jitter or `Retry-After`, within 15 s per sync request or 4 s interactive, then "Google is limiting requests. The next sync tries again."; a limit is never handed to a caller that accepts the status. `quotaExceeded`, `forbiddenForNonOrganizer` ("Only the organizer can change this event in Google."), other 403s, 404, 400 and 409 have messages; 5xx is not retried.
- `listCalendars()`: `owner`, `writer` and `writerWithoutPrivateAccess` writable; `summaryOverride` preferred; unnamed zones and non-`#rrggbb` colors left out; a repeated page token stops.
- `pull()`: `events` with `singleEvents=false`, `showDeleted=true`, 250 per page, sync token or full listing; the page token resumes a budget stop; `nextSyncToken` completes the round; 410 restarts the listing, a 400 on a saved page token restarts it too; an empty listing keeps no token.
- Masters and singles are full upserts, cancelled ones deletes. A stored series whose master changed is read again whole with exceptions from `events.instances` over the window (at most 4 pages of 2500, with a warning when cut off), classified with `isException()` and sorted; a refused instances call keeps that series and the round goes on.
- In a full listing an unchanged stored series (same etag and read-only flag) is not read again. Lone exceptions are merged as partials, or the series is re-read when they don't fit; exceptions of an unknown series fetch the series (and its exceptions in incremental rounds).
- Read-only: `none`, `freeBusyReader` and `reader` pages, invitations (`organizer.self` false, `guestsCanModify` not true), every non-`default` `eventType`. Free/busy occurrences become busy singles with their own id as UID; one with a rule keeps it.
- Series update: GET; another etag or a gone event is a conflict without a write; else PUT Google's copy with FreeScout's fields, `If-Match`, `conferenceDataVersion=1` and `supportsAttachments=true` (attendees, Meet link, reminders kept); 412 is a conflict.
- Occurrence writes GET the instance (computed id, else `instances?originalStart=`), require `sameOccurrence()`, and write under the instance's etag; a deleted occurrence is a DELETE without `If-Match` (410 done). They return the series' stored etag.
- With guests at Google (attendees other than the account), descriptions go without the readable block (`forGuests()`); a block-only description is cleared.
- `create()` inserts with FreeScout's `iCalUID` (Google's own returned as `uid` when different), writes a moved series' overrides (one without an instance is logged), and deletes the series again when anything fails. `delete()` uses `If-Match`; 404 and 410 succeed.
- The provider never changes `calendars.timezone`.

**Deviations:** GET-then-PUT writes; the occurrence precondition; whole series re-reads and the full-listing shortcut; read-only rules (event types, Lars 2026-10-09); free/busy pages; `writerWithoutPrivateAccess`; no token after an empty listing; the 400 restart; per-kind wait caps; `renewAccessToken()`; overrides on create with cleanup; no block for meetings (Lars, 2026-10-09). Known limits for the manual test: renames, per-occurrence descriptions, flattened rich text.

**Verification:**
- Failing run (filter `ConnectionServiceTest|GoogleApiTest|GoogleProviderTest`): `Tests: 78, Assertions: 130, Errors: 51.`
- Filter `OK (78 tests, 366 assertions)`; full `OK (539 tests, 2453 assertions)`.
- Mutation checks (31 listed): among them an accepted rate limit, the shortcut ignoring the read-only flag, no occurrence precondition, no `If-Match`, a PUT of FreeScout's fields only, no renewal on 401, the block kept for a meeting, the account as its own guest. Browser: no UI; account-only behaviour is on the checklist.

**Review:** three rounds with Task 8; fixed C1 (stored EXDATEs carried into partial merges), stale overrides, unprotected occurrence writes, a full listing re-reading every series, and two regressions (a swallowed rate limit on instances, an ignored access-role change); I4 (incremental failures not retried) left to the engine.

---

### Task 10: Microsoft time zones, recurrence and event mapper

**Commit:** `7b7af5f` feat(sync): map microsoft graph events, their recurrence patterns and windows time zones to vcalendars and back

**Files:**
- Create: `CalendarProviders/Microsoft/WindowsZones.php`, `GraphRecurrence.php`, `GraphEventMapper.php`, `CalendarProviders/HtmlText.php`, `Tests/fixtures/microsoft/` (`single`, `all-day`, `series-master`, `instances`, `private`, `relative-monthly`)
- Modify: `Services/VTimezone.php` (`addForEvents()`, `TZID_PROPERTIES`), `Google/GoogleEventMapper.php` (uses them and `HtmlText`)
- Test: `Tests/Unit/WindowsZonesTest.php`, `GraphRecurrenceTest.php`, `GraphEventMapperTest.php`

**Interfaces:**
- Consumes: `EventIcs::PRODID`, `recurrenceKey()`, `addExdate()`, `endOf()`; `VTimezone::isNamedZone()`, `resolve()`, `addTo()`; `OccurrenceService::MAX_ITERATIONS`, `MAX_PER_EVENT`; `RecurrenceRule`'s UNTIL forms.
- Produces: `WindowsZones`, `GraphRecurrence::toRrule()`/`fromRrule()`, `GraphEventMapper::toVCalendar()`, `fromVEvent()`, `occurrenceKey()`, `missingKeys()`, `zoneOf()`, `HtmlText::toPlain()`, `VTimezone::addForEvents()`. Task 11 adds `canWriteRule()`, `changedFields()`, `onLateMonthDay()`.

**What it builds:**
- `toIana()`: intl first, then `TO_IANA` (139 territory-001 names, CLDR 48.2); IANA names and `UTC` pass; unknown names (`tzone://Microsoft/Custom`) give null. `fromIana()`: intl, then `TO_WINDOWS` (596 names incl. PHP's other names via ICU canonical ids), else the name as is. ICU 76.1 matches `TO_IANA` on all 139 (pinned by a test); two protected static methods are the intl seam.
- Regenerating the map: `Tests/tools/windows-zones.php` (committed after W3, with the commands in its header) prints both constants from CLDR `release-48-2` `common/supplemental/windowsZones.xml`. Per `mapZone`, the territory-001 type goes into `TO_IANA` under the Windows name and every listed id into `TO_WINDOWS`; each name from `DateTimeZone::listIdentifiers(ALL_WITH_BC)` that is missing but whose `IntlTimeZone::getCanonicalID()` is mapped is added. Run in ddev (intl); it reports "139 windows names, 596 iana names, ICU 76.1" and reproduces the committed constants exactly (checked 2026-10-15).
- `GraphRecurrence` maps daily, weekly, absolute and relative monthly and yearly, and end date, numbered and no-end ranges both ways; `fromRrule()` answers null for what Graph can't hold (several BYSETPOS, several BYMONTHDAY, hourly, numbered weekly BYDAY, plain day 29 to 31, a monthly start on 29 to 31, yearly 29 February, a start outside its rule).
- Outlook's "monthly on the 31st" becomes `BYMONTHDAY=28,...,d;BYSETPOS=-1`, which (with `BYMONTHDAY=-1`) maps back.
- `WKST` only when it changes the occurrences; a yearly rule on the series' date is plain `FREQ=YEARLY`; several-day relative patterns use `BYSETPOS`, one day `BYDAY=2TU`; "every weekday" is weekly.
- UNTIL becomes Graph's inclusive end date in the series' zone (the day before when that day's occurrence starts after UNTIL); no `recurrenceTimeZone`; only the fields each type needs.
- `toVCalendar()`: subject, body, location, UTC times, `isAllDay`, sensitivity as CLASS, `showAs: free` as TRANSP (never written), `isCancelled`, `iCalUId`, organizer and attendees (never written), recurrence, exceptions from `originalStart`, cancelled instances as EXDATEs.
- `zoneOf()`: the zone the event was made in; a Windows name for the calendar's own zone reads as that zone (no Amsterdam to Berlin); unknown names fall back to `recurrenceTimeZone`, then the calendar's zone.
- `missingKeys()` infers deleted occurrences over the read window, only when Graph's listed occurrences all belong to the rule; otherwise a warning names the keys.
- All-day dates read in both forms Graph may render under UTC (`dateOf()`).
- HTML bodies lose head, styles, scripts, comments and empty-paragraph breaks, then `HtmlText::toPlain()` (moved unchanged from the Google mapper); text bodies get LF ends.
- `fromVEvent()` sends Windows zone names, a text body, `isAllDay`, the master's recurrence, and `sensitivity` only with a CLASS (`PUBLIC` as `normal`).

**Deviations:** Windows names instead of IANA; two bundled maps; changed signatures (`$allDay`, the start, the instance window); the day 29 to 31 forms; no pattern for a start outside its rule; conditional `WKST`; `BYSETPOS` patterns; the zone fallbacks; the guarded inference; shared `HtmlText` and `addForEvents()`.

**Verification:**
- Failing run (filter `WindowsZonesTest|GraphRecurrenceTest|GraphEventMapperTest`): `Tests: 37, Assertions: 1, Errors: 37.`
- Filter `OK (37 tests, 948 assertions)`; neighbours (Google, ICS, CalDAV, export, VTimezone) `OK (119 tests, 606 assertions)`; full `OK (576 tests, 3401 assertions)`.
- Mutation checks (15): among them WKST always written, a plain day 29 to 31, no start check, no day-before UNTIL, no calendar-zone preference, unrounded all-day dates, no EXDATEs for missing occurrences, no bundled maps, no `recurrenceTimeZone` fallback, no inference guard. Browser: no UI.

**Review:** with Task 11; fixed the zone fallbacks and the inference guard (a custom-zone New York series lost real occurrences between the DST changes); left the warning without `calendar_id`.

---

### Task 11: Microsoft provider, shared mailboxes

**Commit:** `54b6575` feat(sync): sync microsoft 365 calendars and shared mailboxes both ways with change-key listings, series instances, checked occurrence writes and retry-after backoff

**Files:**
- Create: `CalendarProviders/OAuthApi.php`, `ConfirmsDeletions.php`, `ExplainsRecurrenceRefusal.php`, `Microsoft/GraphApi.php`, `Microsoft/MicrosoftProvider.php`, `Tests/fixtures/microsoft/calendars.json`
- Modify: `Google/GoogleApi.php` (extends `OAuthApi`), `Google/GoogleProvider.php`, `Microsoft/GraphEventMapper.php`, `Microsoft/GraphRecurrence.php`, `Entities/CalendarEvent.php` (`byRemoteIds()`), `Services/EventIcs.php` (`exdateKeys()`), `RecurrenceRule.php` (comment), `SyncService.php` (confirmed sweep), `EventService.php` (refusal reason)
- Test: `Tests/Unit/GraphApiTest.php`, `MicrosoftProviderTest.php`, `SyncServiceTest.php` (one test)

**Interfaces:**
- Consumes: Task 1's contract and factory; Task 2's rounds and sweep; Task 5's writes and `canWriteSeries()`; Tasks 7 and 9's tokens; Task 10's mapper, recurrence and zones; `OccurrenceService::window()`, `EventIcs` helpers.
- Produces: `OAuthApi`, `GraphApi`, `MicrosoftProvider` (registered as `microsoft`), the two optional interfaces, `canWriteRule()`, `changedFields()`, `onLateMonthDay()`, `byRemoteIds()`, `exdateKeys()`. A Microsoft etag may carry the `reread:` mark; remote ids are `me/calendars/{id}` or `users/{owner}/calendars/{id}`.

**What it builds:**
- `OAuthApi`: the shared loop (token, one renewal after 401, throttling with `Retry-After` or backoff, 15 s or 4 s wait caps, no redirects, scrubbed logs); `GoogleApi` keeps its behaviour on it.
- `GraphApi`: three `Prefer` headers, 429 and 503 retried, next links only under the v1.0 base (refused before the token is fetched); leftover throttling is "Microsoft is limiting requests right now. Try again in a minute."; 412 a conflict; 403, 404, 400 and `ErrorOccurrenceCrossingBoundary` have messages.
- `listCalendars()`: `id,name,hexColor,canEdit,isDefaultCalendar`, 100 per page, repeated links stop; a shared-mailbox owner is lowercased and must be an email ("Enter the email address of the shared mailbox."); 403 or 404 for it: "You don't have access to the calendars of :address."; no calendar zone.
- `pull()` pages `id,changeKey,type` (`next_link`), skips occurrence and exception items, treats an equal changeKey as unchanged and reads the rest (at least one per round, an event listed twice once); a repeating link is an error; a refused saved link restarts; a 404 explains private items.
- Changed masters are read with their instances over the window (100 per page, at most 50; cut-off series infer no deletions). Invitations (`isOrganizer: false`) are read-only.
- Unreadable events keep their stored copy and their changeKey goes into `skipped` (at most 500); an event gone before reading counts as unchanged.
- The weekly pass: when the window start moved 7 days past `reread_from`, every series master is read again.
- `confirmGone()` asks `{calendar}/events?$filter=iCalUId eq '...'&$select=id&$top=1` per candidate, shuffled, at least 5 then within the budget: empty means gone, 400/403/404 keep it, events without iCalUId are asked by id (only 404/410 gone); throttling or 5xx ends the checks without losing the round.
- `SyncService::sweep()` deletes only confirmed ids and rows without a remote id, and stamps the rest through the query builder.
- `create()` POSTs with a `transactionId` (a 412 is retried once), returns id, changeKey and iCalUId, writes a moved series' overrides and EXDATEs to its instances, and deletes the new series when anything fails.
- Series updates read the changeKey (another one or 404 is a conflict) and send only `changedFields()` (nothing when equal; `end` alone for a duration change; the rule only when it or its start date changes); afterwards the etag is `reread:` plus the changeKey.
- Occurrence writes check the series' changeKey, find the instance around its original start (and where it was moved), PATCH changed fields or DELETE an EXDATE's instance, and return the new changeKey; `delete()` checks the changeKey first and retries a 412 once.
- With attendees at Graph (read before every write), bodies go without the readable block (`forAttendees()`).
- `writableRecurrence()` is `canWriteRule()`; `recurrenceRefusal()` answers "Microsoft calendars can't repeat on day 29, 30 or 31 of the month." (`onLateMonthDay()`), which `EventService` shows in the 422; Outlook's last-day rule stays writable.

**Deviations:** the shared `OAuthApi`; three `Prefer` headers; restricted next links; `hexColor` only; mailbox validation; the confirmed sweep instead of a page overlap (coordinator, C1) with the iCalUId check (Deleted Items); the weekly pass; `skipped`; field-level updates and the `reread:` mark; no `If-Match`; no block for meetings and the day 29 to 31 refusal (Lars, 2026-10-09). I6 (read-only calendars) went to Task 13.

**Verification:**
- Failing run (filter `GraphApiTest|MicrosoftProviderTest|SyncServiceTest`): `Tests: 71, Assertions: 79, Errors: 49.`
- Filter `OK (71 tests, 337 assertions)`; Google after the refactor `OK (78 tests, 366 assertions)`; full `OK (625 tests, 3659 assertions)`.
- Mutation checks (48 listed): among them foreign next links, no `Prefer` headers, 412 not a conflict, no weekly pass, no changeKey check, every field sent, the sweep asking nobody, every left-out event gone, membership by GET id, the block kept for a meeting, no day 29 to 31 reason. Browser: no UI; M0 and the account-only behaviour are on the checklist.

**Review:** with Task 10; fixed I1 to I5, I7, I8 and the Minors, then the follow-up's Critical (the existence check never confirmed an Outlook deletion, now the iCalUId filter) and Important (one failed check lost the round); left a filtered answer with another item counting as kept.

---

### Task 12: Settings: tabs, new-calendar types, ICS form, sync section

**Commit:** `1638261` feat(settings): add calendar and connection tabs, a type chooser for new calendars, ics feeds and the sync section with sync now

**Files:**
- Create: `Resources/views/settings/tabs.blade.php`, `sync.blade.php`, `connections.blade.php`, `src/settings/type-chooser.ts`
- Modify: `Entities/Calendar.php`, `Services/MigrationService.php`, `Ics/IcsProvider.php` (`feedUrl()`), `Http/CalendarForm.php`, `Http/SettingsPage.php`, `Settings/CalendarsController.php`, `Http/routes.php`, `settings.blade.php`, `settings/new`, `list`, `edit`, `src/settings/main.ts`, `settings.css`, `Public/build/settings.*`
- Test: `Tests/Feature/SettingsSyncTest.php`, `Resources/frontend/tests/settings.test.ts`

**Interfaces:**
- Consumes: Task 1's `assertAllowed()`, `FakeHttp`; Task 2's `requestSync()`, `relist()`; Task 3's `IcsProvider`; Task 6's `refreshFloating()`; Task 7's `connectionsUrl()`, `PROVIDERS`, `oauth.start`; `usesConnection()`, connection statuses; core's `User::dateFormat()`.
- Produces: `Calendar::SYNC_INTERVALS`, `IcsProvider::feedUrl()`, `CalendarForm::hasLogin()`, `validateNew()` with `provider` `local|ics`, the `SettingsPage` helpers and variables, route `settings.calendars.sync`, markup hooks (`data-ljpc-type-*`, `#ljpc-sync`, `#ljpc-sync-now`, `tr.ljpc-sync-error`, `#ljpc-connection-<id>`, `td.ljpc-count`).

**What it builds:**
- Two tabs as links, "Calendars" and "Connections" (`?tab=connections`).
- "New calendar" is a type chooser with three cards and all three forms rendered (others hidden); "Continue" reloads with `?type=` without JavaScript, `type-chooser.ts` switches at once. After a failed create the posted form (`postedProvider()`) wins, and only it shows old input and errors; a post without `provider` creates a FreeScout calendar.
- The ICS form: name, color, address (http, https or webcal, max 2000) and interval (default 15 minutes). The address is stored as typed and checked through `feedUrl()` and `assertAllowed()` in a rule object (all errors in one round, nothing requested); logins are refused ("Leave the username and password out of the link. FreeScout keeps no passwords for feeds."). New feeds are due at once.
- `SYNC_INTERVALS` (1, 5, 15, 30, 60, 120, 360, 720, 1440 minutes) is shared with the v2 upgrade; labels come from the minutes ("Every minute", "Every :count minutes", "Every hour", "Every :count hours", "Once a day").
- "From a connected account" is a GET form to the pick screen listing `ok` connections, or a link to the Connections tab.
- The sync section on an external calendar: source and account (linked to its row), the address (editable for ICS), the interval, last synced in the admin's zone and clock, the escaped last error, "Sync now", and Reauthorize when the account must sign in again.
- Saving keeps a missing `sync_interval` or `url` and refuses an empty one, doesn't re-check an unchanged address, `relist()`s on a new address and `requestSync()`s on a new interval.
- "Sync now" (its own form via the HTML `form` attribute) answers "The calendar syncs within a minute."; an off calendar or a broken account gets an error, a FreeScout calendar 404; the button is disabled then.
- The list's status: "Off", the account's state with "Reauthorize", "Sync failed" with a "Last error: ..." row, "OK, synced :time" or "Waiting for the first sync" (replaces W1's text).
- The Connections tab lists the accounts (provider, account and CalDAV host, status, calendar count).
- Views print nothing nullable through `{{ }}`; read-only rows use `div.control-label`.

**Deviations:** W1's nine intervals instead of the spec's six; the address stored as typed; logins refused; no re-check of a stored address; "keep" for missing fields; `relist()` on a new address; "Sync now" explains refusals; all three forms rendered; the account path through the pick screen.

**Verification:**
- Failing run (filter `SettingsSyncTest`): `Tests: 22, Assertions: 41, Errors: 4, Failures: 17.`; Vitest could not resolve `type-chooser`.
- Filter `OK (22 tests, 233 assertions)`; full `OK (647 tests, 3892 assertions)`; Vitest 91; stable build; the settings e2e spec `1 passed`.
- Mutation checks (6): a login accepted, a stored address re-checked, no `assertAllowed()`, `postedProvider()` always local, the account state hidden, `requestSync()` instead of `relist()`.
- Browser (Tokyo): both tabs, the chooser with and without JavaScript, a blocked-host error under the address, the sync section and "Sync now", the escaped error and reauthorization rows.

**Review:** with Task 13; fixed logins stored in plain text, "OK" for an account that must sign in again, a re-check on every save, a dead branch and labels without controls.

---

### Task 13: Settings: Connections tab, CalDAV connect, OAuth connect, pick calendars, delete

**Commit:** `0f899aa` feat(settings): add the connections tab with the google and microsoft apps, caldav connect, the pick screen, shared mailboxes, deleting a connection and read-only remote calendars

**Files:**
- Create: migration `2026_10_07_000008_ljpc_calendar_v3_remote_read_only.php`, `Services/CalendarPicker.php`, `settings/apps.blade.php`, `settings/pick.blade.php`
- Modify: `Entities/Calendar.php`, `Services/EventImporter.php`, `Http/CalendarForm.php`, `Http/SettingsPage.php`, `Settings/ConnectionsController.php`, `Http/routes.php`, `settings.blade.php`, `settings/connections` (rewritten), `permissions`, `sync`, `edit`, `settings.css`, `Public/build/settings.css`, `Tests/Support/FakeProvider.php`
- Test: `Tests/Feature/ConnectionsSettingsTest.php`, `Tests/Unit/RemoteReadOnlyMigrationTest.php`, `ProviderWritesApiTest.php` (two tests), `EventImporterTest.php` (one case)

**Interfaces:**
- Consumes: `listCalendars()` and `RemoteCalendar`, `forProvider()`/`swap()`, `userMessage()`, `CalDavClient::sameResource()`, `EventImporter`, `isNamedZone()`; Task 7's credentials, redirect URL, error bags and routes; Task 12's `hasLogin()`, URL and status helpers, the sync section and accounts list; `FakeHttp`, `DavResponses`, `FakeProvider`.
- Produces: `calendars.remote_read_only`, `Calendar::isReadOnly()`, `isWritable()` honouring the flag; the importer no longer storing the calendar's writability; `CalendarPicker`; routes `settings.connections.caldav`, `.calendars`, `.delete`; `WIKI_URL`, `wikiUrl()`, `connectionsUrl(..., $owner)`, `isColor()`, `rightLabels($readOnly)`, `deleteQuestion()`; `WRITE_RIGHTS`, `timezones()`; mode `pick`; `apps` and `missing` on the tab.

**What it builds:**
- The Google panel: client id, write-only secret ("A secret is saved. Leave it empty to keep it."), redirect URL with copy, wiki link, "Connect a Google account" disabled until the app is saved. The Microsoft panel adds the tenant and the expiry date. Each fills from `old()` only when `old('app')` names it, with its own error bag.
- CalDAV connect: address (http or https, max 2000, no login: "Leave the username and password out of the address and fill them in below."), username, password; discovery runs on an unsaved connection, and only success stores it (`ok`); the same server and username update the existing connection. Errors go to bag `caldav`, the password is never flashed back, success opens the pick screen.
- The accounts table: provider, account, status (OK, Needs reauthorization, Error with the escaped message), calendar count, and "Choose calendars", "Reauthorize" (OAuth start, or a CalDAV login form: prefilled username, an empty password keeps the old one, a taken username refused, stored only after discovery works), "Add a shared mailbox" (Microsoft) and "Delete".
- Shared mailboxes: a GET form with `owner`, lowercased and validated as an email before the provider is asked.
- The pick screen: a checkbox per remote calendar; added ones ticked, disabled and linked; read-only ones marked; listing errors escaped; listing refreshes `remote_read_only` of added calendars.
- Adding lists the account again (names, colors, zones and access come from the provider), ignores unknown and added ids, errors when nothing is new. Names are stripped, cut at 180 characters, "Calendar" when empty, unique with " (2)" and onwards; colors kept as lowercase `#rrggbb` else the next swatch; zones only when `isNamedZone()`; new calendars due at once; an escaped flash names them.
- Delete removes the connection's calendars (events and occurrences cascade) and the connection in one transaction, nothing at the provider, after "Delete this connection and its calendars "A", "B"? Their events are removed from FreeScout, not from :provider." (or "Delete this connection? No calendar uses it.").
- Migration 000008 (`remote_read_only`, 000001's platform gate, idempotent, PHP 7.1); `isWritable()` is false with the flag, so agents get 403 before any provider call.
- The matrix offers only Dashboard and View for read-only calendars (`isReadOnly()`: the flag or a provider that can't write, ICS too), with "This calendar is read-only, so people can only view its events."; an account-limited calendar keeps stored write rights for later, an ICS feed drops them.
- `CalendarForm::timezones()` keeps a calendar's older zone name (Google's `Asia/Calcutta`) valid on save.

**Deviations:** `remote_read_only` (coordinator, 2026-10-09); the importer change (the first draft's etag reset raced a running sync); read-only rights; `CalendarPicker` re-listing; name, color and zone rules; old zone names; CalDAV connect rules; mailbox validation; delete wording; `FakeProvider::$listError`.

**Verification:**
- Failing run (filter `ConnectionsSettingsTest|RemoteReadOnlyMigrationTest|ProviderWritesApiTest|EventImporterTest`): `Tests: 53, Assertions: 150, Errors: 14, Failures: 13.`
- Migrate shows `Migrated: 2026_10_07_000008_ljpc_calendar_v3_remote_read_only`, the test database is rebuilt, the PHP 7.1 lint is clean.
- Filter `OK (53 tests, 383 assertions)`; full `OK (674 tests, 4162 assertions)`; Vitest 91; stable build; e2e `14 passed`.
- Mutation checks (13): the flag ignored, writability stored again, a CalDAV login accepted, the password flashed, a taken username, an unescaped flash, any zone stored, the flag not refreshed, re-adding, write rights dropped or a deleted user's kept, another form's old input, a connection deleted without its calendars.
- Browser (Tokyo, Radicale): both app panels with disabled buttons until saved; a wrong then a working CalDAV login; picking and adding a calendar, then shown ticked and disabled; the login and mailbox forms with an invalid address; a read-only matrix; the delete confirmation naming the calendar.

**Review:** with Task 12; fixed events stuck read-only by the etag repair, logins in addresses, rights lost on save, a duplicate connection after reauthorizing, the swatch preview, a deleted user's rights; left the flag flipping between showing and saving the form.

---

### Task 14: Connection health: alerts and banners

**Commit:** `bb7cb4e` feat(health): mail calendar connection problems once to administrators and show them above the settings and on the calendar page

**Files:**
- Create: `Services/HealthService.php`, `settings/health.blade.php`, `src/calendar/banners.ts`
- Modify: `Console/SyncCalendars.php`, `Http/EventPresenter.php`, `Api/EventsController.php`, `Http/FrontendConfig.php`, `Http/SettingsPage.php`, `Http/FrontendStrings.php`, `settings.blade.php`, `App.vue`, `page-config.ts`, `types.ts`, `calendar.css`, `settings.css`, `Public/build`
- Test: `Tests/Unit/HealthServiceTest.php`, `Tests/Feature/HealthBannersTest.php`, `SyncCommandTest.php` (two tests), `ProviderWritesApiTest.php` (one rewritten), `tests/banners.test.ts`

**Interfaces:**
- Consumes: `runDue()`; `appCredentials('microsoft')`; the `needs_reauth` and `last_error` Tasks 2, 7, 9 and 11 set; `usesConnection()`; Tasks 12 and 13's URL helpers and anchors (`#ljpc-app-microsoft`, `#ljpc-connection-<id>`); `isFinished()`; core's `sendAlertMail()`, `SendAlert`, `User::dateFormat()`.
- Produces: `HealthService`; `calendar:sync` calling `checkIfDue()`; the 502 `connection_needs_reauth` before any provider call; `health` in the page config; `last_synced_at`; `banners.ts`; the view `calendar::settings.health`; the e2e hooks `.ljpc-settings .ljpc-health`, `.ljpc-main .ljpc-health`, `.ljpc-main .alert-warning`.

**What it builds:**
- `connection:<id>:reauth` while a connection is `needs_reauth` or `error` (the v2 upgrade's loginless CalDAV connection), named by account, host or `#<id>`.
- `microsoft:secret` when a secret is saved and its expiry date is within 30 days or past, one key for both, in the install's zone.
- `calendar:<id>:failing` when an enabled external calendar has `last_error` and its last success (`last_synced_at`, else `created_at` converted to UTC) is over 24 hours ago, unless its account is a problem already.
- `problems()` is empty until the v2 upgrade has finished.
- `check()` mails each new key once (`sendAlertMail()`, titles "Calendar: an account needs reauthorization", "Calendar: the Microsoft secret expires soon", "Calendar: the Microsoft secret has expired", "Calendar: a calendar stopped syncing", text escaped plus the settings link), storing the keys before queueing; vanished keys are forgotten (24 hours later since the fix round); `checkIfDue()` throttles to 15 minutes.
- The settings banner (`.ljpc-health`, "These calendar problems need an administrator:" with links) shows above the tabs in every mode.
- Administrators get the problems on the calendar page in a banner they can close for that view; agents never get them.
- Agents see "Some calendars are not up to date: :names." for visible, not switched-off calendars with `sync_failed` and no success for 24 hours (or never); administrators never see it; it replaces W2's banner.
- `sync_failed` includes an account that must sign in again; `last_synced_at` is ISO UTC or null; `last_error` never leaves the settings.
- A write to a calendar whose account must sign in again answers 502 `connection_needs_reauth` to whoever may write there; otherwise 403 stays.
- `staleCalendars()` and `pageBanner()` decide the banner, with Vitest cases.

**Deviations:** extra constants and `checkIfDue()`; `error` as reauthorization; account problems covering calendars; the secret rules; the upgrade gate; keys stored before mails; UTC `created_at`; `sync_failed` with the account; the agent banner's rules; the 502 (review I2). Tests started from the stopped drafter's work and were extended.

**Verification:**
- Failing run (filter `HealthServiceTest|HealthBannersTest|SyncCommandTest|ProviderWritesApiTest`): `Tests: 37, Assertions: 90, Errors: 15, Failures: 5.`; Vitest could not find `banners`.
- Filter `OK (37 tests, 184 assertions)`; full `OK (695 tests, 4272 assertions)`; Vitest 94; stable build; e2e `14 passed`.
- Mutation checks (11): re-mailing a key, keeping solved keys, the throttle boundary, a short secret window, unconverted `created_at`, a covered calendar reported, `sync_failed` without the account, no check after the sync, problems before the upgrade, an expiry without a secret, a 403 for the reauthorization case.
- Browser (Tokyo): the settings banner above the tabs; the administrator's calendar banner closes and returns after a reload; the agent sees which calendar is not up to date, never why; Mailpit got 9 mails (3 problems, 3 administrators) and a second check none.

**Review:** with Task 15; fixed I2 (the reauthorization answer), the banner comment and a secret expiring while it is a problem; M2 (flapping problems re-mailed) was decided by Lars on 2026-10-15 for the fix round.

---

### Task 15: End-to-end suite, manual checklist, docs and spec

**Commits:** `4806c5b` test(e2e): cover ics feeds, caldav on radicale, the changed-elsewhere conflict and the health banners, and refuse a used seed; `3a559be` docs: add the w3 manual test, the google, microsoft and caldav wiki drafts, the w3 notes and the w3 decisions in the spec

**Files:**
- Create: `e2e/caldav.spec.ts`, `ics.spec.ts`, `health.spec.ts`, `global-setup.ts`; `Tests/caldav/rights`; `docs/w3-manual-test.md`; `docs/wiki/Google-Calendar.md`, `Microsoft-365.md`, `CalDAV.md`
- Modify: `Tests/e2e/seed.sh`, `e2e/helpers.ts`, `calendar.spec.ts`, `recurring.spec.ts`, `settings.spec.ts`, `playwright.config.ts`, `.gitignore`, `Tests/caldav/docker-compose.radicale.yaml`, `config`, `install.sh`, `CLAUDE.md`, the spec
- Test: the e2e specs

**Interfaces:**
- Consumes: the UI of Tasks 12 and 13 (`#ljpc-caldav`, `.ljpc-pick-list`, "Add calendars", the chooser, "Sync now"), `calendar:sync --calendar`, the 409 with its immediate sync, the banners, `HealthService::OPTION`, `assertAllowed()`.
- Produces: `e2e/helpers.ts` exports (`SEED_COMMAND`, `syncCalendar()`, `radicale()`, `icsLines()`, `radicaleCalendar()`, `radicaleObjects()`, `changeInRadicale()`, `titlesFromServer()`, `openEventId()`, `submit()`, `showWeekOf()`) and seed fields (`feedEventDate`, `failingCalendarId`); the seed's "E2E CalDAV" and "E2E failing feed"; the checklist, wiki drafts and decision-log rows.

**What it builds:**
- Radicale for the suite: ddev's router forwards port 5232 so specs use Node's fetch; a rights file gives users their own collections and opens `calendar-test/e2e-feed` for anonymous reads. `install.sh` prints the whitelist with docker's whole private address pool (ddev changed its subnet after a restart) and says `config:clear`, never `freescout:clear-cache`.
- The seed checks that FreeScout reaches Radicale (else stops with the line to add), recreates `e2e`, `e2e-connect` and `e2e-feed` (one feed event a week ahead), creates the CalDAV calendar and a failing feed, resets the agent's and the e2e mailbox's permission rows, removes old line items, and marks the health check done and the feed mailed, keeping the dev install's own memory.
- `global-setup.ts` refuses a used seed (marker `e2e/.seed-used`) or an outdated one; `helpers.ts` explains a missing seed.
- `caldav.spec.ts` reconnects the seeded account, adds "E2E connect", creates, edits and deletes an event checked in Radicale, and gets "This event was changed elsewhere. Reload and try again." after a change behind FreeScout's back.
- `ics.spec.ts` creates a feed, clicks "Sync now", runs `calendar:sync` through `syncCalendar(id)` (shells into ddev), walks to the event with `showWeekOf()` and checks it is read-only.
- `health.spec.ts`: the administrator sees the failing calendar in both banners; the agent learns which calendar, never why.
- Deletes are re-checked after a reload through the permalink and the events API.
- `docs/w3-manual-test.md`: M0 (HIGH, Graph's iCalUId filter), G1 to G25, M1 to M27, C1 to C7, X1, H1, each with expected result and "If not", every UNVERIFIED behaviour marked, and a "Where to look" list.
- Wiki drafts matching `wikiUrl()`: Google (Testing mode's 7 days, Workspace Internal), Microsoft (single-tenant tenant id, secret renewal), outlook.com through ICS, the iCloud app-specific password, the UID change on a same-account move, no block on meetings, the container whitelist line.
- `CLAUDE.md`: Radicale, `calendar:sync`, the health mails, the test support, and the W3 gotchas.
- The spec: sections 8 and 9 rewritten, section 4 names `HealthService`, section 24 points to W3's answers, 31 decision-log rows with "instead of" for superseded statements in sections 5, 7, 15 and 20.

**Deviations:** Radicale reachable from the host with a rights file; the whitelist documented, not written; the test-only sync helper; the extra seed resets; the fresh-seed guard; a relative feed date; the reconnecting CalDAV spec; one decision-log row marked "to confirm", confirmed on 2026-10-15.

**Verification:**
- Failing run: with Task 14's seed the global setup stops: "This seed is older than the suite. Seed again: ...".
- e2e `19 passed (49.7s)`, re-seeded, `19 passed (49.7s)` again (Tokyo browser); a run without a new seed stops with "An earlier run used this seed, and its events are still there. ..."; after a run the e2e conversation holds one line item, and a seed removes it.
- `npm run check`: Vitest 94 (vue-tsc covers the e2e files); PHP unchanged at `OK (695 tests, 4272 assertions)`.

**Review:** with Task 14; fixed I1 (a recurring-spec assertion that would fail on about 12 days in the next five weeks), the seed's health memory and mailbox rows, checklist pointers and steps, the CLDR note's path, a wiki sentence, two missing decision-log rows and the outdated-seed guard.

---

## Left open for the final review

Minor items from the ledger and the drafter reports, deduplicated. None blocks W3; the whole-branch review decides which to fix.

**Engine and HTTP:**
- The ICS 20 MB limit counts compressed bytes.
- DNS rebinding between the host check and the connection (the same gap as core).
- A unique-key failure on a provider UID collision is reported per round, not resolved.
- The sweep's `updated_at` guard is ambiguous in the repeated hour of a DST change (`_listing_started` is in the install's zone).
- Import failures in incremental rounds are not retried and the token moves on; a permanently unreadable CalDAV object is only logged, not shown in `last_error`.
- `relist()` can race a running sync, which may overwrite it until the next run.

**CalDAV:**
- A null etag means an unconditional PUT.
- A 403 without a precondition, or a missing token, switches a calendar to ctag mode until `relist()`.
- Digest authentication costs an extra probe for every request with a body.
- `newUid()` could move into `EventIcs`.

**OAuth and time zones:**
- Tokens are not revoked after a refused sign-in.
- The export keeps one VTIMEZONE per TZID name.
- The zone validation error comes in a second validation round.
- The warn-once TZID memo lives as long as a long-running worker.

**Google:**
- Stored exceptions outside the occurrence window are lost when a series is read again.
- A series rename doesn't reach separately edited occurrences until Google reports them.
- A description edited in Google for one occurrence can be overwritten by FreeScout's next edit of it.
- One extra `events.instances` call per changed stored series in incremental rounds, including the echo of FreeScout's own series writes.

**Microsoft:**
- The "rule and Graph disagree" warning has no `calendar_id`.
- A filtered iCalUId answer that returns some other item counts as kept.
- An instance deleted between two `/instances` pages shows as deleted until the series is read again.
- Exceptions and deletions outside the occurrence window are only seen through the weekly pass.
- All-day lookups can miss when the calendar's zone and the series' zone are more than 12 hours apart.
- An occurrence or exception moved in from outside the window can switch off the deletion inference for that read.

**Settings:**
- If `remote_read_only` flips between showing and saving the calendar form, Create and Edit changes are ignored or stored rights wiped.
- Migration 000008 has 000001's platform gate and 000007 doesn't; pick one pattern.
- The pick screen's listing writes `remote_read_only` during a page view.

**Copy:** the new strings are English only; translations are W5.

Decided and no longer open: the room-only attendee, the CalDAV 401, flapping health problems and the concurrent health check (2026-10-15, built in the fix round), and core's `checkUrlHost()` (not reported upstream).

---

## Manual verification

- `docs/w3-manual-test.md` is the live test Lars runs before the release with real Google and Microsoft accounts and real CalDAV servers. Start with M0 (HIGH): if Graph doesn't filter a calendar's events by iCalUId, every Microsoft deletion is kept forever, safely but silently. Then G1 to G26, M1 to M28, C1 to C7, X1 and H1; each item says what to do, what to expect and where to look when it fails.
- The wiki drafts in `docs/wiki/` (`Google-Calendar.md`, `Microsoft-365.md`, `CalDAV.md`) are what an administrator follows to create the apps and connect accounts; W5 publishes them at the URLs `SettingsPage::wikiUrl()` links to. Following them is part of the manual test (G1, M1).
- Local setup for both: Radicale (`Tests/caldav/install.sh` and the whitelist line it prints), `calendar:sync` by hand, and the alert mails through the `emails` queue, as `CLAUDE.md` describes.

## After this plan

- Lars's 2026-10-15 decisions were built on `v3` right after the fast-forward, in `9f66fe0..3320b14`: a CalDAV login the server finally refuses (401, during a sync or a write) marks the connection for reauthorization at once; a solved health problem is forgotten only after 24 hours without it; the health check claims its stored result atomically, so two sync runs that check at once mail a new problem only once. The spec's decision log has the rows.
- The CLDR generator for `WindowsZones` is `Tests/tools/windows-zones.php`.
- The whole-branch review of `af41f8c..6765f80` was fixed in `f55b455..f822283`: one commit per item of its fix list (C1 to C3, I1 to I5, M1 to M20, the docs), then the fixes of an independent re-review of that round (the sweep's DST cut-off, the all-day switch, anchors only in v2 YAML, the Graph body guard, the CalDAV username and the ICS address); the manual test gained G26 and M28.
