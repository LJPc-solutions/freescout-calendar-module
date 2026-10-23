import { followStart } from './recurrence'
import { addDays, addMinutes, dateIn, daysBetween, fromParts, isDate, minutesBetween, originalSlot, toParts } from './time'
import type { EventDetail, FieldDefinition, FieldValue, Recurrence } from './types'

/** What the event form edits. All-day end dates are inclusive here; the API's are exclusive. */
export interface FormState {
  calendarId: number | null
  title: string
  allDay: boolean
  startDate: string
  startTime: string
  endDate: string
  endTime: string
  location: string
  description: string
  recurrence: Recurrence | null
  recurrenceCustom: boolean
  reminder: number | null
  fields: Record<string, FieldValue>
  /**
   * The zone the repeat rule is read in (its weekdays, month day and end date): the series' own zone when the form
   * starts from a whole series, else null for the agent's zone. A new series is written in it (toCreatePayload).
   */
  timezone: string | null
}

export type Scope = 'series' | 'occurrence'

const DEFAULT_TIME = '09:00'

/** Values a new event starts with: booleans off, everything else empty. */
export function initialFieldValues(fields: FieldDefinition[], prefill: Partial<Record<string, FieldValue>> = {}): Record<string, FieldValue> {
  const values: Record<string, FieldValue> = {}
  for (const field of fields) {
    const id = String(field.id)
    const given = prefill[id]
    if (given !== undefined) {
      values[id] = given
    } else if (field.type === 'boolean') {
      values[id] = false
    } else if (field.type === 'multiselect') {
      values[id] = []
    } else {
      values[id] = ''
    }
  }

  return values
}

export function emptyForm(calendarId: number | null, start: { date: string; time: string }, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const end = addMinutes(start.date, start.time, 60, timeZone)

  return {
    calendarId,
    title: '',
    allDay: false,
    startDate: start.date,
    startTime: start.time,
    endDate: end.date,
    endTime: end.time,
    location: '',
    description: '',
    recurrence: null,
    recurrenceCustom: false,
    reminder: null,
    fields: initialFieldValues(fields),
    timezone: null,
  }
}

/** A selection on the calendar (FullCalendar start/end strings: dates with an exclusive end, or ISO with offset). */
export function formFromRange(calendarId: number | null, start: string, end: string, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const state = emptyForm(calendarId, { date: start.slice(0, 10), time: DEFAULT_TIME }, timeZone, fields)
  if (isDate(start)) {
    return { ...state, allDay: true, startDate: start, startTime: '', endDate: addDays(end, -1), endTime: '' }
  }
  const from = toParts(start, timeZone)
  const to = toParts(end, timeZone)

  return { ...state, startDate: from.date, startTime: from.time, endDate: to.date, endTime: to.time }
}

export function formFromEvent(event: EventDetail, timeZone: string, fields: FieldDefinition[] = []): FormState {
  const start = toParts(event.start, timeZone)
  const end = toParts(event.end, timeZone)

  return {
    calendarId: event.calendar_id,
    title: event.title_input ?? event.title,
    allDay: event.all_day,
    startDate: start.date,
    startTime: start.time,
    endDate: event.all_day ? addDays(end.date, -1) : end.date,
    endTime: end.time,
    location: event.location,
    description: event.description,
    recurrence: event.recurrence,
    recurrenceCustom: event.recurrence_custom,
    reminder: event.reminder_minutes,
    fields: initialFieldValues(fields, event.field_values),
    timezone: null,
  }
}

/**
 * The form for a whole series, from its master. The agent sees the times in their own zone, but the rule is read where
 * the series lives, so its weekdays keep matching its start for an agent in another zone.
 */
export function formFromSeries(master: EventDetail, timeZone: string, fields: FieldDefinition[] = []): FormState {
  return { ...formFromEvent(master, timeZone, fields), timezone: master.timezone }
}

/**
 * The form for one occurrence. It starts from the occurrence's own title, which an edited occurrence may have. With a
 * title template the title is built, and an occurrence keeps no typed text of its own, so the series' text stays.
 */
