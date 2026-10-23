# W4 manual test: invites with Gmail, Google Workspace, Microsoft 365, Outlook.com and iCloud

W4 built the invite card, the answers and the invites FreeScout sends from fixtures and RFC 5546/6047, and checked the mails in Mailpit. Whether each mail service reads what FreeScout sends, and sends what FreeScout expects, only shows with real accounts. This checklist is that test.

Each item gives the steps, the expected result, and where to look when the result differs. Items marked **UNVERIFIED** test an assumption the code relies on that no documentation confirms; write down what really happens. Number your findings by item (I3, O2 and so on).

## Before you start

**Setup**

- A FreeScout with the module from `v3` and a mailbox that fetches real mail (an install with a working IMAP and SMTP mailbox; the local ddev site receives no mail). Below, "the mailbox" is that mailbox's address.
- A FreeScout calendar the agent may add to: a FreeScout calendar for the first run, then a Google, a Microsoft and a CalDAV calendar for the items that name one.
- Accounts that send and receive invites: a Gmail account, a Google Workspace account, an Outlook.com account, a Microsoft 365 work account and an iCloud account. Each item says which ones it needs.

**Where to look when something fails**

1. The card below the mail. Its heading and status say what FreeScout made of the invite ("New invite", "Update to an event in calendar ...", "Outdated version", "Cancellation", "Answer to an invite").
2. The invite rows: `SELECT id, thread_id, uid, recurrence_id, method, sequence, dtstamp, organizer_email, sender_email, status, response, event_id FROM calendar_invites WHERE conversation_id = <id>`. `status` is pending, applied, processed (an answer to our invite) or ignored.
3. The files FreeScout stored: the mail's attachments (`calendar.ics`, `invite.ics` or a name of the sender's). FreeScout reads METHOD inside the file, because core drops the MIME `method=` parameter.
4. What FreeScout sent: the reply thread's meta holds the iTIP message (`SELECT meta FROM threads WHERE id = <thread id>`, key `ljpccalendarmodule.itip`), and the mail itself arrives with `multipart/alternative` (text, HTML, `text/calendar; method=...`) plus `invite.ics` as `application/ics`. Read the raw mail in the recipient's mail app ("Show original" in Gmail, "View message source" in Outlook).
5. The stored event and its attendees: `SELECT id, ics, attendees, invite_state, sequence FROM calendar_events WHERE id = <event id>` (the id is in the permalink, `/calendar#e<id>`).
6. FreeScout's log, `storage/logs/laravel-YYYY-MM-DD.log`: the card and the mail hooks report what they can't read there, and fetching mail keeps working.

## Incoming invites

- [ ] **I1. A Gmail invite becomes one card, and Yes reaches the organizer.**
  1. From Gmail, create an event, invite the mailbox, and send.
  2. In FreeScout, open the conversation. Expected: one card ("New invite") below the mail, although Gmail attaches the invite twice (`invite.ics` and an unnamed part stored as `calendar.ics`). Title, time in the agent's zone and clock, organizer and place are right.
  3. Choose a calendar and click **Yes**. Expected: "You accepted the invite. The organizer gets your answer.", the event in the chosen calendar, a line item for the add and one for the answer, and a reply in the conversation with the subject "Accepted: <title>".
  4. In Gmail, open the event. Expected: the mailbox shows as accepted in the guest list. **UNVERIFIED:** Gmail reads our REPLY from the inline part.

- [ ] **I2. A Google Workspace update and cancellation.**
  1. From Workspace, invite the mailbox; add the invite in FreeScout (**Add to calendar**, no answer).
  2. Move the event in Google Calendar and save, sending the update. Expected: a new card, "Update to an event in calendar ...", the old time struck through next to the new one. **Apply update** moves the event; the first card now says "A newer version of this invite is in calendar ...".
  3. Delete the event in Google Calendar, sending the cancellation. Expected: a "Cancellation" card; **Cancel event** keeps the event, struck through on a FreeScout or CalDAV calendar, with "Cancelled: " before its title and its time free on a Google or Microsoft calendar.

