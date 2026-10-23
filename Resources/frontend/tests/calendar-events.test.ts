import { afterEach, describe, expect, it, vi } from 'vitest'
import { compareEvents, dragPatch, overlapsRange, seriesDragPatch, toEventInput } from '../src/calendar/events'
import { localeCode } from '../src/calendar/locales'
import { linkRef, permalink, refFromHash } from '../src/calendar/permalink'
import { isIdList, isView } from '../src/calendar/preferences'
import { readStored } from '../src/shared/storage'
import type { CalendarInfo, EventDetail } from '../src/shared/types'

const calendar = { id: 1, color: '#e74c3c', display_priority: 3 } as CalendarInfo
const tz = 'Europe/Amsterdam'
const master: EventDetail = {
  id: 5, calendar_id: 1, uid: 'u5', title: 'Standup', title_input: 'Standup', location: 'Room 1', description: '',
  all_day: false, start: '2026-10-12T09:00:00+02:00', end: '2026-10-12T10:00:00+02:00',
  recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'], count: 5 }, recurrence_custom: false, is_recurring: true,
  field_values: { 1001: 'x' }, reminder_minutes: 15, author: null, conversation: null, editable: true, series_writable: true, attendees: [], can_invite: false,
  timezone: 'Europe/Amsterdam',
}
const days = (count: number) => ({ years: 0, months: 0, days: count, milliseconds: 0 })
const hours = (count: number) => ({ years: 0, months: 0, days: 0, milliseconds: count * 3_600_000 })

