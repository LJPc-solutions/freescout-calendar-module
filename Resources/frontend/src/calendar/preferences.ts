/** The calendar's views, in toolbar order. */
export const VIEWS = ['timeGridDay', 'timeGridWeek', 'dayGridMonth', 'multiMonthYear', 'listWeek']

/** Hidden calendars are stored as a list of calendar ids. */
export function isIdList(value: unknown): value is number[] {
  return Array.isArray(value) && value.every((id) => Number.isInteger(id))
}

/** A stored view counts only while the toolbar still offers it. */
export function isView(value: unknown): value is string {
  return typeof value === 'string' && VIEWS.includes(value)
}
