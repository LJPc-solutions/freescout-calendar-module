# LJPc calendar module for FreeScout

## Branches and plans

- `main` holds the released line: 3.0.0 since 2026-10-23, released as one squashed commit on top of 2.6.4.
- The local branch `v3` keeps the rewrite's 218 commits; it was squashed into `main`, not pushed (a local pre-push hook still blocks it).
- Design: `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`. Research, with sources: `docs/superpowers/specs/2026-10-05-calendar-module-v3-research.md`. The design's decision log is final unless Lars reopens a decision.
- Live tests with real accounts: `docs/w3-manual-test.md` (Google, Microsoft, CalDAV servers) and `docs/w4-manual-test.md` (invites with real mail services).
- The wiki is published from `docs/wiki/`: clone `https://github.com/LJPc-solutions/freescout-calendar-module.wiki.git`, copy the pages in, commit and push.

## Local development

- FreeScout core is the parent checkout `~/Projects/freescout`. It runs as the ddev project `freescout` at https://freescout.ddev.site.
- The local core is 1.8.245 (v3 needs 1.8.243 or newer).
- Run artisan inside the container: `cd ~/Projects/freescout && ddev exec 'php artisan …'`.
- `tinker --execute` is not supported. Pipe a script instead: `ddev exec 'echo "…" | php artisan tinker'`.
  Tinker runs each complete line on its own and carries on after an error. Wrap a longer script in `(function () { … })();` so a failure stops it (see `Tests/e2e/seed.sh`).
- The local core's `config/filesystems.php` had no `local_app` disk (where attachments live) until 2026-10-19, when it was added as on the production installs. Without it `Attachment::create()` fails, in tinker too.
- Starting ddev just to check something? Run `ddev stop` afterwards, because the project is normally stopped.
- The module is active and migrated to v3 in the local database. Dev admin: `dev-calendar@example.com` (user 2).
- FreeScout autoloads `Modules\\<Name>\\` from `Modules/<Name>/`, so the module only boots through the symlink `~/Projects/freescout/Modules/LJPcCalendarModule -> freescout-calendar-module` (outside the repo, already in place). FreeScout still registers the module once.
- Activate the module from the CLI (`\\App\\Module::setActive('ljpccalendarmodule', true)` in tinker, then `php artisan freescout:module-install ljpccalendarmodule`), not through the web UI. Since 1.8.240 the UI renames the module folder on activation.
- Local test data for the calendar page:
  - Calendars "Support" (a dropdown and a boolean field) and "Read only".
  - `agent-ny@example.com` (user 3): America/New_York, 12-hour clock, edits Support and only views Read only.
  - `agent-empty@example.com` (user 4): no calendars.
  - Event 9 has `legacy_id` 300, so `/calendar#300` tests a v2 permalink.
  - `/calendar#e<id>@<recurrence key>` opens one occurrence of a repeating event (`#e12@20261014T080000Z`, or `#e12@20261014` when all-day). The dashboard card and "Copy link" write that form.
- The dev and test databases are migrated through 000010. After a new migration, run `ddev exec php artisan migrate` and rebuild the test database (`Tests/setup-test-db.sh`).
- Sync by hand: `ddev exec php artisan calendar:sync [--calendar=<id>] [--force]` prints the counts.
  - `--calendar` alone only syncs that calendar when it is due; `--force` syncs it anyway.
  - "Sync now" (settings cards, edit page, calendar page sidebar) syncs in the request through `SyncService::syncByHand()` (20 s budget) and says what changed. A calendar that synced less than 30 s ago is left alone (`RECENT_SECONDS`), so agents' clicks never spend a provider's quota.
  - A calendar just added gets its first sync from the page itself (`SettingsPage::syncStartIds()` from the session, `settings/sync-now.ts`), which then asks `settings/calendars/status` every 3 s until it is done.
  - The health check runs after the sync, at most every 15 minutes (option `ljpccalendarmodule.health`; remove it to check at once).
  - Its alert mails are queued on `emails`: `ddev exec php artisan queue:work --once --queue=emails` sends one, and Mailpit shows it (`ddev launch -m`, or `http://localhost:8025/api/v1/messages` inside the web container).
