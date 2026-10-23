import { Temporal } from 'temporal-polyfill'
import { t } from './i18n'
import { daysBetween } from './time'
import type { Frequency, Recurrence } from './types'

/** RRULE weekday codes in ISO order (Monday first), matching Temporal's dayOfWeek - 1. */
export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const

export function weekdayCode(date: string): string {
  return WEEKDAYS[Temporal.PlainDate.from(date).dayOfWeek - 1] as string
}

/** Defaults when the agent picks a frequency, anchored on the event's start date. */
export function defaultRecurrence(freq: Frequency, startDate: string): Recurrence {
  if (freq === 'WEEKLY') {
    return { freq, interval: 1, byday: [weekdayCode(startDate)] }
  }
  if (freq === 'MONTHLY') {
    return { freq, interval: 1, bymonthday: Temporal.PlainDate.from(startDate).day }
  }

  return { freq, interval: 1 }
}

/** The position of a date's weekday in its month: 1-4, or -1 for the last one. */
export function weekdayPosition(date: string): number {
  const day = Temporal.PlainDate.from(date)
  const position = Math.ceil(day.day / 7)

  return position > 4 ? -1 : position
}

/**
 * Keeps a series' rule on the same pattern when its start moves from one date to another (dragging a series):
 * weekly weekdays move along, monthly day or weekday positions follow the new start.
 */
export function shiftRecurrence(rule: Recurrence, fromDate: string, toDate: string): Recurrence {
  const days = daysBetween(fromDate, toDate)
  if (days === 0) {
    return rule
  }
  const next: Recurrence = { ...rule }
  if (rule.freq === 'WEEKLY' && rule.byday?.length) {
    const offset = ((days % 7) + 7) % 7
    next.byday = rule.byday
      .map((code) => WEEKDAYS[(WEEKDAYS.indexOf(code as (typeof WEEKDAYS)[number]) + offset) % 7] as string)
      .sort((a, b) => WEEKDAYS.indexOf(a as (typeof WEEKDAYS)[number]) - WEEKDAYS.indexOf(b as (typeof WEEKDAYS)[number]))
  }
  if (rule.freq === 'MONTHLY') {
    const date = Temporal.PlainDate.from(toDate)
    if (rule.bymonthday !== undefined) {
      next.bymonthday = date.day
    }
    if (rule.bysetpos !== undefined) {
      next.byday = [weekdayCode(toDate)]
      next.bysetpos = rule.bysetpos === -1 && date.day + 7 > date.daysInMonth ? -1 : weekdayPosition(toDate)
    }
  }

  return next
}

/**
 * The rule after its series' start moved from one date to another, both dates read where the rule lives. Weekdays and
 * month days follow the start (shiftRecurrence), except in a weekly rule whose weekdays never included the start's:
 * that one was offset on purpose (another app wrote it), so its weekdays stay.
 */
export function followStart(rule: Recurrence, fromDate: string, toDate: string): Recurrence {
  const days = rule.byday ?? []
  if (rule.freq === 'WEEKLY' && days.length > 0 && !days.includes(weekdayCode(fromDate))) {
    return rule
  }

  return shiftRecurrence(rule, fromDate, toDate)
}

function weekdayName(code: string, locale: string): string {
  // 2024-01-01 is a Monday, so index i is WEEKDAYS[i].
  const index = WEEKDAYS.indexOf(code as (typeof WEEKDAYS)[number])
  const date = new Date(Date.UTC(2024, 0, 1 + index))

  return new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(date)
}

/** A one-line, translated summary such as "Every 2 weeks on Monday, Wednesday, until 31 Dec 2026". */
export function describeRecurrence(rule: Recurrence, locale: string): string {
  const every: Record<Frequency, (count: number) => string> = {
    DAILY: (count) => (count > 1 ? t('Every :count days', { count }) : t('Every day')),
    WEEKLY: (count) => (count > 1 ? t('Every :count weeks', { count }) : t('Every week')),
    MONTHLY: (count) => (count > 1 ? t('Every :count months', { count }) : t('Every month')),
    YEARLY: (count) => (count > 1 ? t('Every :count years', { count }) : t('Every year')),
  }
  const parts = [every[rule.freq](rule.interval)]

  if (rule.freq === 'WEEKLY' && rule.byday?.length) {
    parts.push(t('on :days', { days: rule.byday.map((code) => weekdayName(code, locale)).join(', ') }))
  }
  if (rule.freq === 'MONTHLY' && rule.bymonthday !== undefined) {
    parts.push(t('on day :day', { day: rule.bymonthday }))
  }
  if (rule.freq === 'MONTHLY' && rule.bysetpos !== undefined && rule.byday?.[0]) {
    const positions: Record<number, string> = { 1: t('first'), 2: t('second'), 3: t('third'), 4: t('fourth'), [-1]: t('last') }
    parts.push(t('on the :position :weekday', { position: positions[rule.bysetpos] ?? '', weekday: weekdayName(rule.byday[0], locale) }))
  }

  let text = parts.join(' ')
  if (rule.until) {
    const until = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${rule.until}T00:00:00Z`))
    text += ', ' + t('until :date', { date: until })
  } else if (rule.count) {
    text += ', ' + t(':count times', { count: rule.count })
  }

  return text
}
