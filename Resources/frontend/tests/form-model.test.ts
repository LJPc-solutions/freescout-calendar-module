import { describe, expect, it } from 'vitest'
import { isProxy, reactive } from 'vue'
import { copyForm, copyOfOccurrence, copyOfSeries, emptyForm, formFromEvent, formFromOccurrence, formFromRange, formFromSeries, ruleStartDate, toCreatePayload, toOccurrencePatch, toSeriesPatch, withAllDay, withCalendar, withoutCustomRule, withStart } from '../src/shared/form-model'
import { defaultRecurrence } from '../src/shared/recurrence'
import type { EventDetail, FieldDefinition } from '../src/shared/types'

const tz = 'Europe/Amsterdam'
const fields: FieldDefinition[] = [
  { id: 1001, name: 'Email', type: 'email', required: false, options: [] },
  { id: 1002, name: 'Paid', type: 'boolean', required: false, options: [] },
]
const event: EventDetail = {
  id: 7, calendar_id: 1, uid: 'u7', title: 'Standup', title_input: 'Standup', location: '', description: '',
  all_day: false, start: '2026-10-05T09:00:00+02:00', end: '2026-10-05T09:15:00+02:00',
  recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] }, recurrence_custom: false, is_recurring: true,
  field_values: { 1001: 'a@example.test' }, reminder_minutes: null, author: null, conversation: null, editable: true, series_writable: true, attendees: [], can_invite: false,
  timezone: 'Europe/Amsterdam',
}

