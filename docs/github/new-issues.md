# New issues per feature area (drafts for Lars)

One issue per feature that version 3.0 leaves out on purpose, so users can follow and upvote what matters to them. Each gets the label `enhancement`. Nothing here names a security finding.

---

## Answer one occurrence of a recurring invite

Version 3.0 answers an invite (Yes, Maybe or No) for the whole event. A customer who invites the mailbox to a weekly meeting may want a "No" for one week only.

How it could work: the invite card of a single occurrence (an update with `RECURRENCE-ID`) gets its own answer buttons, and the `REPLY` names that occurrence. Answers that our own invites receive for one occurrence would then be recorded per occurrence too.

---

## Propose a new time for an invite

Customers' calendar apps let an attendee propose another time (`COUNTER`). Version 3.0 neither sends nor reads such proposals.

How it could work: a "Propose a new time" action on the invite card sends a `COUNTER` to the organizer, and a proposal an attendee sends for one of our invites shows on a card that accepts (`REQUEST` with the new time) or declines it (`DECLINECOUNTER`).

---

## Invite people to an event that has no conversation

Version 3.0 sends invites only for events added from a conversation: the invite goes out as a reply in that conversation, from its mailbox. Events created on the calendar page can't invite anyone yet.

How it could work: the event form offers attendees and a mailbox to send from, and FreeScout creates a conversation for the invite, so the answers have a place to arrive.

---

## Edit "this and following" occurrences

Version 3.0 changes one occurrence of a series or the whole series. Calendar apps also offer "this and following events", which splits the series in two.

How it could work: a third choice when changing or deleting an occurrence, which ends the series before it and starts a new series from it, on every kind of calendar.

---

## Sync changes the moment they happen

Version 3.0 checks external calendars on a schedule (every 5 minutes by default) and on "Sync now". Google and Microsoft can also notify FreeScout of a change right away.

How it could work: subscribe to Google's push notifications and Microsoft Graph change notifications, and sync a calendar as soon as either says it changed. This needs FreeScout to be reachable from the internet over HTTPS.

---

## A view with one column per agent

The calendar page shows calendars on top of each other. Teams that plan per person would like to see each agent side by side.

How it could work: a resource view in the day and week views, with one column per calendar or per author.

---

## Personal outlook.com accounts

Version 3.0 connects work and school accounts of Microsoft 365. Personal outlook.com accounts can add their calendar as a read-only ICS feed today (see the FAQ).

How it could work: an app registration that also accepts personal Microsoft accounts, so outlook.com calendars sync both ways.