- Radicale, a local CalDAV server for the tests and the e2e suite (never shipped):
  - Install it with `bash Modules/freescout-calendar-module/Tests/caldav/install.sh` from `~/Projects/freescout`. It copies `Tests/caldav/` into `.ddev/` and restarts ddev.
  - Inside ddev the server is `http://radicale:5232`, user and password `calendar-test`. The host reaches it through ddev's router at `http://freescout.ddev.site:5232`.
  - `Tests/caldav/rights`: every user owns their collections, and `calendar-test/e2e-feed` is readable without a login (the e2e suite subscribes to it as an ICS feed).
  - FreeScout only reaches it with `APP_REMOTE_HOST_WHITE_LIST=radicale,172.16.0.0/12` in `.env` (in place locally). Core checks the address a host resolves to, so the hostname alone is refused, and ddev may give its network another subnet when it creates it again (172.28.0.0/16 and 172.21.0.0/16 seen; `install.sh` reads the current one with `docker network inspect`).
  - After editing `.env`, run `ddev exec php artisan config:clear`. Never `freescout:clear-cache` locally: it caches the config, and the module tests then refuse to run.
  - Use `ddev exec --raw -- curl ...` for curl calls, so quotes survive. Radicale answers DELETE with 200.
  - A second collection: `ddev exec --raw -- curl -s -o /dev/null -w '%{http_code}\n' -u calendar-test:calendar-test -X MKCALENDAR -H 'Content-Type: application/xml; charset=utf-8' --data '<?xml version="1.0" encoding="utf-8"?><C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop><D:displayname>Team planning</D:displayname></D:prop></D:set></C:mkcalendar>' http://radicale:5232/calendar-test/team/` (expect `201`).

## Browser checks

- Run them in Playwright with `timezoneId: 'Asia/Tokyo'`, so the browser zone differs from every profile zone.
- Keep ad-hoc Playwright helper scripts in a scratch directory outside the repo.
- The local debugbar covers the bottom 61 px and puts an inline `margin-bottom` on `<body>`. Remove both (`.phpdebugbar` and that margin) before you measure the layout.
- Headless Chromium hides scrollbars. Launch it with `ignoreDefaultArgs: ['--hide-scrollbars']` to see the page scrollbar.
- `page.goto()` to the same path with only a new hash doesn't reload; it fires `hashchange`. To test a permalink on a fresh load, open `about:blank` first.
- FullCalendar keeps off-screen measuring copies of events and "+N more" links. Click an event through its `[role=button]` element, and pick a "+N more" link by its on-screen position.
- The week view's title shows only the month ("October 2026"). To detect navigation, compare the `[role=columnheader]` texts instead.
- The view tabs are named "Day view", "Week view" and so on: `getByRole('tab', { name: 'Day view' })`.
- FreeScout's conversation page holds hidden Bootstrap `.modal`s of its own. Scope to ours with `.ljpc-modal .modal`.
- Playwright doesn't route the target of a redirect. Read the `Location` header with `page.request.get(url, { maxRedirects: 0 })`.
- The local debugbar's request dump shows posted form values, secrets included. To check that a secret never reaches the HTML, look in `.ljpc-settings`, not the whole page.
- `page.mouse` never scrolls. To drag something below the fold (the settings' field handles), use `locator.dragTo(target, { steps })`.

## Tests and migration rehearsal

- One-time setup (and after schema changes): `ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db.sh`.
  This builds `freescout_test`, copies the `modules` table so the module boots, and restores the empty v2 tables.
- Run the tests: `ddev exec php artisan config:clear && ddev exec 'cd Modules/freescout-calendar-module && php ../../vendor/bin/phpunit'`.
  `Tests/bootstrap.php` refuses to run while the config is cached, because the tests would hit the dev database.
  - The CalDAV integration tests (`@group caldav-server`) skip without Radicale; the suite's counts in the plans assume it runs.
  - Never run two phpunit processes at once: the migration tests run DDL on the shared test database.
  - `ddev exec` without a TTY prints PHPUnit's output on stderr. Capture both streams (`2>&1`) in scripts.
- Test support in `Tests/Support`:
  - `FakeHttp::install()` replaces `HttpClientFactory`: queue responses, read the request history. Install it before anything resolves a provider.
    - Queue a new Response object for every request. Clients built with a body limit (ICS, CalDAV) give every request a capped `sink`, which Guzzle's `MockHandler` fills from the queued body, and Guzzle closes the body of a digest challenge it answers, so a Response queued twice fails with "Stream is detached".
    - A race with another request (a double-click, a sync landing mid-save) is simulated with `Event::listen(TransactionBeginning::class, ...)`, or with a queued callable that changes the database before it answers.
  - `Integration/DecodedBodyCapTest` starts PHP's built-in server on a random port (it skips when that fails) to send gzip bodies: only a real curl handler inflates them.
  - `DavResponses` builds CalDAV answers; `FakeProvider` scripts pull and write results and logs the calls (swap it in with `ProviderRegistry::swap()`); `LogRecorder::install()` keeps log records (core's Mockery can't spy on `Log` under PHP 8.3).
  - Alert mails: `Bus::fake()`, then `Bus::dispatched(\App\Jobs\SendAlert::class)`.
  - A test that runs a mail job (`SendReminder`) calls `\MailHelper::setSystemMailDriver()` before `Mail::fake()`: core swaps the Mail facade whenever the mail config changes, which would replace the fake. `Mailable::render()` needs the real mailer, so render `view($mail->view, $mail->viewData)` after `build()`.
  - A test that creates threads can't use `Bus::fake()` (core's `RealtimeConvNewThread` asks the bus for a command handler): use `Queue::fake()` and `Queue::assertPushedOn('emails', \App\Jobs\SendReplyToCustomer::class)`.
  - A test that sends a reply needs a conversation with a customer: core's `SendReplyToCustomer` listener reads `$conversation->customer`.
  - Invites: `Tests/Support/InviteMail` makes a customer's thread with invite files (fixtures in `Tests/fixtures/invites/`); a test runs core's hooks itself (`\Eventy::action('thread.attachments_list_append', ...)` for the card, `\Eventy::filter('conversation.customer_replied', ...)` for new mail).
