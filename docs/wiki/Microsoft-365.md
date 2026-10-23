# Microsoft 365

The calendar module shows Microsoft 365 calendars in FreeScout, including the calendars of shared mailboxes, and keeps them in sync both ways. Events your agents create or change in FreeScout are written to Microsoft at once, and changes made in Outlook appear in FreeScout at the next sync (every 5 minutes, adjustable per calendar).

Microsoft 365 accounts connect through an app registration that you create once in Microsoft Entra. It belongs to your organization, so your calendar data goes straight from Microsoft to your FreeScout.

> This page describes version 3.0 of the calendar module and later.

## What you need

- FreeScout with the calendar module 3.0 or newer, reachable over HTTPS, with FreeScout's cron job running (the module syncs from it every minute).
- An administrator of your Microsoft 365 organization who can register apps and grant admin consent in Microsoft Entra.
- For personal outlook.com, hotmail.com or live.com calendars, see [Personal Microsoft accounts](#personal-microsoft-accounts) below.

## 1. Register the app

1. In FreeScout, open **Settings > Calendar > Connections**. In the **Microsoft 365** panel, copy the **Redirect URL**. It looks like `https://helpdesk.example.com/calendar/oauth/microsoft/callback`.
2. Open the [Microsoft Entra admin center](https://entra.microsoft.com) and go to **Identity > Applications > App registrations > New registration**.
3. **Name:** for example "FreeScout calendar".
4. **Supported account types:** **Accounts in this organizational directory only (single tenant)**.
5. **Redirect URI:** platform **Web**, with the copied address. (Not "Single-page application": Microsoft ends those sign-ins after 24 hours.)
6. Click **Register**. On the app's **Overview**, copy the **Application (client) ID** and the **Directory (tenant) ID**.

## 2. Give it its permissions

1. Go to **API permissions > Add a permission > Microsoft Graph > Delegated permissions**.
2. Add `offline_access`, `User.Read`, `Calendars.ReadWrite` and `Calendars.ReadWrite.Shared`.
3. Click **Grant admin consent for** your organization. Many organizations don't let users consent to apps themselves; with admin consent, every account can connect.

`Calendars.ReadWrite.Shared` is what gives access to shared mailboxes and to calendars other people shared with an account. FreeScout only uses it for the calendars you choose.

## 3. Create a client secret

1. Go to **Certificates and secrets > Client secrets > New client secret**.
2. Give it a description and an expiry (at most 24 months).
3. Copy the **Value** right away (not the "Secret ID"): Entra shows it only once. Note the expiry date.

## 4. Save the app in FreeScout

In the **Microsoft 365** panel, fill in:

- **Directory (tenant) id:** the Directory (tenant) ID from step 1, or a verified domain of your organization.
- **Application (client) id:** the Application (client) ID.
- **Client secret:** the secret's value.
- **Secret expires on:** the expiry date. Administrators get an email 30 days before it, so a new secret is in place in time.

Click **Save Microsoft app**. FreeScout stores the secret encrypted and never shows it again.

**An app for several organizations.** A registration with the account type "Accounts in any organizational directory (multitenant)" can connect accounts of other organizations too. Fill in `organizations` as the tenant for such an app. A single-tenant app needs its own tenant id: Microsoft refuses the sign-in with `organizations` or `common` (`AADSTS50194`).

## 5. Connect an account and choose its calendars

1. Click **Connect a Microsoft account** and sign in with the account whose calendars you want.
2. FreeScout opens **Choose calendars** with the account's calendars and the calendars other people shared with it in Outlook. Tick the ones FreeScout shows and click **Add calendars**.
3. Each ticked calendar becomes a FreeScout calendar with Outlook's name and color. Open each one to choose who can use it, and how often it syncs.

Calendars the account may only view are added as **read-only**: agents see their events and can't change them. When that changes in Outlook, open **Choose calendars** for the account again and FreeScout follows.

### Shared mailboxes

1. On the account's row on the Connections tab, click **Add a shared mailbox** and fill in the mailbox's email address.
2. **Choose its calendars** lists the shared mailbox's calendars to add.

The signed-in account needs rights on the shared mailbox in Exchange:

- **Full Access:** Exchange admin center > **Recipients > Mailboxes** > the shared mailbox > **Delegation > Read and manage**. This gives access to all of its calendars.
- **Or a permission on its calendar only**, in Exchange Online PowerShell:
  `Add-MailboxFolderPermission -Identity shared@example.com:\Calendar -User agent@example.com -AccessRights Editor`
  (`Reviewer` to only read it.)

Without rights, FreeScout says "You don't have access to the calendars of shared@example.com."

## How events sync

- **Both ways.** Single events and repeating events, including occurrences that were edited or deleted on their own. Before a change to a whole series resets its edited and deleted occurrences, FreeScout asks.
- **Time zones.** Outlook's Windows time zones are translated to the ones FreeScout uses, and back.
- **Repeating monthly on day 29, 30 or 31** can't be stored by Microsoft. FreeScout refuses such a rule on a Microsoft calendar with "Microsoft calendars can't repeat on day 29, 30 or 31 of the month." Outlook's own "last day of the month" works both ways.
- **Custom fields and the ticket.** FreeScout adds a `[FreeScout]` block with the custom fields and the ticket link to the end of the event's body, so they are visible in Outlook too.
- **Meetings with attendees.** When an event has attendees at Microsoft (a booked room counts too), FreeScout leaves the `[FreeScout]` block out of its body, because Outlook mails every update of a meeting to its attendees. The custom fields and the ticket link stay visible in FreeScout.
- **Private events** show as "Busy", without details, and read-only.
- **Invitations from other organizers** are read-only.
- **Changed in two places.** When someone changed an event in Outlook after FreeScout last synced it, saving it in FreeScout says "This event was changed elsewhere. Reload and try again." FreeScout fetches Outlook's version right away, so a reload shows it.
- **Deleted in Outlook.** The event goes to Deleted Items in Outlook and disappears from FreeScout at the next sync.
- **Moving an event to a calendar of another account or provider** creates it in the new calendar first and then removes it from the old one. When the old calendar can't be reached, the agent is told the event is now in both.
- **A meeting with attendees stays in its calendar.** Every move away from a connected calendar removes the event there, which would cancel the meeting for every attendee, so FreeScout asks the agent to move it in the calendar app it comes from.

## Renewing the client secret

The secret belongs to the app registration, so all Microsoft accounts use the same one.

1. Before the expiry date, create a new secret (step 3).
2. Save it in FreeScout with its new expiry date. The connected accounts keep working.
3. Delete the old secret in Entra.

FreeScout emails the administrators 30 days before the date you saved, and shows it at the top of **Settings > Calendar** and on the calendar page for administrators. Once a secret has expired, every Microsoft calendar stops syncing with "Microsoft refused the app's client id or secret. Check the Microsoft app on the Connections tab."

## When an account must sign in again

Microsoft ends an app's access when an administrator resets the user's password or revokes their sessions, when a Conditional Access policy asks for a new sign-in or for multi-factor authentication, and after 90 days without use. FreeScout then:

- shows the account as **Needs reauthorization** on the Connections tab;
- keeps the events of its calendars, but stops syncing them and shows them read-only;
- emails every administrator once (and the addresses under **Settings > Alerts**), and shows the problem at the top of **Settings > Calendar** and on the calendar page for administrators.

Click **Reauthorize** on the account's row and sign in with the same account.

## Removing an account

**Delete** on the account's row removes the connection and its calendars, with their events, from FreeScout. Nothing is deleted at Microsoft. To also end the app's access for everyone, delete the app registration in Entra.

## Personal Microsoft accounts

The app connects the work and school accounts of Microsoft 365. For a personal outlook.com, hotmail.com or live.com calendar, publish it as an ICS link and add that link to FreeScout as an ICS feed, which shows its events read-only:

1. In Outlook.com, open **Settings > Calendar > Shared calendars > Publish a calendar**.
2. Choose the calendar and **Can view all details**, click **Publish**, and copy the **ICS** link.
3. In FreeScout, go to **Settings > Calendar > New calendar**, choose **ICS feed** and paste the link.

Anyone with that link can read the calendar, so FreeScout shows the link only to administrators, in the calendar's settings.

## Troubleshooting

| What you see | What to do |
|---|---|
| `AADSTS50011` (redirect URI mismatch) | Add FreeScout's Redirect URL to the app registration on the **Web** platform, exactly as FreeScout shows it. |
| `AADSTS50194` | The app is single-tenant: fill in your tenant id instead of `organizations` or `common`. |
| `AADSTS65001` or `AADSTS90094` | The organization requires admin consent: grant it under **API permissions**. |
| `AADSTS700016` | The tenant id doesn't match the app registration's directory. |
| "Microsoft refused the app's client id or secret. Check the Microsoft app on the Connections tab." | The secret is wrong (use its **Value**, not its ID) or has expired. Create a new one and save it. |
| "Microsoft did not return a refresh token. Check that the app has the offline_access permission." | Add the `offline_access` permission and connect again. |
| "You don't have access to the calendars of ..." | The account needs Full Access or a calendar permission on that shared mailbox. |
| "Microsoft is limiting requests right now. Try again in a minute." | Microsoft is throttling the account for a moment. The sync waits as long as Microsoft asks. |
| A calendar shows "Sync failed" | Its settings page shows the last error. FreeScout's log (`storage/logs`) has the details, with the calendar's id. The Entra sign-in logs explain `AADSTS` codes. |
