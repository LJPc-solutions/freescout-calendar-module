import { Temporal } from 'temporal-polyfill'

/** FullCalendar's Duration (drop/resize deltas). */
export interface Delta {
  years: number
  months: number
  days: number
  milliseconds: number
}

const DATE = /^\d{4}-\d{2}-\d{2}$/

export function isDate(value: string): boolean {
  return DATE.test(value)
}

/** Splits an API value into form parts in the agent's timezone (never the browser's). */
export function toParts(value: string, timeZone: string): { date: string; time: string } {
  if (isDate(value)) {
    return { date: value, time: '' }
  }
  const zoned = Temporal.Instant.from(value).toZonedDateTimeISO(timeZone)

  return { date: zoned.toPlainDate().toString(), time: zoned.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/**
 * The slot the rule gives an occurrence, from its recurrence id (EventIcs::recurrenceKey): "20261012" in an all-day
 * series, a UTC "20261012T070000Z" in a timed one. A timed slot is read in the agent's zone, like the series' start.
 */
export function originalSlot(recurrenceId: string, allDay: boolean, timeZone: string): { date: string; time: string } {
  const match = (allDay ? /^(\d{4})(\d{2})(\d{2})$/ : /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/).exec(recurrenceId)
  if (!match) {
    throw new Error(`Recurrence id "${recurrenceId}" does not fit ${allDay ? 'an all-day' : 'a timed'} series`)
  }
  const [, year, month, day, hour, minute, second] = match
  const date = `${year}-${month}-${day}`

  return allDay ? { date, time: '' } : toParts(`${date}T${hour}:${minute}:${second}Z`, timeZone)
}

/** A timed form value for the API: no offset, so the server reads it in the agent's FreeScout timezone. */
export function fromParts(date: string, time: string): string {
  return `${date}T${time}`
}

/** The date a wall-clock date and time in one zone falls on in another. */
export function dateIn(date: string, time: string, fromZone: string, toZone: string): string {
  return Temporal.PlainDateTime.from(`${date}T${time}`).toZonedDateTime(fromZone).withTimeZone(toZone).toPlainDate().toString()
}

export function addDays(date: string, days: number): string {
  return Temporal.PlainDate.from(date).add({ days }).toString()
}

/** Whole days from a to b (dates), for all-day lengths. */
export function daysBetween(a: string, b: string): number {
  return Temporal.PlainDate.from(a).until(Temporal.PlainDate.from(b), { largestUnit: 'days' }).days
}

export function todayIn(timeZone: string): string {
  return Temporal.Now.plainDateISO(timeZone).toString()
}

/** The next full hour in the agent's timezone, as form parts: the default start of a new event. */
export function nextFullHour(timeZone: string): { date: string; time: string } {
  const now = Temporal.Now.zonedDateTimeISO(timeZone)
  const next = now.round({ smallestUnit: 'hour', roundingMode: 'ceil' })
  const start = Temporal.ZonedDateTime.compare(next, now) === 0 ? next.add({ hours: 1 }) : next

  return { date: start.toPlainDate().toString(), time: start.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/** Adds minutes to a form date and time (wall clock, so it's DST-safe in the agent's zone). */
export function addMinutes(date: string, time: string, minutes: number, timeZone: string): { date: string; time: string } {
  const zoned = Temporal.PlainDateTime.from(`${date}T${time}`).toZonedDateTime(timeZone).add({ minutes })

  return { date: zoned.toPlainDate().toString(), time: zoned.toPlainTime().toString({ smallestUnit: 'minute' }) }
}

/** Minutes between two form date-times in the agent's zone. */
export function minutesBetween(start: { date: string; time: string }, end: { date: string; time: string }, timeZone: string): number {
  const a = Temporal.PlainDateTime.from(`${start.date}T${start.time}`).toZonedDateTime(timeZone)
  const b = Temporal.PlainDateTime.from(`${end.date}T${end.time}`).toZonedDateTime(timeZone)

  return Math.round(a.until(b, { largestUnit: 'hours' }).total({ unit: 'minutes' }))
}

/** Shifts an API value (ISO with offset, or a date) by a FullCalendar delta, keeping the wall clock in the agent's zone. */
export function shift(value: string, delta: Delta, timeZone: string): string {
  const calendarPart = { years: delta.years, months: delta.months, days: delta.days }
  if (isDate(value)) {
    return Temporal.PlainDate.from(value).add(calendarPart).toString()
  }

  return Temporal.Instant.from(value)
    .toZonedDateTimeISO(timeZone)
    .add(calendarPart)
    .add({ milliseconds: delta.milliseconds })
    .toString({ timeZoneName: 'never', smallestUnit: 'second' })
}

/** The date (in the agent's zone) an API value falls on. */
export function dateOf(value: string, timeZone: string): string {
  return isDate(value) ? value : Temporal.Instant.from(value).toZonedDateTimeISO(timeZone).toPlainDate().toString()
}

/** The first date (in the agent's zone) after an API end value: all-day ends are exclusive dates already. */
export function endDateOf(value: string, timeZone: string): string {
  if (isDate(value)) {
    return value
  }
  const zoned = Temporal.Instant.from(value).toZonedDateTimeISO(timeZone)
  const date = zoned.toPlainDate()

  return (Temporal.ZonedDateTime.compare(zoned, zoned.startOfDay()) === 0 ? date : date.add({ days: 1 })).toString()
}

/** ISO weekday 1 (Monday) to 7 (Sunday) of a date. */
export function weekdayOf(date: string): number {
  return Temporal.PlainDate.from(date).dayOfWeek
}
