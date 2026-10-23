# Calendar module v3, W4: invites, implementation plan

> **How this plan is executed:** in the main session (Lars, 2026-10-19: no subagents), task by task, test first, one or a few commits per task on `v3`. Each task ends with the full PHPUnit suite (with Radicale), `npm run check` and `npm run build` when the frontend changes, and a browser check for UI. The e2e suite runs at the end of each UI task.

**Goal:** incoming calendar invites become a card on the conversation that adds, updates or cancels the event with one click and answers the organizer (#83); events linked to a conversation can invite the customer and others, and their answers are processed automatically (#47).

**Spec:** `docs/superpowers/specs/2026-10-05-calendar-module-v3-design.md`, sections 12 (conversation integration) and 13 (invites); research section D (iTIP and iMIP). The decision log is final.

## Decisions (Lars, 2026-10-19)

- An invite added to a Google or Microsoft calendar becomes an ordinary event there, without attendees; the organizer is named in the readable block. It stays writable, later updates apply with one click, and Google and Outlook never mail anyone. CalDAV keeps ORGANIZER and ATTENDEE with `SCHEDULE-AGENT=CLIENT`.
- RSVP Yes or Maybe also adds the event to the chosen calendar when it is not in one yet. "Add without answering" stays possible.
- A REPLY to one of our invites leaves the conversation's status as it was (no reopening); only a line item records it.
- "Invite customer" in the conversation modal is off by default.
- An outgoing invite mail holds an optional personal message from the agent, followed by a fixed summary (what, when in the mailbox's time zone, where).
- Every invite action adds a line item to the conversation: added, update applied, cancellation applied, our answer, invite/update/cancellation sent, an attendee's answer.
- "No" on an invite whose event is in a calendar keeps the event, struck through as declined.
- Cancelled or declined events: FreeScout and CalDAV calendars use `STATUS:CANCELLED` (cancelled) or our attendee's `PARTSTAT=DECLINED` (declined) and show the event struck through. Google and Microsoft can't keep a cancelled event (Google's cancelled status deletes it), so there the title gets "Cancelled: " or "Declined: " in front and the event is marked free.

From the spec's decision log (2026-10-05): agents always click (no automatic apply of incoming invites); RSVP only for the whole series; outgoing invites only for events linked to a conversation; the organizer of an outgoing invite is the conversation's mailbox address.

## Facts the tasks rely on (checked 2026-10-19)

- FreeScout stores `text/calendar` parts as attachments (`calendar.ics` when unnamed), drops the MIME `method=` parameter, and Gmail adds `invite.ics` with the same content. Read content with `Attachment::getFileContents()`.
- `thread.attachments_list_append` ($thread, $conversation, $mailbox) renders below a thread's attachments; v2 used it for its ICS link, v3 has nothing there yet.
- New mail: `conversation.created_by_customer` and `conversation.customer_replied` (filters and actions) give the saved thread with its attachments. `conversation.status_changing` (FetchEmails) runs before the customer-reply status change, with the conversation still holding its old status.
- A reply thread whose `customer_id` is another customer than the conversation's, with `setTo([email])`, is sent by `SendReplyToCustomer` to that address: the route for an RSVP to the organizer.
- `email.reply_to_customer.swiftmessage` ($swiftmessage, $from_alias, $thread, $mailbox) can add an inline `text/calendar; method=X` part (`multipart/alternative`), which Outlook requires.
- `calendar_events` has `sequence` and `organizer_email` (W1); `calendar_invites` does not exist yet.
- sabre/vobject `ITip\Broker::parseEvent()` builds a REPLY; its `processMessage*` must not be used to apply updates (it wipes series on an occurrence update and cancels whole series), so applying is our own code.
- `EventService::hasAttendees()` already refuses moving a meeting away from its provider calendar (2026-10-15).

## Tasks

### Task 1: `calendar_invites`, `CalendarInvite`, `InviteParser`
- Migration `2026_10_19_000009_ljpc_calendar_v3_invites.php` (gate and idempotent checks like 000008, PHP 7.1-parseable): table `calendar_invites` (id, conversation_id, thread_id, attachment_id, uid, recurrence_id (key or null), method, sequence, dtstamp (UTC), organizer_email, sender_email, summary, status pending|applied|ignored|outdated|processed, event_id, response (accepted|tentative|declined|null), applied_by, applied_at, timestamps; unique (attachment_id, uid, recurrence_id); index uid). Rebuild the test database.
- `Entities/CalendarInvite`.
- `Services/InviteParser::parse(string $ics): array` of messages: method (missing = PUBLISH), uid, recurrence key per component, sequence, dtstamp, organizer, attendees with PARTSTAT and RSVP, summary, start/end/all-day for display, `RANGE=THISANDFUTURE`; a file that isn't iCalendar is no message (logged at info).
- Fixtures in `Tests/fixtures/invites/` written from research section D3: Gmail REQUEST, Outlook/Teams REQUEST (Windows TZID, X-MICROSOFT, no ORGANIZER variant), iCloud (imip.me.com organizer), PUBLISH booking, CANCEL whole and one occurrence, REQUEST for one occurrence, REPLY.

### Task 2: detection and classification (`InviteService`)
- `scan(Attachment)`: one row per (attachment, uid, recurrence) for `.ics` or `text/calendar` attachments, idempotent; Gmail's `calendar.ics` + `invite.ics` pair in one thread is one card (the card groups by uid). New mail is scanned in the created/replied hooks, older mail when its attachments render.
- `classify(CalendarInvite, User)`: new (unknown UID, PUBLISH or REQUEST), update (known UID with higher SEQUENCE, or equal SEQUENCE and newer DTSTAMP), outdated, cancellation, reply (handled in Task 6); the event found by UID across the calendars the user can view; occurrence named; `RANGE=THISANDFUTURE` warning; spoofing warning when the sender is not the organizer (address compare, case-insensitive).

### Task 3: the invite card and applying it
- Card below the thread's attachments (Blade, Bootstrap 3, server-rendered; a small settings-style script posts its actions): title, when (viewer's zone and clock), organizer, state, warnings; actions per state: calendar select + Add, Apply update (old and new time side by side), Cancel event; none for outdated.
- API: `POST /calendar/api/invites/{id}/apply` (calendar_id for new), checks: conversation view, `create` on the chosen calendar or `edit` on the event's calendar.
- Applying goes through `EventService` (provider writes, readable block): new = create with the invite's VCALENDAR (Google/Microsoft: attendees removed, organizer in the readable block; CalDAV: `SCHEDULE-AGENT=CLIENT`); update = replace master or merge one override (EXDATE for a cancelled occurrence), sequence stored; cancel = cancelled state per the decisions above. Line items for each.

### Task 4: RSVP
- Yes, Maybe, No on REQUEST cards when the mailbox address or an alias is an ATTENDEE and RSVP is not FALSE; Yes/Maybe add the event first when it is in no calendar (calendar select on the card).
- REPLY built with `ITip\Broker::parseEvent()` (`scheduleAgentServerRules=false`) for the whole series; a reply thread in the conversation to the organizer (a customer for the organizer's address), subject "Accepted/Tentative/Declined: <summary>"; the inline `text/calendar; method=REPLY` part added in `email.reply_to_customer.swiftmessage` for threads marked in their meta.
- Our answer stored on the invite (`response`) and, for No on an event in a calendar, the declined state per the decisions. Line item.

### Task 5: outgoing invites
- Conversation modal: "Invite customer" (off), extra addresses, personal message. Event form on the calendar page for events linked to a conversation: attendees list, "Notify attendees" (on) when time, rule or status changes; delete offers to send a cancellation.
- The event becomes ours: ORGANIZER = the mailbox address, ATTENDEEs with PARTSTAT=NEEDS-ACTION, SEQUENCE bumped on DTSTART/DTEND/RRULE/RDATE/EXDATE/STATUS changes.
- Sending: a reply thread in the conversation to the attendees, body = message + fixed summary (mailbox time zone), inline `method=REQUEST` (or CANCEL) part plus an `invite.ics` attachment. Line items.
- Storage: CalDAV keeps ATTENDEEs with `SCHEDULE-AGENT=CLIENT`; Google and Microsoft get no attendees, the readable block lists them ("Attendees: <name> <email> (<status>)").

### Task 6: incoming REPLY processing
- A REPLY whose UID matches an event we organize and whose single ATTENDEE equals the sender updates that attendee's PARTSTAT (and the readable block at the provider), marks the invite processed and adds a line item "<name> accepted/declined/may come". Mismatches leave the invite as a plain card with a warning.
- The conversation keeps its status: `conversation.status_changing` remembers the old status, `conversation.customer_replied` restores it when the new thread is such a REPLY.

### Task 7: end-to-end, manual checklist, docs
- e2e: an incoming Gmail-style invite (seeded conversation with an attachment) added, updated and cancelled; RSVP sends a reply thread; an outgoing invite from the modal; a seeded REPLY updates the attendee without reopening.
- `docs/w4-manual-test.md`: recognition and RSVP in Gmail, Google Workspace, Microsoft 365, Outlook.com, iCloud (spec section 20).
- CLAUDE.md, the spec's decision log rows and the wiki draft "Invites and RSVP".