describe('calendar events', () => {
  it('maps an occurrence to a FullCalendar event with the calendar color and priority', () => {
    const input = toEventInput({ id: '7:1', event_id: 7, calendar_id: 1, recurrence_id: '1', title: 'A', start: '2026-10-06', end: '2026-10-07', all_day: true, status: 'cancelled', is_recurring: true, editable: false }, calendar)
    expect(input).toMatchObject({ id: '7:1', allDay: true, color: '#e74c3c', editable: false, extendedProps: { eventId: 7, recurrenceId: '1', status: 'cancelled', priority: 3 } })
  })

  it('puts all-day and multi-day rows first, by priority and then start', () => {
    const allDayLate = { allDay: true, start: 2, duration: 86_400_000, priority: 1 }
    const allDayEarly = { allDay: true, start: 1, duration: 86_400_000, priority: 5 }
    const timed = { allDay: false, start: 0, duration: 3_600_000, priority: 0 }
    expect([timed, allDayEarly, allDayLate].sort(compareEvents)).toEqual([allDayLate, allDayEarly, timed])
  })

  it('keeps timed events chronological and lets priority break ties', () => {
    const a = { allDay: false, start: 10, duration: 1, priority: 9 }
    const b = { allDay: false, start: 10, duration: 1, priority: 1 }
    const c = { allDay: false, start: 5, duration: 1, priority: 50 }
    expect([a, b, c].sort(compareEvents)).toEqual([c, b, a])
  })

  it('sees an event as shown when any part of it falls in the visible range', () => {
    const week = { start: '2026-10-05', end: '2026-10-12' }
    expect(overlapsRange({ start: '2026-09-28', end: '2026-10-10' }, week, tz)).toBe(true)
    expect(overlapsRange({ start: '2026-10-11', end: '2026-10-14' }, week, tz)).toBe(true)
    expect(overlapsRange({ start: '2026-10-04T23:00:00+02:00', end: '2026-10-05T01:00:00+02:00' }, week, tz)).toBe(true)
    expect(overlapsRange({ start: '2026-10-05T00:00:00+02:00', end: '2026-10-05T00:00:00+02:00' }, week, tz)).toBe(true)
  })

  it('sees an event as off screen when it ends before or starts after the visible range', () => {
    const week = { start: '2026-10-05', end: '2026-10-12' }
    expect(overlapsRange({ start: '2026-10-20', end: '2026-10-21' }, week, tz)).toBe(false)
    expect(overlapsRange({ start: '2026-09-28', end: '2026-10-05' }, week, tz)).toBe(false)
    expect(overlapsRange({ start: '2026-10-04T22:00:00+02:00', end: '2026-10-05T00:00:00+02:00' }, week, tz)).toBe(false)
    expect(overlapsRange({ start: '2026-10-12T09:00:00+02:00', end: '2026-10-12T10:00:00+02:00' }, week, tz)).toBe(false)
  })

  it('reads the visible range in the agent zone, not in UTC', () => {
    const week = { start: '2026-10-05', end: '2026-10-12' }
    const lateSunday = { start: '2026-10-11T22:30:00Z', end: '2026-10-11T23:30:00Z' }
    expect(overlapsRange(lateSunday, week, 'Europe/Amsterdam')).toBe(false)
    expect(overlapsRange(lateSunday, week, 'America/New_York')).toBe(true)
  })

  it('maps FreeScout locales to FullCalendar locales', () => {
    expect(localeCode('pt-BR')).toBe('pt-br')
    expect(localeCode('pt-PT')).toBe('pt')
    expect(localeCode('no')).toBe('nb')
    expect(localeCode('kz')).toBe('kk')
    expect(localeCode('de')).toBe('de')
    expect(localeCode('en')).toBeNull()
  })

  it('reads permalinks from the hash', () => {
    const event = (reference: string) => ({ reference, recurrenceId: null })
    expect(refFromHash('#e12')).toEqual(event('e12'))
    expect(refFromHash('#300')).toEqual(event('300'))
    expect(refFromHash('#abc%40example.org')).toEqual(event('abc@example.org'))
    expect(refFromHash('')).toBeNull()
    expect(refFromHash('#%E0%A4%A')).toBeNull()
  })

  it('reads occurrence permalinks and leaves every other ref with an "@" to the server', () => {
    expect(refFromHash('#e12@20261014T080000Z')).toEqual({ reference: 'e12', recurrenceId: '20261014T080000Z' })
    expect(refFromHash('#e12@20261014')).toEqual({ reference: 'e12', recurrenceId: '20261014' })
    expect(refFromHash('#e12%4020261014T080000Z')).toEqual({ reference: 'e12', recurrenceId: '20261014T080000Z' })
    // A UID may look like an occurrence link; only "e<digits>@<key>" is one.
    expect(refFromHash('#20261014T080000Z@example.org')).toEqual({ reference: '20261014T080000Z@example.org', recurrenceId: null })
    expect(refFromHash('#e12@example.org')).toEqual({ reference: 'e12@example.org', recurrenceId: null })
    // Malformed keys are no occurrence: the whole ref goes to the server, which answers that it doesn't exist.
    for (const malformed of ['e12@2026101', 'e12@20261014T0800Z', 'e12@20261014T080000', 'e12@20261014T080000Z@x', 'ex@20261014', 'e12@']) {
      expect(refFromHash(`#${malformed}`)).toEqual({ reference: malformed, recurrenceId: null })
    }
  })

  it('writes permalinks to an event or to one occurrence', () => {
    const location = { origin: 'https://help.example.test', pathname: '/calendar', search: '' }
    expect(permalink(12, null, location)).toBe('https://help.example.test/calendar#e12')
    expect(permalink(12, '20261014T080000Z', location)).toBe('https://help.example.test/calendar#e12@20261014T080000Z')
    expect(linkRef(12, '20261014')).toBe('e12@20261014')
    expect(refFromHash(`#${linkRef(12, '20261014T080000Z')}`)).toEqual({ reference: 'e12', recurrenceId: '20261014T080000Z' })
  })
})

