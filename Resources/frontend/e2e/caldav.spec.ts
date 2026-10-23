import { expect, test } from '@playwright/test'
import { changeInRadicale, dialog, fillTimes, login, onGrid, openCalendar, openEventId, radicaleCalendar, save, seed, submit, titlesFromServer } from './helpers'

// Against Radicale (Tests/caldav). The seed empties its collections e2e ("E2E CalDAV", a calendar of the seeded
// account the agent may change) and e2e-connect ("E2E connect", which the administrator adds here).
test.describe('CalDAV on Radicale (browser in Tokyo)', () => {
  test('connects the account, adds a calendar, and creates, edits and deletes an event in it', async ({ page }) => {
    page.on('dialog', (question) => void question.accept())
    await login(page, seed.admin)
    await page.goto('/app-settings/calendar?tab=connections')
    const connect = page.locator('#ljpc-caldav')
    await connect.getByLabel('Server address').fill('http://radicale:5232/')
    await connect.getByLabel('User name').fill('calendar-test')
    await connect.getByLabel('Password').fill('calendar-test')
    await submit(page, connect.getByRole('button', { name: 'Connect', exact: true }))

    // The account's calendars; the seeded one is added already.
    await expect(page.locator('h3.ljpc-title')).toHaveText('Choose calendars')
    await expect(page.locator('.ljpc-pick-list li', { hasText: 'E2E CalDAV' }).getByRole('checkbox')).toBeDisabled()
    await page.locator('.ljpc-pick-list li', { hasText: 'E2E connect' }).getByRole('checkbox').check()
    await submit(page, page.getByRole('button', { name: 'Add calendars' }))
    await expect(page.locator('.alert-floating', { hasText: 'Added "E2E connect".' })).toBeVisible()
    // The list syncs a calendar just added right away and shows its status live, without the scheduler.
    await expect(page.locator('.ljpc-calendar-card', { hasText: 'E2E connect' }).locator('.ljpc-card-status')).toContainText('OK, synced')

    // A new event is written to Radicale before FreeScout stores it, at the administrator's 10:00 in Amsterdam.
    await openCalendar(page)
    await page.getByRole('button', { name: 'New event' }).click()
    await dialog(page).getByLabel('Calendar').selectOption({ label: 'E2E connect' })
    await dialog(page).getByLabel('Title').fill('E2E CalDAV event')
    await fillTimes(page, '2026-11-24', '10:00')
    await save(page)
    await expect(onGrid(page, 'E2E CalDAV event')).toContainText('10:00 – 11:00')
    let stored = await radicaleCalendar('e2e-connect')
    expect(stored).toContain('SUMMARY:E2E CalDAV event')
    expect(stored).toContain('DTSTART;TZID=Europe/Amsterdam:20261124T100000')

    await onGrid(page, 'E2E CalDAV event').click()
    const id = await openEventId(page)
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit', exact: true }).click()
    await dialog(page).getByLabel('Title').fill('E2E CalDAV event moved')
    await dialog(page).getByLabel('Start time').fill('11:30')
    await dialog(page).getByLabel('Start time').press('Tab')
    await save(page)
    await expect(onGrid(page, 'E2E CalDAV event moved')).toContainText('11:30 – 12:30')
    stored = await radicaleCalendar('e2e-connect')
    expect(stored).toContain('SUMMARY:E2E CalDAV event moved')
    expect(stored).not.toContain('SUMMARY:E2E CalDAV event')
    expect(stored).toContain('DTSTART;TZID=Europe/Amsterdam:20261124T113000')

    await onGrid(page, 'E2E CalDAV event moved').click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
    await page.locator('.ljpc-confirm').getByRole('button', { name: 'Delete' }).click()
    await expect(onGrid(page, 'E2E CalDAV event moved')).toHaveCount(0)
    expect((await radicaleCalendar('e2e-connect')).filter((line) => line.startsWith('SUMMARY:'))).toEqual([])

    // After a reload the server has nothing left either.
    await page.goto('about:blank')
    await page.goto(`/calendar#e${id}`)
    await expect(dialog(page).locator('.alert-danger')).toContainText('no longer exists')
    expect(await titlesFromServer(page, '2026-11-24', '2026-11-25')).not.toContain('E2E CalDAV event moved')
  })

  test('says the event was changed elsewhere when another app changed it first', async ({ page }) => {
    // The agent is in New York with a 12-hour clock.
    await login(page, seed.agent)
    await openCalendar(page)
    await page.getByRole('button', { name: 'New event' }).click()
    await dialog(page).getByLabel('Calendar').selectOption({ label: 'E2E CalDAV' })
    await dialog(page).getByLabel('Title').fill('E2E conflict')
    await fillTimes(page, '2026-11-25', '09:00')
    await save(page)
    await onGrid(page, 'E2E conflict').click()
    const id = await openEventId(page)

    await changeInRadicale('e2e', 'E2E conflict', (ics) => ics.replace('SUMMARY:E2E conflict', 'SUMMARY:E2E conflict from another app'))
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit', exact: true }).click()
    await dialog(page).getByLabel('Title').fill('E2E conflict edited here')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Save' }).click()
    await expect(dialog(page).locator('.alert-danger')).toHaveText('This event was changed elsewhere. Reload and try again.')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Cancel' }).click()
    expect(await radicaleCalendar('e2e')).not.toContain('SUMMARY:E2E conflict edited here')

    // The server synced the calendar before it answered, so a reload shows the other app's version at once.
    await page.goto('about:blank')
    await page.goto(`/calendar#e${id}`)
    await expect(dialog(page).locator('.modal-title')).toHaveText('E2E conflict from another app')
    await expect(page.locator('.ljpc-details')).toContainText('Wednesday, November 25, 2026, 09:00am – 10:00am')
  })
})
