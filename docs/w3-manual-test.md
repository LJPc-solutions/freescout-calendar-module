# W3 manual test: Google Calendar, Microsoft 365 and CalDAV servers

W3 built the Google and Microsoft providers from their documentation and hand-made fixtures, because there were no test accounts. This checklist is the live test with real accounts. It also covers what the CalDAV tests against Radicale can't show: real servers such as iCloud, Fastmail, Nextcloud and Zoho.

Each item gives the steps, the expected result, and where to look when the result differs. Items marked **UNVERIFIED** test an assumption the code relies on that no documentation confirms; write down what really happens, because the code may need a change. Number your findings by item (G8, M0 and so on).

## Before you start

**Setup**

- A FreeScout with the module from `v3`, opened at an HTTPS address. The local ddev site works: Google and Microsoft send the browser back to `https://freescout.ddev.site/calendar/oauth/{google|microsoft}/callback`, and only the browser has to reach it.
- No cron is needed. Sync a calendar by hand and see the counts:
  `cd ~/Projects/freescout && ddev exec php artisan calendar:sync --calendar=<calendar id> --force`
  The calendar id is in its settings URL (`?calendar=<id>`). Without `--force` only due calendars sync; "Sync now" makes a calendar due.
- The health check runs inside `calendar:sync`, at most every 15 minutes. To check right away, forget when it last checked first:
  `ddev exec 'echo "\$h = App\Option::get(\"ljpccalendarmodule.health\", [], true, false); unset(\$h[\"checked_at\"]); App\Option::set(\"ljpccalendarmodule.health\", \$h);" | php artisan tinker'`
  Removing the whole option (`App\Option::remove("ljpccalendarmodule.health")`) also forgets which problems were mailed, so every current problem is mailed again.
- Alert mails are queued on the `emails` queue. Send them with `ddev exec php artisan queue:work --once --queue=emails` (once per mail) and read them in Mailpit (`ddev launch -m`).
- Two agents help: one in Europe/Amsterdam and one in America/New_York, each with a browser in yet another zone.

**Accounts**

- Google: a Workspace account (for an Internal app) or a Gmail account (External app in Testing, with that account as a test user). A second Google account for invitations and sharing.
- Microsoft: a Microsoft 365 work account in a tenant where you can register apps and grant consent, a shared mailbox, and a second user for invitations and sharing.
- CalDAV: whichever of iCloud, Fastmail, Nextcloud and Zoho you can reach.

**Where to look when something fails**

1. The calendar's settings page: "Last synced" and "Last error". The error is FreeScout's own message plus the HTTP status, never the provider's answer.
2. The Connections tab: each account's status ("OK", "Needs reauthorization", "Error") and its last error.
3. FreeScout's log, `storage/logs/laravel-YYYY-MM-DD.log` (`ddev exec tail -f storage/logs/laravel-$(date +%F).log`). FreeScout only writes errors by default, and the lines below are warnings: add `APP_LOG_LEVEL=warning` to `.env` and run `ddev exec php artisan config:clear` first. Lines carry `calendar_id`, `provider`, `status` and at most 500 characters of the answer, tokens removed. The messages to search for: "A Google Calendar request failed", "A Microsoft Graph request failed", "An OAuth token request was refused", "An OAuth account lookup was refused", "A CalDAV server answered with an error", "A pulled calendar event was skipped".
4. The stored event: `ddev exec 'echo "echo Modules\LJPcCalendarModule\Entities\CalendarEvent::find(<event id>)->ics;" | php artisan tinker'`. The event id is in the permalink (`/calendar#e<id>`). Its DTSTART, RRULE, EXDATE and overrides show what FreeScout made of the provider's data; `read_only` on the same row says whether agents may change it.
5. The provider's own view: Google Calendar on the web, the Google Calendar API explorer, Outlook on the web, Microsoft Graph Explorer (https://developer.microsoft.com/graph/graph-explorer), and the Entra sign-in logs for `AADSTS` codes.
6. For the health mails: the problems FreeScout remembers as mailed (`notified`) and since when each of them is gone (`solved`; forgotten after more than 24 hours) in `ddev exec 'echo "echo json_encode(App\Option::get(\"ljpccalendarmodule.health\"));" | php artisan tinker'`, the queued and failed mails (the `jobs` and `failed_jobs` tables), and Mailpit.

## 0. First: Microsoft deletions (HIGH)

