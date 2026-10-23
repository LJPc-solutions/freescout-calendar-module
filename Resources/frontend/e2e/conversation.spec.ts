import { expect, test } from '@playwright/test'
import { dialog, fillTimes, login, seed } from './helpers'

test('adds an event from a conversation with the customer prefilled', async ({ page }) => {
  await login(page, seed.agent)
  await page.goto(seed.conversationPath)
  await page.locator('.ljpc-add-to-calendar').click()

  const modal = dialog(page)
  await expect(modal.getByLabel('Title')).toHaveValue('[E2E Agent] E2E printer is broken')
  await expect(modal.getByLabel('Customer email')).toHaveValue('e2e-customer@example.test')
  await fillTimes(page, '2026-11-19', '13:00')
  await modal.getByLabel('Kind').selectOption('Visit')
  await modal.locator('.modal-footer').getByRole('button', { name: 'Add to calendar' }).click()
  await expect(modal).toContainText('The event was added to the calendar')

  const href = await modal.locator('.modal-footer a', { hasText: 'Open in calendar' }).getAttribute('href')
  expect(href).toMatch(/#e\d+$/)
  await page.goto(href ?? '')
  await expect(page.locator('.ljpc-details')).toContainText('Visit')
  await expect(page.locator('.ljpc-details')).toContainText('Thursday, November 19, 2026, 01:00pm – 02:00pm')
  await expect(page.locator('.ljpc-details a', { hasText: '#' })).toBeVisible()
})
