import type { CalendarInfo } from '../shared/types'

/** A problem an administrator has to solve (Services/HealthService::problems()), with its settings link. */
export interface HealthProblem {
  key: string
  text: string
  url: string
}

/** A calendar counts as not up to date once its last sync failed and its last success is longer ago than this. */
export const STALE_HOURS = 24

export type PageBanner = { kind: 'health'; problems: HealthProblem[] } | { kind: 'stale'; names: string[] } | null

/**
 * The shown calendars whose last sync failed and whose last success is more than STALE_HOURS ago, or that never
 * synced: their events may be out of date. Calendars the agent switched off are left out.
 */
export function staleCalendars(calendars: CalendarInfo[], hidden: number[], now: number): CalendarInfo[] {
  return calendars.filter((calendar) => {
    if (!calendar.sync_failed || hidden.includes(calendar.id)) {
      return false
    }
    const synced = calendar.last_synced_at === null ? NaN : Date.parse(calendar.last_synced_at)

    return Number.isNaN(synced) || now - synced > STALE_HOURS * 3_600_000
  })
}

/**
 * The banner above the calendar: an administrator sees the problems to solve until they close it on this page; an
 * agent sees which of their calendars are not up to date. Administrators get the problems instead of the notice. A
 * failing calendar becomes a problem after a day, counted from its creation when it never synced, while the notice
 * names a calendar that never synced at once, because it shows no events at all.
 */
export function pageBanner(isAdmin: boolean, problems: HealthProblem[], dismissed: boolean, calendars: CalendarInfo[], hidden: number[], now: number): PageBanner {
  if (isAdmin) {
    return problems.length > 0 && !dismissed ? { kind: 'health', problems } : null
  }
  const stale = staleCalendars(calendars, hidden, now)

  return stale.length > 0 ? { kind: 'stale', names: stale.map((calendar) => calendar.name) } : null
}