describe('form model', () => {
  it('starts a new event one hour long with booleans off', () => {
    const state = emptyForm(1, { date: '2026-10-06', time: '23:30' }, tz, fields)
    expect([state.endDate, state.endTime]).toEqual(['2026-10-07', '00:30'])
    expect(state.fields).toEqual({ 1001: '', 1002: false })
  })

  it('turns an all-day selection into inclusive end dates', () => {
    const state = formFromRange(1, '2026-10-06', '2026-10-08', tz)
    expect([state.allDay, state.startDate, state.endDate]).toEqual([true, '2026-10-06', '2026-10-07'])
  })

  it('reads a timed selection in the agent zone', () => {
    const state = formFromRange(1, '2026-10-06T10:00:00+02:00', '2026-10-06T11:30:00+02:00', tz)
    expect([state.startTime, state.endTime]).toEqual(['10:00', '11:30'])
  })

  it('creates with exclusive all-day ends and the chosen calendar fields only', () => {
    const state = { ...formFromRange(1, '2026-10-06', '2026-10-07', tz, fields), title: 'Day off', fields: { 1001: 'x@example.test', 1002: true, 9: 'stale' } }
    expect(toCreatePayload(state, fields)).toEqual({
      calendar_id: 1, title: 'Day off', all_day: true, start: '2026-10-06', end: '2026-10-07', location: '', description: '',
      field_values: { 1001: 'x@example.test', 1002: true }, reminder_minutes: null,
    })
  })

  it('keeps the length when the start moves', () => {
    const state = withStart(emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), '2026-10-07', '15:00', tz)
    expect([state.endDate, state.endTime]).toEqual(['2026-10-07', '16:00'])
  })

  it('moves a monthly day along with the start date', () => {
    const state = { ...emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), title: 'Invoice run', recurrence: defaultRecurrence('MONTHLY', '2026-10-06') }
    const moved = withStart(state, '2026-10-14', '10:00', tz)
    expect(toCreatePayload(moved, []).recurrence).toEqual({ freq: 'MONTHLY', interval: 1, bymonthday: 14 })
  })

  it('moves weekly weekdays and a monthly weekday position along with the start date', () => {
    const weekly = { ...emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), recurrence: defaultRecurrence('WEEKLY', '2026-10-06') }
    expect(withStart(weekly, '2026-10-07', '10:00', tz).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['WE'] })
    const firstTuesday = { ...weekly, recurrence: { freq: 'MONTHLY' as const, interval: 1, byday: ['TU'], bysetpos: 1 } }
    expect(withStart(firstTuesday, '2026-10-15', '10:00', tz).recurrence).toEqual({ freq: 'MONTHLY', interval: 1, byday: ['TH'], bysetpos: 3 })
  })

  it('leaves the weekdays of a rule that never included the start\'s weekday alone', () => {
    // Another app's series: it starts on Sunday 4 October and repeats on Mondays and Wednesdays.
    const offset = { ...emptyForm(1, { date: '2026-10-04', time: '10:00' }, tz), recurrence: { freq: 'WEEKLY' as const, interval: 1, byday: ['MO', 'WE'] } }
    expect(withStart(offset, '2026-10-03', '10:00', tz).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'] })
    // A rule that includes the start's weekday still moves along with it.
    const fromSunday = { ...offset, recurrence: { freq: 'WEEKLY' as const, interval: 1, byday: ['SU', 'WE'] } }
    expect(withStart(fromSunday, '2026-10-05', '10:00', tz).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['MO', 'TH'] })
  })

  it('reads the rule\'s weekdays where the series lives when the agent is in another zone', () => {
    const la = 'America/Los_Angeles'
    // Monday 08:00 in Amsterdam, edited by a Los Angeles agent: the form shows Sunday 23:00.
    const monday: EventDetail = { ...event, start: '2026-10-11T23:00:00-07:00', end: '2026-10-12T00:00:00-07:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } }
    const series = formFromSeries(monday, la, fields)
    expect([series.startDate, series.startTime, series.timezone, ruleStartDate(series, la)]).toEqual(['2026-10-11', '23:00', 'Europe/Amsterdam', '2026-10-12'])
    // A day later is Tuesday 08:00 in Amsterdam; 14:00 that Sunday is still Sunday there, at 23:00.
    expect(withStart(series, '2026-10-12', '23:00', la).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['TU'] })
    expect(withStart(series, '2026-10-11', '14:00', la).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['SU'] })
    // Without a series zone the agent's own zone counts.
    expect(ruleStartDate(formFromEvent(monday, la, fields), la)).toBe('2026-10-11')
  })

  it('keeps the rule when only the start time changes, and leaves a custom rule alone', () => {
    const weekly = { ...emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), recurrence: defaultRecurrence('WEEKLY', '2026-10-06') }
    expect(withStart(weekly, '2026-10-06', '15:00', tz).recurrence).toBe(weekly.recurrence)
    const custom = { ...weekly, recurrence: null, recurrenceCustom: true }
    expect(withStart(custom, '2026-10-08', '10:00', tz).recurrence).toBeNull()
  })

  it('switches between all-day and timed sensibly', () => {
    const timed = emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz)
    const allDay = withAllDay(timed, true, tz)
    expect([allDay.allDay, allDay.startDate, allDay.endDate]).toEqual([true, '2026-10-06', '2026-10-06'])
    expect(withAllDay(allDay, false, tz).startTime).toBe('10:00')
  })

  it('moves the rule to the agent\'s date when a series from another zone becomes all-day', () => {
    const ny = 'America/New_York'
    // Monday 05:00 in Amsterdam: the New York agent sees Sunday 23:00, and an all-day event is on that Sunday.
    const monday: EventDetail = { ...event, start: '2026-10-11T23:00:00-04:00', end: '2026-10-12T00:00:00-04:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } }
    const series = formFromSeries(monday, ny, fields)
    const allDay = withAllDay(series, true, ny)
    expect([allDay.startDate, allDay.endDate, allDay.recurrence]).toEqual(['2026-10-11', '2026-10-12', { freq: 'WEEKLY', interval: 1, byday: ['SU'] }])
    expect(ruleStartDate(allDay, ny)).toBe('2026-10-11')
    // Back to timed: the rule is read where the series lives again, so a save that only changes the title keeps it.
    const timed = withAllDay(allDay, false, ny)
    expect([timed.startDate, timed.startTime, timed.timezone, timed.recurrence]).toEqual(['2026-10-11', '23:00', 'Europe/Amsterdam', { freq: 'WEEKLY', interval: 1, byday: ['MO'] }])
    expect(toSeriesPatch(series, { ...timed, title: 'Planning' }, fields, true)).toEqual({ scope: 'series', title: 'Planning', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } })
    // A rule the form can't show stays as it is.
    const custom = withAllDay({ ...series, recurrence: null, recurrenceCustom: true }, true, ny)
    expect([custom.recurrence, custom.recurrenceCustom]).toEqual([null, true])
  })

  it('sends only what changed for a series, plus the rule of a repeating event', () => {
    const original = formFromEvent(event, tz, fields)
    expect(toSeriesPatch(original, { ...original, title: 'Daily standup' }, fields, true)).toEqual({
      scope: 'series', title: 'Daily standup', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] },
    })
  })

  it('sends times, fields and the calendar when they change', () => {
    const original = formFromEvent(event, tz, fields)
    const changed = { ...original, calendarId: 2, startTime: '10:00', endTime: '10:15' }
    expect(toSeriesPatch(original, changed, fields, true)).toMatchObject({
      all_day: false, start: '2026-10-05T10:00', end: '2026-10-05T10:15', calendar_id: 2, field_values: { 1001: 'a@example.test', 1002: false },
    })
  })

  it('never sends a custom rule this form cannot show', () => {
    const original = { ...formFromEvent(event, tz, fields), recurrence: null, recurrenceCustom: true }
    expect(toSeriesPatch(original, { ...original, title: 'x' }, fields, true)).toEqual({ scope: 'series', title: 'x' })
  })

  it('re-initializes the field values for another calendar', () => {
    const state = { ...formFromEvent(event, tz, fields), fields: { 1001: 'a@example.test', 1002: true } }
    const other: FieldDefinition[] = [
      { id: 2001, name: 'Billable', type: 'boolean', required: false, options: [] },
      { id: 2002, name: 'Tags', type: 'multiselect', required: false, options: ['a'] },
      { id: 1001, name: 'Email', type: 'email', required: false, options: [] },
    ]
    const moved = withCalendar(state, 2, other)
    expect(moved.calendarId).toBe(2)
    expect(moved.fields).toEqual({ 2001: false, 2002: [], 1001: 'a@example.test' })
  })

  it('copies a form state into plain objects, also from a Vue proxy', () => {
    const tags: FieldDefinition = { id: 9, name: 'Tags', type: 'multiselect', required: false, options: ['x', 'y'] }
    const original = formFromEvent({ ...event, field_values: { 1001: 'a@example.test', 9: ['x', 'y'] } }, tz, [...fields, tags])
    const copy = copyForm(reactive(original))
    expect(copy).toEqual(original)
    expect(copy.fields[9]).toEqual(['x', 'y'])
    expect([isProxy(copy), isProxy(copy.recurrence), isProxy(copy.fields), isProxy(copy.fields[9])]).toEqual([false, false, false, false])
    expect(copy.recurrence).not.toBe(original.recurrence)
    expect(() => structuredClone(copy)).not.toThrow()
  })

  it('keeps series keys out of an occurrence edit', () => {
    const original = formFromEvent(event, tz, fields)
    const patch = toOccurrencePatch(original, { ...original, title: 'Moved', startTime: '11:00', endTime: '11:15', reminder: 5, calendarId: 2 }, '20261005T070000Z')
    expect(patch).toEqual({ scope: 'occurrence', recurrence_id: '20261005T070000Z', title: 'Moved', all_day: false, start: '2026-10-05T11:00', end: '2026-10-05T11:15' })
  })

  it('starts an occurrence form from the occurrence\'s own title, unless the calendar builds titles from a template', () => {
    // The details of a renamed occurrence: its own title, and the series' typed text as title_input.
    const renamed: EventDetail = { ...event, title: 'Standup (room B)', title_input: 'Standup' }
    const state = formFromOccurrence(renamed, tz, fields, false)
    expect(state.title).toBe('Standup (room B)')
    // Left untouched, the title is not sent, so a time-only edit keeps it.
    expect(toOccurrencePatch(state, { ...state, startTime: '10:00', endTime: '10:15' }, '20261005T070000Z')).not.toHaveProperty('title')

    const templated: EventDetail = { ...event, title: 'Acme (Visit)', title_input: 'Acme' }
    expect(formFromOccurrence(templated, tz, fields, true).title).toBe('Acme')
  })
})

