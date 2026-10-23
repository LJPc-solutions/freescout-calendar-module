import { expect, test } from '@playwright/test'
import { datesOf, dialog, login, onGrid, openCalendar, seed, showWeekOf, submit } from './helpers'

// The feed is Radicale's export of the collection calendar-test/e2e-feed, which the seed fills with one event a week
// ahead at 10:00 in Amsterdam; the administrator's profile is in Amsterdam with a 24-hour clock.
test('subscribes to an ICS feed in the settings and shows its events read-only', async ({ page }) => {
  await login(page, seed.admin)
  await page.goto('/app-settings/calendar')
  await page.getByRole('link', { name: 'New calendar' }).click()
  await page.getByRole('radio', { name: /ICS feed/ }).check()
  const form = page.locator('[data-ljpc-type-form="ics"]')
  await form.getByLabel('Name').fill('E2E feed')
  await form.getByLabel('Calendar link').fill('http://radicale:5232/calendar-test/e2e-feed/')
  await submit(page, form.getByRole('button', { name: 'Create calendar' }))
  await expect(page.locator('h3.ljpc-title')).toContainText('E2E feed')

  // The new feed is read right away from the page, which shows its status live.
  const status = page.locator('#ljpc-sync ~ .form-group', { hasText: 'Status' }).locator('[data-ljpc-sync-status]')
  await expect(status).toContainText('OK, synced')
  await expect(page.locator('.alert-floating', { hasText: 'Synced: 1 new, 0 changed, 0 removed.' })).toBeVisible()

  // "Sync now" right after it leaves the feed alone and says so; it never waits for the scheduler.
  await submit(page, page.getByRole('button', { name: 'Sync now' }))
  await expect(page.locator('.alert-floating', { hasText: 'This calendar synced moments ago.' })).toBeVisible()

  await openCalendar(page)
  await showWeekOf(page, seed.feedEventDate)
  await expect.poll(() => datesOf(page, 'E2E feed event')).toEqual([seed.feedEventDate])
  await expect(onGrid(page, 'E2E feed event')).toContainText('10:00 – 11:00')
  await onGrid(page, 'E2E feed event').click()
  await expect(dialog(page).locator('.modal-title')).toHaveText('E2E feed event')
  await expect(dialog(page).locator('.modal-footer').getByRole('button', { name: /Edit/ })).toHaveCount(0)
  await expect(dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' })).toHaveCount(0)
})

test('syncs the external calendars from the calendar page', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)
  const sync = page.locator('.ljpc-sidebar .sidebar-buttons').getByRole('button', { name: 'Sync now' })
  await sync.click()
  // The agent sees the seeded CalDAV calendar and the failing feed; the feed's error stays with the administrator.
  await expect(page.locator('.alert-floating', { hasText: 'Not synced: E2E failing feed.' })).toBeVisible()
  await expect(page.locator('.alert-floating')).not.toContainText('answered with an error')
  await expect(sync).not.toHaveAttribute('aria-busy', 'true')
})
