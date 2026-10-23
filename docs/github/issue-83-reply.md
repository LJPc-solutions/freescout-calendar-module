# Reply to #83 (draft for Lars)

Post as a comment on https://github.com/LJPc-solutions/freescout-calendar-module/issues/83, then close the issue. Written for after the 3.0.0 release; posted earlier, replace the last paragraph with: "Version 3.0 is coming soon. The module updates itself from the Modules page; back up your database before you update."

---

Thanks for raising this. Version 3.0 answers it: the README now has a [Scope section](https://github.com/LJPc-solutions/freescout-calendar-module#scope-calendar-invites) that lists what the module does with calendar invites and what it leaves out.

Every point you listed is in:

- **A reschedule replaces the original invite.** The update shows on a card below the mail, with the old time struck through next to the new one, and an agent applies it with one click.
- **A cancellation.** The same card offers "Cancel event". The event stays in the calendar, struck through.
- **Rescheduling or cancelling one occurrence of a recurring meeting.** The card names the occurrence, and applying it changes only that occurrence.
- **RSVP.** Yes, Maybe and No on the card send the answer back to the organizer as a reply in the conversation. The answer is for the whole event; answering a single occurrence is not supported.

Agents can also invite customers to an event they add from a conversation, and the answers are recorded by themselves. The [Invites](https://github.com/LJPc-solutions/freescout-calendar-module/wiki/Invites) page in the wiki explains it all.

Version 3.0 is available from FreeScout's Modules page and needs FreeScout 1.8.243 and PHP 8.2 or newer. Back up your database before you update.

I'm closing this issue. If something doesn't work the way you expect, please open a new one.
