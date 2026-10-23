# Invites

The calendar module reads the calendar invites your customers send to a mailbox, and lets your agents send invites of their own. Everything happens in the conversation: each invite, answer and cancellation is a reply there, and a line item records who did what.

> This page describes version 3.0 of the calendar module and later.

## Invites your customers send

When a mail holds a calendar invite, a card appears below it. It shows the title, the time in the agent's own time zone and clock, the organizer and the place, and offers one action:

| The card says | What it means | What the agent can do |
|---|---|---|
| **New invite** | The event is in no calendar yet. | Choose a calendar and click **Add to calendar**. |
| **Update to an event in calendar ...** | The organizer changed an event that is in a calendar. The old time is struck through next to the new one. | **Apply update**. |
| **Outdated version** | A newer version is in the calendar already. | Nothing to do. |
| **Cancellation** | The organizer cancelled the event (or one occurrence). | **Cancel event**: it stays in the calendar, struck through. |

FreeScout never changes a calendar by itself: an agent always clicks. A card warns when the mail comes from someone other than the invite's organizer, because anyone can send an invite in someone else's name.

An invite for one occurrence of a series names that occurrence, and applying it changes only that occurrence. Gmail, Google Workspace, Outlook, Microsoft 365, iCloud and booking systems that send standard invites all work.

### Answering

When the invite asks the mailbox (or one of its aliases) for an answer, the card has **Yes**, **Maybe** and **No**. The answer goes to the organizer as a reply in the conversation, with the subject "Accepted: <title>" (or Tentative, or Declined), and the organizer's calendar shows it.

- **Yes** and **Maybe** also add the event to the chosen calendar, or apply the update the card offers. **Add to calendar** adds it without answering.
- **No** keeps an event that is in a calendar, struck through as declined.
- An answer is for the whole event, never for one occurrence.

### Google and Microsoft calendars

An invite added to a Google or Microsoft calendar becomes an ordinary event there, without its guest list, so Google and Outlook never mail anyone on FreeScout's behalf. The organizer is named in the event's description. A cancelled or declined event can't stay in those calendars as such, so its title gets "Cancelled: " or "Declined: " in front and its time is marked free.

## Invites your agents send

Agents invite people to an event they add from a conversation. In the conversation's **Add to calendar** window:

1. Tick **Invite the customer** (it is off until the agent ticks it), and add other addresses under **Other people to invite** when needed.
2. Type a **Message with the invite** if you like. The mail holds that message, then what, when and where.
3. Click **Add and invite**.

The invite is a reply in the conversation from the mailbox, which organizes the event. It carries the invite the way mail apps expect it, so Gmail, Outlook and Apple Mail show their Yes, Maybe and No buttons, and add the event to the recipient's calendar.

### Answers come back by themselves

When an attendee answers, their answer arrives in the conversation and FreeScout records it at once: a line item says "<name> accepted "<title>".", and the event's details on the calendar page list every attendee with their answer. An answer leaves the conversation's status as it was, so a closed conversation stays closed.

### Changing and deleting

On the calendar page, the event form of an event added from a conversation lists its **Attendees**:

- With **Notify attendees** ticked (the default), a change of time, repeat rule, title, place or description sends everyone an update. People you add get an invitation, people you remove get a cancellation.
- Deleting the event asks whether to notify the attendees; they then get a cancellation.
- Moving an event by dragging it sends an update too.

In a Google or Microsoft calendar the attendees are listed in the event's description, not in its guest list, so Google and Microsoft send nothing themselves. A CalDAV calendar stores them with the event and tells the server not to send invitations of its own.