- Laravel 5.5 in tests:
  - `TestResponse::json()` takes no key, and `assertJson` fails on PHPUnit 9: use `json()[...]` and `assertJsonFragment`.
  - There is no `assertSessionHasNoErrors()`: use `assertSessionMissing('errors')`. `assertSessionHasErrorsIn($bag, ...)` exists.
  - `Validator::validate()` returns nothing.
  - A guarded `fill()` or `update()` silently drops attributes that aren't columns, so a missing column doesn't show through it.
  - `TestCase` already has `json()` and `instance()`, and PHPUnit's `Assert` has `callback()`: test helpers can't use those names.
- PostgreSQL (local only, in memory): `Tests/postgres/docker-compose.postgres.yaml` is copied into FreeScout's `.ddev` (in place since 2026-10-23), so `ddev start` also starts PostgreSQL 16 at `postgres:5432` (user and password `rehearsal`).
  - The tests run on it too. The local core's `config/database.php` reads the `testing` connection's driver, port and charset from `DB_TEST_DRIVER`, `DB_TEST_PORT` and `DB_TEST_CHARSET` (a local change, 2026-10-23). Build the database with `ddev exec bash Modules/freescout-calendar-module/Tests/setup-test-db-pgsql.sh`, then run phpunit with `DB_TEST_DRIVER=pgsql DB_TEST_HOST=postgres DB_TEST_PORT=5432 DB_TEST_DATABASE=freescout_test DB_TEST_USERNAME=rehearsal DB_TEST_PASSWORD=rehearsal DB_TEST_CHARSET=utf8` in front of it (PHPUnit's `<env>` keeps variables that are already set). On 2026-10-23: 830 tests, 2 skipped (MySQL's zero dates).
- Rehearse the v2 migration on a masked dump: copy it to `~/Projects/freescout/storage/app/rehearsal.sql` (mode 600), then run
  `ddev exec bash Modules/freescout-calendar-module/Tests/rehearse-migration.sh /var/www/html/storage/app/rehearsal.sql`.
  Delete the copy afterwards. The private notes say which dumps exist.
  - The script logs to stderr (`APP_LOG=errorlog`), so dump titles never land in `storage/logs`. Capture both streams (`2>&1`) when you keep its output.
  - It adds the `threads` columns that modules on the source install created (ratings, translations and so on) to the scratch database before the import.
  - A dump that is not valid SQL stops the run at the import. Fix the dump script and dump again; never edit a dump by hand.
  - Add `pgsql` after the dump to rehearse on PostgreSQL: the dump goes into the MySQL scratch database, is copied into a PostgreSQL scratch database, and the v3 migrations run there. On 2026-10-23 all three dumps gave the same counts and report on both.

## Frontend