- [ ] **I3. An Outlook.com and a Microsoft 365 invite. UNVERIFIED in part.**
  1. From Outlook (both kinds of account), invite the mailbox.
  2. Expected: one card. Outlook sends the invite only as an inline `text/calendar` part, which FreeScout stores as an attachment (`calendar.ics` or the event's title).
  3. Click **Maybe**. Expected: the organizer's Outlook shows the mailbox as tentative. **UNVERIFIED:** Outlook processes a REPLY that arrives as a reply in an existing thread, with the subject "Tentative: <title>".
  4. An Outlook invite has a very long UID (it starts with `040000008200E000`); check that a later update from Outlook finds the event (card "Update to ...", not "New invite").

- [ ] **I4. An iCloud invite: relay addresses. UNVERIFIED.**
  1. From the Calendar app with iCloud, invite the mailbox.
  2. Expected: no warning that the mail comes from someone other than the organizer when iCloud sends from a relay address and names the real one in the ORGANIZER's `EMAIL` parameter (FreeScout accepts both). Write down the sender and the ORGANIZER line when there is a warning.
  3. Click **No**. Expected: the answer goes to the ORGANIZER address (the relay), and iCloud shows the mailbox as declined.

- [ ] **I5. One occurrence of a series.**
  1. From Gmail or Outlook, invite the mailbox to a weekly series; add it in FreeScout.
  2. In the organizer's app, move one occurrence. Expected: a card that names that occurrence ("Only the occurrence of <date>."); **Apply update** moves only that occurrence.
  3. Cancel another single occurrence. Expected: "Cancel occurrence" cancels only that one.
  4. In Outlook or Google, change "this and following events". Write down what arrives: Google usually splits the series into two events (a new UID for the rest), Outlook may send `RANGE=THISANDFUTURE`. With the range, the card warns that applying moves the whole series to start at that occurrence. **UNVERIFIED.**

- [ ] **I6. A mail from someone other than the organizer.**
  Forward an invite from another address to the mailbox. Expected: the card warns "This mail comes from ..., not from the organizer ...". Nothing is ever applied without a click.

- [ ] **I7. Older mail.** Open a conversation with an invite that arrived before the module was updated. Expected: the card appears on first opening, the same as for new mail.

## Invites FreeScout sends

- [ ] **O1. Invite a Gmail customer from the conversation, and their answer comes back.**
  1. In a conversation with a Gmail customer, click the calendar button, fill in the event, tick **Invite the customer**, type a message, and click **Add and invite**.
  2. Expected in Gmail: a mail "Invitation: <title>" from the mailbox, with the message, the summary (what, when in the event's zone, where), Gmail's invitation card with Yes, Maybe and No, and `invite.ics`. Nothing mentions the FreeScout ticket link.
  3. Close the conversation in FreeScout. In Gmail, click **Yes**.
  4. Expected in FreeScout: the answer arrives in the conversation, a line item "<name> accepted "<title>".", the event's details show the customer as accepted, and the conversation is still closed. The card below the answer says "This answer is recorded with the event."

- [ ] **O2. The same with Outlook.com and Microsoft 365. UNVERIFIED.**
  Expected: Outlook shows Accept, Tentative and Decline (it reads the inline `method=REQUEST` part), and the answer is processed as in O1. Write down whether Outlook adds the event to the recipient's calendar before they answer.

- [ ] **O3. The same with iCloud. UNVERIFIED.** iCloud may answer from a relay address; FreeScout only takes an answer from the address it invited, so write down the sender of the answer.

- [ ] **O4. Updates and attendees.**
  1. On the calendar page, edit the event of O1: change the time, keep **Notify attendees** ticked, and save. Expected: "Updated invitation: <title>" to the customer; their calendar moves the event.
  2. Add a second address in **Attendees** and save without changing anything else. Expected: an invitation to the new address only.
  3. Remove the second address. Expected: a cancellation to that address only; their calendar removes the event.
  4. Change the title with **Notify attendees** unticked. Expected: no mail.
  5. Drag the event to another time. Expected: an update goes out (a drag has no checkbox, so the attendees are told).

- [ ] **O5. Delete.** Delete the event with **Notify attendees** ticked. Expected: "Cancelled: <title>" to every attendee; their calendars cancel the event. Delete another with the box unticked: no mail.

- [ ] **O6. A Google or Microsoft calendar as the FreeScout calendar.**
  Repeat O1 in a Google calendar and in a Microsoft calendar. Expected: Google and Microsoft send nothing themselves (the event there has no guests), and the event's description at Google or Microsoft ends with the readable block, which lists "Attendees: <name> <address> (<answer>)". After the answer of O1, the block shows the answer at the next change of the event.

- [ ] **O7. A CalDAV calendar as the FreeScout calendar.**
  Repeat O1 in a CalDAV calendar on iCloud, Fastmail or Nextcloud. Expected: one invitation, from FreeScout. **UNVERIFIED:** the server sends no invitation of its own (FreeScout writes the attendees with `SCHEDULE-AGENT=CLIENT`).

## Not covered on purpose

- An answer for one occurrence of a series FreeScout sent (a REPLY with RECURRENCE-ID) is not processed; the conversation reopens as for any mail.
- An answer from another address than the one invited is not processed.
