import { notify } from '../shared/alerts'

/**
 * "Sync now" and the first sync of a calendar just added. A sync button posts its form as usual (the page waits up to
 * 20 seconds) and says so meanwhile. A status marked data-ljpc-sync-start is synced right away from here; while any
 * status is marked data-ljpc-syncing, the page asks the server for it again until the sync is done.
 */
const POLL_MS = 3000
const POLL_LIMIT = 200

interface SyncStatus {
  syncing: boolean
  html: string
  message?: string
  state?: string
}

export function initSyncNow(root: HTMLElement): void {
  const busyText = root.dataset.ljpcSyncingText ?? ''
  root.querySelectorAll<HTMLFormElement>('form[data-ljpc-sync-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const button = (event as SubmitEvent).submitter
      if (button instanceof HTMLButtonElement) {
        button.innerHTML = `<i class="glyphicon glyphicon-refresh ljpc-spin" aria-hidden="true"></i> ${escape(busyText)}`
        // Disabled after the browser has taken the submission, so the form is still sent.
        window.setTimeout(() => {
          button.disabled = true
        })
      }
    })
  })
  const statuses = Array.from(root.querySelectorAll<HTMLElement>('[data-ljpc-sync-status]'))
  if (statuses.some((status) => status.hasAttribute('data-ljpc-syncing')) && root.dataset.ljpcSyncStatusUrl) {
    void follow(root.dataset.ljpcSyncStatusUrl, statuses)
  }
}

async function follow(statusUrl: string, statuses: HTMLElement[]): Promise<void> {
  try {
    for (const status of statuses) {
      const start = status.dataset.ljpcSyncStart
      if (start) {
        const result = await request<SyncStatus>(start, 'POST')
        show(status, result)
        // "Synced moments ago": the scheduler got there first, and the status already says so.
        if (result.message && result.state !== 'recent') {
          notify(result.state === 'failed' ? 'error' : 'success', result.message)
        }
      }
    }
    for (let tries = 0; tries < POLL_LIMIT; tries++) {
      const syncing = statuses.filter((status) => status.hasAttribute('data-ljpc-syncing'))
      if (syncing.length === 0) {
        return
      }
      await new Promise((resolve) => window.setTimeout(resolve, POLL_MS))
      const query = new URLSearchParams()
      syncing.forEach((status) => query.append('ids[]', status.dataset.ljpcSyncStatus ?? ''))
      const answer = await request<{ calendars: Record<string, SyncStatus> }>(`${statusUrl}?${query.toString()}`, 'GET')
      syncing.forEach((status) => {
        const result = answer.calendars[status.dataset.ljpcSyncStatus ?? '']
        if (result) {
          show(status, result)
        }
      })
    }
  } catch (error) {
    // The scheduler still syncs the calendar; the page only stops showing it live.
    console.error('The calendar sync status could not be followed', error)
  }
}

function show(status: HTMLElement, result: SyncStatus): void {
  status.innerHTML = result.html // rendered and escaped by the server (settings/card_status)
  status.toggleAttribute('data-ljpc-syncing', result.syncing)
}

async function request<T>(url: string, method: 'GET' | 'POST'): Promise<T> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
  })
  if (!response.ok) {
    throw new Error(`${method} ${url} answered ${response.status}`)
  }

  return (await response.json()) as T
}

function escape(text: string): string {
  const span = document.createElement('span')
  span.textContent = text

  return span.innerHTML
}
