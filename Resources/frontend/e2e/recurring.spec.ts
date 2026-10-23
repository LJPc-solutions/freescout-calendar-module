import { expect, test } from '@playwright/test'
import { createWeekly, datesOf, daysApart, dialog, dragBy, eventOn, login, onGrid, openCalendar, save, seed, showWeekOf, titlesFromServer } from './helpers'

test.describe('repeating events (agent in New York, browser in Tokyo)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, seed.agent)
    await openCalendar(page)
  })

  test('edits one occurrence or the whole series and keeps 10:00 across the US clock change', async ({ page }) => {
    // Mondays and Wednesdays from 26 October; the US leaves daylight saving time on 1 November 2026.
    await createWeekly(page, 'E2E weekly', '2026-10-26', '10:00', 'Wednesday', 4)
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-10-26', '2026-10-28'])
    await expect(onGrid(page, 'E2E weekly')).toContainText(['10:00am – 11:00am', '10:00am – 11:00am'])

    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-11-02', '2026-11-04'])
    await expect(onGrid(page, 'E2E weekly')).toContainText(['10:00am – 11:00am', '10:00am – 11:00am'])

    await (await eventOn(page, 'E2E weekly', '2026-11-04')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit this occurrence' }).click()
    await dialog(page).getByLabel('Title').fill('E2E weekly special')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])
    await expect.poll(() => datesOf(page, 'E2E weekly')).toEqual(['2026-11-02'])
    // Its form starts from its own title, not the series' title.
    await (await eventOn(page, 'E2E weekly special', '2026-11-04')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit this occurrence' }).click()
    await expect(dialog(page).getByLabel('Title')).toHaveValue('E2E weekly special')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog(page)).toHaveCount(0)

    await (await eventOn(page, 'E2E weekly', '2026-11-02')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit series' }).click()
    await dialog(page).getByLabel('Title').fill('E2E weekly renamed')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E weekly renamed')).toEqual(['2026-11-02'])
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])

    await (await eventOn(page, 'E2E weekly renamed', '2026-11-02')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Delete' }).click()
    await page.locator('.ljpc-confirm').getByRole('button', { name: 'This occurrence' }).click()
    await expect.poll(() => datesOf(page, 'E2E weekly renamed')).toEqual([])
    await expect.poll(() => datesOf(page, 'E2E weekly special')).toEqual(['2026-11-04'])

    // After a reload the server lists only the edited occurrence of this series in those days (other specs' events
    // may fall on them too).
    await page.reload()
    expect((await titlesFromServer(page, '2026-11-02', '2026-11-05')).filter((title) => title.startsWith('E2E weekly'))).toEqual(['E2E weekly special'])
  })

  test('dragging a series moves its weekday along', async ({ page }) => {
    await createWeekly(page, 'E2E series drag', '2026-11-23', '09:00', null, 3)
    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-11-23'])

    await dragBy(page, await eventOn(page, 'E2E series drag', '2026-11-23'), await daysApart(page, '2026-11-23', '2026-11-24'), 0)
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Whole series' }).click()

    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-11-24'])
    await expect(onGrid(page, 'E2E series drag')).toContainText('09:00am – 10:00am')
    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E series drag')).toEqual(['2026-12-01'])
  })

  test('asks before a whole-series drag resets an edited occurrence', async ({ page }) => {
    // Mondays and Wednesdays from 7 December; Wednesday's occurrence gets a title of its own.
    await createWeekly(page, 'E2E reset drag', '2026-12-07', '09:00', 'Wednesday', 4)
    await expect.poll(() => datesOf(page, 'E2E reset drag')).toEqual(['2026-12-07', '2026-12-09'])
    await (await eventOn(page, 'E2E reset drag', '2026-12-09')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit this occurrence' }).click()
    await dialog(page).getByLabel('Title').fill('E2E reset drag special')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E reset drag special')).toEqual(['2026-12-09'])
    const oneDay = await daysApart(page, '2026-12-07', '2026-12-08')

    // Cancel puts the event back, and the edited occurrence keeps its title.
    await dragBy(page, await eventOn(page, 'E2E reset drag', '2026-12-07'), oneDay, 0)
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Whole series' }).click()
    await expect(dialog(page).locator('.modal-body')).toHaveText('Edited and deleted occurrences of this series will be reset. Continue?')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog(page)).toHaveCount(0)
    await expect.poll(() => datesOf(page, 'E2E reset drag')).toEqual(['2026-12-07'])
    await expect.poll(() => datesOf(page, 'E2E reset drag special')).toEqual(['2026-12-09'])

    // Continue moves the series a day (Tuesdays and Thursdays), and the edited occurrence is a plain one again.
    const confirmed = page.waitForResponse((response) => response.request().method() === 'PATCH' && (response.request().postData() ?? '').includes('"drop_exceptions":true'))
    await dragBy(page, await eventOn(page, 'E2E reset drag', '2026-12-07'), oneDay, 0)
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Whole series' }).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Continue' }).click()
    expect((await confirmed).status()).toBe(200)
    await expect.poll(() => datesOf(page, 'E2E reset drag')).toEqual(['2026-12-08', '2026-12-10'])
    await expect.poll(() => datesOf(page, 'E2E reset drag special')).toEqual([])
  })

  test('duplicates one occurrence as a single event, or the whole series from that occurrence on', async ({ page }) => {
    // Mondays and Wednesdays from 21 December, six times: up to Wednesday 6 January.
    await createWeekly(page, 'E2E duplicate', '2026-12-21', '11:00', 'Wednesday', 6)
    await page.getByRole('button', { name: /next/i }).click()
    await (await eventOn(page, 'E2E duplicate', '2026-12-28')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Duplicate' }).click()
    await expect(dialog(page).locator('.modal-body')).toHaveText('Duplicate only this occurrence or the whole series?')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'This occurrence' }).click()

    const modal = dialog(page)
    await expect(modal.locator('.modal-title')).toHaveText('New event')
    await expect(modal.getByLabel('Repeat', { exact: true })).toHaveValue('')
    await modal.getByLabel('Title').fill('E2E duplicate copy')
    await save(page)

    await expect.poll(() => datesOf(page, 'E2E duplicate copy')).toEqual(['2026-12-28'])
    await expect.poll(() => datesOf(page, 'E2E duplicate')).toEqual(['2026-12-28', '2026-12-30'])
    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E duplicate')).toEqual(['2027-01-04', '2027-01-06'])
    await expect(onGrid(page, 'E2E duplicate copy')).toHaveCount(0)

    // "Whole series" from Wednesday 6 January repeats the same way from there: Mondays and Wednesdays, six times.
    await (await eventOn(page, 'E2E duplicate', '2027-01-06')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Duplicate' }).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Whole series' }).click()
    await expect(modal.locator('.modal-title')).toHaveText('New event')
    await expect(modal.locator('input[type="date"]').first()).toHaveValue('2027-01-06')
    await expect(modal.getByLabel('Start time')).toHaveValue('11:00')
    await expect(modal.getByLabel('Repeat', { exact: true })).toHaveValue('WEEKLY')
    await expect(modal.locator('.ljpc-weekdays label.active')).toHaveCount(2)
    await expect(modal.locator('.ljpc-weekdays label[title="Monday"] input')).toBeChecked()
    await expect(modal.locator('.ljpc-weekdays label[title="Wednesday"] input')).toBeChecked()
    await expect(modal.getByLabel('Number of times')).toHaveValue('6')
    await modal.getByLabel('Title').fill('E2E duplicate series')
    await save(page)

    await expect.poll(() => datesOf(page, 'E2E duplicate series')).toEqual(['2027-01-06'])
    await page.getByRole('button', { name: /next/i }).click()
    await expect.poll(() => datesOf(page, 'E2E duplicate series')).toEqual(['2027-01-11', '2027-01-13'])
  })

  test('copies a series with a rule the form can not show without that rule', async ({ page }) => {
    // The seed's "E2E custom rule": the second Tuesday of November, December and January, 10:00 in New York.
    await showWeekOf(page, '2026-12-08')
    await (await eventOn(page, 'E2E custom rule', '2026-12-08')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Duplicate' }).click()
    // No question: a new event can't repeat by such a rule, so only the occurrence is copied.
    const modal = dialog(page)
    await expect(modal.locator('.modal-title')).toHaveText('New event')
    await expect(modal.locator('input[type="date"]').first()).toHaveValue('2026-12-08')
    await expect(modal.getByLabel('Start time')).toHaveValue('10:00')
    await expect(modal.getByLabel('Repeat', { exact: true })).toHaveValue('')
    await modal.locator('.modal-footer').getByRole('button', { name: 'Cancel' }).click()

    // The series opened as a whole copies from its first start, and the new event drops the rule too.
    await (await eventOn(page, 'E2E custom rule', '2026-12-08')).click()
    await expect(page).toHaveURL(/#e\d+@\d{8}T\d{6}Z$/)
    const series = (await page.evaluate(() => window.location.hash)).replace(/@.*$/, '')
    await page.goto('about:blank')
    await page.goto(`/calendar${series}`)
    await expect(dialog(page).locator('.modal-title')).toHaveText('E2E custom rule')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Duplicate' }).click()
    await expect(modal.locator('.modal-title')).toHaveText('New event')
    await expect(modal.locator('input[type="date"]').first()).toHaveValue('2026-11-10')
    await expect(modal.getByLabel('Repeat', { exact: true })).toHaveValue('')
  })

  test('asks inside the series form before a new start resets an edited occurrence', async ({ page }) => {
    await createWeekly(page, 'E2E reset form', '2026-12-14', '09:00', 'Wednesday', 4)
    await (await eventOn(page, 'E2E reset form', '2026-12-16')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit this occurrence' }).click()
    await dialog(page).getByLabel('Title').fill('E2E reset form special')
    await save(page)
    await expect.poll(() => datesOf(page, 'E2E reset form special')).toEqual(['2026-12-16'])

    await (await eventOn(page, 'E2E reset form', '2026-12-14')).click()
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Edit series' }).click()
    await dialog(page).getByLabel('Start time').fill('10:00')
    await dialog(page).getByLabel('Start time').press('Tab')
    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Save' }).click()
    const question = dialog(page).locator('.ljpc-confirm')
    await expect(question).toContainText('Edited and deleted occurrences of this series will be reset. Continue?')

    // Cancel keeps the form open with the agent's input.
    await question.getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog(page).getByLabel('Start time')).toHaveValue('10:00')
    await expect(dialog(page).locator('.ljpc-confirm')).toHaveCount(0)

    await dialog(page).locator('.modal-footer').getByRole('button', { name: 'Save' }).click()
    await question.getByRole('button', { name: 'Continue' }).click()
    await expect(dialog(page)).toHaveCount(0)
    await expect.poll(() => datesOf(page, 'E2E reset form')).toEqual(['2026-12-14', '2026-12-16'])
    await expect.poll(() => datesOf(page, 'E2E reset form special')).toEqual([])
  })
})
