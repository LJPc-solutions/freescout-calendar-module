import { describe, expect, it } from 'vitest'
import { defaultRecurrence, describeRecurrence, shiftRecurrence, weekdayPosition } from '../src/shared/recurrence'

describe('recurrence', () => {
  it('anchors new rules on the start date', () => {
    expect(defaultRecurrence('WEEKLY', '2026-10-07')).toEqual({ freq: 'WEEKLY', interval: 1, byday: ['WE'] })
    expect(defaultRecurrence('MONTHLY', '2026-10-07')).toEqual({ freq: 'MONTHLY', interval: 1, bymonthday: 7 })
    expect(defaultRecurrence('DAILY', '2026-10-07')).toEqual({ freq: 'DAILY', interval: 1 })
  })

  it('names the position of a weekday in its month, with the fifth as the last', () => {
    expect(weekdayPosition('2026-10-05')).toBe(1)
    expect(weekdayPosition('2026-10-26')).toBe(4)
    expect(weekdayPosition('2026-10-29')).toBe(-1)
  })

  it('moves weekly weekdays along with a dragged series', () => {
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['MO', 'WE'] }, '2026-10-05', '2026-10-06').byday).toEqual(['TU', 'TH'])
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['SU'] }, '2026-10-11', '2026-10-12').byday).toEqual(['MO'])
    expect(shiftRecurrence({ freq: 'WEEKLY', interval: 1, byday: ['MO'] }, '2026-10-05', '2026-10-02').byday).toEqual(['FR'])
  })

  it('moves monthly days and weekday positions to the new start', () => {
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, bymonthday: 5 }, '2026-10-05', '2026-10-07').bymonthday).toBe(7)
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['MO'], bysetpos: 1 }, '2026-10-05', '2026-10-13')).toEqual({ freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: 2 })
    expect(shiftRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['MO'], bysetpos: -1 }, '2026-10-26', '2026-10-27').bysetpos).toBe(-1)
  })

  it('leaves the rule alone when the date does not change', () => {
    const rule = { freq: 'WEEKLY' as const, interval: 2, byday: ['MO'] }
    expect(shiftRecurrence(rule, '2026-10-05', '2026-10-05')).toBe(rule)
  })

  it('describes rules in plain language', () => {
    expect(describeRecurrence({ freq: 'WEEKLY', interval: 2, byday: ['MO', 'WE'] }, 'en')).toBe('Every 2 weeks on Monday, Wednesday')
    expect(describeRecurrence({ freq: 'MONTHLY', interval: 1, byday: ['TU'], bysetpos: -1, count: 5 }, 'en')).toBe('Every month on the last Tuesday, 5 times')
    expect(describeRecurrence({ freq: 'DAILY', interval: 1, until: '2026-12-31' }, 'en')).toBe('Every day, until Dec 31, 2026')
  })
})