- Sources live in `Resources/frontend` (Vite, TypeScript, Vue for the calendar page and the conversation modal, a plain script for the settings pages). The build goes to `Public/build/`, which is committed: installs have no Node.
- This shell sets `NODE_ENV=production` and npm `omit=dev`. Always install with `npm ci --include=dev` (or `npm install --include=dev`), or the build tools are missing.
- `npm run check` (vue-tsc and Vitest) and `npm run build` in `Resources/frontend`. Commit `Public/build` with the sources; CI rebuilds and fails when they differ.
- TypeScript stays on 6.x: vue-tsc 3.3 does not run on TypeScript 7 yet.
- Bundles load through `Http/Assets.php` as ES modules with the CSP nonce. Never add them to the `javascripts` filter.
- Never style FullCalendar 7 through `.fc-*` class names: they are hashed. Use the `--fc-classic-*` variables and our class hooks (`eventClass`, `popoverClass`).
- No `<style>` blocks in Vue components: each entry imports one CSS file, so the build emits exactly one CSS file per entry.
- The calendar page uses FreeScout's own sidebar column (`@section('sidebar')` in `index.blade.php`); `CalendarSidebar.vue` teleports into it with core's classes (`.sidebar-title`, `.sidebar-menu`, `.sidebar-buttons`). Core's jQuery menu button binds before Vue renders, so the component opens and closes the menu below 992px itself.
- The dashboard card renders in its own `.dash-cards` row and a nonce'd inline script moves it to the front of the mailbox cards (`partials/dash_card.blade.php`); outside that row core's floating card broke the dashboard on phones.
- End-to-end tests (Playwright, against https://freescout.ddev.site): `ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh`, then `npm run e2e` in `Resources/frontend`. On a fresh machine (or after a Playwright upgrade), run `npx playwright install chromium` in `Resources/frontend` once first. The seed creates `e2e-admin@example.test` and `e2e-agent@example.test` (password in the script) in the dev database only; it refuses to run outside ddev. The browser runs in Asia/Tokyo and the agent's profile in America/New_York, so any use of the browser's timezone fails the suite.
  - Seed before every run. The suite leaves its events behind, and a second run on the same seed would find every title twice, so the global setup (`e2e/global-setup.ts`) refuses a seed an earlier run used (marker `e2e/.seed-used`, which the seed removes).
  - The CalDAV, ICS and health specs need Radicale and its `.env` line; the seed checks both. It empties the Radicale collections `e2e`, `e2e-connect` and `e2e-feed`, puts one event a week ahead in the feed, and seeds a CalDAV account with the calendar "E2E CalDAV" and a feed that has failed for two days.
  - The specs reach Radicale with Node's fetch (`radicale()` in `e2e/helpers.ts`). They never run `calendar:sync`: the pages sync calendars themselves (a first sync after adding, "Sync now").
  - Times are shown the way FreeScout core shows them (`formatClock` in `shared/format.ts`, PHP's `h:ia` or `H:i`). On the agent's 12-hour clock, event chips read "10:00am – 11:00am" and the time axis "02:00pm". The details put the full date in front: "Monday, November 16, 2026, 10:00am – 11:00am". A 24-hour clock (the e2e admin's) reads "10:00 – 11:00".

## Real installs and test data

- **This repo is public.** Never commit hostnames, IPs, client names, credentials, or details of unpatched vulnerabilities.
- **Private material lives outside the repo** in `~/Projects/freescout-calendar-v3-testdata/` (mode 700):
  - `PRIVATE-NOTES.md`: real installs (hosts, access routes, statistics) and the v2 security findings.
  - The masked dumps of the calendar tables.

  Never commit any of it, and delete the folder after the 3.0 release.
- **Read-only access to real installs is fine.** Never write to them without Lars's explicit consent.
- **Two real installs run a privately maintained fork, 2.7.3,** not the public 2.6.4. It stores `display_priority` inside `calendars.custom_fields`.
- **Dumps on production:** always add `--single-transaction --skip-lock-tables`. The default `--lock-tables` blocks writes to `threads` during a full scan, which takes about 45 s on a 700k-thread install. Filter `threads` with a JOIN, never with `IN (subquery)`.
- **Reading the DB password from `.env`:** don't `source` the file, because passwords with special characters break. Use `export MYSQL_PWD="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"` and then `mariadb -h localhost -u freescout freescout`.

## Invites (W4)

- The card below a customer's message hooks into `thread.attachments_list_append`, which core calls inside the attachments' `<ul>`, so the card is an `<li>` (`partials/invite_card`, `Http/InviteCard`). Its styles are in `Public/css/module.css`; `Public/build/invite.js` posts its forms (`Api\InvitesController`).
- New mail is read in the filters `conversation.created_by_customer` and `conversation.customer_replied` (`InviteReplies`), before core saves the conversation; `conversation.status_changing` remembers the status, so an answer to our invite keeps it.
- Our calendar replies (answers, invites, updates, cancellations) are threads made by `ItipMail::send()`; the thread's meta `ljpccalendarmodule.itip` holds the iTIP message, which the `email.reply_to_customer.swiftmessage` hook adds inline and `email.reply_to_customer.subject` uses for the subject. Core sends them after its undo delay; run `queue:work --once --queue=emails` and look in Mailpit.
- `calendar_events.attendees` holds the people we invited and their answers. It is the source for every mail and for the readable block of Google and Microsoft events, which never get attendees, so a sync leaves it alone.
- The e2e seed makes a conversation "E2E invite" anew on every seed, with an invite for the mailbox (`invites.spec.ts`).

## Reminders (W5)

- `calendar:sync` runs `ReminderService::scan()` first, before the sync, which may take its whole budget. The option `ljpccalendarmodule.reminders` holds the last scan (UTC); the first scan only starts the clock.
- A reminder goes to the author and the linked conversation's assignee who can view the calendar, once per occurrence and person (`calendar_reminder_log`, unique, rows pruned two days after the occurrence). It goes as `Jobs\SendReminder` on `emails` (`Mail\ReminderMail`, the agent's zone, clock and language).
- An occurrence that has started gets no reminder, and neither does an all-day one whose day is over. An all-day event reminds at 09:00 in the calendar's zone, whole days before.
- Local check: create an event whose reminder falls a minute ahead, run `calendar:sync` before and after that minute, then send the job. The dev queue holds reply jobs from earlier e2e runs that fail and come back with a delay, so `queue:work --once` may not reach the reminder. Run the job from tinker instead: unserialize `json_decode($row->payload, true)['data']['command']` from `jobs`, call `handle()`, and delete the row. Mailpit then shows the mail.

## Gotchas

- **`getenv()` returns false for `.env` values in production.** FreeScout caches the config (`freescout:clear-cache` runs `config:cache`), and then `.env` is never loaded. Always use `config()` for `.env` values.
- **Never bundle a package that core also ships.** The module's Composer autoloader is prepended in `boot()`, so its copies win over core's (Guzzle, PSR-7, psr/*, symfony, Carbon, monolog, ramsey/uuid…). Core moved to Guzzle 8 and psr/http-message 2 in 1.8.232.
- **CSP rules for scripts.**
  - Script tags need `{!! \Helper::cspNonceAttr() !!}`. `unsafe-inline` and `unsafe-eval` are stripped.
  - Never print Blade output inside JS; `{{ }}` escapes for HTML, not for JavaScript. Pass data as `<script type="application/json">` instead.
- **Calendar invites arrive as attachments.** FreeScout stores incoming `text/calendar` parts as attachments named `calendar.ics`, and the MIME `method=` parameter is lost. Gmail also adds `invite.ics`, so the same invite shows up twice.
- **Read attachment content with `Attachment::getFileContents()`.** Since 1.8.242 attachments may live on S3, so don't use local paths.
- **Every pushed tag ships to all users.** `.github/workflows/release.yml` zips the repo for each tag pushed and uploads `latest.zip` and `version.txt`, which every install auto-updates from. Don't push test tags.
  - The upload goes over FTP to `resources.ljpc.network` (the `FTP_SERVER` secret since 2026-10-23; the old host stopped resolving). The workflow does not create the GitHub release: `gh release create <tag> --notes-file ...`.
  - Docker Hub sometimes answers 500 when the PHP 7.1 lint pulls `php:7.1-cli`. Nothing is uploaded then; rerun with `gh run rerun <id> --failed`.
  - A release candidate is built locally in a clean clone with the same checks (lint, `check-shared-packages.php`, frontend build compared, `build-release.sh <tag>`) and tested on Lars's own install.
- **Core views throw in module tests on PHP 8.3, unless FreeScout's null-safe `e()` is loaded.** The conversation page passes `null` to Laravel's `e()`, and the test error handler turns that deprecation into an exception. Production defines a null-safe `e()` (`$value ?? ''`, `doubleEncode = false`) in `public/index.php` and `artisan` before the autoloader, but PHPUnit's own autoloader defines Laravel's first, so `Tests/bootstrap.php` can't replace it. `Tests/null-safe-e.php` holds production's version; run `ddev exec 'cd Modules/freescout-calendar-module && php -d auto_prepend_file=/var/www/html/Modules/freescout-calendar-module/Tests/null-safe-e.php ../../vendor/bin/phpunit'` to render core views like production does (checked 2026-10-06: the conversation page then renders with status 200 for its agent). The plain command still works for everything else; a plain run keeps throwing on core views that print `null`, so write module views null-safe anyway (`SettingsPage::oldString()`).
- **Core's `\Helper::checkUrlHost()` refuses every URL with a port or a username** (its `normalizeIPv6InUrl()` wraps `host:port` in brackets). `HttpClientFactory::assertAllowed()` checks `scheme://host/` instead. Never use `\Helper::getRemoteFileContents()`: it logs the full URL, and an ICS URL can be a secret.
- **Guzzle 8** rejects string timeouts (`.env` values are strings, so cast them to float), and does digest authentication itself (curl's `CURLAUTH_DIGEST` option doesn't work through it).
- **Write events through Eloquent.** The generation sweep spares rows whose `updated_at` falls after the listing started; a query-builder update that skips `updated_at` can be swept away during a full listing.
- **Time zones of calendars and rules:** anything that changes `calendars.timezone` calls `OccurrenceService::refreshFloating()`. Compare and show rules in `EventIcs::ruleZone()`.
- **Never call `ConnectionService::accessToken()` inside a database transaction.** Its row lock would last until that transaction ends, and a rollback would also undo `needs_reauth`.
- **Eloquent timestamps are in the install's zone** (`APP_TIMEZONE`, Europe/Amsterdam locally); the module's own sync columns (`last_synced_at`, `next_sync_at`) are UTC. Convert before comparing them (`HealthService`).
- **`last_error` is for administrators in the settings only.** Agents get `sync_failed` and `last_synced_at` (`EventPresenter::calendar()`).
- **zsh doesn't word-split unquoted variables** (`set -- $p`, `$cmd args`). Use `${=p}`, or run the script in bash.
- **The CLDR map in `WindowsZones`** is regenerated with `Tests/tools/windows-zones.php` (needs intl, so run it in ddev; the commands are in its header). For CLDR 48.2 it reproduces the committed constants exactly.
- **Store a calendar's zone only when `VTimezone::isNamedZone()` accepts it** (providers report offsets and Windows names too).
- **Escape flash messages that carry a name with `e()`:** core prints floating flashes through `safe_raw_html()`.
- **The events list loads only some columns** (`Api\EventsController@index`). A column `EventPresenter::occurrence()` reads must be in that list too.
- **A model from `create()` or `firstOrCreate()` lacks the table's defaults.** Set a column the code reads right away (`CalendarInvite::status`) explicitly.
- **Laravel 5.5 has no `Schema::hasIndex()`.** Migrations ask Doctrine's schema manager (core ships doctrine/dbal), as 000007 does.
- **Translations:** Dutch, German, French and Spanish translate every string; the other 17 languages fall back to English (`Tests/Unit/TranslationsTest` checks both directions and the placeholders). Dutch addresses the agent with "je", the others follow core ("Sie", "vous", "usted"). Core's JSON translations win over the module's for the same English key (Laravel loads the module's paths first and the app's own last), so a word core translates in another sense needs a key of its own: "On date", not "On" (core: "Aan"); "User name", not "Username" (core's Dutch: "Gebruikers").
- **PostgreSQL aborts a transaction after any failed statement.** An insert that may hit a unique key (the reminder log, the health option) gets a `DB::transaction()` of its own, which becomes a savepoint inside a caller's transaction. v2's `dateTimeTz` columns are `timestamp with time zone` there and read back with an offset (`2023-01-12 16:45:00+00`).
- **Line item sentences start with `:person`.** On the conversation page core fills it in with a rendered view that has whitespace around the name, so "by :person." reads "by you .". (The v2 sentence "Added to calendar ... by :person." keeps it for its translations.)
- **Every file in `Database/Migrations`, the service provider and `start.php` must parse on PHP 7.1** (core's minimum). Old servers load them before any guard can act: the provider and `start.php` on every request, and Laravel's migrator requires every migration file before it runs the first one, whose refusal (000001) needs the provider. Keep them free of PHP 7.4+ syntax (arrow functions, typed properties, `mixed`, `never`), or old servers get a parse error instead of the paused notice or the refusal. The release workflow lints exactly these files in `php:7.1-cli`.