- [ ] **M0. Graph filters a calendar's events by iCalUId, for single events and for series. UNVERIFIED.**
  FreeScout removes an event deleted in Outlook only when this works. Outlook moves a deleted event to Deleted Items and keeps its id, so FreeScout asks the calendar's own event list for the event's iCalUId instead (`MicrosoftProvider::inCalendar()`).
  1. Connect a Microsoft account (M1, M2) and add its calendar. In Outlook, create a single event "M0 single" and a weekly series "M0 series" in that calendar. Sync.
  2. In Graph Explorer, signed in as the same account: `GET https://graph.microsoft.com/v1.0/me/calendars?$select=id,name` for the calendar id, then `GET https://graph.microsoft.com/v1.0/me/calendars/{id}/events?$select=subject,iCalUId&$top=50` for both iCalUIds.
  3. For each: `GET https://graph.microsoft.com/v1.0/me/calendars/{id}/events?$filter=iCalUId eq '{iCalUId}'&$select=id,subject&$top=1`.
  4. Delete both in Outlook. Repeat step 3, then sync twice.
  - **Expected:** step 3 answers 200 with exactly that event, for the single event and for the series master. After the deletes it answers 200 with an empty `value`, and after the syncs both events are gone from FreeScout.
  - **If not:** a 400 from step 3 means Graph doesn't filter this list on iCalUId. FreeScout then keeps every event deleted in Outlook for good. That is safe, but silent: a 400 is an answer the check expects, so nothing is logged. Note Graph's error text; the fix belongs in `MicrosoftProvider::inCalendar()`. If step 3 works but the events stay, look in the log for "Microsoft could not say whether events left out of a listing still exist" or "Microsoft events left out of a listing are checked at the next sweep".

## 1. Google Calendar

- [ ] **G1. Create the Google app.** Follow the wiki draft `docs/wiki/Google-Calendar.md`: a Google Cloud project, the Google Calendar API, the OAuth consent screen (Internal for Workspace, External in Testing for Gmail), the scopes `https://www.googleapis.com/auth/calendar.calendarlist.readonly` and `https://www.googleapis.com/auth/calendar.events`, and a "Web application" OAuth client with the redirect URL from Settings > Calendar > Connections. Save the client id and secret there.
  - **Expected:** "Google app saved.", an empty secret field with "A secret is saved. Leave it empty to keep it.", and "Connect a Google account" becomes a link.
  - **If not:** the error under the field. The secret never appears in the page; the local debugbar shows posted values, so check `.ljpc-settings` only.
- [ ] **G2. Connect an account.** Click "Connect a Google account" and allow both permissions.
  - **Expected:** Google's consent screen asks for the calendar list and the events; afterwards the pick screen "Choose calendars" lists the account's own and shared calendars, the primary one marked "Primary" and calendars the account may only read marked "Read only". The account on the Connections tab is the primary calendar's address in lower case.
  - **If not:** a floating error message on the Connections tab names the reason. `redirect_uri_mismatch` on Google's page means the redirect URL in the OAuth client differs from FreeScout's. "Google did not return a refresh token..." means the account had already granted the app; remove its access (G22) and connect again. Look for "An OAuth token request was refused" in the log.
- [ ] **G3. Granular consent.** Connect again and untick one of the two permissions on Google's consent screen.
  - **Expected:** "Google did not grant access to the calendars. Connect the account again and allow every permission it asks for." The connection keeps its previous login.
  - **If not:** if the connection is stored anyway, Google's token answer had no `scope` field (FreeScout only checks the scopes when it has one). Note it.
- [ ] **G4. Choose calendars.** Tick the primary calendar, a calendar shared with you with "Make changes to events", and one shared with "See all event details". Click "Add calendars".
  - **Expected:** each becomes a FreeScout calendar with Google's name and color. The "See all event details" calendar is read-only: its permission matrix offers only Dashboard and View, and agents can't create or edit there. A calendar in an older zone name (an India calendar reports `Asia/Calcutta`) keeps that zone, also after saving the calendar's settings.
  - **Then:** make the read-only calendar writable for you in Google's sharing settings and open the pick screen again ("Choose calendars"). Agents can now edit its events at once, without a sync.
  - **If not:** `calendars.remote_read_only` is set from Google's `accessRole` when the pick screen lists the account; check the row with tinker.
