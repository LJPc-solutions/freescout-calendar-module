import { describe, expect, it } from 'vitest'
import { syncSummary, type SyncResult } from '../src/calendar/sync'

const result = (id: number, name: string, state: SyncResult['state'], created = 0, updated = 0, deleted = 0, message = `${name}: ${state}`): SyncResult =>
  ({ id, name, state, created, updated, deleted, message })

describe('syncSummary', () => {
  it('says nothing when the agent sees no external calendar', () => {
    expect(syncSummary([])).toBeNull()
  })

  it("repeats the server's own sentence for a single calendar", () => {
    expect(syncSummary([result(1, 'Team', 'synced', 2, 1, 0, 'Synced: 2 new, 1 changed, 0 removed.')])).toEqual({ type: 'success', text: 'Synced: 2 new, 1 changed, 0 removed.' })
    expect(syncSummary([result(1, 'Team', 'failed', 0, 0, 0, 'This calendar could not be synced right now.')])).toEqual({ type: 'error', text: 'This calendar could not be synced right now.' })
  })

  it('adds up several calendars and names the ones that did not sync', () => {
    const results = [result(1, 'Team', 'synced', 2, 1, 0), result(2, 'Holidays', 'partial', 5, 0, 1), result(3, 'Broken', 'failed'), result(4, 'Old', 'reauth')]

    expect(syncSummary(results)).toEqual({
      type: 'error',
      text: 'Synced: 7 new, 1 changed, 1 removed. Some calendars finish in the background. Not synced: Broken, Old.',
    })
  })

  it('says so when nothing changed in any calendar', () => {
    expect(syncSummary([result(1, 'Team', 'synced'), result(2, 'Holidays', 'recent')])).toEqual({ type: 'success', text: 'Synced. Nothing changed.' })
  })

  it('counts a calendar that synced moments ago as done', () => {
    expect(syncSummary([result(1, 'Team', 'recent'), result(2, 'Holidays', 'synced', 1)])).toEqual({ type: 'success', text: 'Synced: 1 new, 0 changed, 0 removed.' })
  })
})
