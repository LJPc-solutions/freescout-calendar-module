import { expect, test } from '@playwright/test'
import { login, openCalendar, seed } from './helpers'

test.use({ viewport: { width: 375, height: 800 } })

test('opens on the list view with the calendars folded into the sidebar menu and no sideways scrolling', async ({ page }) => {
  await login(page, seed.agent)
  await openCalendar(page)

  const menu = page.locator('.ljpc-calendar-menu')
  const toggle = page.getByRole('button', { name: 'Show calendars' })
  await expect(menu).not.toHaveClass(/active/)
  await expect(page.getByRole('tab', { name: 'List view', exact: true })).toHaveAttribute('aria-selected', 'true')
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0)

  // Like a mailbox's folders: the menu button opens the calendars, a calendar switches without closing it, Escape closes.
  await toggle.click()
  await expect(menu).toHaveClass(/active/)
  await expect(toggle).toHaveAttribute('aria-expanded', 'true')
  const support = page.getByRole('switch', { name: 'E2E Support' })
  await support.click()
  await expect(support).toHaveAttribute('aria-checked', 'false')
  await expect(menu).toHaveClass(/active/)
  await support.click()
  await page.keyboard.press('Escape')
  await expect(menu).not.toHaveClass(/active/)
  await expect(toggle).toHaveAttribute('aria-expanded', 'false')

  // "New event" lives in the bar's buttons, as a mailbox's "New conversation" does.
  await page.locator('.ljpc-sidebar .sidebar-buttons').getByRole('button', { name: 'New event' }).click()
  await expect(page.locator('.ljpc-modal .modal-title')).toHaveText('New event')
})