describe('drag and resize', () => {
  const times = { all_day: false, start: '2026-10-14T11:00:00+02:00', end: '2026-10-14T12:00:00+02:00' }

  it('moves one occurrence with its new times only, never the series keys', () => {
    const patch = dragPatch('occurrence', '20261014T070000Z', times)
    expect(patch).toEqual({ scope: 'occurrence', recurrence_id: '20261014T070000Z', ...times })
    for (const key of ['calendar_id', 'recurrence', 'field_values', 'reminder_minutes']) {
      expect(patch).not.toHaveProperty(key)
    }
  })

  it('moves a single event with its new times only', () => {
    expect(dragPatch('series', null, times)).toEqual({ scope: 'series', ...times })
  })

  it('moves a whole series from its own first start and shifts its weekdays along', () => {
    expect(seriesDragPatch(master, days(1), days(1), tz)).toEqual({
      scope: 'series', all_day: false, start: '2026-10-13T09:00:00+02:00', end: '2026-10-13T10:00:00+02:00',
      recurrence: { freq: 'WEEKLY', interval: 1, byday: ['TU', 'TH'], count: 5 },
    })
  })

  it('keeps the wall clock in the agent zone across a DST change, and resizes only the end', () => {
    expect(seriesDragPatch(master, days(14), days(14), tz)).toMatchObject({ start: '2026-10-26T09:00:00+01:00', end: '2026-10-26T10:00:00+01:00' })
    expect(seriesDragPatch(master, days(14), days(14), 'America/New_York')).toMatchObject({ start: '2026-10-26T03:00:00-04:00' })
    expect(seriesDragPatch(master, days(0), hours(1), tz)).toEqual({
      scope: 'series', all_day: false, start: '2026-10-12T09:00:00+02:00', end: '2026-10-12T11:00:00+02:00', recurrence: master.recurrence,
    })
  })

  it('reads the weekdays where the series lives when the agent is in another zone', () => {
    const la = 'America/Los_Angeles'
    // Monday 08:00 in Amsterdam, which a Los Angeles agent sees and drags on Sunday at 23:00.
    const monday: EventDetail = { ...master, start: '2026-10-11T23:00:00-07:00', end: '2026-10-12T00:00:00-07:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['MO'] } }
    // A day later is Tuesday 08:00 in Amsterdam.
    expect(seriesDragPatch(monday, days(1), days(1), la)).toMatchObject({ start: '2026-10-12T23:00:00-07:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['TU'] } })
    // Nine hours earlier stays on the agent's Sunday, but is Sunday 23:00 in Amsterdam too: the rule follows.
    expect(seriesDragPatch(monday, hours(-9), hours(-9), la)).toMatchObject({ start: '2026-10-11T14:00:00-07:00', recurrence: { freq: 'WEEKLY', interval: 1, byday: ['SU'] } })
    // Without a zone of its own (all-day), the agent's zone counts.
    const allDay: EventDetail = { ...monday, all_day: true, start: '2026-10-12', end: '2026-10-13', timezone: null }
    expect(seriesDragPatch(allDay, days(1), days(1), la)).toMatchObject({ recurrence: { freq: 'WEEKLY', interval: 1, byday: ['TU'] } })
  })

  it('leaves the weekdays of a rule that never included the start\'s weekday alone', () => {
    // Another app's series: it starts on Sunday 4 October and repeats on Mondays and Wednesdays.
    const offset: EventDetail = { ...master, start: '2026-10-04T10:00:00+02:00', end: '2026-10-04T11:00:00+02:00' }
    expect(seriesDragPatch(offset, days(1), days(1), tz)).toMatchObject({ start: '2026-10-05T10:00:00+02:00', recurrence: master.recurrence })
  })

  it('moves an all-day series by whole days and never sends a custom rule', () => {
    const allDay = { ...master, all_day: true, start: '2026-10-12', end: '2026-10-13' }
    expect(seriesDragPatch(allDay, days(2), days(2), tz)).toMatchObject({ all_day: true, start: '2026-10-14', end: '2026-10-15' })
    expect(seriesDragPatch({ ...master, recurrence: null, recurrence_custom: true }, days(1), days(1), tz)).not.toHaveProperty('recurrence')
    expect(seriesDragPatch({ ...master, recurrence_custom: true }, days(1), days(1), tz)).not.toHaveProperty('recurrence')
  })
})

describe('stored preferences', () => {
  afterEach(() => vi.unstubAllGlobals())

  function store(values: Record<string, string>): void {
    vi.stubGlobal('window', { localStorage: { getItem: (key: string) => values[key] ?? null } })
  }

  it('keeps hidden calendars only as a list of ids', () => {
    store({ ok: '[3,10]', text: '"10"', mixed: '[3,"x"]', object: '{"0":3}', broken: '[3,' })
    expect(readStored('ok', [], isIdList)).toEqual([3, 10])
    for (const key of ['text', 'mixed', 'object', 'broken', 'missing']) {
      expect(readStored(key, [], isIdList)).toEqual([])
    }
  })

  it('keeps a stored view only when the toolbar offers it', () => {
    store({ week: '"timeGridWeek"', year: '"multiMonthYear"', gone: '"resourceTimeline"', number: '3' })
    expect(readStored('week', 'dayGridMonth', isView)).toBe('timeGridWeek')
    expect(readStored('year', 'timeGridWeek', isView)).toBe('multiMonthYear')
    expect(readStored('gone', 'timeGridWeek', isView)).toBe('timeGridWeek')
    expect(readStored('number', 'timeGridWeek', isView)).toBe('timeGridWeek')
  })

  it('reads unchecked values as before', () => {
    store({ collapsed: 'true' })
    expect(readStored('collapsed', false)).toBe(true)
  })
})
