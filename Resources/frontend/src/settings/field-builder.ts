import Sortable from 'sortablejs'

const WITH_OPTIONS = ['dropdown', 'multiselect']

/** Shows the options box only for dropdown and multiselect fields (it stays visible without JavaScript). */
function syncOptions(row: HTMLElement): void {
  const type = row.querySelector<HTMLSelectElement>('[data-ljpc-field-type]')?.value ?? ''
  row.querySelector<HTMLElement>('[data-ljpc-field-options]')?.classList.toggle('hidden', !WITH_OPTIONS.includes(type))
}

function syncRemoved(row: HTMLElement): void {
  const removed = row.querySelector<HTMLInputElement>('[data-ljpc-field-remove]')?.checked ?? false
  row.classList.toggle('is-removed', removed)
}

function newKey(): string {
  return `new${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

/**
 * Enhances the server-rendered custom field table: add rows from the template, drag rows to reorder (the form
 * submits rows in document order), hide options for types without them, and mark rows ticked for removal.
 */
export function initFieldBuilder(root: HTMLElement): void {
  const rows = root.querySelector<HTMLElement>('[data-ljpc-field-rows]')
  const template = root.querySelector<HTMLTemplateElement>('template[data-ljpc-field-template]')
  if (!rows || !template) {
    return
  }

  rows.querySelectorAll<HTMLElement>('[data-ljpc-field-row]').forEach((row) => {
    syncOptions(row)
    syncRemoved(row)
  })
  rows.addEventListener('change', (event) => {
    const row = (event.target as Element).closest<HTMLElement>('[data-ljpc-field-row]')
    if (row) {
      syncOptions(row)
      syncRemoved(row)
    }
  })

  const add = root.querySelector<HTMLButtonElement>('[data-ljpc-add-field]')
  // Without JavaScript the server's empty row adds a field; the button only makes sense with this script.
  add?.classList.remove('hidden')
  add?.addEventListener('click', () => {
    const html = template.innerHTML.split('__KEY__').join(newKey())
    rows.insertAdjacentHTML('beforeend', html)
    const row = rows.lastElementChild as HTMLElement
    syncOptions(row)
    row.querySelector<HTMLInputElement>('input[type="text"]')?.focus()
  })

  Sortable.create(rows, { handle: '.ljpc-drag-handle', animation: 150, draggable: '[data-ljpc-field-row]' })
}
