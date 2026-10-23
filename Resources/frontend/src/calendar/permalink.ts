/**
 * Permalinks: "#e<id>" is a v3 event and "#e<id>@<recurrence key>" one occurrence of it, with the key the API takes
 * as recurrence_id ("20261014T080000Z" for a timed event, "20261014" for an all-day one). Any other ref (a v2 item
 * id or a UID, which may contain "@" too) is resolved by the server.
 */
export interface Permalink {
  reference: string
  recurrenceId: string | null
}

const OCCURRENCE = /^e(\d+)@(\d{8}(T\d{6}Z)?)$/

export function refFromHash(hash: string): Permalink | null {
  let ref: string
  try {
    ref = decodeURIComponent(hash.replace(/^#/, '')).trim()
  } catch {
    return null
  }
  if (ref === '' || ref.length > 191) {
    return null
  }
  const occurrence = OCCURRENCE.exec(ref)

  return occurrence ? { reference: `e${occurrence[1]}`, recurrenceId: occurrence[2] ?? null } : { reference: ref, recurrenceId: null }
}

/** The API reference of a v3 event. */
export function eventRef(id: number): string {
  return `e${id}`
}

/** What a link to an event carries after "#": the occurrence when there is one, else the event. */
export function linkRef(id: number, recurrenceId: string | null): string {
  return recurrenceId ? `${eventRef(id)}@${recurrenceId}` : eventRef(id)
}

export function permalink(id: number, recurrenceId: string | null, location: Pick<Location, 'origin' | 'pathname' | 'search'>): string {
  return `${location.origin}${location.pathname}${location.search}#${linkRef(id, recurrenceId)}`
}
