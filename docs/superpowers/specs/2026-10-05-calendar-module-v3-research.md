# Calendar module 3.0: background research (2026-10-05)

This file holds the research behind [the 3.0 design](2026-10-05-calendar-module-v3-design.md): five parallel investigations, done on 2026-10-05.

- Paths are relative to the module root, unless they start with `freescout/`, which means the FreeScout core checkout one level above `Modules/`.
- External facts were true on the research date. Re-check anything time-sensitive (versions, policies, dates) during planning.

## A. Audit of the current module (v2.6.4)

### A1. How v2 works

- **`calendars` table:**
  - Columns: `name` (unique), `enabled`, `color`, `type` (`normal`, `ics` or `caldav`), `title_template`, `sync_version`, `last_full_sync`, `force_legacy_mode`.
  - `permissions` is JSON: `{<user or team id>: {showInDashboard, showInCalendar, createItems, editItems}}`.
  - `custom_fields` is JSON and mixes field definitions (`fields[]`) with connection settings (`url`, `username`, `password` in plaintext, `refresh`, `last_update`).
- **`calendar_items`** (only for `normal` calendars):
  - Columns: `calendar_id`, `author_id`, `is_all_day`, `is_private`, `is_read_only`, `title`, `body`, `state`, `location`, `start`/`end`.
  - `start`/`end` are `dateTimeTz` holding UTC wall-clock values.
  - `custom_fields` is JSON: `{author_id, conversation_id, custom_field_<id>: value}`.
  - There is no `uid` column and no foreign keys.
- **`calendar_event_index`:** a cache of external events, only used when `CALENDAR_ENABLE_EVENT_INDEX=true`.
- **ICS calendars:** `file_get_contents($url)` saves the feed to `storage/app/ljpccalendarmodule/{id}.ics`, and every read parses that file again (`Entities/Calendar.php`).
- **CalDAV calendars:**
  - Reading uses sabre/dav REPORT, with a fallback that GETs each event by href (`Http/Helpers/CalDAV.php`).
  - Writing PUTs a hand-built VEVENT to `<collection><uid>.ics`, with module metadata as YAML inside DESCRIPTION.
- **Sync:**
  - The `schedule` hook runs `calendar:update-external-calendars` every minute.
  - That command dispatches `UpdateExternalCalendarJob` on FreeScout's single queue, which is shared with outgoing mail.
- **Hooks used:**
  - `javascripts`: injects laroute.js and general.js on every page.
  - `menu.append`, `menu.selected`
  - `dashboard.before`, `schedule`
  - `conversation.action_buttons`
  - `thread.action_text`: line items with `action_type` 186.
  - `thread.attachments_list_append`
  - `settings.sections`, `settings.view`, `settings.section_settings`
- **Frontend:**
  - The calendar page is Toast UI Calendar 2.1.3 plus moment, with about 1000 lines of inline JS in `Resources/views/partials/scripts.blade.php`.
  - The conversation page uses `Public/js/calendar-conversation.js`.
  - The settings page is a Vue 3 + Vite app (`Resources/src/CalendarSettings`) that is built by hand.

### A2. Feature inventory (parity checklist for 3.0)

**Settings**
- Unlimited calendars with name, color (a picker plus 9 swatches) and type. The type is fixed after creation.
- ICS URL, or CalDAV URL, username and password, with 9 refresh intervals.
- A title template with clickable merge tags.
- A custom field builder:
  - Types: text, number, dropdown, boolean, multiselect, date, email, source.
  - Each field has a required flag and CSV options.
  - Fields can be reordered by dragging.
- A permission matrix per user and team (dashboard, view, create, edit), with "select all" per column.
- Two export URLs (full and free/busy).
- Delete calendar.

**Calendar page**
- **Views:** day, week and month. Mobile is forced to day view.
- **Format:** the week starts on Monday, times are 24-hour, and the interface follows the locale.
- **Navigation:** previous, next and today. Each calendar can be toggled visible or hidden.
- **Creating:** select a time range to create an event, with title, start, end, location, body, calendar and custom fields.
- **Event details:**
  - Edit and delete.
  - "Create copy". When the times are unchanged it prefixes "Copy of".
  - Copy permalink (`/calendar#<id>`).
  - Shows the creator and a source link back to the conversation.
