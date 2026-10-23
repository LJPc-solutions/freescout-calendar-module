import { expect, test } from '@playwright/test'
import { login, seed, submit } from './helpers'

test('creates, configures and deletes a calendar', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept())
  await login(page, seed.admin)
  const name = `E2E settings ${Date.now()}`

  await page.goto('/app-settings/calendar')
  await page.getByRole('link', { name: 'New calendar' }).click()
  await page.fill('#ljpc-name', name)
  await submit(page, page.getByRole('button', { name: 'Create calendar' }))
  await expect(page.locator('h3.ljpc-title')).toContainText(name)

  // A dropdown in the empty row, a boolean through "Add field" (options hide for it).
  const rows = page.locator('[data-ljpc-field-row]')
  await rows.last().locator('input[type="text"]').fill('Kind')
  await rows.last().locator('[data-ljpc-field-type]').selectOption('dropdown')
  await rows.last().locator('textarea').fill('Call\nVisit')
  await page.getByRole('button', { name: 'Add field' }).click()
  await rows.last().locator('input[type="text"]').fill('Paid')
  await rows.last().locator('[data-ljpc-field-type]').selectOption('boolean')
  await expect(rows.last().locator('[data-ljpc-field-options]')).toBeHidden()

  await page.locator('[data-ljpc-select-all="user:can_view"]').check()
  await submit(page, page.getByRole('button', { name: 'Save calendar' }))
  const row = (field: string) => rows.filter({ has: page.locator(`input[name$="[name]"][value="${field}"]`) })
  await expect(row('Kind')).toHaveCount(1)
  await expect(row('Kind').locator('[data-ljpc-field-type]')).toHaveValue('dropdown')
  await expect(row('Kind').locator('textarea')).toHaveValue('Call\nVisit')
  await expect(row('Paid')).toHaveCount(1)
  await expect(row('Paid').locator('[data-ljpc-field-type]')).toHaveValue('boolean')
  await expect(page.locator('[data-ljpc-right="user:can_view"]')).not.toHaveCount(0)
  await expect(page.locator('[data-ljpc-right="user:can_view"]:not(:checked)')).toHaveCount(0)

  // Drag "Paid" above "Kind" by its handle, in small steps for SortableJS. The handles sit below the fold, and
  // dragTo scrolls them into view (raw mouse moves don't).
  await row('Paid').locator('.ljpc-drag-handle').dragTo(row('Kind').locator('.ljpc-drag-handle'), { steps: 15, targetPosition: { x: 6, y: 2 } })
  await expect(rows.nth(0).locator('input[type="text"]')).toHaveValue('Paid')
  await submit(page, page.getByRole('button', { name: 'Save calendar' }))
  await expect(rows.nth(0).locator('input[type="text"]')).toHaveValue('Paid')
  await expect(rows.nth(1).locator('input[type="text"]')).toHaveValue('Kind')

  const before = await page.locator('#ljpc-export-full').inputValue()
  await submit(page, page.locator('.ljpc-new-link').first())
  await expect(page.locator('#ljpc-export-full')).not.toHaveValue(before)

  await submit(page, page.getByRole('button', { name: 'Delete calendar' }))
  await expect(page.getByRole('link', { name: 'New calendar' })).toBeVisible()
  await expect(page.getByRole('link', { name })).toHaveCount(0)
})
