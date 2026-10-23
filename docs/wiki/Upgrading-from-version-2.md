# Upgrading from version 2

Version 3 rebuilds the calendar module from the ground up. It converts your calendars, events and permissions in place, the first time it runs, and keeps every event linked to its conversation.

> This page describes version 3.0 of the calendar module and later.

## Before you upgrade

1. **Back up your database.** The upgrade converts the calendar tables in place, and version 3 stays on version 3: restoring this backup is the way back to version 2. Rolling back the module's migrations is no way back, because it removes the calendar data.

   ```bash
   mysqldump --single-transaction --skip-lock-tables -u freescout -p freescout > freescout-before-calendar-3.sql
   ```

2. **Check the requirements:** FreeScout 1.8.243 or newer and PHP 8.2 or newer. On an older FreeScout or PHP, version 3 pauses itself, shows administrators what to update, and leaves your data as it is; the calendar is back as soon as FreeScout or PHP is updated.

3. **Check FreeScout's time zone** under Manage > Settings > General. Version 2 stored all-day events as midnight, and the upgrade reads them in this zone. FreeScout's list has no Europe/Amsterdam: choose "Central European Time" (Europe/Berlin) for the Netherlands and Belgium, which keeps the same time.

## The upgrade

Update the module on FreeScout's Modules page. The upgrade then runs by itself:

- **Calendars** keep their name, color, custom fields and title template. Normal calendars become FreeScout calendars; ICS and CalDAV calendars sync again from scratch.
- **CalDAV logins** move to a connection per account, on the Connections tab of the calendar settings. An ICS address with a username and password in it loses them: FreeScout keeps no passwords for feeds, so such a feed needs an address that works without them.
- **Events** keep their conversation, author and custom field values. Links to an event from version 2 (`/calendar#123`) still open it.
- **Permissions** become rows per user and team. Team permissions had no effect in version 2 and count from version 3 on, so check the teams the report names.
- **Export links** are new: every calendar gets a new link. Apps that subscribed with an old link need the new one, from the calendar's settings under "Subscribe from other apps".

While the upgrade runs, the calendar page says it is being upgraded.

## The upgrade report

Manage > Settings > Calendar shows the report: what was converted, what to check, and every event that was left out, with all its data, so you can add it again by hand.

An event the upgrade can't convert yet keeps the calendar in upgrade mode, so nothing goes missing on the way. The report lists it under "Events still waiting to be converted":

1. Solve the problem the report names, or click **Skip** to leave the event out of version 3. A skipped event stays in the report with all its data.
2. Run the upgrade again with "Migrate DB" under Manage > System > Tools.

## After the upgrade

- Read the report's warnings, above all the team permissions that now take effect.
- Connect your Google and Microsoft 365 accounts on the Connections tab if you want those calendars in FreeScout; version 2 could only read them through an ICS or CalDAV link.
- Remove the version 2 settings `CALENDAR_ENABLE_EVENT_INDEX`, `CALENDAR_ENABLE_CALDAV_REPORTS` and `CALENDAR_FORCE_LEGACY_MODE` from `.env`. Version 3 no longer reads them.
- Hand the new export links to the apps that subscribe to your calendars.
