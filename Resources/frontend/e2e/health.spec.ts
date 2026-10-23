import { expect, test } from '@playwright/test'
import { login, openCalendar, seed } from './helpers'

// The seed set "E2E failing feed" to failing for two days; the agent may view it.
const problem = 'The calendar "E2E failing feed" has not synced for more than 24 hours, so its events may be out of date.'

test('shows the administrator the failing calendar above the settings and on the calendar page', async ({ page }) => {
  await login(page, seed.admin)
  await page.goto('/app-settings/calendar')
  const link = page.locator('.ljpc-settings .ljpc-health').getByRole('link', { name: problem })
  await expect(link).toHaveAttribute('href', new RegExp(`/app-settings/calendar\\?calendar=${seed.failingCalendarId}$`))
  await link.click()
  await expect(page.locator('h3.ljpc-title')).toContainText('E2E failing feed')
  await expect(page.locator('.text-danger', { hasText: 'Last error: The feed answered with an error. (HTTP 404)' })).toBeVisible()

  await openCalendar(page)
  const banner = page.locator('.ljpc-main .ljpc-health')
  await expect(banner.getByRole('link', { name: problem })).toBeVisible()
  // Closed for this page only.
  await banner.getByRole('button', { name: 'Close' }).click()
  await expect(page.locator('.ljpc-main .ljpc-health')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.ljpc-main .ljpc-health')).toBeVisible()
})

test('tells the agent which calendar is not up to date, never why', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)
  const banner = page.locator('.ljpc-main .alert-warning')
  await expect(banner).toHaveText('Some calendars are not up to date: E2E failing feed.')
  expect(await page.content()).not.toContain('answered with an error')

  // A calendar the agent switched off no longer counts.
  await page.getByRole('switch', { name: 'E2E failing feed' }).click()
  await expect(banner).toHaveCount(0)
})
