import { describe, expect, it } from 'vitest'
import { addDays, addMinutes, dateIn, dateOf, daysBetween, fromParts, minutesBetween, shift, toParts } from '../src/shared/time'

const zero = { years: 0, months: 0, days: 0, milliseconds: 0 }

describe('time', () => {
  it('splits an API instant into form parts in the agent zone, not the browser zone', () => {
    expect(toParts('2026-10-06T08:00:00Z', 'Europe/Amsterdam')).toEqual({ date: '2026-10-06', time: '10:00' })
    expect(toParts('2026-10-06T23:30:00+00:00', 'Australia/Sydney')).toEqual({ date: '2026-10-07', time: '10:30' })
  })

  it('keeps dates as dates', () => {
    expect(toParts('2026-10-06', 'Europe/Amsterdam')).toEqual({ date: '2026-10-06', time: '' })
  })

  it('builds offset-free timed values that the server reads in the agent zone', () => {
    expect(fromParts('2026-10-06', '09:15')).toBe('2026-10-06T09:15')
  })

  it('finds the date a wall-clock time in one zone falls on in another', () => {
    expect(dateIn('2026-10-11', '23:00', 'America/Los_Angeles', 'Europe/Amsterdam')).toBe('2026-10-12')
    expect(dateIn('2026-10-11', '14:00', 'America/Los_Angeles', 'Europe/Amsterdam')).toBe('2026-10-11')
    expect(dateIn('2026-10-12', '08:00', 'Europe/Amsterdam', 'America/Los_Angeles')).toBe('2026-10-11')
  })

  it('counts days and adds them', () => {
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01')
    expect(daysBetween('2026-10-06', '2026-10-09')).toBe(3)
  })

  it('adds wall-clock minutes across the autumn DST change', () => {
    expect(addMinutes('2026-10-25', '01:30', 120, 'Europe/Amsterdam')).toEqual({ date: '2026-10-25', time: '02:30' })
    expect(minutesBetween({ date: '2026-10-25', time: '00:00' }, { date: '2026-10-25', time: '04:00' }, 'Europe/Amsterdam')).toBe(300)
  })

  it('shifts a series start by a drag delta and keeps the wall clock over DST', () => {
    expect(shift('2026-10-20T10:00:00+02:00', { ...zero, days: 7 }, 'Europe/Amsterdam')).toBe('2026-10-27T10:00:00+01:00')
    expect(shift('2026-10-20T10:00:00+02:00', { ...zero, milliseconds: 5_400_000 }, 'Europe/Amsterdam')).toBe('2026-10-20T11:30:00+02:00')
    expect(shift('2026-10-30', { ...zero, days: 3 }, 'Europe/Amsterdam')).toBe('2026-11-02')
  })

  it('finds the date of an instant in the agent zone', () => {
    expect(dateOf('2026-10-06T23:30:00Z', 'Europe/Amsterdam')).toBe('2026-10-07')
    expect(dateOf('2026-10-06', 'Asia/Tokyo')).toBe('2026-10-06')
  })
})
