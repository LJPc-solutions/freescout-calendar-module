# CalDAV

The calendar module shows the calendars of any CalDAV server in FreeScout and keeps them in sync both ways: Nextcloud, Baïkal, iCloud, Fastmail, Zoho, cPanel and every other server that offers CalDAV. Events your agents create or change in FreeScout are written to the server at once, and changes made elsewhere appear in FreeScout at the next sync (every 5 minutes, adjustable per calendar).

> This page describes version 3.0 of the calendar module and later.

## Connect an account

1. Open **Settings > Calendar > Connections** and fill in the **CalDAV** form:
   - **Server address:** the server's address (see the table below). FreeScout finds the account's calendars from it. The address of one calendar works too.
   - **Username** and **Password:** use an app password when the server offers one. Keep the username and password out of the address itself.
2. Click **Connect**. FreeScout stores the login encrypted, and only after it has found the account's calendars with it.
3. **Choose calendars** lists them. Tick the ones FreeScout shows and click **Add calendars**.
4. Each ticked calendar becomes a FreeScout calendar with the server's name, color and time zone. Open each one to choose who can use it, and how often it syncs.

Calendars the account may only read are added as **read-only**: agents see their events and can't change them.

## Server addresses

| Server | Server address | Username | Password |
|---|---|---|---|
| Nextcloud | `https://cloud.example.com/remote.php/dav` | Nextcloud username | An app password: **Personal settings > Security > Devices and sessions > Create new app password** |
| Baïkal | `https://dav.example.com/dav.php` | Baïkal username | Its password |
| iCloud | `https://caldav.icloud.com/` | Apple Account email address | An **app-specific password** (see below) |
| Fastmail | `https://caldav.fastmail.com/` | Fastmail email address | An app password with access to calendars: **Settings > Privacy and security > Manage app passwords** |
| Zoho | The address under **Zoho Calendar > Settings > CalDAV Sync**, on your data centre's host (`calendar.zoho.com`, `calendar.zoho.eu`, `calendar.zoho.in` and so on) | Email address | An app-specific password when two-factor authentication is on |
| cPanel | `https://example.com:2080/` | The full email address of the account | Its password |

### iCloud: an app-specific password

iCloud only accepts an app-specific password for CalDAV, also when two-factor authentication is off; the normal Apple Account password is refused with "The CalDAV server refused the username or password."

1. Sign in at [account.apple.com](https://account.apple.com) and open **Sign-In and Security > App-Specific Passwords**.
2. Create one named "FreeScout" and use it as the password.

Apple ends every app-specific password when the Apple Account password changes. Then create a new one and use **Reauthorize** on the account's row.

## How events sync

- **Both ways.** Single events and repeating events, including occurrences that were edited or deleted on their own. Before a change to a whole series resets its edited and deleted occurrences, FreeScout asks.
- **Custom fields and the ticket.** FreeScout adds a `[FreeScout]` block with the custom fields and the ticket link to the end of the description, so they are visible in every calendar app. Edits to that block elsewhere are replaced at the next save in FreeScout.
- **Changed in two places.** When someone changed an event on the server after FreeScout last synced it, saving it in FreeScout says "This event was changed elsewhere. Reload and try again." FreeScout fetches the server's version right away, so a reload shows it.
- **Moving an event to a calendar of another account or provider** creates it in the new calendar first and then removes it from the old one. When the old calendar can't be reached, the agent is told the event is now in both.
- **A meeting with attendees stays in its calendar.** Every move away from a connected calendar removes the event there, which would cancel the meeting for every attendee, so FreeScout asks the agent to move it in the calendar app it comes from.

### Moving an event between two calendars of one account

Every event has a UID that calendar apps know it by. Some servers allow a UID only once per account, across all of its calendars: Fastmail and other servers built on Cyrus, and possibly iCloud. When you move an event between two calendars of such an account in FreeScout, the server refuses the UID in the new calendar, so FreeScout gives the moved event a new UID there.

- FreeScout's own links to the event and the "added to the calendar" lines in tickets keep working.
- Other calendar apps see the moved event as a new event, and people who were invited to it from another app may get it as a new invitation.

## A server on your own network

FreeScout blocks requests to private network addresses, so that nobody can use it to reach internal systems. To connect a CalDAV server on your own network, such as a Nextcloud on the LAN or in a container next to FreeScout, allow it in FreeScout's `.env` file:

```
APP_REMOTE_HOST_WHITE_LIST=nextcloud.internal,192.168.10.0/24
```

- FreeScout checks the address a host name points to, so list the **host name and its address**, or the range it is in.
- For a server in a Docker container, the range is the subnet of the Docker network that FreeScout reaches it on. `docker network inspect <network>` shows it under `Subnet`, for example `APP_REMOTE_HOST_WHITE_LIST=nextcloud,172.18.0.0/16`.
- Separate entries with commas, and add them to an existing `APP_REMOTE_HOST_WHITE_LIST` line instead of adding a second one.
- Then run `php artisan freescout:clear-cache`, so FreeScout reads `.env` again.

Until then, connecting says "This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST."

## When the password changes

When the server refuses the saved password, FreeScout:

- shows the account as **Needs reauthorization** on the Connections tab, with the login form open on its row;
- keeps the events of its calendars, but stops syncing them and shows them read-only;
- no longer sends that password when it syncs or saves events;
- emails every administrator once (and the addresses under **Settings > Alerts**), and shows the problem at the top of **Settings > Calendar** and on the calendar page for administrators.

Fill in the new password on the account's row and click **Connect again**. An empty password field keeps the saved one.

## Removing an account

**Delete** on the account's row removes the connection and its calendars, with their events, from FreeScout. Nothing is deleted on the server.

## Troubleshooting

| What you see | What to do |
|---|---|
| "The CalDAV server refused the username or password." | Check the username, and use an app password where the server requires one (iCloud always does). |
| The account shows **Needs reauthorization** although its password is right | The server refused the login once, which some servers do while their login service is briefly down. Fill in the password on the account's row and click **Connect again**. |
| "Leave the username and password out of the address and fill them in below." | Remove `user:password@` from the server address. |
| "This address is not allowed. An administrator can allow it with APP_REMOTE_HOST_WHITE_LIST." | The server is on a private network address; see [A server on your own network](#a-server-on-your-own-network). |
| "The CalDAV server refused this request (HTTP 403)." when an agent saves an event | The account may only read this calendar on the server. Give it write access there, or set the calendar's rights in FreeScout so agents only view it. |
| "The CalDAV server has nothing at this address" | Check the server address; try the address of one calendar instead. |
| "This account has no calendars with events that FreeScout can show." | The account holds only address books or task lists, or the address points elsewhere. |
| A calendar shows "Sync failed" | Its settings page shows the last error. FreeScout's log (`storage/logs`) has the details, with the calendar's id. |
