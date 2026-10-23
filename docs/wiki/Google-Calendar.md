# Google Calendar

The calendar module shows Google calendars in FreeScout and keeps them in sync both ways. Events your agents create or change in FreeScout are written to Google at once, and changes made in Google appear in FreeScout at the next sync (every 5 minutes, adjustable per calendar).

Google accounts connect through an OAuth app that you create once in the Google Cloud console. It belongs to your organization, so your calendar data goes straight from Google to your FreeScout. Every Google account you connect signs in through that app.

> This page describes version 3.0 of the calendar module and later.

## What you need

- FreeScout with the calendar module 3.0 or newer, reachable over HTTPS, with FreeScout's cron job running (the module syncs from it every minute).
- A Google account that may create projects in the Google Cloud console. For Google Workspace, use an account of the organization whose calendars you connect.

## 1. Create the project and turn on the Calendar API

1. Open the [Google Cloud console](https://console.cloud.google.com/) and create a project, for example "FreeScout calendar".
2. Go to **APIs and services > Library**, search for **Google Calendar API** and click **Enable**.

## 2. Set up the consent screen

Go to **Google Auth Platform** (in older consoles **APIs and services > OAuth consent screen**).

1. **Branding:** an app name such as "FreeScout calendar", a support email and a developer contact address.
2. **Audience:** choose who can connect.
   - **Google Workspace: choose Internal.** Every account of your organization can connect, Google needs no verification, and the connection stays signed in. This is the best choice for a Workspace organization.
   - **Gmail accounts or accounts of other organizations: choose External.** While the app is in **Testing**, only the test users you list can connect, and **Google ends their access after 7 days**. FreeScout then marks the account "Needs reauthorization" and emails the administrators, and someone has to click "Reauthorize" every week. To keep connections signed in, click **Publish app**. Google verifies apps with calendar access (a privacy policy and a short demo video, usually a few business days); until then, people see a "Google hasn't verified this app" screen, which they can pass through "Advanced".
3. **Data access:** add these two scopes:
   - `https://www.googleapis.com/auth/calendar.calendarlist.readonly` (the list of calendars, including the ones shared with the account)
   - `https://www.googleapis.com/auth/calendar.events` (reading and writing the events of those calendars)

## 3. Create the OAuth client

1. In FreeScout, open **Settings > Calendar > Connections**. In the **Google Calendar** panel, copy the **Redirect URL**. It looks like `https://helpdesk.example.com/calendar/oauth/google/callback`.
2. In the Google console, go to **Clients > Create client**.
3. Choose **Web application**, give it a name, and add the copied address under **Authorized redirect URIs**.
4. Click **Create** and copy the **Client ID** and the **Client secret**.

## 4. Save the app in FreeScout

In the **Google Calendar** panel, fill in the **Client id** and the **Client secret** and click **Save Google app**. FreeScout stores the secret encrypted and never shows it again; the form then says "A secret is saved".

## 5. Connect an account and choose its calendars

1. Click **Connect a Google account** and sign in with the account whose calendars you want.
2. Allow both permissions Google asks for. When one is left out, FreeScout asks you to connect again.
3. FreeScout opens **Choose calendars** with the account's own calendars and the calendars shared with it. Tick the ones FreeScout shows and click **Add calendars**.
4. Each ticked calendar becomes a FreeScout calendar with Google's name, color and time zone. Open each one to choose who can use it, and how often it syncs.

Calendars the account may only read ("See all event details" or "See only free/busy") are added as **read-only**: agents see their events and can't change them. When the sharing changes in Google, open **Choose calendars** for the account again and FreeScout follows.

Connect more accounts the same way. Connecting an account that is connected already updates it.

## How events sync

- **Both ways.** Single events and repeating events, including occurrences that were edited or deleted on their own. Before a change to a whole series resets its edited and deleted occurrences, FreeScout asks.
- **Custom fields and the ticket.** FreeScout adds a `[FreeScout]` block with the custom fields and the ticket link to the end of the description, so they are visible in Google too. Edits to that block in Google are replaced at the next save in FreeScout.
- **Meetings with guests.** When an event has guests in Google, FreeScout leaves the `[FreeScout]` block out of its description, because Google shows the organizer's description to every guest and mails them updates. The custom fields and the ticket link stay visible in FreeScout.
- **Private events** show as "Busy", without details, and read-only.
- **Working location, out of office, focus time, birthdays and events from Gmail** are shown read-only.
- **Invitations from other organizers** are read-only: only the organizer can change them in Google.
- **Changed in two places.** When someone changed an event in Google after FreeScout last synced it, saving it in FreeScout says "This event was changed elsewhere. Reload and try again." FreeScout fetches Google's version right away, so a reload shows it.
- **Moving an event to a calendar of another account or provider** creates it in the new calendar first and then removes it from the old one. When the old calendar can't be reached, the agent is told the event is now in both.
- **A meeting with attendees stays in its calendar.** Every move away from a connected calendar removes the event there, which would cancel the meeting for every attendee, so FreeScout asks the agent to move it in the calendar app it comes from.

## When an account must sign in again

Google ends an app's access when someone removes it in their Google account, after 7 days for an External app in Testing, and after six months without use. FreeScout then:

- shows the account as **Needs reauthorization** on the Connections tab;
- keeps the events of its calendars, but stops syncing them and shows them read-only;
- emails every administrator once (and the addresses under **Settings > Alerts**), and shows the problem at the top of **Settings > Calendar** and on the calendar page for administrators.

Click **Reauthorize** on the account's row and sign in with the same Google account.

## Removing an account

**Delete** on the account's row removes the connection and its calendars, with their events, from FreeScout. Nothing is deleted in Google. To also end the app's access, open the Google account's **Security > Your connections to third-party apps and services** and remove the app.

## Troubleshooting

| What you see | What to do |
|---|---|
| `Error 400: redirect_uri_mismatch` from Google | The authorized redirect URI of the OAuth client must be exactly the Redirect URL FreeScout shows, including `https`. |
| `Error 403: org_internal` | The app is Internal, so only accounts of its own Workspace organization can connect. |
| "Google did not return a refresh token. Remove the app's access in your Google account and connect again." | Google already gave this account a token for the app. Remove the app's access in the Google account, then connect again. |
| "Google did not grant access to the calendars. Connect the account again and allow every permission it asks for." | A permission was unticked on Google's consent screen. Connect again and allow both. |
| "Google refused the app's client id or secret. Check the Google app on the Connections tab." | Copy the client id and secret from the Google console again, and save the Google app. |
| "Google is limiting requests. The next sync tries again." | Google's quota for the project is used up for the moment. The sync continues on its own. |
| A calendar shows "Sync failed" | Its settings page shows the last error. FreeScout's log (`storage/logs`) has the details, with the calendar's id. |
