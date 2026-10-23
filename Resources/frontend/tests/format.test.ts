import { describe, expect, it } from 'vitest'
import { addressList, answerLabel, calendarTimeText, formatClock, formatWhen } from '../src/shared/format'

describe('formatClock', () => {
  it('writes the 12-hour clock as FreeScout core does (h:ia)', () => {
    expect(formatClock(0, 0, true)).toBe('12:00am')
    expect(formatClock(12, 0, true)).toBe('12:00pm')
    expect(formatClock(9, 5, true)).toBe('09:05am')
    expect(formatClock(14, 30, true)).toBe('02:30pm')
    expect(formatClock(23, 59, true)).toBe('11:59pm')
  })

  it('writes the 24-hour clock with two-digit hours (H:i)', () => {
    expect(formatClock(23, 59, false)).toBe('23:59')
    expect(formatClock(0, 0, false)).toBe('00:00')
    expect(formatClock(9, 5, false)).toBe('09:05')
  })
})

describe('calendarTimeText', () => {
  // FullCalendar's wall-clock fields; instantMs only tells equal moments apart.
  const at = (hour: number, minute: number, instantMs: number) => ({ hour, minute, instantMs })

  it('shows one time, or a range with an en dash', () => {
    expect(calendarTimeText({ start: at(14, 0, 1) }, true)).toBe('02:00pm')
    expect(calendarTimeText({ start: at(14, 0, 1), end: null }, false)).toBe('14:00')
    expect(calendarTimeText({ start: at(10, 0, 1), end: at(11, 30, 2) }, true)).toBe('10:00am – 11:30am')
    expect(calendarTimeText({ start: at(23, 0, 1), end: at(0, 0, 2) }, false)).toBe('23:00 – 00:00')
  })

  it('shows a range that ends where it starts as one time', () => {
    expect(calendarTimeText({ start: at(9, 0, 5), end: at(9, 0, 5) }, true)).toBe('09:00am')
  })
})

describe('formatWhen', () => {
  // 20:30 in Amsterdam is 14:30 in New York (and already the next day in Tokyo).
  const start = '2026-10-06T20:30:00+02:00'
  const end = '2026-10-06T21:30:00+02:00'

  it('shows the date in the agent\'s locale and zone, then the times on their clock', () => {
    expect(formatWhen(start, end, false, 'nl', 'America/New_York', true)).toBe('dinsdag 6 oktober 2026, 02:30pm – 03:30pm')
    expect(formatWhen(start, end, false, 'en', 'America/New_York', true)).toBe('Tuesday, October 6, 2026, 02:30pm – 03:30pm')
  })

  it('uses the 24-hour clock', () => {
    expect(formatWhen(start, end, false, 'nl', 'America/New_York', false)).toBe('dinsdag 6 oktober 2026, 14:30 – 15:30')
    expect(formatWhen(start, end, false, 'nl', 'Asia/Tokyo', false)).toBe('woensdag 7 oktober 2026, 03:30 – 04:30')
  })

  it('names both dates for an event over several days', () => {
    expect(formatWhen('2026-10-06T22:00:00-04:00', '2026-10-07T01:30:00-04:00', false, 'en', 'America/New_York', true))
      .toBe('Tuesday, October 6, 2026, 10:00pm – Wednesday, October 7, 2026, 01:30am')
  })

  it('shows one time for an event without length', () => {
    expect(formatWhen(start, start, false, 'en', 'America/New_York', true)).toBe('Tuesday, October 6, 2026, 02:30pm')
  })

  it('keeps all-day events as dates with an inclusive end', () => {
    expect(formatWhen('2026-10-06', '2026-10-07', true, 'en', 'America/New_York', true)).toBe('Tuesday, October 6, 2026')
    // Intl's own range, which puts thin spaces around its dash.
    expect(formatWhen('2026-10-06', '2026-10-09', true, 'en', 'America/New_York', true).replace(/\s/g, ' ')).toBe('Tuesday, October 6 – Thursday, October 8, 2026')
  })
})

describe('addressList', () => {
  it('splits on commas, semicolons and spaces and drops the empty parts', () => {
    expect(addressList(' anna@example.com, bob@example.net;carol@example.org\n dana@example.com ,')).toEqual(['anna@example.com', 'bob@example.net', 'carol@example.org', 'dana@example.com'])
    expect(addressList('  ')).toEqual([])
  })
})

describe('answerLabel', () => {
  it('names each answer, and every other status as not answered yet', () => {
    expect([answerLabel('ACCEPTED'), answerLabel('TENTATIVE'), answerLabel('DECLINED'), answerLabel('NEEDS-ACTION'), answerLabel('DELEGATED')])
      .toEqual(['accepted', 'maybe', 'declined', 'no answer yet', 'no answer yet'])
  })
})
