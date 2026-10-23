import { expect, type Locator, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'

export interface Seed {
  password: string
  admin: string
  agent: string
  conversationPath: string
  readOnlyEventId: number
  /** The day (YYYY-MM-DD, a week from the seed) of the event in Radicale's feed collection, 10:00 to 11:00 in Amsterdam. */
  feedEventDate: string
  /** "E2E failing feed": an ICS calendar the seed set to failing for two days. */
  failingCalendarId: number
  /** "E2E invite": a customer's mail with an invite for the mailbox, on feedEventDate at 15:00 in Amsterdam. */
  inviteConversationPath: string
}

export const SEED_COMMAND = 'cd ~/Projects/freescout && ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh'

/** Written by Tests/e2e/seed.sh inside ddev. */
function readSeed(): Seed {
  let text: string
  try {
    text = readFileSync(new URL('./.seed.json', import.meta.url), 'utf8')
  } catch {
    throw new Error(`The e2e suite has no seed yet. Run: ${SEED_COMMAND}`)
  }

  return JSON.parse(text) as Seed
}

export const seed = readSeed()

/** Radicale as the host reaches it: ddev's router forwards port 5232 (Tests/caldav/docker-compose.radicale.yaml). */
const radicaleUrl = process.env.E2E_RADICALE_URL ?? 'http://freescout.ddev.site:5232'
const radicaleLogin = `Basic ${Buffer.from('calendar-test:calendar-test').toString('base64')}`

/** A request to Radicale with Node's fetch and the test account's basic auth. */
export function radicale(path: string, init: RequestInit = {}): Promise<Response> {
  return fetch(radicaleUrl + path, { ...init, headers: { Authorization: radicaleLogin, ...(init.headers as Record<string, string> | undefined) } })
}

/** The unfolded lines of an iCalendar text. */
export function icsLines(ics: string): string[] {
  return ics.replace(/\r?\n[ \t]/g, '').split(/\r?\n/)
}

/** The lines of every event of a collection: Radicale answers a GET on a calendar with all its events. */
export async function radicaleCalendar(collection: string): Promise<string[]> {
  const response = await radicale(`/calendar-test/${collection}/`)
  expect(response.status).toBe(200)

  return icsLines(await response.text())
}

/** The objects of a collection with their address and iCalendar text (a calendar-query REPORT). */
export async function radicaleObjects(collection: string): Promise<{ href: string; ics: string }[]> {
  const response = await radicale(`/calendar-test/${collection}/`, {
    method: 'REPORT',
    headers: { Depth: '1', 'Content-Type': 'application/xml; charset=utf-8' },
    body: '<?xml version="1.0" encoding="utf-8"?><C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop><D:getetag/><C:calendar-data/></D:prop><C:filter><C:comp-filter name="VCALENDAR"/></C:filter></C:calendar-query>',
  })
  expect(response.status).toBe(207)
  const unescape = (text: string) => text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#13;/g, '\r').replace(/&amp;/g, '&')

  return (await response.text()).split(/<response>/).slice(1).map((part) => ({
    href: unescape(part.match(/<href>([^<]+)<\/href>/)?.[1] ?? ''),
    ics: unescape(part.match(/<C:calendar-data>([\s\S]*?)<\/C:calendar-data>/)?.[1] ?? ''),
  }))
}

/** Changes an event in Radicale behind FreeScout's back, the way another calendar app would. */
export async function changeInRadicale(collection: string, title: string, change: (ics: string) => string): Promise<void> {
  const object = (await radicaleObjects(collection)).find((item) => icsLines(item.ics).includes(`SUMMARY:${title}`))
  if (!object) {
    throw new Error(`No "${title}" in Radicale's ${collection}`)
  }
  const response = await radicale(object.href, { method: 'PUT', headers: { 'Content-Type': 'text/calendar; charset=utf-8' }, body: change(object.ics) })
  expect([201, 204]).toContain(response.status)
}

/** The titles the server lists for the agent or administrator from start to end (dates), read fresh from the API. */
export async function titlesFromServer(page: Page, start: string, end: string): Promise<string[]> {
  const response = await page.request.get('/calendar/api/events', { params: { start, end } })
  expect(response.status()).toBe(200)

  return ((await response.json()) as { title: string }[]).map((row) => row.title).sort()
}

/** The event id of the open details, from their permalink (#e<id>, or #e<id>@<key> for an occurrence). */
export async function openEventId(page: Page): Promise<number> {
  await expect(page).toHaveURL(/#e\d+/)

  return Number((await page.evaluate(() => window.location.hash)).match(/^#e(\d+)/)?.[1])
}

/** Submits a settings form and waits for the page the server redirects to, so no check runs on the old page. */
export async function submit(page: Page, button: Locator): Promise<void> {
  const loaded = page.waitForEvent('load')
  await button.click()
  await loaded
}

export async function login(page: Page, email: string): Promise<void> {
  await page.goto('/login')
  await page.fill('#email', email)
  await page.fill('#password', seed.password)
  await page.click('button[type="submit"]')
  await expect(page).not.toHaveURL(/\/login/)
}

export async function openCalendar(page: Page): Promise<void> {
  await page.goto('/calendar')
  // The calendars sit in FreeScout's own sidebar column; on a phone that column folds its menu away.
  await expect(page.locator('.ljpc-calendar-menu')).toBeAttached()
}

/** Our open modal; FreeScout's own pages (the conversation page) hold hidden Bootstrap modals of their own. */
export function dialog(page: Page): Locator {
  return page.locator('.ljpc-modal .modal')
}

/** Sets the open form's start date and time (and end time); the inputs react on change, hence the Tab. */
export async function fillTimes(page: Page, date: string, start: string, end?: string): Promise<void> {
  const modal = dialog(page)
  await modal.locator('input[type="date"]').first().fill(date)
  await modal.locator('input[type="date"]').first().press('Tab')
  await modal.getByLabel('Start time').fill(start)
  await modal.getByLabel('Start time').press('Tab')
  if (end) {
    await modal.getByLabel('End time').fill(end)
    await modal.getByLabel('End time').press('Tab')
  }
}

export async function save(page: Page): Promise<void> {
  await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Save' }).click()
  await expect(dialog(page)).toHaveCount(0)
}

/** Creates a timed event through "New event"; the page then shows the event's week. */
export async function createEvent(page: Page, title: string, date: string, start: string): Promise<void> {
  await page.getByRole('button', { name: 'New event' }).click()
  await dialog(page).getByLabel('Title').fill(title)
  await fillTimes(page, date, start)
  await save(page)
}

/** Creates a weekly event (on the start's weekday, plus extraDay when given) that repeats a number of times. */
export async function createWeekly(page: Page, title: string, date: string, time: string, extraDay: string | null, times: number): Promise<void> {
  await page.getByRole('button', { name: 'New event' }).click()
  const modal = dialog(page)
  await modal.getByLabel('Title').fill(title)
  await fillTimes(page, date, time)
  await modal.getByLabel('Repeat', { exact: true }).selectOption('WEEKLY')
  if (extraDay) {
    await modal.locator(`.ljpc-weekdays label[title="${extraDay}"]`).click()
  }
  await modal.getByText('After', { exact: true }).click()
  await modal.getByLabel('Number of times').fill(String(times))
  await modal.getByLabel('Number of times').press('Tab')
  await save(page)
}

/** Today in a timezone plus a number of days, as YYYY-MM-DD. */
export function dateIn(timeZone: string, days = 0): string {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
  const date = new Date(`${today}T12:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)

  return date.toISOString().slice(0, 10)
}

/** A date as the details show it: "Wednesday, October 7, 2026". */
export function longDate(date: string): string {
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(`${date}T12:00:00Z`))
}

/** Events on the grid: eventDidMount gives every event element its full title. */
export function onGrid(page: Page, title: string): Locator {
  return page.locator(`.ljpc-grid [title="${title}"]`)
}

/** The date (from the week view's column headers) of each event with this title, in document order. */
async function columnDates(page: Page, title: string): Promise<string[]> {
  const columns = await page.locator('[role="columnheader"][data-date]').evaluateAll((elements) => elements.map((element) => {
    const box = element.getBoundingClientRect()

    return { date: element.getAttribute('data-date') ?? '', left: box.left, right: box.right }
  }))

  return onGrid(page, title).evaluateAll((elements, all) => elements.map((element) => {
    const box = element.getBoundingClientRect()
    const x = box.left + box.width / 2

    return all.find((column) => x >= column.left && x <= column.right)?.date ?? '?'
  }), columns)
}

/** The dates of the events with this title in the week view, sorted. */
export async function datesOf(page: Page, title: string): Promise<string[]> {
  return (await columnDates(page, title)).sort()
}

/** The event with this title on a date of the week view. */
export async function eventOn(page: Page, title: string, date: string): Promise<Locator> {
  const index = (await columnDates(page, title)).indexOf(date)
  if (index < 0) {
    throw new Error(`No "${title}" on ${date}`)
  }

  return onGrid(page, title).nth(index)
}

/** Moves the week view to the week of a date with the toolbar's previous and next buttons. */
export async function showWeekOf(page: Page, date: string): Promise<void> {
  const headers = page.locator('[role="columnheader"][data-date]')
  for (let step = 0; step < 104; step++) {
    const shown = await headers.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-date') ?? ''))
    if (shown.includes(date)) {
      return
    }
    const first = shown[0] ?? ''
    await page.getByRole('button', { name: date < first ? /prev/i : /next/i }).click()
    await expect(page.locator(`[role="columnheader"][data-date="${first}"]`)).toHaveCount(0)
  }
  throw new Error(`The week view never showed ${date}`)
}

/** The horizontal distance between two days' column headers in the week view. */
export async function daysApart(page: Page, from: string, to: string): Promise<number> {
  const a = await page.locator(`[role="columnheader"][data-date="${from}"]`).boundingBox()
  const b = await page.locator(`[role="columnheader"][data-date="${to}"]`).boundingBox()
  if (!a || !b) {
    throw new Error(`No column for ${from} or ${to}`)
  }

  return b.x - a.x
}

/** Drags in small steps, the way FullCalendar's interaction plugin needs pointer moves. */
export async function dragBy(page: Page, target: Locator, dx: number, dy: number): Promise<void> {
  // A refetch still running would replace the event element halfway through the drag.
  await expect(page.locator('.ljpc-grid')).not.toHaveClass(/is-loading/)
  const box = await target.boundingBox()
  if (!box) {
    throw new Error('Nothing to drag')
  }
  const x = box.x + Math.min(12, box.width / 2)
  const y = box.y + Math.min(6, box.height / 2)
  await page.mouse.move(x, y)
  await page.mouse.down()
  await page.mouse.move(x + dx, y + dy, { steps: 15 })
  await page.mouse.up()
}
