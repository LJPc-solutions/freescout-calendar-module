# 3.0.0

Version 3 rebuilds the calendar module from the ground up. Calendars sync both ways with Google Calendar, Microsoft 365 and every CalDAV server, the invites customers send are added with one click, agents invite customers themselves, and everyone gets a reminder before an event starts.

> [!CAUTION]
> **Back up your database before you update.** The update converts the calendar tables in place, and a version 3 install stays on version 3: restoring the backup is the way back. Read [Upgrading from version 2](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Upgrading-from-version-2) first.

## Requirements

- FreeScout 1.8.243 or newer
- PHP 8.2 or newer
- MySQL, MariaDB or PostgreSQL

On an older FreeScout or PHP, version 3 pauses itself and shows administrators what to update. Your data stays as it is, but the calendar is unavailable until FreeScout or PHP is updated, so update those first.

## New

- **Google Calendar and Microsoft 365**, including shared mailboxes and room calendars, synced both ways through an OAuth app of your own. The [Google](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Google-Calendar) and [Microsoft](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Microsoft-365) pages walk you through it.
- **CalDAV synced both ways**, with accounts connected once on the new Connections tab. FreeScout finds an account's calendars from the server address.
- **Repeating events** in FreeScout calendars, changed one occurrence at a time or as a whole series.
- **A new calendar page** with day, week, month, year and list views, in each agent's own time zone and clock, with a link to every event and occurrence.
- **Invites (#83, #47):** a card below a customer's invite adds it, applies its update or cancellation, and answers Yes, Maybe or No. Agents invite customers to the events they add from a conversation, and the answers are recorded by themselves.
- **Reminders** by email to the agent who added an event and to the assignee of its conversation.
- **Permissions per user, team and mailbox (#42)**, with separate rights to view, add, change and see a calendar on the dashboard.
- **Health checks:** administrators get an email when a calendar stops syncing, an account must sign in again, or the Microsoft app's secret is about to expire.
- **"Sync now"** on every external calendar, which says what changed.
- **Translations:** Dutch, German, French and Spanish translate every text.

## Good to know when you upgrade

- The upgrade runs by itself and writes a report under Manage > Settings > Calendar. An event it can't convert yet keeps the calendar in upgrade mode until it is solved or skipped, so nothing goes missing on the way.
- **Team permissions now count.** In version 2 they had no effect. The report names every team that now gives its members access.
- **Export links are new.** Apps that subscribed to a calendar with an old link need the new one, from the calendar's settings.
- **CalDAV logins move to the Connections tab.** An ICS address with a username and password in it loses them, because FreeScout keeps no passwords for feeds.
- The `.env` settings `CALENDAR_ENABLE_EVENT_INDEX`, `CALENDAR_ENABLE_CALDAV_REPORTS` and `CALENDAR_FORCE_LEGACY_MODE` are no longer used.
- Version 3 also closes security issues found in version 2. Update every install.
