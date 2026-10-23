import { expect, test } from '@playwright/test'
import { createEvent, createWeekly, dateIn, datesOf, dialog, login, longDate, onGrid, openCalendar, seed } from './helpers'

test('opens events from permalinks and explains unknown ones', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)
  await createEvent(page, 'E2E permalink', '2026-11-18', '15:00')
  await onGrid(page, 'E2E permalink').click()
  // The details write the permalink once the event has loaded.
  await expect(page).toHaveURL(/#e\d+$/)
  const hash = await page.evaluate(() => window.location.hash)

  // A fresh load of the permalink, on a calendar that opens on the current week.
  await page.goto('about:blank')
  await page.goto(`/calendar${hash}`)
  await expect(dialog(page).locator('.modal-title')).toHaveText('E2E permalink')
  await expect(page.locator('.ljpc-details')).toContainText('Wednesday, November 18, 2026, 03:00pm – 04:00pm')
  await expect(onGrid(page, 'E2E permalink')).toBeVisible()

  await page.goto('/calendar#e999999999')
  await expect(dialog(page).locator('.alert-danger')).toContainText('no longer exists')

  // A link to an occurrence the event doesn't have (any more) opens the event itself, and says why.
  await page.goto('about:blank')
  await page.goto(`/calendar${hash}@20261119T200000Z`)
  await expect(page.locator('.alert', { hasText: 'This occurrence no longer exists, so the event itself is shown.' }).first()).toBeVisible()
  await expect(dialog(page).locator('.modal-title')).toHaveText('E2E permalink')
  await expect(page).toHaveURL(new RegExp(`${hash}$`))
})

test('opens a weekly event from the dashboard card on that occurrence and its week', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)
  // In the agent's zone the series began 13 days ago, so the card's next 7 days hold only tomorrow's occurrence.
  const tomorrow = dateIn('America/New_York', 1)
  await createWeekly(page, 'E2E dashboard weekly', dateIn('America/New_York', -13), '10:00', null, 4)

  await page.goto('/')
  const link = page.locator('.ljpc-dash-title', { hasText: 'E2E dashboard weekly' })
  await expect(link).toHaveAttribute('href', /\/calendar#e\d+@\d{8}T\d{6}Z$/)
  await link.click()

  await expect(dialog(page).locator('.modal-title')).toHaveText('E2E dashboard weekly')
  await expect(page.locator('.ljpc-details')).toContainText(`${longDate(tomorrow)}, 10:00am – 11:00am`)
  await expect(page).toHaveURL(/#e\d+@\d{8}T\d{6}Z$/)
  // The calendar shows the occurrence's week, not the series' first one.
  await expect.poll(() => datesOf(page, 'E2E dashboard weekly')).toEqual([tomorrow])
  await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
  await expect(page.locator('.ljpc-confirm')).toContainText('Delete only this occurrence, or the whole series?')

  // The series' own permalink deletes the whole series, and says so.
  const series = (await page.evaluate(() => window.location.hash)).replace(/@.*$/, '')
  await page.goto('about:blank')
  await page.goto(`/calendar${series}`)
  await expect(dialog(page).locator('.modal-title')).toHaveText('E2E dashboard weekly')
  await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
  await expect(page.locator('.ljpc-confirm')).toContainText('Delete the whole series?')
  await expect(page.locator('.ljpc-confirm').getByRole('button')).toHaveText(['Cancel', 'Delete'])
})
