import { expect, test } from '@playwright/test'
import { createEvent, daysApart, dialog, dragBy, login, onGrid, openCalendar, openEventId, save, seed } from './helpers'

// Times as the agent's 12-hour clock shows them, in FreeScout core's style ("h:ia"): "10:00am – 11:00am" on the event
// chips and in the details. Each expectation pins the start's hour, minutes and am/pm (and the end's), so a shift of
// any hour fails.
test.describe('calendar page (agent in New York, browser in Tokyo, 12-hour clock)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, seed.agent)
    await openCalendar(page)
  })

  test('shows every view without errors', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (error) => errors.push(error.message))
    for (const view of ['Day', 'Month', 'Year', 'List', 'Week']) {
      const tab = page.getByRole('tab', { name: `${view} view`, exact: true })
      await tab.click()
      await expect(tab).toHaveAttribute('aria-selected', 'true')
      await expect(page.locator('.ljpc-grid')).toBeVisible()
    }
    // The week view's time axis is on the agent's clock too.
    await expect(page.locator('.ljpc-grid [data-time="14:00:00"]').filter({ hasText: /\S/ })).toHaveText('02:00pm')
    expect(errors).toEqual([])
  })

  test('creates an event at the profile time, then edits and deletes it', async ({ page }) => {
    await createEvent(page, 'E2E standup', '2026-11-16', '10:00')
    await expect(onGrid(page, 'E2E standup')).toContainText('10:00am – 11:00am')

    await onGrid(page, 'E2E standup').click()
    await expect(page.locator('.ljpc-details')).toContainText('Monday, November 16, 2026, 10:00am – 11:00am')
    await expect(page).toHaveURL(/#e\d+$/)
    const id = await openEventId(page)

    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit', exact: true }).click()
    await dialog(page).getByLabel('Title').fill('E2E standup moved')
    await dialog(page).getByLabel('Start time').fill('11:30')
    await dialog(page).getByLabel('Start time').press('Tab')
    await save(page)
    await expect(onGrid(page, 'E2E standup moved')).toContainText('11:30am – 12:30pm')

    await onGrid(page, 'E2E standup moved').click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
    await page.locator('.ljpc-confirm').getByRole('button', { name: 'Delete' }).click()
    await expect(onGrid(page, 'E2E standup moved')).toHaveCount(0)

    // After a reload the server has nothing left either.
    await page.goto('about:blank')
    await page.goto(`/calendar#e${id}`)
    await expect(dialog(page).locator('.alert-danger')).toContainText('no longer exists')
  })

  test('moves an event by dragging it to another day', async ({ page }) => {
    await createEvent(page, 'E2E drag', '2026-11-17', '14:00')

    // The details load from the server, so they show the move only once its PATCH has been answered.
    const saved = page.waitForResponse((response) => response.request().method() === 'PATCH' && /\/calendar\/api\/events\/\d+$/.test(response.url()))
    await dragBy(page, onGrid(page, 'E2E drag'), await daysApart(page, '2026-11-17', '2026-11-19'), 0)
    expect((await saved).status()).toBe(200)

    await expect(onGrid(page, 'E2E drag')).toBeVisible()
    await onGrid(page, 'E2E drag').click()
    await expect(page.locator('.ljpc-details')).toContainText('Thursday, November 19, 2026, 02:00pm – 03:00pm')
  })

  test('shows a read-only calendar without edit actions', async ({ page }) => {
    await page.goto(`/calendar#e${seed.readOnlyEventId}`)
    await expect(dialog(page).locator('.modal-title')).toHaveText('E2E fixed')
    // The admin typed 12:00 in Amsterdam; New York is six hours behind once the US clocks went back.
    await expect(page.locator('.ljpc-details')).toContainText('Friday, November 20, 2026, 06:00am – 07:00am')
    await expect(dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit' })).toHaveCount(0)
    await expect(dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' })).toHaveCount(0)
  })
})