describe('duplicating an occurrence', () => {
  // The details of the weekly Standup's occurrence of Monday 19 October, moved to Wednesday 10:00-10:30 and renamed.
  const moved: EventDetail = { ...event, title: 'Standup (room B)', start: '2026-10-21T10:00:00+02:00', end: '2026-10-21T10:30:00+02:00' }

  it('copies only the occurrence as a single event on its own date and time', () => {
    const state = copyOfOccurrence(moved, tz, fields, false)
    expect(state).toMatchObject({
      title: 'Standup (room B)', allDay: false, startDate: '2026-10-21', startTime: '10:00', endDate: '2026-10-21', endTime: '10:30',
      recurrence: null, recurrenceCustom: false, fields: { 1001: 'a@example.test', 1002: false },
    })
    expect(toCreatePayload(state, fields)).not.toHaveProperty('recurrence')
  })

  it('copies the series from a moved occurrence\'s original slot, with the series\' own details, length and rule', () => {
    // The moved occurrence's details show Wednesday 10:00; its recurrence id still names its slot, Monday 19 October 09:00.
    const state = copyOfSeries(event, '20261019T070000Z', tz, fields)
    expect(state).toMatchObject({
      title: 'Standup', startDate: '2026-10-19', startTime: '09:00', endDate: '2026-10-19', endTime: '09:15',
      recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] }, fields: { 1001: 'a@example.test', 1002: false },
    })
    expect(toCreatePayload(state, fields)).toMatchObject({ start: '2026-10-19T09:00', end: '2026-10-19T09:15', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } })
  })

  it('keeps every weekday of the rule when the copy starts on a later weekday of the week', () => {
    // Mondays and Wednesdays at 09:00; Wednesday 28 October is after the clock change, so its slot is 08:00 UTC.
    const mondayWednesday: EventDetail = { ...event, recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'] } }
    expect(copyOfSeries(mondayWednesday, '20261028T080000Z', tz, fields)).toMatchObject({
      startDate: '2026-10-28', startTime: '09:00', endDate: '2026-10-28', endTime: '09:15',
      recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'] },
    })
  })

  it('copies an all-day occurrence with inclusive end dates, as a single day or as a series', () => {
    const allDay: EventDetail = { ...event, all_day: true, start: '2026-10-05', end: '2026-10-07' }
    const occurrence: EventDetail = { ...allDay, start: '2026-10-12', end: '2026-10-14' }

    expect(copyOfOccurrence(occurrence, tz, fields, false)).toMatchObject({ allDay: true, startDate: '2026-10-12', endDate: '2026-10-13', recurrence: null })
    const series = copyOfSeries(allDay, '20261012', tz, fields)
    expect(series).toMatchObject({ allDay: true, startDate: '2026-10-12', endDate: '2026-10-13', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } })
    expect(toCreatePayload(series, fields)).toMatchObject({ all_day: true, start: '2026-10-12', end: '2026-10-14' })
  })

  it('keeps a monthly rule on a weekday position as it is', () => {
    // The second Tuesday of every month from 13 October, copied from the second Tuesday of December.
    const monthly: EventDetail = {
      ...event, start: '2026-10-13T09:00:00+02:00', end: '2026-10-13T09:15:00+02:00',
      recurrence: { freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: 2 },
    }
    expect(copyOfSeries(monthly, '20261208T080000Z', tz, fields)).toMatchObject({
      startDate: '2026-12-08', startTime: '09:00', endTime: '09:15', recurrence: { freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: 2 },
    })
  })

  it('keeps the interval and a COUNT or UNTIL end as they are', () => {
    const counted: EventDetail = { ...event, recurrence: { freq: 'WEEKLY', interval: 2, byday: ['MO', 'WE'], count: 5 } }
    expect(copyOfSeries(counted, '20261021T070000Z', tz, fields).recurrence).toEqual({ freq: 'WEEKLY', interval: 2, byday: ['MO', 'WE'], count: 5 })
    const until: EventDetail = { ...event, recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'], until: '2026-12-28' } }
    expect(copyOfSeries(until, '20261021T070000Z', tz, fields).recurrence).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'], until: '2026-12-28' })
  })

  it('starts a new event from a series with a custom rule without repetition, so the form shows what is saved', () => {
    const custom = formFromEvent({ ...event, recurrence: null, recurrence_custom: true }, tz, fields)
    const copy = withoutCustomRule(custom)
    expect(copy).toMatchObject({ recurrence: null, recurrenceCustom: false, startDate: '2026-10-05', startTime: '09:00', title: 'Standup' })
    expect(toCreatePayload(copy, fields)).not.toHaveProperty('recurrence')
    // A rule the form can show stays as it is.
    const weekly = formFromEvent(event, tz, fields)
    expect(withoutCustomRule(weekly)).toBe(weekly)
  })

  it('copies a series in its own zone, so a Los Angeles agent\'s copy of a Monday 08:00 Amsterdam series stays on Monday', () => {
    const la = 'America/Los_Angeles'
    // The master as the details show it to that agent: Monday 12 October 08:00 in Amsterdam is Sunday 23:00 in Los Angeles.
    const monday: EventDetail = { ...event, start: '2026-10-11T23:00:00-07:00', end: '2026-10-12T00:00:00-07:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } }
    const state = copyOfSeries(monday, '20261019T060000Z', la, fields)
    expect(state).toMatchObject({ startDate: '2026-10-18', startTime: '23:00', endDate: '2026-10-19', endTime: '00:00', timezone: 'Europe/Amsterdam' })
    expect(toCreatePayload(state, fields)).toMatchObject({
      start: '2026-10-18T23:00', end: '2026-10-19T00:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] }, timezone: 'Europe/Amsterdam',
    })
  })

  it('sends the series zone only with a rule, and every other form starts without one', () => {
    const copy = copyOfSeries(event, '20261019T070000Z', tz, fields)
    expect(toCreatePayload({ ...copy, recurrence: null }, fields)).not.toHaveProperty('timezone')
    const weekly = { ...emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz), recurrence: defaultRecurrence('WEEKLY', '2026-10-06') }
    expect(toCreatePayload(weekly, fields)).not.toHaveProperty('timezone')
    expect([
      emptyForm(1, { date: '2026-10-06', time: '10:00' }, tz).timezone,
      formFromRange(1, '2026-10-06T10:00:00+02:00', '2026-10-06T11:00:00+02:00', tz).timezone,
      formFromEvent(event, tz, fields).timezone,
      formFromOccurrence(event, tz, fields, false).timezone,
      copyOfOccurrence(event, tz, fields, false).timezone,
    ]).toEqual([null, null, null, null, null])
    // A series without a zone of its own (floating or all-day) is copied in the agent's zone.
    expect(copyOfSeries({ ...event, timezone: null }, '20261019T070000Z', tz, fields).timezone).toBeNull()
  })

  it('refuses a recurrence id that does not fit the series', () => {
    expect(() => copyOfSeries(event, '20261019', tz, fields)).toThrow('does not fit a timed series')
    expect(() => copyOfSeries({ ...event, all_day: true, start: '2026-10-05', end: '2026-10-06' }, '20261019T070000Z', tz, fields)).toThrow('does not fit an all-day series')
  })
})