export function formFromOccurrence(event: EventDetail, timeZone: string, fields: FieldDefinition[], hasTitleTemplate: boolean): FormState {
  const state = formFromEvent(event, timeZone, fields)

  return hasTitleTemplate ? state : { ...state, title: event.title }
}

/**
 * "Duplicate" on one occurrence of a repeating event, copying only that occurrence: a single event on its date and
 * time, from its own details (the title as formFromOccurrence picks it), without repetition.
 */
export function copyOfOccurrence(occurrence: EventDetail, timeZone: string, fields: FieldDefinition[], hasTitleTemplate: boolean): FormState {
  return { ...formFromOccurrence(occurrence, timeZone, fields, hasTitleTemplate), recurrence: null, recurrenceCustom: false }
}

/**
 * "Duplicate" on one occurrence of a repeating event, copying the whole series: the same repetition as a new series
 * with the master's details and length, starting at the occurrence's original slot (where the rule put it, also when
 * the occurrence itself was moved). The rule stays exactly as it is, because that slot always fits it, and the copy is
 * written in the series' zone.
 */
export function copyOfSeries(master: EventDetail, recurrenceId: string, timeZone: string, fields: FieldDefinition[]): FormState {
  const state = formFromSeries(master, timeZone, fields)
  const slot = originalSlot(recurrenceId, state.allDay, timeZone)

  return { ...withStart(state, slot.date, slot.time, timeZone), recurrence: state.recurrence }
}

/**
 * A new event can't repeat by a rule this form can't show (a create payload never sends one), so a copy of an event
 * with a custom rule starts without repetition, and the form says so.
 */
export function withoutCustomRule(state: FormState): FormState {
  return state.recurrenceCustom ? { ...state, recurrence: null, recurrenceCustom: false } : state
}

/** A plain copy of a form state. FormState is JSON-safe, and unlike structuredClone this also copies Vue proxies. */
export function copyForm(state: FormState): FormState {
  return JSON.parse(JSON.stringify(state)) as FormState
}

/** Another calendar: its own custom fields, keeping the values of fields both calendars share. */
export function withCalendar(state: FormState, calendarId: number | null, fields: FieldDefinition[]): FormState {
  return { ...state, calendarId, fields: initialFieldValues(fields, state.fields) }
}

/**
 * Switching all-day on or off keeps the dates and picks sensible times. An all-day rule is read on the dates, which are
 * the same in every zone, and a timed one where the series lives (state.timezone, kept for the way back), so the rule
 * follows the start date from where it was read to where it is read now: a series from another zone may be on another
 * day there.
 */
export function withAllDay(state: FormState, allDay: boolean, timeZone: string): FormState {
  if (allDay === state.allDay) {
    return state
  }
  let next: FormState
  if (allDay) {
    next = { ...state, allDay, endDate: state.endDate < state.startDate ? state.startDate : state.endDate }
  } else {
    const startTime = state.startTime || DEFAULT_TIME
    const end = addMinutes(state.startDate, startTime, 60, timeZone)
    const endDate = state.endDate > state.startDate ? state.endDate : end.date
    next = { ...state, allDay, startTime, endDate, endTime: state.endTime || end.time }
  }
  const recurrence = state.recurrence && !state.recurrenceCustom
    ? followStart(state.recurrence, ruleStartDate(state, timeZone), ruleStartDate(next, timeZone))
    : state.recurrence

  return { ...next, recurrence }
}

/** The start date where the rule is read: in the series' zone (state.timezone) or the agent's. All-day dates are the same everywhere. */
export function ruleStartDate(state: FormState, timeZone: string): string {
  return ruleDate(state, state.startDate, state.startTime, timeZone)
}

function ruleDate(state: FormState, date: string, time: string, timeZone: string): string {
  return state.allDay || !time || !state.timezone ? date : dateIn(date, time, timeZone, state.timezone)
}

/**
 * Moving the start keeps the event's length, like every calendar app does. The repeat rule follows the new start
 * date where the rule is read (weekdays shift along, a monthly day or weekday position follows), so it never disagrees
 * with DTSTART. A weekly rule whose weekdays never included the start's was offset on purpose (another app wrote it),
 * so its weekdays stay.
 */
