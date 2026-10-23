import { describe, expect, it } from 'vitest'
import { STALE_HOURS, pageBanner, staleCalendars, type HealthProblem } from '../src/calendar/banners'
import type { CalendarInfo } from '../src/shared/types'

const now = Date.parse('2026-10-09T12:00:00Z')
const hoursAgo = (hours: number) => new Date(now - hours * 3_600_000).toISOString().replace(/\.\d{3}Z$/, 'Z')
const calendar = (id: number, name: string, syncFailed: boolean, lastSyncedAt: string | null) =>
  ({ id, name, sync_failed: syncFailed, last_synced_at: lastSyncedAt }) as CalendarInfo

const calendars = [
  calendar(1, 'Failing for days', true, hoursAgo(50)),
  calendar(2, 'Failing since this morning', true, hoursAgo(3)),
  calendar(3, 'Never synced', true, null),
  calendar(4, 'Fine', false, hoursAgo(1)),
  calendar(5, 'Local', false, null),
  calendar(6, 'Hidden and failing', true, hoursAgo(50)),
  calendar(7, 'Exactly a day', true, hoursAgo(STALE_HOURS)),
]
const problems: HealthProblem[] = [{ key: 'calendar:1:failing', text: 'The calendar "Failing for days" has not synced for more than 24 hours, so its events may be out of date.', url: '/app-settings/calendar?calendar=1' }]

describe('calendar page banners', () => {
  it('names the shown calendars whose sync failed and whose last success is more than a day ago or never was', () => {
    expect(staleCalendars(calendars, [6], now).map((item) => item.name)).toEqual(['Failing for days', 'Never synced'])
    expect(staleCalendars(calendars, [], now).map((item) => item.id)).toEqual([1, 3, 6])
    expect(staleCalendars(calendars, [], now + 3_600_000).map((item) => item.id)).toEqual([1, 3, 6, 7])
  })

  it('shows an administrator the health problems until they are dismissed, and never the agents banner', () => {
    expect(pageBanner(true, problems, false, calendars, [], now)).toEqual({ kind: 'health', problems })
    expect(pageBanner(true, problems, true, calendars, [], now)).toBeNull()
    expect(pageBanner(true, [], false, calendars, [], now)).toBeNull()
  })

  it('shows an agent which calendars are not up to date', () => {
    expect(pageBanner(false, [], false, calendars, [6], now)).toEqual({ kind: 'stale', names: ['Failing for days', 'Never synced'] })
    expect(pageBanner(false, [], false, calendars.filter((item) => !item.sync_failed), [], now)).toBeNull()
    expect(pageBanner(false, [], false, calendars, [1, 3, 6], now)).toBeNull()
  })
})
