import { t } from '../shared/i18n'

/** One calendar's answer from POST calendar/api/sync (Api\SyncController, SyncService::syncByHand). */
export interface SyncResult {
  id: number
  name: string
  state: 'synced' | 'partial' | 'running' | 'recent' | 'queued' | 'failed' | 'off' | 'reauth'
  created: number
  updated: number
  deleted: number
  /** The server's sentence for this calendar; an agent's never holds the provider's error. */
  message: string
}

const NOT_SYNCED: SyncResult['state'][] = ['failed', 'off', 'reauth']
const UNFINISHED: SyncResult['state'][] = ['partial', 'running', 'queued']

/** The one notice after "Sync now" on the calendar page; null when the agent sees no external calendar. */
export function syncSummary(results: SyncResult[]): { type: 'success' | 'error'; text: string } | null {
  const [only] = results
  if (only === undefined) {
    return null
  }
  if (results.length === 1) {
    return { type: NOT_SYNCED.includes(only.state) ? 'error' : 'success', text: only.message }
  }
  const failed = results.filter((result) => NOT_SYNCED.includes(result.state))
  const done = results.filter((result) => !NOT_SYNCED.includes(result.state))
  const parts: string[] = []
  if (done.length > 0) {
    const total = (key: 'created' | 'updated' | 'deleted') => done.reduce((sum, result) => sum + result[key], 0)
    const counts = { created: total('created'), updated: total('updated'), deleted: total('deleted') }
    parts.push(counts.created + counts.updated + counts.deleted === 0 ? t('Synced. Nothing changed.') : t('Synced: :created new, :updated changed, :deleted removed.', counts))
    if (done.some((result) => UNFINISHED.includes(result.state))) {
      parts.push(t('Some calendars finish in the background.'))
    }
  }
  if (failed.length > 0) {
    parts.push(t('Not synced: :names.', { names: failed.map((result) => result.name).join(', ') }))
  }

  return { type: failed.length > 0 ? 'error' : 'success', text: parts.join(' ') }
}
