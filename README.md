# LJPc calendar module for FreeScout

Calendars inside FreeScout, right next to your conversations: calendars stored in FreeScout itself, Google Calendar, Microsoft 365, any CalDAV server and the ICS feed of any other app. Agents plan appointments from a conversation, add the calendar invites customers send with one click, invite customers themselves and get a reminder before an event starts.

<a href="https://www.buymeacoffee.com/Lars-" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-orange.png" alt="Buy Me A Coffee" height="60" style="height: 60px !important;width: 217px !important;" ></a>

> [!CAUTION]
> Back up your database before you upgrade to version 3. The upgrade converts the calendar tables in place, and a version 3 install stays on version 3: restoring the backup is the way back. The [upgrade guide](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Upgrading-from-version-2) explains every step.

## What it does

- **Five kinds of calendars**
  - FreeScout calendars, stored in FreeScout, with repeating events.
  - Google Calendar and Microsoft 365, including shared mailboxes and room calendars, synced both ways through an OAuth app of your own.
  - CalDAV servers such as Nextcloud, iCloud, Fastmail, Baïkal, Zoho and cPanel, synced both ways.
  - The ICS feed of any other app, such as a booking system, shown read-only.
- **A calendar page** with day, week, month, year and list views, in each agent's own FreeScout time zone and clock. Repeating events can be changed one occurrence at a time or as a whole series, and every event and occurrence has a link of its own.
- **Events from a conversation:** the calendar button on a conversation adds an event linked to it, and the conversation records who added what.
- **Invites:** a card below a customer's mail adds the invite it holds, applies its update or cancellation, and answers Yes, Maybe or No. Agents invite customers to the events they add from a conversation, and their answers are recorded by themselves.
- **Reminders** by email to the agent who added an event and to the assignee of its conversation, in their own language, time zone and clock.
- **Permissions** per user, team and mailbox: who sees a calendar, who adds and changes its events, and whose dashboard shows it.
- **Custom fields** per calendar, a title template built from them, and export links to subscribe to a calendar from other apps.
- **A dashboard card** with the next events.
- **Health checks:** administrators get an email when a calendar stops syncing or an account needs to sign in again.

## Requirements

- FreeScout 1.8.243 or newer
- PHP 8.2 or newer
- MySQL, MariaDB or PostgreSQL
- FreeScout's cron job: the module syncs calendars and sends reminders from it every minute

On an older FreeScout or PHP, the module pauses itself and shows administrators what to update.

## Installation

1. Download the [latest module zip](https://resources.ljpc.network/freescout-modules/calendar/latest.zip). Use this zip rather than a copy of the repository: it holds the built frontend and the PHP dependencies.
2. Unpack it in FreeScout's `Modules` folder, so the module lives in `Modules/LJPcCalendarModule`.
3. Activate the module on FreeScout's Modules page.
4. Go to Manage > Settings > Calendar. Create calendars and choose who can use them, and connect Google, Microsoft 365 and CalDAV accounts on the Connections tab.

## Updating

The module updates itself from FreeScout's Modules page. Coming from version 2? Read [Upgrading from version 2](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Upgrading-from-version-2) first.

## Documentation

The [wiki](https://github.com/LJPc-solutions/freescout-calendar-module/wiki) explains every part:

- [Google Calendar](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Google-Calendar): create the OAuth app, for Google Workspace and for other Google accounts
- [Microsoft 365](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Microsoft-365): register the app, shared mailboxes and the client secret
- [CalDAV](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/CalDAV): server addresses for Nextcloud, iCloud, Fastmail, Baïkal, Zoho and cPanel
- [Invites](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Invites): the invites customers send and the invites agents send
- [Upgrading from version 2](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Upgrading-from-version-2)
- [FAQ](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/FAQ)

## Scope: calendar invites

Calendar invites travel as iTIP messages in email (RFC 5546 and RFC 6047). This is what the module does with them.

**Supported**

- Invites a customer sends (`REQUEST`): new events, updates (by `SEQUENCE`) and updates of one occurrence of a series (`RECURRENCE-ID`). A card below the mail shows each one, and an agent applies it with one click.
- Cancellations a customer sends (`CANCEL`), of a whole event or of one occurrence.
- An update for "this and following events" (`RANGE=THISANDFUTURE`): applied as a change of the whole series from that occurrence on, and the card says so before the agent applies it.
- Answering an invite (`REPLY`) with Yes, Maybe or No for the whole event, from the mailbox address or alias the invite was sent to. Yes and Maybe also add the event to a calendar.
- Invites agents send for events they add from a conversation (`REQUEST`, updates and `CANCEL`), with the mailbox as the organizer.
- Answers to those invites: recorded with the event as they arrive, without reopening the conversation.

**Not supported**

- Applying incoming invites automatically. An agent always decides, because anyone can send an invite in someone else's name.
- Answering one occurrence of a series.
- Proposals for a new time (`COUNTER`, `DECLINECOUNTER`), `REFRESH` and `ADD`.
- Inviting people to an event that has no conversation.
- Guest lists in Google and Microsoft calendars: an invite stored there becomes an ordinary event, so Google and Microsoft never mail anyone on FreeScout's behalf. The event's description names the organizer and the attendees.

## Translations

Dutch, German, French and Spanish translate every text. Czech, Danish, Persian, Finnish, Croatian, Italian, Japanese, Korean, Norwegian, Polish, Portuguese (Brazil and Portugal), Russian, Slovak, Swedish, Turkish and Chinese (Simplified) keep their translations from version 2 and show the texts added since in English. A pull request with translations for your language is very welcome: the files are in `Resources/lang`.

## Built by LJPc

[LJPc](https://ljpc.nl) builds and maintains this module. Need a FreeScout module of your own, a link between FreeScout and your other systems, or anything else built to measure? Mail [info@ljpc.nl](mailto:info@ljpc.nl?subject=Calendar%20module) or visit [ljpc.nl](https://ljpc.nl).

## Donations

This module is open source and took a lot of work. If it helps you or your business, [a donation](https://www.buymeacoffee.com/Lars-) is much appreciated.
