import type { EventInput } from '@fullcalendar/vue3'
import type { Scope } from '../shared/form-model'
import { followStart } from '../shared/recurrence'
import { addDays, dateOf, endDateOf, shift, type Delta } from '../shared/time'
import type { CalendarInfo, EventDetail, Occurrence } from '../shared/types'

/** extendedProps of every event on the calendar. */
export interface EventProps {
  eventId: number
  calendarId: number
  recurrenceId: string | null
  status: string
  isRecurring: boolean
  priority: number
}

export function toEventInput(occurrence: Occurrence, calendar: CalendarInfo | undefined): EventInput {
  const props: EventProps = {
    eventId: occurrence.event_id,
    calendarId: occurrence.calendar_id,
    recurrenceId: occurrence.recurrence_id,
    status: occurrence.status,
    isRecurring: occurrence.is_recurring,
    priority: calendar?.display_priority ?? 100,
  }

  return {
    id: occurrence.id,
    title: occurrence.title,
    start: occurrence.start,
    end: occurrence.end,
    allDay: occurrence.all_day,
    color: calendar?.color || '#3498db',
    editable: occurrence.editable,
    extendedProps: props,
  }
}

/** What FullCalendar 7.1 hands to an eventOrder comparator: the event's fields with extendedProps spread in. */
export interface OrderSubject {
  allDay: boolean | number
  start: number
  duration: number
  priority?: number
}

const DAY_MS = 86_400_000

function isRow(subject: OrderSubject): boolean {
  return Boolean(subject.allDay) || subject.duration >= DAY_MS
}

/**
 * The 2.7.x order: all-day and multi-day rows first, sorted by the calendar's display priority and then start;
 * timed events stay chronological and priority only breaks ties.
 */
export function compareEvents(a: OrderSubject, b: OrderSubject): number {
  const rowA = isRow(a)
  const rowB = isRow(b)
  if (rowA !== rowB) {
    return rowA ? -1 : 1
  }
  const byPriority = (a.priority ?? 100) - (b.priority ?? 100)
  const byStart = a.start - b.start

  return rowA ? byPriority || byStart : byStart || byPriority
}

/**
 * Whether an event (API start and exclusive end) shows anywhere in a visible range of dates (exclusive end), read in
 * the agent's zone. Opening an event only moves the calendar when this is false.
 */
export function overlapsRange(event: { start: string; end: string }, range: { start: string; end: string }, timeZone: string): boolean {
  const first = dateOf(event.start, timeZone)
  const end = endDateOf(event.end, timeZone)
  // A zero-length event still shows on its start date.
  const afterLast = end > first ? end : addDays(first, 1)

  return first < range.end && afterLast > range.start
}

/** The new times of an event dragged or resized on the grid, as FullCalendar gives them. */
export interface DraggedTimes {
  all_day: boolean
  start: string
  end: string
}

/**
 * A single event or one occurrence dragged or resized on the grid: only its new times. An occurrence never carries
 * the series' calendar, rule, fields or reminder (the server rejects those).
 */
export function dragPatch(scope: Scope, recurrenceId: string | null, times: DraggedTimes): Record<string, unknown> {
  return scope === 'occurrence' ? { scope, recurrence_id: recurrenceId, ...times } : { scope, ...times }
}

/**
 * A whole series dragged or resized on the grid: its own first start and end move by FullCalendar's deltas, keeping
 * the wall clock in the agent's zone, and its weekdays or month day move along as the series' own zone sees the dates
 * (followStart, like withStart in the form). A custom rule is never sent.
 */
export function seriesDragPatch(master: EventDetail, startDelta: Delta, endDelta: Delta, timeZone: string): Record<string, unknown> {
  const start = shift(master.start, startDelta, timeZone)
  const patch: Record<string, unknown> = { scope: 'series', all_day: master.all_day, start, end: shift(master.end, endDelta, timeZone) }
  if (master.recurrence && !master.recurrence_custom) {
    const ruleZone = master.timezone ?? timeZone
    patch.recurrence = followStart(master.recurrence, dateOf(master.start, ruleZone), dateOf(start, ruleZone))
  }

  return patch
}
