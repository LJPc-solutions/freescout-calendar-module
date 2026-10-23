import { Temporal } from 'temporal-polyfill'
import { t } from './i18n'
import { addDays } from './time'
import type { FieldDefinition, FieldValue } from './types'

/** Between the start and the end of a time range ("02:30pm – 03:30pm"). */
const RANGE_SEPARATOR = ' – '

/**
 * A time of day the way FreeScout core shows it: "14:30", or on a 12-hour clock "02:30pm". Core turns "H:i" into
 * "h:ia" for those agents (User::dateFormat()), so the hour keeps two digits and am/pm is PHP's: lowercase English.
 */
export function formatClock(hour: number, minute: number, hour12: boolean): string {
  const minutes = String(minute).padStart(2, '0')
  if (!hour12) {
    return `${String(hour).padStart(2, '0')}:${minutes}`
  }

  return `${String(hour % 12 || 12).padStart(2, '0')}:${minutes}${hour < 12 ? 'am' : 'pm'}`
}

/** A wall-clock moment as FullCalendar hands it to a formatter function: in the calendar's time zone (the agent's). */
interface CalendarClock {
  hour: number
  minute: number
  instantMs: number
}

/**
 * FullCalendar's time text (eventTimeFormat, slotHeaderFormat): one time, or a range when it passes an end. A range
 * that ends where it starts shows its one time, as the Intl formatting did before.
 */
export function calendarTimeText(info: { start: CalendarClock; end?: CalendarClock | null }, hour12: boolean): string {
  const start = formatClock(info.start.hour, info.start.minute, hour12)
  if (!info.end || info.end.instantMs === info.start.instantMs) {
    return start
  }

  return start + RANGE_SEPARATOR + formatClock(info.end.hour, info.end.minute, hour12)
}

/**
 * When an event takes place, in the agent's zone: "Tuesday, October 6, 2026, 02:30pm – 03:30pm", with the date part in
 * the agent's locale and the times on FreeScout's clock. An event over several days names both dates; an all-day
 * event shows its dates only (inclusive end).
 */
export function formatWhen(start: string, end: string, allDay: boolean, locale: string, timeZone: string, hour12: boolean): string {
  const format = new Intl.DateTimeFormat(locale, { dateStyle: 'full', timeZone: 'UTC' })
  if (allDay) {
    const last = addDays(end, -1)
    const from = new Date(`${start}T00:00:00Z`)

    return last <= start ? format.format(from) : format.formatRange(from, new Date(`${last}T00:00:00Z`))
  }
  const from = Temporal.Instant.from(start).toZonedDateTimeISO(timeZone)
  const to = Temporal.Instant.from(end).toZonedDateTimeISO(timeZone)
  const day = (moment: Temporal.ZonedDateTime) => format.format(new Date(`${moment.toPlainDate().toString()}T00:00:00Z`))
  const opening = `${day(from)}, ${formatClock(from.hour, from.minute, hour12)}`
  if (from.epochMilliseconds === to.epochMilliseconds) {
    return opening
  }
  const sameDay = from.toPlainDate().equals(to.toPlainDate())

  return opening + RANGE_SEPARATOR + (sameDay ? '' : `${day(to)}, `) + formatClock(to.hour, to.minute, hour12)
}

export function displayFieldValue(field: FieldDefinition, value: FieldValue | undefined): string {
  if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
    return ''
  }
  if (Array.isArray(value)) {
    return value.join(', ')
  }
  if (field.type === 'boolean') {
    return value === true ? t('Yes') : t('No')
  }

  return String(value)
}

/** Minutes before the start; null means the calendar's default and -1 means no reminder (API contract). */
export function reminderOptions(): { value: number | null; label: string }[] {
  return [
    { value: null, label: t('Calendar default') },
    { value: -1, label: t('No reminder') },
    { value: 5, label: t('5 minutes before') },
    { value: 10, label: t('10 minutes before') },
    { value: 15, label: t('15 minutes before') },
    { value: 30, label: t('30 minutes before') },
    { value: 60, label: t('1 hour before') },
    { value: 120, label: t('2 hours before') },
    { value: 1440, label: t('1 day before') },
    { value: 2880, label: t('2 days before') },
    { value: 10080, label: t('1 week before') },
  ]
}

export function reminderLabel(minutes: number | null): string {
  return reminderOptions().find((option) => option.value === minutes)?.label ?? t(':count minutes before', { count: minutes ?? 0 })
}

/** The addresses typed in one field, separated by commas, semicolons or spaces; the server checks each one. */
export function addressList(text: string): string[] {
  return text.split(/[\s,;]+/).filter((address) => address !== '')
}

/** An attendee's answer to our invite (their PARTSTAT). */
export function answerLabel(partstat: string): string {
  return ({ ACCEPTED: t('accepted'), TENTATIVE: t('maybe'), DECLINED: t('declined') } as Record<string, string>)[partstat] ?? t('no answer yet')
}