- [ ] **G5. First sync and time zones.** Sync each calendar. Open the calendar page as the Amsterdam agent and as the New York agent.
  - **Expected:** every event of the past 12 months and the next 24 months appears, at the same moment for both agents, each in their own zone.
  - **If not:** "Last error" on the calendar; "A pulled calendar event was skipped" in the log gives the event's `remote_id_hash` and the reason.
- [ ] **G6. Two-way edits.** Create an event in FreeScout with custom field values and a linked conversation. Change its title and time in FreeScout, then change it in Google and sync. Delete one event in FreeScout and another in Google.
  - **Expected:** the new event appears in Google at once, with a `[FreeScout]` block of fields and the ticket link at the end of its description. Each change shows on the other side (Google's after a sync). Deletes go both ways.
  - **If not:** a save that fails shows the provider's message in the form; the log has "A Google Calendar request failed" with the status.
- [ ] **G7. The event's UID. UNVERIFIED.** Create an event in FreeScout, then read it in the API explorer (`events.get`) or in Google's export (Settings > Import and export).
  - **Expected:** its `iCalUID` is the UID FreeScout stored (`CalendarEvent::find(<id>)->uid`).
  - **If not:** Google gave it its own iCalUID. FreeScout takes it over from the insert's answer, so nothing breaks, but note it.
- [ ] **G8. Exceptions written by FreeScout. UNVERIFIED.** Create a weekly series in FreeScout. Edit one occurrence ("Edit this occurrence") and delete another. Then right away edit the whole series' title.
  - **Expected:** Google shows the edited occurrence and the missing one. The series edit works without "This event was changed elsewhere".
  - **If not:** FreeScout builds the instance id as `<series id>_<YYYYMMDDTHHMMSSZ>` (or `_<YYYYMMDD>` for all-day), which Google doesn't document, and falls back to listing the instances. A 409 on the series edit means an instance write changes the series' etag: the next sync stores the new etag, so a second try works. Note which happened.
- [ ] **G9. Exceptions made in Google. UNVERIFIED.** In Google, edit one occurrence of a series and delete another. Sync. Do the same with an all-day series.
  - **Expected:** FreeScout shows the edited occurrence with its own time or title, and the deleted one is gone, for both series.
  - **If not:** FreeScout reads exceptions through `events.instances` with `showDeleted=true` over its occurrence window, and assumes cancelled occurrences come back from it, and that an all-day instance's `originalStartTime` is a date. Check the stored event's EXDATE and overrides.
- [ ] **G10. Whole-series changes with exceptions.** On a series with an edited and a deleted occurrence, change the series' time once in FreeScout and once in Google. Sync and compare.
  - **Expected:** FreeScout asks "Edited and deleted occurrences of this series will be reset. Continue?" before its own change. After each change and a sync, both sides list the same occurrences.
  - **If not:** note what Google kept of the exceptions.
- [ ] **G11. A series renamed in FreeScout with separately edited occurrences.**
  - **Expected (known limit):** the edited occurrences keep their old title in FreeScout until Google reports them changed.
- [ ] **G12. Meet link, attachments, reminders and guests. UNVERIFIED.** Edit, in FreeScout, an event made in Google with a Meet link, an attachment, reminders and a guest.
  - **Expected:** all four are still on the event in Google afterwards. FreeScout writes with `conferenceDataVersion=1` and `supportsAttachments=true` and assumes that keeps them.
- [ ] **G13. Meetings with guests get no readable block.** Edit the event from G12 in FreeScout (title and a custom field).
  - **Expected:** the description in Google, and the update mail the guest gets, have no `[FreeScout]` block. FreeScout still shows the fields and the ticket link. An event without guests keeps the block. An event that only books a room (a resource) loses it too: confirm that this is what you want.
- [ ] **G14. Rich text.** Give an event a description with bold text and a link in Google's editor. Sync, then edit the event in FreeScout.
  - **Expected:** FreeScout shows the description as plain text with the link target kept. The first FreeScout edit adds the `[FreeScout]` block, so Google's description becomes plain text (known limit) with one block, never two. Then make a word bold in Google again, sync, and change only the title in FreeScout: Google keeps the bold word, because FreeScout sends the description only when its text changed.
- [ ] **G15. Private events. UNVERIFIED for shared calendars.** Make an event private in your own calendar, and another in a calendar shared with you with "Make changes to events" but without access to private events.
  - **Expected:** both show as "Busy" in FreeScout, without details, and read-only. FreeScout assumes Google sends the second one with `visibility: private`.
- [ ] **G16. Free/busy sharing. UNVERIFIED.** Add a calendar shared with "See only free/busy (hide details)", including a recurring event.
  - **Expected:** busy blocks, read-only, and the recurring one repeats. FreeScout reads the free/busy items as the documentation describes them (an id, times, sometimes a rule); note any field that differs.
- [ ] **G17. Event types.** Create a working location, an out-of-office and a focus-time event, and have a birthday and an event from Gmail.
  - **Expected:** all are imported and read-only (Lars, 2026-10-09).
  - **If not:** the stored event's `read_only` and its ICS (see "Where to look", item 4).
- [ ] **G18. Invitations.** Let the second Google account invite yours.
  - **Expected:** the event is read-only in FreeScout. When forced through the API, Google's answer becomes "Only the organizer can change this event in Google."
- [ ] **G19. Moves.** Move an event between two Google calendars of one account in FreeScout (change its calendar). Then move a series with an edited occurrence from a CalDAV calendar to Google.
  - **Expected:** the event is created in the target, then deleted from the source; the series arrives with its edited occurrence.
  - **If not:** when the delete in the source fails, the agent sees that the event is now in both calendars, and the source is listed in full at its next sync.
  - **Also:** try to move a meeting with a guest to another calendar, also one of the same account. FreeScout refuses it under the calendar field: "This is a meeting with attendees. Move it in the calendar app it comes from, so the attendees are not sent a cancellation." Nothing changes in Google, and the guest gets no mail.
- [ ] **G20. Changed elsewhere.** Edit an event in Google, then edit it in FreeScout before the next sync.
  - **Expected:** "This event was changed elsewhere. Reload and try again." Nothing is saved. A reload shows Google's version at once (the server syncs that calendar before it answers).
- [ ] **G21. A shared calendar's role changes.** Change a calendar shared with you from "See all event details" to "Make changes to events" and back.
  - **Expected:** reopening the pick screen updates whether agents may write. Read-only flags of single events follow after Google's next full sync.
- [ ] **G22. Access revoked.** In the Google account, remove the app's access (myaccount.google.com > Security > Your connections to third-party apps and services). Sync one of its calendars.
  - **Expected:** the connection shows "Needs reauthorization". Its calendars stop syncing and keep their events, and agents see them read-only. A save sent from a calendar page that was opened before answers "This calendar's connection needs to be re-authorized by an administrator.". After the next health check, administrators get one mail "Calendar: an account needs reauthorization", and the banner shows it on the settings page and the calendar page. "Reauthorize" on the account's row signs in again; the status turns "OK" and the banner disappears.
  - **If not:** look for "An OAuth token request was refused" with `invalid_grant` in the log; for the mail, see "Where to look", item 6.
- [ ] **G23. Testing mode (External app in Testing only).** Leave a connection alone for 8 days.
  - **Expected:** Google expires the refresh token after 7 days; the connection behaves as in G22. This is why the wiki tells Workspace admins to make the app Internal.
- [ ] **G24. An expired page token. UNVERIFIED.** Hard to force; note it if it happens on a large calendar. FreeScout assumes Google answers a 400 and starts the listing over ("Google refused the saved page token of a calendar; its listing starts over").
- [ ] **G25. Delete the connection.** Delete the Google account on the Connections tab.
  - **Expected:** the confirmation names its calendars and says their events are removed from FreeScout, not from Google. Afterwards the calendars and their events are gone from FreeScout and untouched in Google.
- [ ] **G26. A repeat rule removed and added. UNVERIFIED.** In FreeScout, set the repeat of a weekly series without exceptions to "Does not repeat" and save; sync. Then give a single event a weekly rule and save; sync.
  - **Expected:** Google shows one event where the series was, and a weekly series where the single event was, and FreeScout shows the same after the sync. FreeScout writes the event back whole (`events.update`) without `recurrence` for the first and with it for the second, and assumes Google accepts both.
  - **If not:** the form's error, and "A Google Calendar request failed" with the status in the log.

## 2. Microsoft 365

- [ ] **M1. Register the app.** Follow the wiki draft `docs/wiki/Microsoft-365.md`: a single-tenant app registration in Microsoft Entra, the redirect URL from FreeScout on the **Web** platform, the delegated Graph permissions `offline_access`, `User.Read`, `Calendars.ReadWrite` and `Calendars.ReadWrite.Shared`, admin consent, and a client secret. Save the directory (tenant) id, the application (client) id, the secret's value and its expiry date in FreeScout.
  - **Expected:** "Microsoft app saved." and "Connect a Microsoft account" becomes a link.
  - **Also, UNVERIFIED:** with this single-tenant app, type `organizations` as the tenant and connect. Expect Microsoft to refuse the sign-in (an `AADSTS50194`-like error), shown on the Connections tab as "Microsoft did not connect the account: ...". Put the tenant id back afterwards.
- [ ] **M2. Connect an account.** Click "Connect a Microsoft account" and consent.
  - **Expected:** the pick screen lists the account's calendars, the default one marked "Primary". The account on the Connections tab is its mail address (or user principal name) in lower case.
  - **If not:** Microsoft's error page shows an `AADSTS` code; the Entra sign-in log explains it. `AADSTS65001` or `AADSTS90094` means consent is missing. "Microsoft did not return a refresh token..." means `offline_access` is missing.
- [ ] **M3. Own and shared calendars.** Add the default calendar, a second calendar of the account, and a calendar another user shared with you through an Outlook sharing invitation with "Can view all details".
  - **Expected:** the shared one is "Read only" on the pick screen and read-only for agents (`canEdit` false). None has a time zone of its own; they use FreeScout's.
- [ ] **M4. Shared mailboxes. UNVERIFIED for folder permissions.** On the account's row, "Add a shared mailbox":
  1. a mailbox the account has Full Access to;
  2. a mailbox where it only has a permission on the Calendar folder (`Add-MailboxFolderPermission -Identity shared@example.com:\Calendar -User you@example.com -AccessRights Editor`);
  3. a mailbox it has no rights on;
  4. an address that isn't one (`support@`).
  - **Expected:** 1 lists the mailbox's calendars to add. For 2 note whether the list appears and which calendars it holds. 3 says "You don't have access to the calendars of <address>." 4 says "Enter the email address of the shared mailbox."
- [ ] **M5. Two-way edits.** Create an event in FreeScout with custom field values and a linked conversation, change its title and time in FreeScout, then change it in Outlook and sync. Delete one event in FreeScout and another in Outlook.
  - **Expected:** the new event appears in Outlook at once, with a `[FreeScout]` block of fields and the ticket link at the end of its body. Each change shows on the other side (Outlook's after a sync). Deletes go both ways.
  - **If not:** a save that fails shows the provider's message in the form; the log has "A Microsoft Graph request failed" with the status.
- [ ] **M6. A large calendar. UNVERIFIED.** Sync a calendar with more than 100 events in the window. Then leave a listing unfinished (stop after the first `calendar:sync`) for an hour and sync again.
  - **Expected:** the listing pages through Graph's next links (FreeScout assumes Graph pages with `$skip`) and completes; every event is there once. An expired next link starts the listing over: "Microsoft refused the saved page link of a calendar; its listing starts over" in the log.
- [ ] **M7. Time zones.** Create an event in Outlook on the web in "(UTC+01:00) Amsterdam, Berlin" in a calendar whose FreeScout zone is Europe/Amsterdam. Create events in FreeScout as agents in Amsterdam, New York and Kolkata. If you have desktop Outlook, create one in a custom time zone.
  - **Expected:** Outlook's event is stored with the zone `Europe/Amsterdam` and shows at the right time for every agent. FreeScout's events land at the right time in Outlook (FreeScout sends Windows zone names, such as "W. Europe Standard Time"). The custom zone's event shows at the right moment.
- [ ] **M8. All-day events. UNVERIFIED.** Create all-day events of one and of three days in Outlook and in FreeScout.
  - **Expected:** the same dates on both sides, with the end exclusive (a one-day event ends the next day at Graph). FreeScout reads Graph with `Prefer: outlook.timezone="UTC"` and assumes it renders all-day dates and `originalStart` predictably under it.
  - **If not:** the stored DTSTART and DTEND (`VALUE=DATE`), and Graph's `start` and `end` in Graph Explorer.
- [ ] **M9. Recurrence both ways. UNVERIFIED for the marked patterns.** Create each of these in Outlook and in FreeScout, and compare the occurrences over a few months:
  - weekly on Monday and Wednesday; every other week on Tuesday and Thursday (the week start matters);
  - monthly on the 15th; monthly on the last day (Outlook's own option);
  - monthly on day 31 made in Outlook (**UNVERIFIED** how Graph expands it): FreeScout reads it as the last day of shorter months, so compare April, June and February;
  - the second Tuesday, the last Friday, the first weekend day (**UNVERIFIED**, several days in one relative pattern);
  - yearly; yearly on 29 February (**UNVERIFIED** how Graph expands it in other years);
  - until a date, and a number of times.
  - **Expected:** both sides list the same occurrences. A pattern FreeScout can't map locks the series for whole-series edits; its occurrences stay editable.
  - **If not:** compare the stored RRULE and DTSTART (see "Where to look", item 4) with the pattern in Graph Explorer (`GET .../events/{id}?$select=recurrence,start`).
- [ ] **M10. Day 29 to 31 is refused.** In FreeScout, create a monthly series on day 31 in a Microsoft calendar, and move a series like that into one.
  - **Expected:** "Microsoft calendars can't repeat on day 29, 30 or 31 of the month." Nothing is saved. A series made in Outlook with "last day of the month" stays editable as a whole.
- [ ] **M11. Exceptions made in Outlook. UNVERIFIED.** Edit one occurrence and delete another in Outlook. Sync.
  - **Expected:** FreeScout shows both changes. FreeScout assumes editing an occurrence changes the series' changeKey; if not, the weekly pass over every series picks the change up within a week. Note which.
- [ ] **M12. Exceptions made in FreeScout.** Edit one occurrence and delete another in FreeScout, then edit the whole series' title right away.
  - **Expected:** Outlook shows both changes, and the series edit works without "This event was changed elsewhere".
- [ ] **M13. Whole-series changes. UNVERIFIED.** On a meeting series with attendees and with an edited and a deleted occurrence:
  1. change its title in FreeScout;
  2. change only its end (a longer meeting);
  3. change its start time or its rule.
  - **Expected:** 1 keeps Outlook's exceptions, and attendees get no update mail when nothing they can see changed. For 2 and 3 note what Outlook keeps of the exceptions, and whether it accepts an update with only the end; the next sync makes FreeScout follow Outlook.
- [ ] **M14. An occurrence can't jump its neighbours.** Drag an occurrence onto the day of the next occurrence.
  - **Expected:** "Microsoft does not let an occurrence move onto or past the day of the occurrence before or after it."
- [ ] **M15. Private events. UNVERIFIED for readers.** Mark an event private in your own calendar. Then add a calendar shared with you without "Can view private items" that holds a private event.
  - **Expected:** the own private event shows as "Busy", read-only. For the shared calendar note what arrives; when Graph refuses the listing, the calendar's error is "Microsoft could not list the events of this calendar. It may have been deleted, or it holds private events this account may not see."
- [ ] **M16. Invitations.** Let the second user invite your account.
  - **Expected:** read-only in FreeScout.
- [ ] **M17. Changed elsewhere.** Edit an event in Outlook, then in FreeScout before the next sync.
  - **Expected:** "This event was changed elsewhere. Reload and try again."; a reload shows Outlook's version at once.
- [ ] **M18. UIDs and moves.** Create an event in FreeScout in a Microsoft calendar. Move a series with an edited and a deleted occurrence from a FreeScout calendar, and one from a CalDAV calendar, to Microsoft.
  - **Expected:** the new event takes Outlook's iCalUId (FreeScout stores the uid Graph returns). Both moved series keep their edited and deleted occurrences in Outlook.
- [ ] **M19. HTML bodies. UNVERIFIED.** Give an Outlook event a formatted body. Sync, then edit the event in FreeScout.
  - **Expected:** FreeScout shows plain text. After the edit, Outlook's body has one `[FreeScout]` block, never two. FreeScout asks Graph for text bodies with `Prefer: outlook.body-content-type="text"` and assumes Graph honours it on every read.
- [ ] **M20. Deleted and moved in Outlook.** Delete an event in Outlook (it goes to Deleted Items). Move another to a second calendar that FreeScout also shows. Sync both calendars.
  - **Expected:** the deleted event is gone from FreeScout (see M0). The moved event leaves the first FreeScout calendar and appears in the second.
- [ ] **M21. Meetings with attendees get no readable block.** Edit, in FreeScout, a meeting your account organizes with an attendee outside the organization. Then edit an event without attendees, and one that only books a room.
  - **Expected:** the attendee's update mail and the body in Outlook have no `[FreeScout]` block; FreeScout still shows the fields and the ticket link. The event without attendees keeps the block. The room-only booking also loses it (a room counts as an attendee): confirm that this is what you want.
- [ ] **M22. Exchange's spurious 412. UNVERIFIED.** Hard to force. When the log shows a 412 on a create or delete, check that the event exists exactly once: FreeScout retries such a 412 once and assumes that is safe.
- [ ] **M23. Reauthorization.** Revoke the user's sessions in Entra (Users > the user > Revoke sessions), or let a Conditional Access sign-in frequency expire, then sync.
  - **Expected:** `invalid_grant` or `interaction_required` (MFA) sets the connection to "Needs reauthorization"; one alert mail and the banners follow, as in G22. "Reauthorize" signs in again and the status returns to "OK". Signing in with another account than the connection's is refused: "You signed in as ..., but this connection belongs to ...".
  - **Also, if you can:** change the user's mail address in Entra, then "Reauthorize". The connection finds the user by Graph's user id: the sign-in works and the account's row shows the new address.
- [ ] **M24. The secret's expiry date.** Set "Secret expires on" to a date 10 days ahead and save. Forget the last health check (see "Before you start") and run a sync.
  - **Expected:** one mail "Calendar: the Microsoft secret expires soon" naming the date (see "Where to look", item 6), and the banner on the settings page and the calendar page links to the Microsoft app. A second sync after 15 minutes mails nothing new. A date in the past changes the text to "...expired on ... Microsoft calendars don't sync until a new secret is saved." without a second mail (the same problem). A date more than 30 days ahead removes the problem from the banners. Bringing it back within a day mails nothing; after more than a day (backdate `solved` as in H1) it mails once more.
  - **Also:** let a real secret expire, or save a wrong one. Every sync then fails with "Microsoft refused the app's client id or secret. Check the Microsoft app on the Connections tab."
- [ ] **M25. The weekly pass.** Delete, in Outlook, an occurrence about 23 months ahead of a series that has no other change. Sync daily.
  - **Expected:** FreeScout drops the occurrence within a week, when it reads every series again.
  - **If not:** the stored event's EXDATE (see "Where to look", item 4).
- [ ] **M26. Throttling.** Hard to force. When Graph limits the account, agents see "Microsoft is limiting requests right now. Try again in a minute." and the sync waits for `Retry-After`.
- [ ] **M27. Delete the connection.** As G25, with "not from Microsoft 365" in the confirmation.
- [ ] **M28. A repeat rule removed and added. UNVERIFIED.** In FreeScout, set the repeat of a weekly series without exceptions to "Does not repeat" and save; sync. Then give a single event a weekly rule and save; sync.
  - **Expected:** Outlook shows one event where the series was, and a weekly series where the single event was, and FreeScout shows the same after the sync. FreeScout sends `{"recurrence": null}` on the series master for the first and a `recurrence` on the single event for the second (a PATCH of the changed fields), which Graph documents for neither.
  - **If not:** the form's error, and "A Microsoft Graph request failed" with the status in the log; note what Outlook did with the event.

## 3. CalDAV servers

- [ ] **C1. Nextcloud.** Connect with the server address (`https://cloud.example.com/remote.php/dav`) and an app password. Add an own calendar and one shared with you read-only.
  - **Expected:** both are found; the shared one is "Read only". Changes go both ways; after the server forgets an old sync token (Nextcloud prunes them after 60 days) the calendar is listed again: "A CalDAV server no longer knows the sync token; the calendar is listed again".
- [ ] **C2. iCloud. UNVERIFIED statuses.** Connect `https://caldav.icloud.com/` with your Apple ID and an app-specific password. Try the normal password first.
  - **Expected:** the normal password gives "The CalDAV server refused the username or password."; the app-specific password works, and the stored calendar addresses are on the partition host (`https://pNN-caldav.icloud.com/...`).
  - **Note:** what iCloud answers when a sync token is no longer valid (FreeScout handles a 403 or 409 that names `valid-sync-token`), and what it answers when you move an event between two iCloud calendars in FreeScout (see C3).
- [ ] **C3. Fastmail: a UID once per account.** Connect `https://caldav.fastmail.com/` with an app password that has calendar access. In FreeScout, move an event between two calendars of this account.
  - **Expected:** the move works. Fastmail (Cyrus) allows a UID only once per account, so FreeScout creates the copy under a new UID after the server's `no-uid-conflict` answer. FreeScout's permalinks and conversation line items keep working; other calendar apps and invitations see a new event (Lars, 2026-10-09).
- [ ] **C4. Zoho. UNVERIFIED.** Connect with the address from Zoho's Settings > CalDAV Sync, your email address and an app-specific password. Sync, then edit an event in FreeScout twice.
  - **Expected:** the first sync finds every event (FreeScout lists with PROPFIND and multiget, never with an empty sync-collection, which Zoho answers with nothing). Note whether edits get "This event was changed elsewhere" without a change elsewhere, or whether every sync downloads every event again: research says Zoho gives other ETags in REPORT than in GET.
- [ ] **C5. A login in the address.** Type `https://user:password@cloud.example.com/remote.php/dav`.
  - **Expected:** "Leave the username and password out of the address and fill them in below." Nothing is stored.
- [ ] **C6. A new app password.** Open the calendar page as an agent who may edit one of the account's calendars. Revoke the app password at the server and sync. Then let the agent change an event on the page that is still open, and run a health check (forget when it last checked, then sync). Last, fill in a new app password in the login form on the account's row, click "Connect again", and sync once more.
  - **Expected:** after the first sync, the Connections tab shows the account as "Needs reauthorization" with "The account must be connected again.", with the login form open on its row. The calendar's last error is "This calendar's connection needs to be re-authorized by an administrator. (HTTP 401)". The agent's change is refused with "This calendar's connection needs to be re-authorized by an administrator.", nothing changes at the server, and after a reload the account's calendars are read-only. The health check sends one mail "Calendar: an account needs reauthorization", and the administrators' banners list it. After the first refusal no sync or write sends the revoked password: the log holds one "A CalDAV server answered with an error" with `status` 401. The new password sets the account back to "OK" without a last error, and the next sync clears the calendar's error. An empty password field keeps the saved one.
  - **Note:** the status the server answers a revoked password with, in that log line. Only a 401 marks the account; a server that answers 403 only gives the calendar the error "The CalDAV server refused the username or password. (HTTP 403)", and administrators hear of it through H1 after a day.
- [ ] **C7. A server on a private address.** Connect a CalDAV server on your own network (a Nextcloud on the LAN or in a container).
  - **Expected:** "This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST." until `.env` holds the host name and its address range (see `docs/wiki/CalDAV.md`).

## 4. ICS feeds and calendar health

- [ ] **X1. An outlook.com calendar as a feed.** In Outlook.com, publish a calendar (Settings > Calendar > Shared calendars > Publish a calendar, "Can view all details") and add its ICS link as an ICS feed in FreeScout.
  - **Expected:** the events appear read-only after "Sync now" and a sync. This is the wiki's advice for outlook.com accounts, which the Microsoft app (work and school accounts) doesn't connect.
- [ ] **H1. A calendar that stops syncing, and comes back.** Point an ICS feed at an address that answers 404 and make its last success older than a day (`ddev exec 'echo "Modules\LJPcCalendarModule\Entities\Calendar::find(<id>)->update([\"last_synced_at\" => \"2026-01-01 00:00:00\"]);" | php artisan tinker'`). Give an agent view rights. Sync it, forget when the health check last checked (see "Before you start"), and sync again.
  - **Expected:** one mail "Calendar: a calendar stopped syncing" naming the calendar, with the link to its settings. The administrator's banners list it. The agent sees "Some calendars are not up to date: <name>." and never the error text.
  - **Within a day:** fix the address, sync, forget when it last checked and sync again. The banners no longer list it, and "Where to look", item 6, shows it under `solved`. Break the address again, set `last_synced_at` back as above, sync, forget when it last checked and sync again. **Expected:** no new mail; the banners list it again.
  - **After more than a day:** fix it once more and run a check as above. Then make it look gone for more than a day: `ddev exec 'echo "\$h = App\Option::get(\"ljpccalendarmodule.health\", [], true, false); \$h[\"solved\"] = array_map(function () { return \"2026-01-01T00:00:00Z\"; }, \$h[\"solved\"] ?? []); App\Option::set(\"ljpccalendarmodule.health\", \$h);" | php artisan tinker'`. Break it again as above and run a check. **Expected:** one new mail.
  - **If not:** see "Where to look", item 6.

## 5. When you are done

- [ ] Search the day's log for `calendar_id` and note every warning you can't explain.
- [ ] Delete the test connections (G25, M27), the app secrets you no longer need, and the Outlook and Google test events.
