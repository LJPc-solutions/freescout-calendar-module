export type Provider = 'local' | 'ics' | 'caldav' | 'google' | 'microsoft'

export type FieldType = 'text' | 'number' | 'dropdown' | 'boolean' | 'multiselect' | 'date' | 'email' | 'source'

export interface FieldDefinition {
  id: string | number
  name: string
  type: FieldType
  required: boolean
  options: string[]
}

export interface Rights {
  show_in_dashboard: boolean
  can_view: boolean
  can_create: boolean
  can_edit: boolean
}

/** GET /calendar/api/calendars */
export interface CalendarInfo {
  id: number
  name: string
  color: string
  provider: Provider
  display_priority: number
  fields: FieldDefinition[]
  has_title_template: boolean
  rights: Rights
  writable: boolean
  sync_failed: boolean
  /** The last successful sync (ISO 8601 UTC); null when there was none. */
  last_synced_at: string | null
}

/** GET /calendar/api/events: one row per occurrence, times in the agent's timezone. */
export interface Occurrence {
  id: string
  event_id: number
  calendar_id: number
  recurrence_id: string | null
  title: string
  start: string
  end: string
  all_day: boolean
  status: string
  is_recurring: boolean
  editable: boolean
}

export type Frequency = 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY'

export interface Recurrence {
  freq: Frequency
  interval: number
  byday?: string[]
  bysetpos?: number
  bymonthday?: number
  until?: string
  count?: number
}

export type FieldValue = string | number | boolean | string[]

/** Someone invited from FreeScout (InviteSender) and their answer: NEEDS-ACTION, ACCEPTED, TENTATIVE or DECLINED. */
export interface Attendee {
  email: string
  name: string
  partstat: string
}

/** GET /calendar/api/events/{ref} and the create/update responses. */
export interface EventDetail {
  id: number
  calendar_id: number
  uid: string
  title: string
  title_input: string | null
  location: string
  description: string
  all_day: boolean
  /** The named zone a timed event lives in (a floating one: its calendar's); null for an all-day event or a zone without a name. */
  timezone: string | null
  start: string
  end: string
  recurrence: Recurrence | null
  recurrence_custom: boolean
  is_recurring: boolean
  field_values: Record<string, FieldValue>
  reminder_minutes: number | null
  author: { id: number; name: string } | null
  conversation: { id: number; number: number; url: string } | null
  editable: boolean
  /** False for a series whose rule its calendar's provider can't write: it changes one occurrence at a time. */
  series_writable: boolean
  /** The people invited from FreeScout, with their answers. */
  attendees: Attendee[]
  /** Whether the event can have attendees: it was added from a conversation, and nobody else sent it to us. */
  can_invite: boolean
  /** Only on a save that succeeded in part, such as a move the previous calendar didn't complete. */
  warning?: string
}
