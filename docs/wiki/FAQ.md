# FAQ

> This page describes version 3.0 of the calendar module and later.

## Syncing

**How often does a calendar sync?**
Every 5 minutes by default, adjustable per calendar in its settings. Changes made in FreeScout go to Google, Microsoft or the CalDAV server at once. "Sync now", in the calendar's settings and in the sidebar of the calendar page, syncs right away and tells you what changed.

**Nothing syncs at all.**
The module syncs from FreeScout's cron job, every minute. Check that the cron job runs (Manage > System shows it).

**A calendar says it failed to sync.**
Its settings show the last error. Administrators also get an email when a calendar has not synced for a day, when an account must sign in again, and before the Microsoft app's client secret expires.

**I use a personal outlook.com account.**
Microsoft only gives work and school accounts the calendar access the module needs. Publish the calendar in Outlook on the web (Settings > Calendar > Shared calendars > Publish a calendar), copy the ICS link, and add it in FreeScout as an ICS feed. It shows read-only.

**Why can't agents change the events of a calendar?**
An ICS feed is read-only by nature. A Google, Microsoft or CalDAV calendar is read-only when the connected account may only view it; it then says "Read only" in the settings.

**Private events of a Google or Microsoft calendar show as "Busy".**
That is on purpose: a private event comes into FreeScout without its details and can't be changed there.

**What happens when I move an event to another calendar?**
It is created in the new calendar and removed from the old one. An event moved out of a Google, Microsoft or CalDAV calendar is deleted there. A meeting with attendees from such a calendar stays where it is, so its attendees are not sent a cancellation: move it in the app it comes from.

## Time zones

**In which time zone do agents see events?**
In the time zone of their FreeScout profile, on its 12 or 24-hour clock, wherever their browser is. Each calendar has a time zone too, for events that have none of their own (such as all-day events from a feed).

**FreeScout's time zone keeps changing to Samoa.**
FreeScout's own list of time zones has no Europe/Amsterdam. When it is set some other way, saving Manage > Settings > General stores the first zone of the list, "(GMT-11:00) Midway Island, Samoa", and the same happens to a profile. Choose "Central European Time" (Europe/Berlin) instead, which keeps the same time.

## Reminders

**Who gets a reminder?**
The agent who added the event, and the agent the event's conversation is assigned to, while they may view the calendar. The reminder comes by email, in their own language, time zone and clock. Events that come in from Google, Microsoft, CalDAV or a feed, with no author or conversation in FreeScout, get no reminder from FreeScout: their own app reminds.

**When does it go?**
At the time set on the event, or the calendar's default reminder. An all-day event reminds at 09:00 in the calendar's time zone: on the day itself, or the chosen number of days before.

## Permissions

**Who can see a calendar?**
Administrators always can. Everyone else gets their rights from the calendar's settings, where a row per user, team or mailbox says who may view the calendar, add events, change and delete them, and see the next events on their dashboard. Rights add up: a person gets every right from their own row, their teams and the mailboxes they can open.

## Invites

See [Invites](Invites).

## Translations

Dutch, German, French and Spanish translate every text; the other languages show the texts added in version 3 in English. A pull request with a translation is very welcome: the files are in `Resources/lang`.
