# LJPc calendar module 3.0: design

- **Date:** 2026-10-05
- **Status:** design approved in brainstorm, awaiting written-spec review
- **Branch:** `v3`
- **Background research:** [2026-10-05-calendar-module-v3-research.md](2026-10-05-calendar-module-v3-research.md)

## 1. Why a 3.0

- **FreeScout has moved on:**
  - Latest is 1.8.245 and PHP 7.4 is now the minimum.
  - 1.8.232 brought Guzzle 8 and PSR-7 3.
  - A stricter CSP.
  - A remote storage API (1.8.242).
  - Module folders are renamed on activation (1.8.240).
- **Microsoft 365 can only be reached through Graph.** EWS has been off by default since 2026-10-01 and Exchange Online has no CalDAV. Google CalDAV no longer accepts passwords. Users are asking for Google and Microsoft calendars (#64, #69, #44, #61).
- **Toast UI Calendar is archived** (last release 2022-08) and has open DST bugs.
- **v2.6.4 needs security hardening and has data integrity bugs.** The security findings are tracked privately until 3.0 ships; the requirements are in section 18.
- **Open requests:** #83 (invite handling), #42 (calendar per mailbox), #46 (list and year views), #47 (invite participants).
- **No competitor:** there is no other calendar module in the FreeScout ecosystem.
- **A privately maintained fork exists.** Version 2.7.3 (based on 2.6.4, released 2026-07-27) runs on LJPc's own mailbox and on a client's install. It points to the same `version.txt`, so it auto-updates to 3.0.0. 3.0 must migrate its data and keep its features (research section F).

**Release form:** one 3.0.0 release containing everything below, with no public beta. It is built in five workstreams (section 23).

## 2. Scope

### In scope for 3.0

1. **Rewrite:**
   - The data model, with iCalendar as the source of truth.
   - One authorization layer.
   - Parity with every v2 feature (the inventory is in the research file, section A2).
2. **Calendar types:**
   - `local`, with recurrence.
   - `ics`, read-only.
   - `caldav`, `google` and `microsoft`, all with two-way sync.
3. **Connecting accounts:** the admin connects an account per calendar, using their own OAuth app. The token endpoints are configurable so a future LJPc relay can plug in.
4. **Microsoft shared mailboxes and room calendars** through delegated `Calendars.ReadWrite.Shared`.
5. **Private external events** are stored as "Busy", without details, and are read-only.
6. **Frontend:** FullCalendar 7.1.x with day, week, month, year and list views, rendered in the agent's FreeScout timezone.
7. **Permission rows** for users, teams and mailboxes (#42). A user's rights are the union of all rows that apply to them.
8. **Incoming invites (#83):**
   - An invite card on the conversation.
   - The agent applies a new invite, update or cancellation with one click, including for single occurrences.
   - RSVP for the whole series.
9. **Outgoing invites (#47):** only for events linked to a conversation. The mailbox is the organizer, and REPLY messages are processed automatically.
10. **Agent reminders:** sent to the creator and to the assignee of the linked conversation.
11. **Custom field values** are visible in every client through a readable block in the description.
12. **Export links:** new random, revocable export tokens.
13. **Migration** from v2 that converts tables in place and produces a migration report.
14. **Translations and docs:** all 21 translations updated, and setup guides on the GitHub wiki.
15. **Domain and author:** `ljpc.nl` replaces `ljpc.solutions` everywhere, and the author name becomes "LJPc".
16. **Features of the 2.7.x fork:**
    - A display priority per calendar (lower renders first).
    - The full event title on hover.
    - A collapsible calendar list, remembered per browser.
    - A compact month view whose "+N" popover grows to show every event.

### Out of scope for 3.0

- **The paid LJPc OAuth relay.** It comes after 3.0, once it has been validated through the product process.
- **Webhooks and push notifications.** 3.0 polls through cron.
- **Microsoft app-only access** (application permissions with Exchange RBAC).
- **Google domain-wide delegation.**
- **Applying incoming invites automatically.** Agents always click. Incoming REPLY to our own invites is the exception.
- **RSVP for a single occurrence.**
- **Sending invites from the calendar page** when the event has no conversation.
- **"This and following" edits.** Recurring edits are either "this occurrence" or "whole series".
- **Personal outlook.com accounts.** The docs point these users to an ICS publish link.
- **Resource views** (one column per user).
- **Backup tables** of v2 data. The user must back up the database first.

## 3. Platform requirements

- **Supported versions:** PHP 8.2 or newer, FreeScout 1.8.243 or newer, and MySQL, MariaDB or PostgreSQL.
- **Version checks:**
  - `module.json` sets `requiredAppVersion: 1.8.243`.
  - The service provider checks `PHP_VERSION_ID >= 80200` **before** it requires `vendor/autoload.php`. On a lower version it registers an admin notice and boots nothing else. A crash like the one in #70 must be impossible.
- **Module identity stays the same:**
  - alias `ljpccalendarmodule`
  - namespace `Modules\LJPcCalendarModule`
  - folder `LJPcCalendarModule`
  - `latestVersionUrl`
  - view namespace `calendar::`
  - constant `LJPC_CALENDARS_MODULE`
- **Dependencies** in the module's `vendor/`:
  - `sabre/dav`, for its client.
  - `sabre/vobject`, which parses and writes every iCalendar, expands recurrences, and provides `ITip\Broker` for building REPLY messages.
  - `dallgoot/yaml`, used only to read the legacy v2 metadata.
- **Dropped:** `johngrogg/ics-parser`, `spatie/icalendar-generator`, `spatie/enum` and the `psr/log` pin.
- **Must never be bundled:** any package that FreeScout core also ships (Guzzle, PSR-7, psr/*, symfony/*, Carbon, monolog, ramsey/uuid and others). Composer `replace` keeps them out. CI fails if the module's lock file contains a package that is also in core's lock file.
- **HTTP:**
  - The Google, Microsoft and ICS providers use core's Guzzle 8, configured with the `Helper` default options.
  - CalDAV uses sabre's client.

## 4. Architecture

```
Http/Controllers (thin)
   │  validate input, call policy, call service, return JSON / view
   ▼
CalendarPolicy ──────────────── user / team / mailbox permission rows
   ▼
Services
   EventService        create/update/delete events, recurrence edits, custom fields
   OccurrenceService   expand VCALENDAR into calendar_occurrences
   SyncService         pull loop per calendar, leases, budget, error state
   ConnectionService   OAuth flows, token refresh
   HealthService       connection health: alert mails and banners
   InviteService       detect, classify and apply iTIP messages; RSVP; outgoing invites
   ReminderService     due-reminder scan and delivery
   MigrationService    v2 → v3 conversion steps and report
   ▼
Providers (one interface, five implementations)
   LocalProvider  IcsProvider  CalDavProvider  GoogleProvider  MicrosoftProvider
   ▼
Storage: calendars, calendar_connections, calendar_permissions,
         calendar_events (VCALENDAR source), calendar_occurrences,
         calendar_invites, calendar_reminder_log
```

**Provider interface.** Each provider declares its own capabilities: `read`, `write`, and `writableRecurrence(VEvent): bool`. The operations are:

- `listCalendars(Connection)`
- `pull(Calendar, SyncState, Budget): ChangeSet`. A `ChangeSet` holds upserts, deletes, the next sync state, and whether the pull is complete.
- `create(Calendar, VCalendar): RemoteRef`
- `update(Calendar, Event, VCalendar): RemoteRef`. Sends `If-Match` or the provider's equivalent.
- `delete(Calendar, Event)`. A 404 or 410 counts as success.

`LocalProvider` implements the write methods as no-ops, so that every write goes through `EventService` the same way.

**Directory layout.** It follows the current module conventions:

- `Entities/`
- `Http/`
- `Services/`
- `Providers/` (Laravel service providers)
- `CalendarProviders/`
- `Console/`
- `Database/Migrations/`
- `Resources/frontend/`, the Vite source
- `Public/build/`, the built assets, committed
- `Tests/`

## 5. Data model

All tables respect FreeScout's table prefix. Datetimes are stored as UTC `timestamp`, not `timestamptz`, which avoids the PostgreSQL parsing issue from v2 (D10).

### `calendars` (rebuilt in place)

| Column | Notes |
|---|---|
| id, name, color, enabled | as v2 (`name` unique) |
| display_priority | int; lower renders first; default 100 for `local`, 200 for external calendars (2.7.x fork semantics) |
| provider | `local`, `ics`, `caldav`, `google`, `microsoft` (v2 `type`: `normal` → `local`) |
| connection_id | nullable, FK `calendar_connections` |
| remote_id | ICS URL, CalDAV collection URL, Google calendarId, Graph calendar id (plus owner address for shared mailboxes) |
| timezone | IANA, default `config('app.timezone')`; used for floating times |
| title_template | `{{title}}` plus `{{field:<id>}}` placeholders (UI shows names) |
| fields | JSON: custom field definitions only `[{id, name, type, required, options[]}]` |
| sync_interval | minutes, default 5 (v2 refresh options mapped) |
| sync_state | JSON: the provider's own state (sync token, page token or next link, ctag, what a stopped round finished on its page) plus the engine's keys, which start with `_` (generation, listing, seen, listing start, empty listings) |
| next_sync_at, last_synced_at, last_error, sync_lease_until | sync bookkeeping |
| sync_failures | failed syncs in a row; the next attempt waits the interval doubled per failure, at most six intervals |
| remote_read_only | the account may only read this calendar at the provider (set when it is picked, refreshed on the pick screen); read-only for everyone, the matrix offers only Dashboard and View |
| default_reminder_minutes | nullable |
| export_token, export_busy_token | 40 random chars each, regenerable |
| timestamps | |

### `calendar_connections` (new)

| Column | Notes |
|---|---|
| id, provider | `caldav`, `google`, `microsoft` |
| account | display name / email of the connected account |
| server_url | CalDAV only |
| credentials | `encrypt()`ed JSON: CalDAV username/password, or OAuth refresh token, access token, expiry, granted scopes |
| status | `ok`, `needs_reauth`, `error` |
| last_error, secret_expires_at | secret expiry entered by admin (Microsoft) |
| created_by | user id |
| timestamps | |

### `calendar_permissions` (new, replaces the `permissions` JSON)

| Column | Notes |
|---|---|
| calendar_id | FK, cascade |
| subject_type, subject_id | `user`, `team` (Teams module user of `TYPE_ROBOT`), `mailbox` |
| show_in_dashboard, view, create, edit | booleans; `edit` includes delete |

Unique key on (calendar_id, subject_type, subject_id).

### `calendar_events` (new, replaces `calendar_items` and `calendar_event_index`)

| Column | Notes |
|---|---|
| id, calendar_id | FK, cascade |
| uid | iCalendar UID; unique per calendar |
| ics | longtext: the full VCALENDAR for this UID (master plus overrides, VTIMEZONEs) |
| remote_id, remote_href, etag | provider identifiers (Google event id, Graph immutable id, CalDAV href) |
| remote_id_hash | sha1 of `remote_id`, unique per calendar: pulled events are matched by it, because `remote_id` is text |
| sync_generation | the generation of the last listing that mentioned the event; a completed full listing sweeps the older ones |
| sequence | iTIP sequence |
| summary, starts_at, ends_at, all_day, timezone, is_recurring, status, is_private | derived from `ics` for queries and lists; never edited directly |
| organizer_email | set when the event came from an invite or is our own invite |
| author_id, conversation_id | FreeScout links (nullable) |
| field_values | JSON `{<field id>: value}` |
| reminder_minutes | nullable; null means calendar default; `-1` means off |
| read_only | provider or privacy forced: an event with a private or confidential component (the master or one occurrence, stored as "Busy"), an invitation or event type the provider locks, overrides without their master, a feed. A rule the provider can't write is no reason: it locks whole-series changes only (`Calendar::canWriteSeries()`, 403 `series_not_writable`), and the occurrences stay editable |
| legacy_id | v2 `calendar_items.id` or v2 external UID, for old permalinks and line items |
| timestamps | |

### `calendar_occurrences` (new)

| Column | Notes |
|---|---|
| id, event_id, calendar_id | FKs, cascade |
| recurrence_id | UTC instant or date; null for non-recurring |
| starts_at, ends_at, all_day, status | |

Index on (calendar_id, starts_at) and (calendar_id, ends_at).

### `calendar_invites` (new)

| Column | Notes |
|---|---|
| id, conversation_id, thread_id, attachment_id | |
| uid, recurrence_id, method, sequence, dtstamp | parsed from the attachment |
| organizer_email, sender_email | for the spoofing warning |
| status | `pending`, `applied`, `ignored`, `outdated`, `processed` (REPLY) |
| event_id, applied_by, applied_at | |

### `calendar_reminder_log` (new)

| Column | Notes |
|---|---|
| occurrence key | event_id plus occurrence start |
| user_id, sent_at | unique on (event_id, occurrence start, user_id) |

**FreeScout options** (`Option::set`):
- Google: client id, encrypted secret.
- Microsoft: client id, encrypted secret, tenant (default `organizations`).
- Authorize and token endpoints default to Google and Microsoft. They are overridable in `Config/config.php` through `.env`, which is the relay hook. There is no UI for them.

## 6. Time model

- **Timed events:**
  - `starts_at` and `ends_at` are in UTC.
  - `timezone` is the IANA name taken from DTSTART's TZID, or the calendar timezone for floating times.
  - The VCALENDAR keeps the original TZID and VTIMEZONE.
- **All-day events:**
  - Stored as `DTSTART;VALUE=DATE`.
  - The database keeps the date at 00:00 with `all_day = 1`.
  - The API sends `YYYY-MM-DD`, with an exclusive end date.
- **API output:** ISO 8601 with an offset for timed events. FullCalendar runs with `timeZone` set to `Auth::user()->timezone`, or the app timezone as a fallback.
- **Form input:** read in the agent's FreeScout timezone, never in the browser's timezone. This fixes the root cause of #21, #38 and #49.
- **Microsoft timezones:**
  - Windows names are converted to IANA with `IntlTimeZone::getIDForWindowsID()` when intl is installed. Otherwise a bundled CLDR `windowsZones` map is used.
  - Every Graph request sends `Prefer: outlook.timezone="UTC"`.
- **Range queries:**
  - Occurrences where `starts_at < rangeEnd AND ends_at > rangeStart`. This includes multi-day events that start before the range (fixes D7).
  - For all-day rows the range is widened by 14 hours and then filtered by date in the user's timezone.

## 7. Recurrence and occurrences

- `OccurrenceService` expands each event with sabre/vobject's `EventIterator`. It handles RRULE, RDATE, EXDATE and overrides.
- **Window:**
  - The expansion runs from 12 months back to 24 months ahead, with a cap of 5000 occurrences per event.
  - Rows are regenerated whenever an event is saved or synced.
  - The sync command extends the window once a day.
- **Outside the window**, for example when navigating three years ahead, the API expands on the fly with the same function and does not store the result.
- **Recurrence options in the UI** for `local`, `caldav` and `google` calendars:
  - daily, weekly (with weekdays), monthly (by day of month, or by nth weekday), yearly
  - an interval
  - an end: never, until a date, or a number of times
- **Microsoft calendars:** every option in this list maps onto Graph's `patternedRecurrence`, so the same UI applies.
- **Editing:**
  - Each recurring edit offers "this occurrence" (adds or updates an override, or adds an EXDATE when deleting) or "whole series".
  - A whole-series change that moves the start or changes the rule resets the edited and deleted occurrences (it drops the overrides and EXDATEs), like Outlook. It never happens silently: while the series has any, the API saves nothing and answers 409 with `{ "code": "drops_exceptions", "message": ... }`. The agent confirms (after a whole-series drag in a dialog, in the series form inside the form), and the same change is sent again with `drop_exceptions: true`. Title, location, description, fields, reminder and end-only (resize) changes keep the exceptions and never ask.
  - A series whose RRULE is not writable for its provider is `read_only` at series level, but single occurrences can still be edited.

## 8. Providers and sync

### Sync engine

- **Scheduling:**
  - The command `calendar:sync` is added to FreeScout's `schedule` filter and runs every minute in the background (`withoutOverlapping(5)->runInBackground()`, because core schedules `queue:work` after the module's commands).
  - It does not use the queue, because v2's queue jobs blocked outgoing mail (D8).
  - It syncs nothing while the upgrade from v2 is unfinished.
- **What each run does:**
  - Picks enabled external calendars where `next_sync_at <= now`, whose connection (if they have one) has the status `ok`, and that no other run holds.
  - Takes a lease of 2 minutes. The lease is renewed before every pull round and after every 100 imported events, and only the run that holds it records the result.
  - Pulls changes until the provider reports the pull complete or the run's 40-second budget is used up. An unfinished pull keeps its cursor in `sync_state` and continues in the next run.
  - Afterwards checks the connection health, at most every 15 minutes (section 9).
- **Scheduling the next sync:**
  - After a complete pull: `last_synced_at = now` and `next_sync_at = now + sync_interval ± 10% jitter`, at least a minute. An unfinished pull is due again at once.
  - After an error:
    - `last_error` gets FreeScout's own translated message plus the HTTP status, never the provider's answer.
    - The existing data stays as it is (fixes D1).
    - The next attempt follows exponential backoff: the interval doubles per failure, capped at the calendar's interval × 6.
    - An error that means the account must sign in again also sets its connection to `needs_reauth`: a refused OAuth grant, or a CalDAV server's final 401 (section 9).
- **Applying a pull:**
  - Upserts match by remote id, through the indexed `remote_id_hash`, falling back to UID.
  - Local fields (`author_id`, `conversation_id`, `field_values`, `reminder_minutes`, `legacy_id`) are kept. The readable block and v2 metadata are stripped from the description (section 15).
  - Occurrences are recomputed.
  - Provider deletes remove the event and its occurrences. A conversation line item pointing to a deleted event shows "event deleted".
  - An event that can't be read or stored keeps its stored copy and is logged. The rest of the round still applies.
- **Full listings are swept by generation.** An ICS fetch, a CalDAV or Google listing without a sync token, and every Microsoft round are full listings.
  - A full listing may span several runs. Every event it mentions gets the calendar's current generation, and when the listing completes, the events with an older generation are deleted.
  - Events saved in FreeScout after the listing started are spared (by `updated_at`; when the app's clock went back during the listing, the cut-off moves back by as much), so every write goes through Eloquent.
  - An empty listing only deletes after two empty listings in a row, because Zoho answers an empty first sync. An empty ICS feed or CalDAV collection keeps no validators, ctag or sync token, so its next pull is a full listing again and the second empty listing happens.
  - A provider that can confirm deletions (`ConfirmsDeletions`, Microsoft) is asked about the candidates first. Only the events it confirms gone are deleted.
- **Who wins:** on pull, the provider always wins. Local writes are synchronous with preconditions (see below), so a conflict surfaces as an error when the agent saves.
- **Manual sync:** "Sync now" on each external calendar's card and edit page in the settings, and in the calendar page's sidebar for everyone who can view an external calendar, syncs right away within 20 seconds and reports what was added, changed and removed (or the error; agents only learn that a calendar failed). A listing that needs longer goes on in the next runs. A calendar that completed a sync less than 30 seconds ago is left alone. A calendar just added gets its first sync from the settings page itself, which shows its status live until it is done. The CLI takes `--force` and `--calendar=<id>`. A request made while a run holds the calendar stays: the run keeps an earlier `next_sync_at` stored meanwhile instead of planning its own.

### Writes

- Every create, update and delete on a remote calendar is executed synchronously during the request, at the provider first.
- **Saving:**
  - The local row is saved only after the provider confirms.
  - The returned id, href, etag (and Microsoft's own UID) are stored, so the next pull does not see our own change as a remote change.
  - A sync that stored a new event between the provider's create and the local save leaves one row: the imported one gets the author, the ticket link, the field values and the reminder.
- **Errors.** Nothing is saved locally in any of these cases.
  - A precondition failure (CalDAV 412, a Google etag mismatch, a Graph changeKey mismatch) returns HTTP 409 `changed_elsewhere`: "This event was changed elsewhere. Reload and try again." Before answering, the server syncs that calendar with a 10-second budget, so a reload shows the other version at once. That sync waits out throttling for at most 4 seconds per request. When a sync run holds the calendar, or a full listing of it has not finished (it may take many rounds), it is made due instead.
  - A network or provider error returns 502 `provider_unavailable`, with the provider's message translated where possible.
  - A connection that needs reauthorization returns 502 `connection_needs_reauth`. A calendar whose connection isn't `ok` answers that before any provider call, to whoever may write in it.
  - A calendar that the account may only read (`remote_read_only`) refuses writes with 403 before any provider call.
- **Rules a provider can't write:** creating or moving a series with such a rule answers 422 (Microsoft names day 29 to 31). A whole-series change of an existing one answers 403 `series_not_writable`, while its single occurrences stay editable (section 7).
- **Moving an event to a calendar of another provider or account:**
  - It is created in the target first and deleted from the source second.
  - If the delete fails, the agent is told the event is now in both calendars, and the source is listed in full at its next sync.
  - A target that already has an event with the same UID (the copy such a failed delete left) answers 422 on `calendar_id` before any provider call.
  - On a CalDAV server that allows a UID only once per account (Cyrus, as at Fastmail), a move within one account creates the copy under a new UID.
  - A meeting never moves away from its provider calendar: an event with an attendee other than the calendar's own account (the connection's account, a Google calendar id that is an address, a Microsoft calendar's mailbox; a room counts) answers 422 on `calendar_id` before any provider call, also for a move within one account (decision log).
- **A whole-series copy** of a series in another zone is created in the series' own zone; a floating series' copy in its calendar's zone.
- **The readable block** is left out of Google and Microsoft events that have attendees at the provider (decision log).

### Providers

All HTTP goes through core's Guzzle 8 via `HttpClientFactory`:
- FreeScout's proxy, timeout and TLS options;
- for addresses an admin typed (ICS, CalDAV), `\Helper::checkUrlHost()` on the host of the address and of every redirect hop;
- logs with the calendar id, provider and status, without secrets, tokens or full ICS addresses.

| Provider | Discovery | Pull | Write | Notes |
|---|---|---|---|---|
| ICS | none (URL). `webcal://` is read as https; an address with a username or password is refused. | GET through `HttpClientFactory`, never core's `getRemoteFileContents()`, which logs the full URL. Conditional GET where possible, at most 20 MB, counted after decoding while the body arrives (a gzip body is far larger than its transfer). A content hash without the DTSTAMP lines skips an unchanged feed. Split by UID with sabre/vobject. Every fetch is a full listing, swept by generation. | read-only | |
| CalDAV | server URL plus credentials; `.well-known/caldav`, principal and calendar-home discovery, falling back to a direct collection URL. The login is stored only after discovery with it works. When a redirect to another host answers 401, that host is asked once more with the login, never from https to http. A final 401 during a sync or a write sets the connection to `needs_reauth`; a 403 doesn't. | The first listing is a PROPFIND (Depth 1, `getetag`) plus `calendar-multiget`, never a sync-collection with an empty token (Zoho answers that with nothing). The collection's `DAV:sync-token`, read before that listing, starts the `sync-collection` REPORTs (RFC 6578, Depth 0). A 403 or 409 that names `valid-sync-token` starts a new listing. Without sync-collection: ctag plus etag diff. Answers are capped at 50 MB, counted after decoding while they arrive. | PUT to the stored `remote_href` with `If-Match` (`If-Match: *` when the server gave no ETag, so an object deleted elsewhere is never created again), the VTIMEZONEs in the body. New events go to `<collection>/<sha1(uid)>.ics` with `If-None-Match: *`, and on `no-uid-conflict` once more under a new UID. DELETE with `If-Match`; a 412 on a DELETE counts as deleted when the object is gone. | Over Guzzle, not sabre's HTTP client: one HTTP stack and one test mock for every provider. sabre/xml parses and writes the XML. Guzzle does digest authentication. Uses the real href (fixes D3). Edits the stored VCALENDAR instead of rebuilding it (fixes D2). ATTENDEE and ORGANIZER are written with `SCHEDULE-AGENT=CLIENT`. |
| Google | `calendarList`, which includes shared calendars. A `reader` or `freeBusyReader` role makes the calendar read-only. | `events.list` with `syncToken` and `showDeleted`. On 410 the token is cleared and a full listing runs. A changed stored series is read again with its exceptions from `events.instances` over the occurrence window; exceptions on their own are merged as overrides. Once the budget is spent a round stops before the next series it would read, and the next round lists the same page and skips what the stopped one finished. | A series: GET, then a PUT of the whole event with `If-Match` (the stored etag), `conferenceDataVersion=1` and `supportsAttachments=true`. An occurrence: its instance is read and compared with the stored copy, then written under the instance's own etag. DELETE with `If-Match`. | Private and confidential events become "Busy" and read-only; free/busy pages become busy events. Every event type is imported; types other than `default`, and invitations from other organizers, are read-only. On 403 rate limits and 429: backoff with jitter, at most 4 seconds while an agent waits and 15 seconds in a sync. |
| Microsoft | `me/calendars`, plus a shared mailbox the admin adds by address (`users/{address}/calendars`). `canEdit` false makes the calendar read-only. | Every calendar, the default one included, is listed by event id and changeKey (following next links while the budget lasts) and compared with the stored events. Changed series are read again with their `/instances` over the window, and every series once a week. A deletion is confirmed with `$filter=iCalUId eq '…'` on the calendar's own events, because an event deleted in Outlook moves to Deleted Items and keeps its id. Graph `patternedRecurrence` is translated to RRULE. | Create with a `transactionId`; update with a PATCH of only the fields that differ; delete. The changeKey is compared right before writing. A 412 on a create or delete is retried once. | Graph has no documented If-Match for events, so there is a small race we accept. `Prefer: IdType="ImmutableId"`, `outlook.timezone="UTC"` and `outlook.body-content-type="text"` on every request. Graph gets Windows time zone names (`WindowsZones`, from CLDR), because it accepts only some IANA names. On 429 and 503 the client honours `Retry-After`. Private events become "Busy" and read-only; invitations are read-only. A monthly rule on day 29 to 31 is refused. |

## 9. OAuth and connections

- **Settings → Calendar** has two tabs, "Calendars" and "Connections". The Connections tab holds:
  - **The Google app:** client id, client secret, the exact redirect URL with a copy button, and a link to the wiki page.
  - **The Microsoft app:** directory (tenant) id, client id, client secret, the secret's expiry date, the redirect URL and the wiki link.
    - The tenant is required: a tenant id or a verified domain. `organizations` (or `common`) only works for a multi-tenant app registration.
    - The expiry date is an app setting, because the secret belongs to the app registration that every Microsoft connection shares. `calendar_connections.secret_expires_at` stays unused.
  - **The CalDAV form:** server address, username and password.
  - **The connected accounts** with their status, "Choose calendars", "Reauthorize", "Delete" and, for Microsoft, "Add a shared mailbox".
- Secrets are stored with `encrypt()` and are write-only: the forms show "A secret is saved" and an empty field.
- **Connecting:**
  - The "Connect account" link (a GET request) goes to `/calendar/oauth/{provider}/start`. It stores a `state`, a PKCE verifier (S256) and the connection being reauthorized in the session for 10 minutes. Google is asked for offline access and consent.
  - The provider then redirects to `/calendar/oauth/{provider}/callback`, which checks the state and exchanges the code with the verifier and the secret.
  - The account is Google's primary calendar id, or Graph's mail address (or user principal name), in lower case. The same provider and account updates the existing connection. A reauthorization must sign in as the connection's own account.
  - A Microsoft account is known by Graph's user id (`/me` `id`, kept with the tokens), so a user whose address changed still reauthorizes and reconnects its own connection; the address is what the account is shown as. A connection stored without the id is matched by its address once, and gets the id then.
  - A successful sign-in, and a CalDAV login that discovery accepts, makes the account's calendars due at the next run and drops their failure backoff (`next_sync_at` now, `sync_failures` 0).
  - Then "Choose calendars" opens. Each picked calendar becomes a FreeScout calendar with name, color and time zone prefilled. "New calendar" on the Calendars tab asks the type first (a FreeScout calendar, an ICS feed, or calendars of a connected account) and leads to the same screen.
  - A calendar the account may only read gets `calendars.remote_read_only`, refreshed every time the pick screen lists the account. It is read-only for everyone, and its permission matrix only offers Dashboard and View.
- **Scopes:**
  - Google: `https://www.googleapis.com/auth/calendar.calendarlist.readonly` and `https://www.googleapis.com/auth/calendar.events` (sensitive, not restricted). Google's consent screen lets people untick scopes, so the token answer must hold both, or the connection is refused.
  - Microsoft: `offline_access User.Read Calendars.ReadWrite Calendars.ReadWrite.Shared`.
- **Token refresh:**
  - The access token is refreshed when it expires within 5 minutes. A 401 on a token that should still be valid renews it once.
  - Refresh holds a row lock per connection, because Microsoft rotates refresh tokens and two parallel refreshes would invalidate each other. A token another process stored meanwhile is used as it is. The lock is never taken inside another transaction.
  - `invalid_grant` and `interaction_required` set the status to `needs_reauth`, with "The account must be connected again.". The calendars stop syncing but keep their data, and they refuse writes. `invalid_client` and `unauthorized_client` point at the app's credentials instead.
  - A 401 on a renewed token marks the connection only while its stored tokens are still the refused ones, and the sign-in callback writes `ok` explicitly, so a reauthorization that finishes while a sync's old token is refused always wins.
- **Health** (`HealthService`), checked inside `calendar:sync` at most every 15 minutes:
  - The problems:
    - a connection that needs reauthorization (a refused OAuth grant or CalDAV login), or that the v2 upgrade created without a login (status `error`);
    - the Microsoft secret expires within 30 days, or has expired;
    - an enabled external calendar has an error, and its last success (or its creation) is more than 24 hours ago, unless its account is a problem already.
  - Each new problem is mailed once through core's system alert (`\MailHelper::sendAlertMail()`, to every active admin plus the Settings → Alerts recipients).
    - A problem that persists is not mailed again, and neither is one that comes back within 24 hours of being solved. One that has been gone for more than 24 hours is forgotten, so it is mailed again when it returns.
    - The option `ljpccalendarmodule.health` holds `checked_at`, the mailed problems (`notified`) and since when each of them is gone (`solved`). An option stored before `solved` existed keeps working: its problems count as present at `checked_at`.
    - The check stores its result before it queues the mails, with a compare-and-swap on the option's row (an update that only matches the value the check read, or the first insert of the option's unique name). Of two `calendar:sync` runs that check at the same moment, only one stores and mails; the next check finds what the other one alone saw.
  - FreeScout's notification bell only shows conversation notifications, so there is no in-app notification.
  - The problems that are present now show in a banner above the settings tabs and, for admins, on the calendar page, where it can be closed for that page.
  - Agents only see "Some calendars are not up to date: …" for their visible calendars whose sync failed and whose last success is more than a day ago, or that never synced (they show no events at all). They never receive `last_error`.
- **CalDAV connections** use the same table and the same "Choose calendars" step. The login is stored only after discovery with it found the account's calendars. Connecting the same server and username again updates that connection, however the address is written (a trailing slash, the host's case), also when a second post of the form (a double-click) saved it while discovery ran. "Add calendars" locks the connection's row, so a double-click adds each calendar once. An address with a username or password in it is refused.
  - A server that finally refuses the login during a sync or a write (HTTP 401, after any digest challenge Guzzle answers itself) sets the status to `needs_reauth`, with "The account must be connected again.", as a refused OAuth grant does. A 403 doesn't: it names a precondition, or refuses that one request.
  - No sync or write sends that login again (discovery for "Choose calendars", or "Connect again" with an empty password field, still does). The account's row on the Connections tab opens its login form ("Reauthorize") while the status isn't `ok`, and a login that discovery accepts sets the status back to `ok` and clears `last_error`.
  - Discovery during "Connect" or "Reauthorize" never changes the status: a refused login only shows the form error.
  - A 401 for a login an administrator replaced while the request ran leaves the connection as it is.
- **Deleting a connection** deletes its calendars and their events in FreeScout, after a confirmation that names them. Nothing is deleted at the provider. Connections the v2 upgrade created stay when their last calendar is deleted; the admin deletes them on the Connections tab.

## 10. Permissions

- `CalendarPolicy` is the only place where calendar access is decided.
- Effective rights = admin ? all : the union of every matching row:
  - **User row:** `subject_id == user id`.
  - **Team row:** the user is a member through `\Team::getMembers()`. Only evaluated when the Teams module is active.
  - **Mailbox row:** `$user->hasAccessToMailbox(subject_id)`.
- **Every endpoint:**
  - Reading requires `view`.
  - Creating requires `create`.
  - Updating, moving and deleting require `edit`, plus a check that the event belongs to the calendar given in the request.
  - The dashboard card requires `show_in_dashboard`.
  - Disabled calendars are not shown to agents.
- **Actions on a conversation** additionally require `$user->can('view', $conversation)`. This covers:
  - creating an event from a conversation
  - importing an attachment
  - applying an invite
  - RSVP
  - sending invites

- **Data agents receive:**
  - The agent API returns calendars with only `{id, name, color, provider, display_priority, fields, has_title_template, rights, writable, sync_failed, last_synced_at}`. `writable` says whether the calendar can be written at all (its provider, the account's access); `sync_failed` and `last_synced_at` feed the "not up to date" banner, and `last_error` never reaches agents. Event details add `editable` and `series_writable` (false for a series whose rule the provider can't write).
  - Connection credentials, tokens and the permission matrix go only to admin endpoints. Secrets are never returned at all, and are write-only in the UI.
- **New calendars** have no rows, so only admins see them. The settings app keeps "select all" per column.

## 11. Frontend

- **One Vite project** in `Resources/frontend`:
  - Vue 3.5.
  - FullCalendar 7.1.x pinned to an exact version, using `@fullcalendar/vue3`.
  - TypeScript, type-checked with `vue-tsc` in every build.
  - SortableJS instead of vuedraggable.
  - Three entries: `calendar` and `conversation` (Vue), and `settings` (plain TypeScript that enhances the server-rendered settings pages).
  - Output goes to `Public/build/` with stable file names plus `?v=<file modification time>`.
- **Loading:**
  - Scripts load with `type="module"` and `\Helper::cspNonceAttr()`, inside the page's `javascripts` section.
  - Deliberate exception: the settings and conversation bundles print their tags inline, next to their own HTML (in core's settings page and from the conversation toolbar hook, which can't fill the layout's sections). Module scripts are deferred, so they still run after the page is parsed. Keep it that way.
  - The calendar bundle is never added to the global `javascripts` filter.
  - `laroute.js` and `general.js` are no longer injected on every page. This is the suspected cause of #57 and #58.
- **Passing data:**
  - Server data (locale, user timezone, calendars, translations, routes, CSRF token) is passed in `<script type="application/json" id="calendar-config">`.
  - There is no Blade inside JavaScript and no `innerHTML` with user data. Vue escapes all text.
- **Look and feel:**
  - Modals use Bootstrap 3 markup so they match FreeScout.
  - The classic FullCalendar theme is tuned with `--fc-classic-*` variables, and module CSS is scoped under a wrapper class.
- **Views:**
  - Day, week, month, year (`multiMonthYear`) and list.
  - Mobile opens on the list view, and the week starts on Monday.
  - The locale is mapped from `app()->getLocale()` (the agent's own language), and right-to-left is used for ar, he and fa.
- **Event form:**
  - calendar, title, an explicit all-day toggle, start and end, location, description
  - recurrence, reminder, custom fields
  - attendees: only when the event is linked to a conversation, and the calendar is writable
  - Read-only events open a details view instead of the form.
- **Details view:**
  - creator, linked conversation, attendees with their response status
  - "copy permalink", "duplicate"
  - "Duplicate" on an occurrence of a repeating event asks what to copy: "this occurrence" (a single event on its date and time, without repetition) or "whole series" (the same repetition as a new series with the series' own details and length, starting at the occurrence's original slot, its recurrence id, so a moved occurrence starts where the rule put it; the rule stays exactly as it is, with its weekdays, interval, monthly mode, COUNT and UNTIL). A series opened without an occurrence, and a single event, are copied as they are. A new event never repeats by a custom rule (one the form can't show), so its form starts with "Does not repeat", and an occurrence of a series with a custom rule copies only itself, without asking.
  - edit and delete, shown according to the user's rights
- **Times** are shown the way FreeScout core shows them: "02:30pm" on the 12-hour clock (PHP's `h:ia`: two-digit hour, lowercase English am/pm) and "14:30" on the 24-hour clock, with ranges as "02:30pm – 03:30pm". This covers event chips, the time axis and the details. Dates stay in the agent's locale (`Intl` `dateStyle: 'full'`), and the native time inputs in the forms stay native.
- **Permalinks:** a v3 event is `/calendar#e<id>`, and `e<digits>` only ever resolves as a v3 id. Anything else, a bare number included (`/calendar#<n>`, the v2 format), resolves as `legacy_id`, then as UID, and never as a v3 id. `legacy_id` holds v2 item ids and v2 external UIDs.
  - One occurrence of a repeating event is `/calendar#e<id>@<recurrence key>`, for example `#e12@20261014T080000Z`. The key is the one the API takes as `recurrence_id` (`EventIcs::recurrenceKey`): `Ymd\THis\Z` in UTC for a timed event, `Ymd` for an all-day one. It opens that occurrence and moves the calendar to its date.
  - Only `e<digits>@<key>` splits; any other ref with an `@` (a UID) still goes to the server whole.
  - The details' "copy permalink", the address bar and the dashboard card write the occurrence form for an occurrence.
- **Parity with the 2.7.x fork:**
  - `eventOrder` sorts by the calendar's `display_priority`, then by start. Timed events stay chronological, and priority only decides ties.
  - Every event element gets a `title` attribute with the full title.
  - The calendar list can be collapsed, and the state is kept in `localStorage` (wrapped in try/catch).
  - The month view uses `dayMaxEvents` with a "+N" popover that grows to fit, scrolling only beyond the viewport.
  - An expanded all-day section shows every row.
- **Sync errors:** when a visible calendar's last sync failed, the calendar page shows a non-blocking banner.

## 12. Conversation integration

- **"Add to calendar"** (the `conversation.action_buttons` hook) opens a Vue modal with:
  - calendar, title (hidden when the calendar has a title template), start and end, all-day
  - custom fields: email fields are prefilled with the customer email, and boolean fields work correctly (fixes the v2 bug)
  - "invite customer" and extra attendee addresses
  - reminder
- **Line items:** a line item (`action_type` 186) is added to the conversation, as in v2. The renderer accepts both the v2 meta (`calendar_item_id`, `event_uid`) and the v3 meta (`event_id`). Its start time follows the viewer's FreeScout timezone and clock ("02:30pm" or "14:30"), and the 24-hour clock when nobody is viewing.
- **ICS attachments:**
  - An `.ics` or `text/calendar` attachment (case-insensitive) renders an invite card (section 13) instead of the v2 "Add X to a calendar" link.
  - Gmail's duplicate pair (`calendar.ics` plus `invite.ics`) is merged into one card by UID.
  - Attachments are read with `Attachment::getFileContents()`, so they also work with S3.
- **Dashboard card:**
  - Shows the next 3 occurrences within 7 days from calendars the user has `show_in_dashboard` on.
  - Reads only from the database, with no remote calls.
  - Each calendar is isolated, so one broken calendar cannot break the dashboard (#61).

## 13. Invites

### Detection

- `InviteService::scan(Attachment)` parses the attachment with sabre/vobject and writes one `calendar_invites` row per (UID, RECURRENCE-ID). A missing METHOD is treated as PUBLISH.
- **New mail** is scanned in the `conversation.created_by_customer` and `conversation.customer_replied` hooks, because REPLY messages must be processed when they arrive.
- **Mail from before v3** is scanned when its attachment is first rendered. The result is cached in the table.
- For FreeScout ≥ 1.8.183 the `method=` MIME parameter is not available after the mail is fetched, so the METHOD inside the file is the one used.

### Incoming invite card (#83)

| Situation | Card | Action (requires the policy checks from section 10) |
|---|---|---|
| unknown UID, PUBLISH or REQUEST | "New invite" | pick calendar, **Add** |
| known UID, higher SEQUENCE, or equal SEQUENCE with a newer DTSTAMP | "Update to <event> in <calendar>", old and new time side by side | **Apply update** |
| known UID, lower SEQUENCE, or equal SEQUENCE with an older or equal DTSTAMP | "Outdated version" | none |
| CANCEL | "Cancellation" | **Cancel event**: sets `STATUS:CANCELLED`. The event stays visible, shown struck through. |
| RECURRENCE-ID present | as above, naming the occurrence | merges only that override, or cancels only that occurrence (EXDATE or a cancelled override). This is the Nextcloud TipBroker approach, not sabre's `processMessageRequest/Cancel`. |
| `RANGE=THISANDFUTURE` | treated as an update to the master | warning: "applies to this and following occurrences" |

**More rules:**
- **Spoofing warning:** when the sender's email does not match ORGANIZER, the card shows a warning. Nothing is ever applied automatically.
- **Recurring invites are imported once,** with their RRULE, EXDATEs and overrides (fixes D9). Re-importing the same UID updates the event instead of duplicating it.
- **Calendar-specific handling:**
  - Google: imported invites are stored through `events.import` when it keeps the iCalUID and organizer, or else through insert with the attendees left out. This is decided in planning.
  - Microsoft: attendees are left out, and the organizer is listed in the readable block. Otherwise Outlook would send invitations itself.
- **RSVP:**
  - The buttons Yes, Maybe and No are shown on REQUEST cards when the mailbox email or one of its aliases is an ATTENDEE and RSVP is not FALSE.
  - The reply is built with `ITip\Broker::parseEvent()` (`scheduleAgentServerRules=false`).
  - It is created as a reply thread addressed to ORGANIZER, with the subject "Accepted/Tentative/Declined: <summary>" in the mailbox's locale.
  - In `email.reply_to_customer.swiftmessage` an inline `text/calendar; method=REPLY` part is added inside the `multipart/alternative`.
  - The stored PARTSTAT is updated.

### Outgoing invites (#47)

- Only available for events linked to a conversation. The organizer is the conversation's mailbox address.
- **Sending:**
  - Sending or updating creates a reply thread in the conversation to all attendees.
  - The body is a readable summary with the time in the mailbox timezone, together with an inline `method=REQUEST` part and an `invite.ics` attachment.
- **Updates and cancellations:**
  - A change to time, recurrence or status increments SEQUENCE.
  - The agent sees a "notify attendees" checkbox, on by default.
  - Deleting the event offers to send a CANCEL.
- **Incoming REPLY** messages are matched by UID to events where we are the organizer, and the ATTENDEE must equal the sender. They update PARTSTAT automatically, mark the invite as `processed`, and add a line item ("<name> accepted").
- **Where attendees are stored:**
  - CalDAV: written with `SCHEDULE-AGENT=CLIENT`.
  - Google and Microsoft: only in the readable block (section 15), so those services do not send their own invitations.

## 14. Reminders

- `calendar:sync` also runs a reminder scan every minute.
- **When a reminder is due:** for each occurrence with `starts_at - reminder_minutes` in `(last scan, now]` and no entry in the log, the reminder goes to:
  - the event's `author_id`
  - the assignee of the linked conversation, if there is one and it is a different person

  Each recipient must still have `view` on the calendar.
- **Delivery:**
  - Email goes through FreeScout's system mail settings.
  - There is also an in-app notification, through FreeScout's own notification system, **if** it can show notifications that do not belong to a conversation. This is verified during planning. If it can't, reminders are email-only.
- **Settings:** each calendar has a default reminder, and each event can override it or turn it off.

## 15. Custom fields and the readable block

- **Source of truth:** the values in `calendar_events.field_values`.
- **The readable block:** every write to CalDAV, Google or Microsoft, and the full ICS export, appends a readable block to the end of DESCRIPTION:

  ```
  [FreeScout]
  <field name>: <value>
  Attendees: <name> <email> (accepted)        (Google/Microsoft only)
  Ticket #<number>: <conversation url>
  ```

- **On pull,** everything from the line `[FreeScout]` to the end is stripped before the description is stored. If an external client edits the block, the edits are ignored, and the next FreeScout save restores it.
- **Legacy v2 YAML** in the description is detected on pull, on CalDAV calendars only (v2 wrote it nowhere else). Its values go into `field_values` and `conversation_id` (keyed through the field mapping), and the YAML is stripped. It is never written again. YAML with an anchor, or more than 64 KB of it, is never parsed (aliases of nested anchors expand without limit) and stays as ordinary text.
- **The busy export** does not include the block.

## 16. Export

- **Route:** `/calendar/{id}/export/{token}.ics`, throttled at 60 requests per minute.
- **Tokens:**
  - Compared with `hash_equals`.
  - `export_token` gives full details, and `export_busy_token` gives free/busy only ("Busy", times only).
  - The admin can regenerate either token in settings.
- **Generation:** the file is always generated from the database, also for external calendars, so it is no longer a raw proxy of the upstream feed.
- **Old v2 links stop working.** The upgrade notice and the migration report tell admins to resubscribe.

## 17. Migration from v2

`MigrationService` runs from module migrations. The steps are idempotent: they check `hasTable` and `hasColumn` and record their progress in an option. They process data in chunks and are tested on MySQL, MariaDB and PostgreSQL.

1. Create the new tables.
2. **Convert calendars:**
   - `type` becomes `provider` (`normal` → `local`).
   - v2 `custom_fields.fields` become `fields`.
   - The ICS URL becomes `remote_id`.
   - CalDAV URL, username and password become a `calendar_connections` row (credentials encrypted), with `remote_id` set to the collection URL.
   - The refresh interval becomes `sync_interval`.
   - Title template names become field ids.
   - New export tokens are generated.
   - `custom_fields` may be NULL (seen on real installs), which means no fields and no connection.
   - `custom_fields.display_priority` (fork 2.6.7 and later) becomes `display_priority`. Without it: 100 for `normal`, 200 for `ics`/`caldav`.
3. **Convert permissions:**
   - The JSON becomes rows. User rows and team rows are migrated **as they are**.
   - The report lists which team rows now take effect, since team rows were not evaluated in v2.
   - Rows for deleted users or teams are dropped and listed.
4. **Convert `calendar_items`** to `calendar_events`:
   - A VCALENDAR is built for each item.
   - `legacy_id` is set to the old item id, and the item's conversation id, author id and custom field values are copied.
   - All-day items are converted from "local midnight stored as UTC" to dates using `app.timezone`. The report shows the count and the assumed timezone.
   - Items that cannot be converted are listed in the report and never silently dropped.
5. **Line items:** nothing to do. The renderer reads both v2 and v3 meta.
6. **External calendars:**
   - The v2 cache files and `calendar_event_index` are not converted.
   - `next_sync_at` is set to now, so the first sync rebuilds them. It reads legacy YAML and links v2 metadata by UID through `legacy_id`.
7. **Clean up only after steps 1 to 6 have succeeded:**
   - Drop `calendar_items` and `calendar_event_index`.
   - Drop the `permissions` and `custom_fields` columns, plus the unused columns `force_legacy_mode`, `sync_version` and `last_full_sync`.
   - Delete `storage/app/ljpccalendarmodule/*.ics`.
   - Ignore the old `.env` flags.

**While migrating:**
- Until the last step has succeeded, the calendar page and the API return a "migration in progress / failed" state with a link to the report.
- The report is stored as an option and shown under settings: counts, warnings and errors.
- If a step fails it stops and reports. A rerun resumes from that step.

**Before upgrading:** the release notes, the README and the module description all say "back up your database before upgrading". There is no beta and there are no backup tables (section 2), so testing on real v2 data is mandatory (section 20).

## 18. Security requirements

Every item below needs a regression test. The v2 findings behind them are tracked privately until 3.0 ships.

| Area | 3.0 requirement |
|---|---|
| Output in views | no Blade output inside `<script>`; config passed as JSON; Vue escaping; no `innerHTML` with data; `strip_tags` on calendar names |
| Export links | random tokens compared with `hash_equals`, regenerable, throttled |
| Secrets | encrypted with `encrypt()`, write-only, returned only to admin endpoints (and never the secret itself) |
| Authorization | `CalendarPolicy` on every endpoint; event and calendar scoping; conversation `view` policy for conversation actions |
| Remote URLs | FreeScout remote-URL helpers; http(s) only; no `file_get_contents` on URLs |
| Platform | PHP guard before loading the module's autoloader |

**General rules:**
- Never log secrets or tokens.
- Validate all input server-side with Laravel validators.
- CSRF stays on for every state-changing route.
- OAuth `state` and PKCE are verified on callback.

## 19. Error handling

- No swallowed exceptions.
- **Provider errors:**
  - Logged with context: calendar id, provider, HTTP status, and a truncated body with no tokens.
  - Stored in `last_error`.
  - Shown to admins in settings, and to agents as a calendar banner.
- **User-facing errors** are translated and specific:
  - "changed elsewhere"
  - "calendar provider unreachable"
  - "connection needs to be re-authorized"
  - "you don't have permission"
- **Failure isolation:** a failing calendar never affects other calendars, the dashboard, or outgoing mail.

## 20. Testing

- **PHPUnit** (module `Tests/`, run with FreeScout's phpunit inside ddev). Areas covered:
  - recurrence expansion and edits, including the DST weeks for Europe/Amsterdam and Australia/Sydney
  - the time model and all-day handling
  - converting Windows timezones to IANA
  - converting Graph recurrence to and from RRULE
  - `CalendarPolicy`, with user, team and mailbox rows
  - classifying and applying iTIP messages, using real fixtures from Gmail, Google Workspace, Outlook/Exchange, Teams, iCloud and Zoom
  - building REPLY messages
  - every migration step, using v2 fixtures
  - export tokens
  - parsing and stripping the readable block and the legacy YAML
  - every security item in section 18
- **CalDAV integration** against a Radicale or Nextcloud container.
- **Google and Microsoft** against real test accounts, with responses recorded as fixtures for the unit tests.
- **Playwright end-to-end** on the local ddev FreeScout:
  - creating, editing, dragging and deleting events
  - recurring edits
  - every view
  - the conversation modal
  - the invite card and RSVP
  - running with the browser timezone different from the agent's profile timezone, around the DST changes
- **Migration** runs on copies of real v2 databases on MySQL, MariaDB and PostgreSQL before release.
  - Masked dumps of the calendar tables are in `~/Projects/freescout-calendar-v3-testdata/`, outside any repo with mode 700, and are deleted after the 3.0 release:
    - LJPc's own mailbox: fork 2.7.3, MariaDB 11.8.
    - A client install: fork 2.7.3, MariaDB 10.11.
    - A client install: public 2.6.4, MariaDB 10.11.
  - The dumps were made server-side: CalDAV passwords, usernames and all calendar URLs are replaced, and the external event index is exported as schema only.
  - There is no real PostgreSQL install, so the same dumps are converted to PostgreSQL for that run.
  - The real installs and their statistics are in research section F.
- **RSVP and invites** are tested by hand for recognition in Gmail, Google Workspace, Microsoft 365, Outlook.com, Exchange on-prem (if available) and iCloud.

## 21. Release and distribution

- `vendor/` and `node_modules/` are removed from git (added to `.gitignore`).
- **Release workflow** (on a tag):
  1. Set up PHP 8.2 and Node.
  2. `composer install --no-dev --optimize-autoloader`.
  3. Fail if the module lock file contains a package that also appears in FreeScout core's lock file.
  4. `npm ci && npm run build`, then `git diff --exit-code Public/build`.
  5. Zip as `LJPcCalendarModule/`, excluding `Resources/frontend`, `Tests`, `docs` and `.github`.
  6. Upload the versioned zip, `latest.zip` and `version.txt`.
  7. Tags that contain `-` (for example `3.0.0-rc1`) are uploaded only as a versioned zip, never as `latest.zip` or `version.txt`.
- **`module.json`:**
  - `version` 3.0.0
  - `requiredAppVersion` 1.8.243
  - `author` "LJPc"
  - `authorUrl` https://ljpc.nl
  - the description mentions Google, Microsoft 365, CalDAV and ICS
- **`composer.json`:** the author name becomes "LJPc". The README link points to https://ljpc.nl.
- **Local dev:**
  - Update the local FreeScout from 1.8.218 to ≥ 1.8.245 first.
  - Since 1.8.240, activation renames the module folder to `LJPcCalendarModule`, so the dev checkout must live there or be symlinked.
- **Before release:** update LJPc's own mailbox from FreeScout 1.8.234 to 1.8.243 or newer, otherwise it can't run 3.0.

## 22. Documentation and translations

- **GitHub wiki pages:**
  - Google setup, with both Workspace "Internal" and External "Publish app" paths, and a warning about the 7-day token expiry in Testing mode.
  - Microsoft 365 setup: app registration, scopes, shared mailboxes and secret rotation.
  - CalDAV providers (Nextcloud, Baikal, iCloud, Fastmail, Zoho, cPanel).
  - Upgrading from v2.
  - Invites and RSVP.
  - FAQ, including that outlook.com users should use an ICS publish link.
- **Inside the module:** the Connections screen links to the matching wiki page and shows the redirect URL.
- **README:**
  - features
  - requirements
  - a backup warning
  - a "Scope" section stating which iTIP features are and are not supported (the answer to #83)
  - links to the wiki
- **Translations:** every new and changed string is translated into all 21 languages, keeping the existing terminology.
- **GitHub drafts:** a reply to #83 and new issues per feature area are drafted and shown to Lars before posting.

## 23. Workstreams (one implementation plan each)

1. **W1 Platform and data:**
   - release pipeline, dependency cleanup, PHP guard
   - schema, `MigrationService`
   - time model, `OccurrenceService`
   - `CalendarPolicy`
   - local events through `EventService`, the API, export
   - security items
2. **W2 Frontend:**
   - the Vite setup
   - the calendar page (FullCalendar, all views, the form including recurrence)
   - the settings pages, server-rendered (calendars, fields, the permission matrix with team and mailbox rows, export links, the migration report)
   - the conversation modal
   - the dashboard card
3. **W3 Providers and sync:**
   - the sync engine
   - ICS
   - CalDAV (discovery, sync-collection)
   - OAuth, Google, Microsoft (shared mailboxes, recurrence translation, timezone map)
   - the Connections settings and the screens that add external calendars
   - connection health and admin notifications
4. **W4 Invites:**
   - detection
   - the invite card and applying invites (including single occurrences)
   - RSVP
   - outgoing invites and REPLY processing
   - the readable attendee block
5. **W5 Finishing:**
   - reminders
   - translations
   - wiki, README, `module.json`
   - the #83 reply and issues
   - the migration test on real data
   - the final end-to-end pass

Order: W1, then W2 and W3 in parallel, then W4, then W5. Everything merges into `v3`, and the release is cut from `v3` after W5.

## 24. To verify during planning

1. Whether FreeScout's notification bell can show module notifications that don't belong to a conversation (reminders and connection health). Answered in W3: it can't (`WebsiteNotification` skips rows without a thread), so connection health mails and shows banners (decision log).
2. The exact names and behavior of the remote-URL helpers in 1.8.245 (`Helper::sanitizeRemoteUrl`, `getRemoteFileContents`). Answered in W3: `getRemoteFileContents` logs the full URL, so the module fetches with Guzzle and checks hosts with `Helper::checkUrlHost` (section 8).
3. Graph v1.0: the most reliable way to get series exceptions and cancelled occurrences for non-default calendars (instances within the window, or `exceptionOccurrences`/`cancelledOccurrences`). Answered in W3: instances within the window (section 8).
4. Google: the minimal scopes, and whether invites stored in Google calendars should use `events.import`. The scopes were answered in W3 (section 9); `events.import` is left to W4.
5. FullCalendar 7.1.x open regressions (#8094, #8101, #8102) at implementation time.
6. How the Teams module exposes membership (`\Team::getMembers`), and how to behave when the module is inactive.

## 25. Decision log (2026-10-05)

| Topic | Decision |
|---|---|
| Separate 2.6.x release | none; everything ships in 3.0 |
| Minimum versions | PHP 8.2+, FreeScout 1.8.243+ |
| Release form | one big 3.0, no public beta |
| Google/Microsoft business model | free in the module with the admin's own OAuth app; paid LJPc relay later |
| Relay timing | after 3.0, after validation; 3.0 makes the token endpoints configurable |
| Who connects accounts | the admin, per calendar |
| Sync direction | two-way |
| #83 scope | invite card, one-click apply (including single occurrences), RSVP for the whole series |
| Recurrence in local calendars | yes, with recurrence options in the form |
| Extras in 3.0 | #42 calendar per mailbox, #46 list and year views, #47 invite participants, agent reminders |
| Microsoft shared mailboxes | yes, through delegated `Calendars.ReadWrite.Shared` |
| Export links | new secret links; old ones stop working |
| Permissions with mailboxes | mailbox becomes a row type in the matrix; rights are the union of rows |
| Outgoing invites | from the mailbox, in the conversation; REPLY processed automatically |
| Reminders | FreeScout notification plus email, to the creator and the assignee |
| Testing before release | no beta |
| Calendar library | FullCalendar v7 |
| v2 data | converted in place, no backup tables |
| Translations | Claude translates all 21 languages |
| Setup docs | GitHub wiki |
| Private external events | shown as "Busy", without details |
| Event storage | iCalendar as source of truth, plus derived columns and an occurrences table |
| Custom field visibility | visible in all clients through a readable block in the description |
| Saving while the provider is down | write directly, otherwise show an error |
| v2 team rows | migrated as they are; the report lists rows that take effect |
| Invites without a conversation | not in 3.0 |
| `vendor/` in releases | built by CI; removed from git |
| Domain | ljpc.nl replaces ljpc.solutions |
| 2.7.x fork | migrate its data and include all its features |
| Real test data | masked dumps of the calendar tables only (own mailbox and two client installs), kept locally, deleted after release |
| Visibility of `v3` | stays local or in a private mirror until 3.0.0 is released; it is published together with a security advisory for 2.6.4 |
| Settings UI (2026-10-06) | server-rendered FreeScout forms with a little TypeScript, not a Vue app; the admin JSON API from W1 becomes form actions |
| Connections UI (2026-10-06) | built in W3 together with its OAuth and sync backend |
| Dashboard right (2026-10-06) | stays independent of View, as in v2 (real installs use it); the matrix explains it |
| Frontend language (2026-10-06) | TypeScript |
| Stuck migration (2026-10-06) | a "Skip" button per pending v2 item in the migration report, which records the full row; then Manage > System > Tools > Migrate DB |
| Occurrence permalinks (2026-10-06) | Occurrence permalinks are `#e<id>@<recurrence key>`, with the key as `EventIcs::recurrenceKey` writes it |
| Series exceptions on a move or rule change (2026-10-06) | still reset, but only after the agent confirms: a server-side guard answers 409 `drops_exceptions` and saves nothing until the request carries `drop_exceptions: true`. Clients recognize it by the code, because a provider conflict (W3) is a 409 too |
| Duplicating an occurrence (2026-10-06) | asks "this occurrence" (a single event, no repetition) or "whole series": the same repetition as a new series from the master's details, starting at the occurrence's original slot with the rule unchanged ("Weekoverleg, elke maandag sinds 6 jan 2025", duplicated from Monday 12 October 2026, repeats every Monday from 12 October 2026) |
| Times on the calendar page (2026-10-06) | FreeScout core's style, not `Intl`'s: "02:30pm" (`h:ia`) or "14:30" (`H:i`), ranges with an en dash; dates stay `Intl` in the agent's locale |
| W3 plan (2026-10-07) | one plan for providers and sync, not split |
| Testing Google and Microsoft (2026-10-07) | built against their documentation with hand-made fixtures, without test accounts; Lars tests both by hand from `docs/w3-manual-test.md` before the release (instead of "real test accounts" in section 20) |
| CalDAV tests (2026-10-07) | the integration tests and the e2e suite run against Radicale, an extra ddev service for local development only, never shipped |
| Connection health (2026-10-07) | one email per problem to every admin through core's system alert, not repeated while it persists and sent again when it returns (refined 2026-10-15: only after 24 hours without recurrence, see "Forgetting solved health problems"), plus a banner in the settings and on the calendar page; agents only see that a calendar is not up to date. No notification bell: core's bell only shows notifications that belong to a conversation |
| Calendar settings layout (2026-10-07) | two tabs, "Calendars" and "Connections"; "New calendar" asks the type first (FreeScout calendar, ICS feed, from a connected account); the Connections tab holds the Google and Microsoft apps, CalDAV, and the accounts with their status, "Choose calendars", "Reauthorize", "Delete" and "Add a shared mailbox" |
| Deleting a connection (2026-10-07) | deletes its calendars and their events in FreeScout after a confirmation that names them; nothing is deleted at the provider |
| Duplicating a series across zones (2026-10-07) | the server creates the whole-series copy in the series' own zone; the form keeps showing the agent's times |
| Moving between providers or accounts (2026-10-07) | allowed: created in the target first, deleted from the source second; when the delete fails, the agent is told the event is now in both calendars |
| CalDAV transport (2026-10-07) | Guzzle through `HttpClientFactory`, not sabre's HTTP client: one HTTP stack with the same proxy, timeout, TLS and SSRF handling, and one test mock, for every provider; sabre/xml still parses and writes the XML |
| Microsoft tenant (2026-10-07) | the tenant field is required and takes the directory (tenant) id or a domain; `organizations` and `common` only work for multi-tenant app registrations |
| Microsoft secret expiry (2026-10-07) | an app setting (`ljpccalendarmodule.microsoft_secret_expires_at`), not a column per connection: the secret belongs to the app registration that every Microsoft connection shares; `calendar_connections.secret_expires_at` stays unused |
| Matching pulled events (2026-10-07) | by an indexed `remote_id_hash` (sha1 of the remote id), because `remote_id` is text, which MySQL can't index whole |
| Full listings (2026-10-07) | swept by generation over as many runs as needed; an empty listing only deletes after two empty listings in a row (Zoho); an empty ICS feed or CalDAV collection keeps no validators or token, so that second listing comes (final review, 2026-10-15) |
| Health checks (2026-10-07) | inside `calendar:sync`, at most every 15 minutes, not a separate scheduled command |
| v2 connections (2026-10-07) | stay when their last calendar is deleted; the admin deletes them on the Connections tab |
| First CalDAV listing (2026-10-09) | PROPFIND plus `calendar-multiget`, never a sync-collection with an empty token (Zoho answers that with nothing); the sync token comes from the collection's `DAV:sync-token`, read before the listing |
| CalDAV move within one account (2026-10-09) | on servers that allow a UID once per account (Cyrus, as at Fastmail, maybe iCloud) the moved event gets a new UID after the server's `no-uid-conflict`; FreeScout's links and line items keep working, other apps see a new event. On the CalDAV wiki page |
| Changed elsewhere (2026-10-09) | on a provider conflict the server syncs that calendar right away (10-second budget) before it answers 409, so "Reload and try again" is true at once; when a sync run holds the calendar, it is made due instead. Refined in the final review (2026-10-15): that sync waits out throttling for at most 4 seconds per request, and a full listing that has not finished is made due instead of continued |
| Series a provider can't write (2026-10-09) | its single occurrences stay editable; a whole-series change answers 403 `series_not_writable`, a create or a move with such a rule 422, with the provider's reason when it gives one (the optional `ExplainsRecurrenceRefusal`) |
| Copy of a floating series (2026-10-09) | written in its calendar's zone; a later zone change of the calendar moves the original, not the copy |
| Graph time zones (2026-10-09) | FreeScout sends Windows zone names (a bundled CLDR map, intl first), because Graph documents only some IANA names as accepted |
| Microsoft listing (2026-10-09) | every calendar, the default one included, is listed by event id and changeKey and compared with the stored events, instead of `calendarView/delta` (beta-only for other calendars); changed series are read again with their instances, every series once a week |
| Google event types (2026-10-09) | every type is imported (default, outOfOffice, focusTime, workingLocation, birthday, fromGmail), all but default read-only; no `eventTypes` filter |
| Readable block and attendees (2026-10-09) | no readable block on Google and Microsoft events that have attendees at the provider (meetings made in Google or Outlook), because those providers share or mail the organizer's description; the fields and the ticket link stay in FreeScout, and events FreeScout creates keep the block (instead of section 15 for these events). A room or resource counts as an attendee (controller, kept on the manual test). CalDAV is unaffected (`SCHEDULE-AGENT=CLIENT`, no server mail) |
| Monthly on day 29 to 31 at Microsoft (2026-10-09) | refused on Microsoft calendars with "Microsoft calendars can't repeat on day 29, 30 or 31 of the month." (instead of "every option maps" in section 7); Outlook's "last day" rules are read and written |
| Read-only remote calendars (2026-10-09) | a calendar the account may only read (CalDAV without write privilege, Google `reader` or `freeBusyReader`, Graph `canEdit` false) gets `calendars.remote_read_only` when it is picked, refreshed when the pick screen lists the account again; `Calendar::isWritable()` is false and the permission matrix offers only Dashboard and View |
| Microsoft deletions (2026-10-09) | a provider that can confirm deletions (the optional `ConfirmsDeletions`) is asked before the generation sweep deletes events, and only the events it confirms gone are deleted. Graph checks with `$filter=iCalUId` on the calendar's events, because an event deleted in Outlook moves to Deleted Items and keeps its id; to verify by hand (manual test M0) |
| v2 line items without `event_uid` (2026-10-09) | the 2 CalDAV line items of the rehearsal without `event_uid` stay as they are and show their stored text (the `LineItemText` fallback) |
| Health problems (2026-10-15) | a connection with the status `error` (made by the v2 upgrade without a login) counts like one that needs reauthorization, and a failing calendar of a problem account is covered by that account's problem; for agents, `sync_failed` is also true while the calendar's account must sign in again, and a failing calendar that never synced counts as not up to date at once, while it becomes an administrator's problem a day after its creation |
| CalDAV redirects (2026-10-09) | when a CalDAV address redirects to another host and that host answers 401, the request goes there once more with the login (Guzzle drops it on a cross-host redirect), never from https to http; every hop passes the host check |
| Rich text from Google and Microsoft (2026-10-09) | descriptions are read as plain text (links kept), and FreeScout writes a description back, as plain text, only when what it sends differs from the provider's text: on its first edit of an event (which adds the readable block), and later when the text or the field values change; a meeting gets no block, so only a change of its text does. An accepted limit (wording refined in the final review, 2026-10-15) |
| CalDAV refused login (2026-10-15) | a CalDAV server's final 401 during a sync or a write marks the connection `needs_reauth` right away, as Google and Microsoft do: its calendars stop syncing, writes answer 502 `connection_needs_reauth`, the health check mails once, and the account's row opens the login form. A digest challenge Guzzle answers itself never counts, a 403 never counts, and discovery during "Connect" or "Reauthorize" only shows the form error (instead of the calendar error that reached administrators a day later as a failing calendar) |
| Forgetting solved health problems (2026-10-15) | a mailed problem is forgotten only after it has been gone for more than 24 hours, so one that comes and goes is mailed once; the banners show only the problems that are present now, and the Microsoft secret's "expires soon" and "expired" keep sharing one problem (instead of "sent again when it returns" in the connection health row of 2026-10-07) |
| Health check lock (2026-10-15, controller) | the check stores its result with a compare-and-swap on the option's row and queues the mails only after that, so two `calendar:sync` runs that check at the same moment mail a new problem once; Laravel 5.5 has no `Cache::lock` |
| Moving a meeting (2026-10-15) | refused: an event from a provider calendar that lists an attendee other than the calendar's own account answers 422 on `calendar_id` with "This is a meeting with attendees. Move it in the calendar app it comes from, so the attendees are not sent a cancellation.", for every target (another provider, another account, and the same account, because every move away from a provider is a create plus a delete), before any provider call. Deleting a meeting at its organizer's provider cancels it for every attendee, and the copy would invite nobody (instead of "allowed" in the move row of 2026-10-07 for meetings; Lars) |
| Core's `checkUrlHost()` (2026-10-15) | its over-blocking of addresses with a port or a username is not reported upstream; the module checks `scheme://host/` only (Lars) |
| W3 execution (2026-10-15) | W3 was drafted task by task on one draft branch, `w3-draft`, and `v3` was fast-forwarded to it, with a final whole-branch review and one fix round instead of re-transcribing the plan (Lars) |
| "Sync now" (2026-10-19) | syncs right away (20 s budget) and says what changed, on every external calendar's settings card and edit page and in the calendar page's sidebar for admins and agents (agents sync the calendars they can view and never read the error); a calendar just added syncs at once from the page with a live status; a calendar synced less than 30 s ago is not fetched again; no event count on the cards (Lars) |
| Google UID kept by a deleted event (2026-10-19) | an event moved out of a Google calendar and back is inserted again under a new UID when Google still keeps the deleted copy's iCalUID (409 duplicate), as on CalDAV servers that allow a UID once per account (controller, from a bug Lars hit) |
| Invites in Google and Microsoft calendars (2026-10-19) | an invite added to a Google or Microsoft calendar becomes an ordinary event without attendees, and the readable block names the organizer; CalDAV keeps ORGANIZER and ATTENDEE with `SCHEDULE-AGENT=CLIENT` (Lars) |
| RSVP and the event (2026-10-19) | Yes and Maybe also add the event to the chosen calendar (or apply the update the card offers); "Add to calendar" adds without answering; No keeps an event that is in a calendar, struck through as declined (Lars) |
| Cancelled and declined events (2026-10-19) | FreeScout and CalDAV calendars use `STATUS:CANCELLED` or our attendee's `PARTSTAT=DECLINED`, and the calendar page strikes both through; Google and Microsoft get "Cancelled: " or "Declined: " before the title, in the install's language, and a free time (Google's cancelled status deletes an event) (Lars) |
| Answers to our invites (2026-10-19) | a REPLY to an invite we sent leaves the conversation's status as it was; only a line item records it (Lars) |
| Outgoing invites (2026-10-19) | "Invite customer" in the conversation modal is off by default; the mail is the agent's optional message plus a fixed summary (what, when, where); every invite action adds a line item (Lars) |
| Invite details (2026-10-19, confirmed by Lars 2026-10-23) | the answer buttons show unless the invite says `RSVP=FALSE` (the spec's rule; RFC 5545's default is FALSE); answers and the summary mail are in the install's language; the summary's time is in the event's own zone with its name, because FreeScout mailboxes have no time zone (instead of "the mailbox timezone" in section 13); updates go out when SEQUENCE rose (any change of time, rule, title, place or description), people added get an invitation and people removed a cancellation; "Notify attendees" also covers a drag on the calendar, which has no checkbox; an attendee's earlier answer is kept when the time changes; `invite.ics` goes as `application/ics` next to the inline `text/calendar` part; an answer is processed only from the address we invited, and only for the whole event; answering an invite makes its organizer a FreeScout customer, because core addresses a reply to a customer |
| Reminders (2026-10-23) | as section 14: mail to the event's author and the linked conversation's assignee while they can view the calendar; no bell (section 24); events without an author or conversation get none; an all-day event's reminder goes at 09:00 in the calendar's zone, on the day for the choices under one day and that many whole days before for the rest (Lars) |
| Translations (2026-10-23) | Dutch, German, French and Spanish get every string; the other 17 languages fall back to English (instead of all 21 in section 22); strings no longer used leave all 21 files (Lars) |
| Release deviations from W1 (2026-10-23) | confirmed: an rc tag only keeps its zip as a workflow artifact; `README.md` stays out of the zip; the agent calendar shape uses `writable` and has `display_priority`, `has_title_template` and `last_error` (Lars) |
| Release candidate (2026-10-23) | no private mirror: the zip is built locally with the release script and its checks, and tested on Lars's own mailbox before the 3.0.0 tag (Lars) |
| Wiki, #83 and issues (2026-10-23) | may go online before the release, after Lars approves the text; each wiki page says it applies from 3.0, and nothing names the 2.6.4 findings (Lars) |