export function withStart(state: FormState, date: string, time: string, timeZone: string): FormState {
  const recurrence = state.recurrence && !state.recurrenceCustom
    ? followStart(state.recurrence, ruleStartDate(state, timeZone), ruleDate(state, date, time, timeZone))
    : state.recurrence
  if (state.allDay) {
    const length = Math.max(0, daysBetween(state.startDate, state.endDate))

    return { ...state, startDate: date, endDate: addDays(date, length), recurrence }
  }
  const minutes = Math.max(0, minutesBetween({ date: state.startDate, time: state.startTime }, { date: state.endDate, time: state.endTime }, timeZone))
  const end = addMinutes(date, time, minutes, timeZone)

  return { ...state, startDate: date, startTime: time, endDate: end.date, endTime: end.time, recurrence }
}

export function apiTimes(state: FormState): { all_day: boolean; start: string; end: string } {
  if (state.allDay) {
    return { all_day: true, start: state.startDate, end: addDays(state.endDate, 1) }
  }

  return { all_day: false, start: fromParts(state.startDate, state.startTime), end: fromParts(state.endDate, state.endTime) }
}

/** Only the fields of the chosen calendar are sent. */
function fieldValuesFor(state: FormState, fields: FieldDefinition[]): Record<string, FieldValue> {
  const values: Record<string, FieldValue> = {}
  for (const field of fields) {
    const id = String(field.id)
    if (state.fields[id] !== undefined) {
      values[id] = state.fields[id] as FieldValue
    }
  }

  return values
}

export function toCreatePayload(state: FormState, fields: FieldDefinition[]): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    calendar_id: state.calendarId,
    title: state.title,
    ...apiTimes(state),
    location: state.location,
    description: state.description,
    field_values: fieldValuesFor(state, fields),
    reminder_minutes: state.reminder,
  }
  if (state.recurrence) {
    payload.recurrence = state.recurrence
    if (state.timezone) {
      payload.timezone = state.timezone
    }
  }

  return payload
}

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

function timesChanged(original: FormState, state: FormState): boolean {
  return !same(apiTimes(original), apiTimes(state))
}

/**
 * A whole-series save sends only what changed, so an unchanged series keeps its exceptions. The repeat rule is
 * always sent for a repeating event (unless it is a custom rule this form can't show), so an all-day switch
 * rewrites its end date in the right form.
 */
export function toSeriesPatch(original: FormState, state: FormState, fields: FieldDefinition[], wasRecurring: boolean): Record<string, unknown> {
  const payload: Record<string, unknown> = { scope: 'series' }
  for (const [key, name] of [['title', 'title'], ['location', 'location'], ['description', 'description']] as const) {
    if (state[key] !== original[key]) {
      payload[name] = state[key]
    }
  }
  if (timesChanged(original, state)) {
    Object.assign(payload, apiTimes(state))
  }
  if (!state.recurrenceCustom && (wasRecurring || state.recurrence !== null)) {
    payload.recurrence = state.recurrence
  }
  const moved = state.calendarId !== original.calendarId
  if (moved) {
    payload.calendar_id = state.calendarId
  }
  if (moved || !same(fieldValuesFor(state, fields), fieldValuesFor(original, fields))) {
    payload.field_values = fieldValuesFor(state, fields)
  }
  if (state.reminder !== original.reminder) {
    payload.reminder_minutes = state.reminder
  }

  return payload
}

/** One occurrence: never the series' calendar, rule, fields or reminder (the server rejects those). */
export function toOccurrencePatch(original: FormState, state: FormState, recurrenceId: string): Record<string, unknown> {
  const payload: Record<string, unknown> = { scope: 'occurrence', recurrence_id: recurrenceId }
  for (const key of ['title', 'location', 'description'] as const) {
    if (state[key] !== original[key]) {
      payload[key] = state[key]
    }
  }
  if (timesChanged(original, state)) {
    Object.assign(payload, apiTimes(state))
  }

  return payload
}