- **Drag and resize** to reschedule.

**Conversation**
- **"Add to calendar":**
  - Fields: calendar, title (prefilled as `[assignee] subject`, hidden when the calendar has a template), start time (fixed 1-hour length), custom fields.
  - Email-type fields are prefilled with the customer's email.
- **Conversation history:** a line item with a "View event" link.
- **ICS attachments:** an "Add file.ics to a calendar" link per attachment. If the conversation has an ICS attachment, the calendar button imports it.

**Other**
- Dashboard card with the next 3 events within 7 days.
- Top menu item.
- Token-based ICS export.
- Two-way CalDAV; read-only ICS.
- The `--force` sync command and the performance flags.
- 21 translations.
- Auto-update through `latestVersionUrl`.

### A3. Security and integrity findings

The security findings (C1, C2, H1 to H4, M1 to M4) are kept out of this public repo until 3.0 is released, because 2.6.4 is still in use. They are in the maintainer's private notes. The hardening requirements that address them are in design section 18.

**Data integrity**
- **D1:** a CalDAV failure returns `[]`, which overwrites the cache with an empty calendar.
- **D2:** editing a CalDAV event rebuilds the VEVENT, which drops RRULE, attendees, alarms and the all-day flag. No ETag is sent.
- **D3:** the code assumes `href = uid.ics`, so events created elsewhere are edited or deleted at the wrong resource.
- **D4:** the generated ICS is invalid: `DTSTART;TZID=UTC:...Z`, no line folding, and all-day dates are off by one day east of UTC (#49).
- **D5:** all-day is guessed from the duration: anything over 86280 s counts as all-day.
- **D6:** the browser timezone is assumed to equal the app timezone, and the user's FreeScout timezone is ignored.
- **D7:** `whereBetween('start')` hides multi-day events that started earlier.
- **D8:** sync job problems:
  - The job overwrites admin edits.
  - Jobs are not deduplicated.
  - There is no HTTP timeout.
  - A hung job blocks the mail queue.
- **D9:** recurring ICS imports are expanded into about 104 separate items, CANCEL is imported as a new event, and re-importing creates duplicates.
- **D10:** probably broken on PostgreSQL (`timestamptz`).
- **D11:** index inconsistencies.
- **D12:** team permissions are never evaluated.
- **D13:** `getEvents?eventId=` and the `authorized` route are broken.
- **D14:** one bad feed blanks the dashboard (#61), and there are N+1 queries.

### A4. Recurring themes in the closed issues

1. **Install and migration failures:** #24, #26, #45, #51, #55, #60, #62, #66, #76. This is the biggest support cost.
2. **Timezones, DST, all-day and locale:** #11, #21, #27, #31, #38, #49, #53.
3. **CalDAV providers:** cPanel #48, Google #69 (needs OAuth), Zoho #77.
4. **Outlook:** #44, #61.
5. **PHP versions:** #17, #20, #70.
6. **Conflicts with other modules:** #57, #58 (suspected cause: the global JS injection).
7. **Fragile YAML metadata:** #68.
8. **Scope requests:**
   - #42: calendar per mailbox. Lars answered that it would come "when I have time to rebuild the whole module".
   - #46: more views.
   - #47: email the event to recipients.
   - #83: invite handling.

### A5. Vendored dependencies (v2)

| Package | Version | Overlap with core |
|---|---|---|
| psr/log | 1.0.2 | pinned to match core |
| sabre/dav, http, xml, vobject, uri, event | 4.7.0, 5.1.12, 2.2.11, 4.5.6, 2.3.4, 5.1.7 | none |
| johngrogg/ics-parser | 3.4.1 | none |
| spatie/icalendar-generator, spatie/enum | 2.8.1, 3.13.0 | none |
| dallgoot/yaml | 0.9.1.2, needs PHP ≥ 8.1.14 | none |

Load order matters:
- The module's Composer autoloader is registered with `prepend=true` in `boot()`.
- For any class that hasn't been loaded yet, it wins over core's autoloader.

## B. FreeScout platform

### B1. Versions

- **Latest:** 1.8.245 (2026-10-03). The local checkout is 1.8.218.
- **PHP:**
  - Minimum PHP 7.4 since 1.8.237.
  - PHP 8.4 support landed in 1.8.167 to 1.8.192, and PHP 8.5 in 1.8.200.
  - The installation guide says not to use 8.1 ("8.2 and newer are OK").
- **Laravel:** stays on 5.5.40 with overrides. The "Laravel No-Upgrade Policy" is in the wiki's Development-Guide.
- **1.8.232 (2026-07-31):**
  - Guzzle 6.5.8 to 8.0.1, psr7 1.9.1 to 3.0.0, promises to 3.0.0, psr/http-message 1.0.1 to 2.0.
  - New: psr/http-client, psr/http-factory.
- **1.8.240:** activating a module renames its folder to the namespace of its first provider (`LJPcCalendarModule`).
- **1.8.242/243:** remote storage (S3 module).
  - Use `Storage::disk('local_app')`; the `private` disk is gone.
  - Read attachments with `Attachment::getFileContents()` / `getFileStream()`.
- **Other changes:**
  - 1.8.198: `Helper::sanitizeRemoteUrl()` and `getRemoteFileContents()`, plus `APP_REMOTE_HOST_WHITE_LIST`.
  - 1.8.219: raw queries must add the table prefix.
  - 1.8.234: the conversation status API.
  - 1.8.240: `Mailbox::excludeArchived()`.
- **`module.json` fields:**
  - `requiredAppVersion` only shows a warning; it is enforced only for licensed official modules.
  - `requiredModules`, `requiredPhpExtensions`.
  - `latestVersionUrl` may point to a module.json (1.8.173+).
  - There is no PHP-version field.
- **CSP** (`freescout/app/Misc/Helper.php`, `getCspValue`/`cspMetaTag`):
  - `script-src 'self' 'nonce-…'`, with `unsafe-inline` and `unsafe-eval` stripped from anything modules add.
  - `default-src 'self'`, `form-action 'self'` (extend with `csp.form_action`), `base-uri 'none'`.
  - `style-src` allows inline styles.

### B2. Hooks

- No hook was removed between 1.8.150 and 1.8.245, and about 60 were added.
- **Useful for 3.0:**
  - `email.reply_to_customer.swiftmessage` (1.8.182)
  - `conversation.get_action_buttons` (1.8.160)
  - `conversation.before_threads` (1.8.186)
  - `customers.profile_tabs.append` (1.8.176)
  - `mailbox.sidebar.buttons` (1.8.213)
  - `mailbox.deleted` (1.8.199)
  - `command.after_app_update` (1.8.202)
  - `csp.form_action`, `csp.script_src`
- **Incoming mail hooks** (`freescout/app/Console/Commands/FetchEmails.php` in 1.8.218):

  | Hook | Line | What it gives |
  |---|---|---|
  | `fetch_emails.data_to_save` | ~858 | the raw Webklex message; the only place where `method=` is visible |
  | `fetch_emails.should_save_thread` | ~915/945 | |
  | `conversation.created_by_customer` | filter ~1218, action ~1230 | attachments already saved |
  | `conversation.customer_replied` | filter ~1220, action ~1233 | attachments already saved |

### B3. Core mailbox OAuth

- **Code:** `freescout/app/Misc/Mail.php` (`oauthGetAuthorizationUrl`, `oauthGetAccessToken`, refresh), `freescout/app/Mailbox.php`, `MailboxesController::oauth()`. Plain cURL, no OAuth library.
- **Where things are stored:**
  - Client id: in `in_username`/`out_username`.
  - Secret: encrypted, in `in_password`/`out_password`.
  - Tokens: in `mailboxes.meta['oauth']`, encrypted (1.8.209+).
- **Scopes are hardcoded:**
  - Microsoft: `offline_access IMAP.AccessAsUser.All SMTP.Send` (outlook.office.com).
  - Google: `https://mail.google.com/`.
  - There is no filter to extend them.
- **Conclusion:** a module cannot rely on core's tokens. It can at most reuse the same Azure or Google client registration and run its own consent and token storage.
- **OauthLogin module:** plain cURL. It doesn't store tokens, so it is useless for background sync.

### B4. Vendor constraints

- **Core ships:**
  - laravel 5.5.40, symfony 3.4.x
  - Carbon 1.35.1
  - psr/log 1.0.2, psr/container 1.0, psr/simple-cache 1.0.1
  - psr/http-message 2.0, Guzzle 8.0.1, psr7 3.0.0
  - monolog 1.27.1, ramsey/uuid 3.9.6
  - swiftmailer 6.1.3, egulias 2.1.10, webklex 4.1.1
  - doctrine/dbal 2.12, flysystem 1.1.4
  - htmlpurifier 4.19, eventy 0.5.4, symfony polyfills
- **Not usable:**
  - `google/apiclient` (monolog ^2.9, Guzzle ^7, PHP ^8.1)
  - `google/auth` (psr/log ^2|^3)
  - `microsoft/microsoft-graph` (PHP ^8.2, Guzzle ^7.4.5 via Kiota, ramsey/uuid ^4)
- **Usable:**
  - `league/oauth2-client` 2.9.1 accepts Guzzle ^8, but saves little.
  - sabre/* doesn't overlap with core.

### B5. Competitors and demand

- **Official modules:** 78 at freescout.net/modules ($1.99 to $24, lifetime per instance). None of them is a calendar, booking or appointment module.
- **Community:** LJPc Calendar is the only calendar module on the Community-Modules wiki list. Related projects:
  - `AllstarGER/freescout-calendar-invite-module` (March 2026) only displays invites. It sends RSVPs with `Mail::raw` and system mail settings, and trusts values posted from the browser.
  - `tombruton87/freescout-mail365` shows a pattern for Graph with plain cURL and app-only access.
- **Workarounds today:** Zapier, Make or CalGet, which need the paid API & Webhooks module.
- **Core issues about calendars:**
  - #1383: a team calendar, pointed to this module.
  - #1412, #2054: incoming ICS.
  - #5063: Zoho CalDAV.

## C. Google and Microsoft

### C1. Google

- **API choice:**
  - Use Calendar API v3, not Google CalDAV.
  - Google CalDAV is OAuth-only and "not officially supported" (DAVx5).
  - Vendors have documented bugs with edits to recurring instances.
- **OAuth for self-hosted installs:**
  - Every admin needs their own Cloud project and a Web OAuth client.
  - Redirect URIs must be HTTPS on a public domain. The redirect happens in the browser, so an intranet install works if it has a public DNS name.
  - The device flow does not allow Calendar scopes.
- **Scopes and verification:**
  - Calendar scopes are **sensitive**, not restricted.
  - Verification takes 3 to 5 days and needs a verified domain, a privacy policy and a demo video.
  - It is not required for Internal (Workspace) apps or for personal use under 100 users.
- **Testing mode:**
  - In **Testing** status, refresh tokens expire after **7 days**. The admin must press "Publish app".
  - External apps that are unverified but in production show an "unverified app" screen and are capped at 100 users for the project's lifetime.
- **Other ways a token is lost:**
  - It goes unused for 6 months.
  - The user revokes access.
  - An account holds more than 100 tokens per client.
  - A Workspace admin blocks unconfigured apps.
- **Quotas:**
  - Projects created after 2026-05-01 get new quotas, and billing changes are announced for later in 2026.
  - Default limits are 10,000 requests per minute per project and 600 per minute per user. Over-limit returns 403 or 429; Google advises backoff with jitter.
- **Sync:**
  - `syncToken` cannot be combined with `timeMin`/`timeMax`, so the first sync is unbounded.
  - A 410 means a full resync.
  - Deleted events arrive as `status=cancelled`.
- **Push channels** require public HTTPS, last 7 days by default, and are not renewed automatically.
- **Recurrence:** RRULE, EXDATE and RDATE on the master. Instances carry `recurringEventId` and `originalStartTime`.
- **Domain-wide delegation** is possible (an RS256 JWT through `openssl_sign`), but the key is a domain-wide master key, and new Cloud orgs block key creation by default.

### C2. Microsoft

- **EWS:**
  - Off by default since **2026-10-01** unless the tenant allow-listed it.
  - Shutdown starts 2027-04-01, with full removal around May 2027.
  - Exchange Online has no CalDAV, so Graph is the only read/write route.
- **Graph endpoints:**
  - `calendarView` returns expanded occurrences.
  - v1.0 documents delta only for the default calendar (`/me/calendarView/delta`). Delta per calendar is beta only.
  - A delta token can expire, which returns `syncStateNotFound` or 410.
- **Event IDs:** they change when an item moves, unless every request sends `Prefer: IdType="ImmutableId"`.
- **Change notifications:**
  - At most 10,080 minutes.
  - The endpoint must be public HTTPS, validate within 10 seconds, and acknowledge within 3 seconds.
  - Not available for `.Shared` delegated access.
- **Throttling:** 10,000 requests per 10 minutes and 4 concurrent requests per app per mailbox. Over-limit returns 429 with `Retry-After`.
- **Simplest app setup:**
  - A single-tenant app with delegated `Calendars.ReadWrite` and `offline_access`.
  - Refresh tokens last 90 days and renew on every use.
  - Secrets last at most 24 months.
  - Admins who already connected a mailbox by OAuth can reuse that app registration.
- **Personal outlook.com accounts:** need an Entra tenant registration, which is too painful. Point these users to ICS.
- **Application permissions:**
  - Scoping them requires Exchange RBAC for Applications through PowerShell, and the admin must remove the unscoped Entra grant.
  - This is expert-level work, so it stays out of 3.0.
- **Recurrence (`patternedRecurrence`):**
  - Pattern types: daily, weekly, absolute or relative monthly, absolute or relative yearly.
  - No hourly recurrence, no full BYSETPOS, no multiple BYMONTHDAY, no RDATE.
  - Converting Graph to iCal is mechanical; converting iCal to Graph loses information.
  - Event types: `occurrence`, `exception`, `seriesMaster`.
- **Times:** `dateTimeTimeZone` has no offset and supports Windows timezone names. Convert them to IANA with `IntlTimeZone::getIDForWindowsID()` or the CLDR `windowsZones` table.

### C3. Admin onboarding steps

- **Google (15 to 30 minutes):**
  1. Create a Cloud project.
  2. Enable the Calendar API.
  3. Fill in Branding.
  4. Set the audience: Internal (Workspace), or External plus "Publish app".
  5. Add the calendar scopes.
  6. Create a Web client with the exact redirect URL.
  7. Paste the id and secret into the module.
  8. Users click through the "unverified" screen.
  9. In Workspace, the admin may need to mark the client as Trusted.
- **Microsoft 365:**
  1. In Entra, create an app registration (single tenant) with a Web redirect URI.
  2. Create a client secret (at most 24 months) and copy its Value.
  3. Add the delegated permissions `Calendars.ReadWrite`, `Calendars.ReadWrite.Shared`, `offline_access` and `User.Read`, then grant consent.
  4. Paste the tenant, client id and secret into the module.
  5. Rotate the secret before it expires.

## D. iTIP and iMIP (issue #83)

### D1. Standards

- **Sources:** RFC 5546 (iTIP) and RFC 6047 (iMIP).
- **Methods:**
  - PUBLISH: no RSVP expected.
  - REQUEST: invite or update.
  - REPLY: PARTSTAT ACCEPTED, TENTATIVE or DECLINED.
  - CANCEL.
  - ADD, REFRESH, COUNTER, DECLINECOUNTER: rare.
- **Identity:** UID, plus RECURRENCE-ID for a single occurrence.
- **Ordering:** the highest SEQUENCE wins, then the latest DTSTAMP.
- **SEQUENCE:** must increase when DTSTART, DTEND, DURATION, RRULE, RDATE, EXDATE or STATUS change. A REPLY keeps the request's SEQUENCE.
- **CANCEL:**
  - Without RECURRENCE-ID it cancels the whole series.
  - With RECURRENCE-ID only those occurrences.
  - `RANGE=THISANDFUTURE` cancels from that occurrence on.
  - Google tends to split series instead.
- **REPLY:** exactly one ATTENDEE (the replier) with PARTSTAT, plus ORGANIZER, UID, DTSTAMP and the request's SEQUENCE.
- **iMIP:**
  - The MIME part must be `text/calendar; method=X`, with X matching METHOD.
  - From, Sender and Reply-To can't be trusted, so read ORGANIZER and ATTENDEE from the data.
  - Only the organizer may update or cancel.

### D2. sabre/vobject `ITip\Broker`

Versions 4.5.6 and 5.0.0 behave the same here; 5.0 needs PHP ≥ 8.2.

- **`processMessage()`** handles only REQUEST, CANCEL and REPLY for VEVENT.
- **`processMessageRequest()`:**
  - Replaces every component and ignores SEQUENCE.
  - An update for a single occurrence wipes the series (sabre-io/vobject#365, open since 2017).
- **`processMessageCancel()`:** cancels the whole series even when only one RECURRENCE-ID was given. Nextcloud's `TipBroker` overrides this per occurrence.
- **`parseEvent($newCal, 'mailto:…', $oldCal)`** builds a correct REPLY `Message`. Set `scheduleAgentServerRules=false` outside a CalDAV server.
- **Standalone use:** works without sabre/dav.
- **Conclusion:** use it for REPLY generation; write our own logic for applying updates.

### D3. Real-world formats

- **Gmail:**
  - `multipart/mixed` containing `multipart/alternative` (text, html, `text/calendar; method=REQUEST`), plus an `application/ics` attachment named `invite.ics`.
  - Sent from `calendar-notification@google.com`.
- **Exchange, Outlook and Teams:**
  - `multipart/alternative` with a base64 `text/calendar; method=REQUEST` part.
  - Windows TZIDs with VTIMEZONE, `X-MICROSOFT-*` properties, long hex UIDs.
  - ORGANIZER is sometimes missing; Nextcloud then falls back to the recipient.
  - Event attachments that aren't invites use PUBLISH.
- **iCloud:** ORGANIZER is often an `@imip.me.com` relay address, so always reply to ORGANIZER, never to the conversation's customer.
- **RSVP pitfalls:**
  - The REPLY part must be inline in `multipart/alternative`; Outlook ignores it as an attachment.
  - ATTENDEE must be exactly the invited address, otherwise Outlook treats it as a "party crasher".
  - From should match ATTENDEE.
  - UID and SEQUENCE must match the request.
  - Whether Google applies third-party REPLY messages is undocumented. It needs a test matrix.
- **Security:** Outlook and Gmail apply REQUEST or CANCEL from anyone who knows the UID (Tarlogic, December 2025). Never auto-apply a CANCEL from an unverified sender.

### D4. FreeScout handling

- **Storage:**
  - `text/calendar` parts are stored as attachments.
  - Unnamed parts become `calendar.ics` (`freescout/overrides/webklex/php-imap/src/Part.php:301-314`, `Attachment.php:290-298`; since 1.8.183).
  - The `method=` parameter is dropped after fetching.
  - A rare inline part without a name goes to `$bodies['calendar']` and is effectively lost.
- **One mail, several mailboxes:** a mail can be imported into several mailboxes (`extra_import`), so match by UID across conversations.
- **Sending an RSVP:**
  - `freescout/app/Jobs/SendReplyToCustomer.php` calls `MailHelper::setMailDriver($mailbox)`, which handles SMTP, OAuth and sendmail.
  - `freescout/app/Mail/ReplyToCustomer.php:136` fires `email.reply_to_customer.swiftmessage`. That is where a `Swift_MimePart($ics, 'text/calendar')` with the `method` parameter can be attached. It lands in `multipart/alternative` by default.

### D5. Other helpdesks

| Helpdesk | Invite handling |
|---|---|
| Front | invite card with RSVP and update notices, but only for individual Gmail and O365 inboxes |
| Zammad | read-only .ics preview (5.2+) |
| osTicket | plain attachment |
| Zendesk, Freshdesk, Help Scout | nothing native documented |

No helpdesk applies updates to a shared calendar, so this would set 3.0 apart.

## E. Frontend library

### E1. Toast UI Calendar

- The repo is archived. The last release, 2.1.3, was on 2022-08-16 and the last commit on 2023-02-09.
- 208 issues remain open, including the DST and timezone bugs #258, #423, #882, #1049 and #1461.
- The module ships 4.3 MB of its source in every zip.

### E2. Comparison (sizes measured by the researcher, min+gzip)

| | FullCalendar 7.1.0 | EventCalendar 5.15.0 | Schedule-X 4.9.1 |
|---|---|---|---|
| Release | 2026-09-05 (v7.0.0 2026-06-19) | 2026-09-25 | 2026-09-29 |
| License | MIT core; premium is commercial, non-profit, or a free AGPLv3 key | MIT, everything | MIT core plus a proprietary premium tier |
| Size | about 89 KB tree-shaken | about 39 KB | about 80 KB |
| Named timezones | built in (Temporal) | no (fixed offsets only; #677) | yes |
| Drag, resize, select to create | MIT | MIT | select to create is premium |
| List and year views | MIT | list only | yes |
| Resource views | premium | MIT | premium |
| Locales | 79, with right-to-left | through Intl | about 33 |
| Maintainers | commercially funded since about 2010 | 1 | 1 |

**FullCalendar v7 notes:**
- CSS ships as separate files.
- The package is ESM-only.
- There is no Bootstrap 3 theme, so the classic theme is tuned with `--fc-classic-*` variables.
- Open regressions: #8094, #8101, #8102.

**Dropping moment.js:**
- It can go. The bundled copy of moment-with-locales is 76 KB gzipped, and core doesn't ship moment.
- Use `Intl` and FullCalendar's formatting instead.

**Root cause of the DST bugs:** the code converts with `moment(input.value).toISOString()`, which uses the browser's timezone, while FreeScout formats everything in the user's profile timezone.

### E3. CSP rules for module JS

- Load scripts as `<script type="module" src … nonce>`.
- No `eval`. Precompiled Vue templates are fine.
- No inline JS with data. Pass data in a `<script type="application/json">` block, which needs no nonce.
- Don't add ES modules to the global `javascripts` filter, because Minify concatenates its files.

## F. Real installations (read-only inventory, 2026-10-05)

Hosts and client names are kept in the maintainer's private notes.

| Install | FreeScout | PHP | DB | Calendar module | Data |
|---|---|---|---|---|---|
| A (LJPc's own mailbox) | 1.8.234 | 8.3 | MariaDB 11.8 | fork 2.7.3 | 8 calendars (6 normal, 1 CalDAV, 1 ICS; 2 with `custom_fields = NULL`), 114 items (1 all-day, 59 linked to a conversation), 1740 index rows, 105 line items |
| B (client) | 1.8.245 | 8.3 | MariaDB 10.11 | fork 2.7.3 | 7 calendars (3 normal, 4 ICS incl. Google calendars), `display_priority` set on 5, 598 items (58 all-day, oldest 2021-11, 169 linked), empty index, 402 line items |
| C (client) | 1.8.243 | 8.2 | MariaDB 10.11 | public 2.6.4 | 6 calendars (4 normal with `custom_fields = NULL`, 2 ICS incl. a Google calendar), 5 items (2 linked), 4 line items, names with umlauts |
| D, E (clients) | 1.8.238, 1.8.240 | | | not installed | |

All installs have the same 7 module migrations recorded.

**About the 2.7.x fork (2.6.5 to 2.7.3, all released 2026-07-27)**

This is a privately maintained fork of 2.6.4. Source: `CHANGELOG.md` in the installed module.

What it adds:
- Overlap-based range queries for local and external events.
- Protection against stale responses while navigating.
- On API errors, keeping the events already shown instead of clearing them.
- `display_priority` in `calendars.custom_fields`:
  - Lower renders first.
  - Default 100 for local calendars, 200 for external ones.
  - Used before the start date in the all-day and multi-day rows.
- A denser month view.
- A "+N" popup that grows to fit.
- Hover titles.
- A collapsible calendar list, stored in browser storage.
- An all-day section that fits every row when expanded.

What it leaves alone:
- No migrations and no core changes.
- `module.json` keeps the LJPc `latestVersionUrl`, so it auto-updates to 3.0.

## Sources

- **FreeScout:**
  - releases: `gh api repos/freescout-help-desk/freescout/releases`
  - [Microsoft 365 OAuth wiki](https://github.com/freescout-help-desk/freescout/wiki/Connect-FreeScout-to-Microsoft-365-Exchange-via-OAuth)
  - [module directory](https://freescout.net/modules)
- **Google:**
  - [CalDAV guide](https://developers.google.com/workspace/calendar/caldav/v2/guide)
  - [sync](https://developers.google.com/workspace/calendar/api/guides/sync)
  - [push](https://developers.google.com/workspace/calendar/api/guides/push)
  - [quota](https://developers.google.com/workspace/calendar/api/guides/quota)
  - [sensitive scopes](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification)
  - [OAuth token expiry](https://developers.google.com/identity/protocols/oauth2)
  - [unverified apps](https://support.google.com/cloud/answer/13464323)
  - [recurring events](https://developers.google.com/workspace/calendar/api/guides/recurringevents)
- **Microsoft:**
  - [calendarView](https://learn.microsoft.com/en-us/graph/api/user-list-calendarview?view=graph-rest-1.0)
  - [event delta](https://learn.microsoft.com/en-us/graph/api/event-delta?view=graph-rest-1.0)
  - [immutable ids](https://learn.microsoft.com/en-us/graph/outlook-immutable-id)
  - [throttling](https://learn.microsoft.com/en-us/graph/throttling-limits)
  - [shared calendars](https://learn.microsoft.com/en-us/graph/outlook-get-shared-events-calendars)
  - [recurrencePattern](https://learn.microsoft.com/en-us/graph/api/resources/recurrencepattern?view=graph-rest-1.0)
  - [refresh tokens](https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens)
  - [EWS deprecation](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/deprecation-of-ews-exchange-online)
  - [RBAC for applications](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac)
- **iTIP:**
  - [RFC 5546](https://www.rfc-editor.org/rfc/rfc5546), [RFC 6047](https://www.rfc-editor.org/rfc/rfc6047)
  - [sabre Broker 4.5.6](https://github.com/sabre-io/vobject/blob/4.5.6/lib/ITip/Broker.php), [vobject#365](https://github.com/sabre-io/vobject/issues/365)
  - [Nextcloud TipBroker](https://github.com/nextcloud/server/blob/master/apps/dav/lib/CalDAV/TipBroker.php)
  - [Tarlogic calendar abuse](https://www.tarlogic.com/blog/abusing-calendar-processing/)
- **Frontend:**
  - [Toast UI](https://github.com/nhn/tui.calendar)
  - [FullCalendar releases](https://github.com/fullcalendar/fullcalendar/releases), [FullCalendar license](https://fullcalendar.io/license)
  - [EventCalendar](https://github.com/vkurko/calendar)
  - [Schedule-X premium](https://schedule-x.dev/premium)
  - [moment project status](https://momentjs.com/docs/#/-project-status/)
