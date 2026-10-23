import { expect, test } from '@playwright/test'
import { dialog, fillTimes, login, onGrid, openCalendar, seed, showWeekOf } from './helpers'

// Invites (spec section 13): the card below a customer's mail, our answer, and the invites we send.

test('adds an invite from a customer mail, answers it and keeps a declined event struck through', async ({ page }) => {
  await login(page, seed.agent)
  await page.goto(seed.inviteConversationPath)
  const card = page.locator('.ljpc-invite')
  await expect(card).toContainText('New invite')
  await expect(card).toContainText('E2E planning call')
  await expect(card).toContainText('E2E Customer <e2e-customer@example.test>')

  await card.getByLabel('Calendar').selectOption({ label: 'E2E Support' })
  await card.getByRole('button', { name: 'Yes' }).click()
  await expect(card).toContainText('You accepted this invite.')
  await expect(card).toContainText('This version is in calendar "E2E Support".')

  // The answer went to the organizer as a reply, and both actions are line items.
  await page.reload()
  await expect(page.locator('.thread-type-message').first()).toContainText('accepted the invite "E2E planning call"')
  await expect(page.locator('.thread-type-lineitem', { hasText: 'added the invite "E2E planning call" to calendar "E2E Support"' })).toBeVisible()

  await card.getByRole('button', { name: 'No' }).click()
  await expect(card).toContainText('You declined this invite.')

  const href = await card.getByRole('link', { name: 'View event' }).getAttribute('href')
  await page.goto('about:blank')
  await page.goto(href ?? '')
  await expect(dialog(page)).toContainText('E2E planning call')
  await dialog(page).getByRole('button', { name: 'Close' }).first().click()
  await showWeekOf(page, seed.feedEventDate)
  await expect(onGrid(page, 'E2E planning call').first()).toBeVisible()
  await expect(page.locator('.ljpc-event-cancelled', { hasText: 'E2E planning call' }).first()).toBeAttached()
})

test('invites the customer from the conversation and shows the attendees in the calendar', async ({ page }) => {
  await login(page, seed.agent)
  await page.goto(seed.conversationPath)
  await page.locator('.ljpc-add-to-calendar').click()
  const modal = dialog(page)
  await modal.getByLabel('Title').fill('E2E invite sent')
  await fillTimes(page, '2026-11-24', '11:00')
  await expect(modal.getByLabel('Message with the invite')).toHaveCount(0)
  await modal.getByLabel('Invite the customer (e2e-customer@example.test)').check()
  await modal.getByLabel('Message with the invite').fill('See you then.')
  await modal.locator('.modal-footer').getByRole('button', { name: 'Add and invite' }).click()
  await expect(modal).toContainText('The invite goes to the attendees as a reply in this conversation.')

  const href = await modal.locator('.modal-footer a', { hasText: 'Open in calendar' }).getAttribute('href')
  await page.goto(href ?? '')
  const details = page.locator('.ljpc-details')
  await expect(details).toContainText('E2E Customer <e2e-customer@example.test> (no answer yet)')

  await dialog(page).getByRole('button', { name: 'Edit', exact: true }).click()
  await page.locator('#ljpc-event-form-series-attendees').fill('e2e-customer@example.test, e2e-guest@example.test')
  await expect(page.getByLabel('Notify attendees')).toBeChecked()
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.locator('.alert-success, .alert', { hasText: 'Event saved.' }).first()).toBeVisible()

  await openCalendar(page)
  await page.goto('about:blank')
  await page.goto(href ?? '')
  await expect(details).toContainText('e2e-guest@example.test (no answer yet)')
  await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
  await expect(page.getByLabel('Notify attendees')).toBeChecked()
})
