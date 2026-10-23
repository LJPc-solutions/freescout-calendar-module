// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest'
import { initFieldBuilder } from '../src/settings/field-builder'
import { initSelectAll } from '../src/settings/select-all'
import { initTemplateChips } from '../src/settings/template-chips'
import { initTypeChooser } from '../src/settings/type-chooser'

const row = (key: string, type = 'text') => `<tr data-ljpc-field-row><td><span class="ljpc-drag-handle"></span></td>
  <td><input type="text" name="fields[${key}][name]"></td>
  <td><select name="fields[${key}][type]" data-ljpc-field-type><option value="text"${type === 'text' ? ' selected' : ''}>text</option><option value="dropdown"${type === 'dropdown' ? ' selected' : ''}>dropdown</option></select></td>
  <td data-ljpc-field-options><textarea name="fields[${key}][options]"></textarea></td>
  <td><input type="checkbox" name="fields[${key}][remove]" value="1" data-ljpc-field-remove></td></tr>`

describe('settings enhancements', () => {
  beforeEach(() => {
    document.body.innerHTML = `<div class="ljpc-settings">
      <div data-ljpc-fields><table><tbody data-ljpc-field-rows>${row('a')}${row('b', 'dropdown')}</tbody></table>
        <template data-ljpc-field-template>${row('__KEY__')}</template><button type="button" class="hidden" data-ljpc-add-field>Add</button></div>
      <table><tr><th><input type="checkbox" class="hidden" data-ljpc-select-all="user:can_view"></th></tr>
        <tr><td><input type="checkbox" data-ljpc-right="user:can_view" checked></td></tr>
        <tr><td><input type="checkbox" data-ljpc-right="user:can_view"></td></tr></table>
      <input data-ljpc-template-input value="{{field:12}} {{title}}">
      <button type="button" data-ljpc-insert="{{title}}">Title</button><button type="button" data-ljpc-insert="{{field:12}}">Customer</button>
      <p data-ljpc-template-preview></p></div>`
  })

  it('shows options only for dropdown and multiselect fields', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    const options = document.querySelectorAll('[data-ljpc-field-options]')
    expect(options[0]?.classList.contains('hidden')).toBe(true)
    expect(options[1]?.classList.contains('hidden')).toBe(false)
  })

  it('shows the add button and adds rows with a fresh key from the template', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    expect(document.querySelector('[data-ljpc-add-field]')?.classList.contains('hidden')).toBe(false)
    ;(document.querySelector('[data-ljpc-add-field]') as HTMLButtonElement).click()
    const rows = document.querySelectorAll('[data-ljpc-field-rows] [data-ljpc-field-row]')
    expect(rows).toHaveLength(3)
    expect(rows[2]?.querySelector('input[type="text"]')?.getAttribute('name')).toMatch(/^fields\[new[a-z0-9]+\]\[name\]$/)
  })

  it('marks rows ticked for removal', () => {
    initFieldBuilder(document.querySelector('[data-ljpc-fields]') as HTMLElement)
    const remove = document.querySelector('[data-ljpc-field-remove]') as HTMLInputElement
    remove.checked = true
    remove.dispatchEvent(new Event('change', { bubbles: true }))
    expect(document.querySelector('[data-ljpc-field-row]')?.classList.contains('is-removed')).toBe(true)
  })

  it('selects a whole permission column and reflects partial selections', () => {
    initSelectAll(document)
    const header = document.querySelector('[data-ljpc-select-all]') as HTMLInputElement
    expect(header.classList.contains('hidden')).toBe(false)
    expect(header.indeterminate).toBe(true)
    header.checked = true
    header.dispatchEvent(new Event('change'))
    expect(Array.from(document.querySelectorAll<HTMLInputElement>('[data-ljpc-right]')).every((cell) => cell.checked)).toBe(true)
  })

  it('inserts placeholders and previews them with field names', () => {
    initTemplateChips(document)
    expect(document.querySelector('[data-ljpc-template-preview]')?.textContent).toBe('[Customer] [Title]')
    const input = document.querySelector('[data-ljpc-template-input]') as HTMLInputElement
    input.value = ''
    ;(document.querySelector('[data-ljpc-insert="{{title}}"]') as HTMLButtonElement).click()
    expect(input.value).toBe('{{title}}')
  })
})

describe('new calendar type chooser', () => {
  beforeEach(() => {
    document.body.innerHTML = `<div class="ljpc-settings">
      <form method="GET" data-ljpc-type-chooser>
        <input type="radio" name="type" value="local" data-ljpc-type checked>
        <input type="radio" name="type" value="ics" data-ljpc-type>
        <input type="radio" name="type" value="account" data-ljpc-type>
        <button type="submit" data-ljpc-type-continue>Continue</button>
      </form>
      <div class="ljpc-type-form" data-ljpc-type-form="local"></div>
      <div class="ljpc-type-form hidden" data-ljpc-type-form="ics"></div>
      <div class="ljpc-type-form hidden" data-ljpc-type-form="account"></div></div>`
  })

  const shown = (): string[] =>
    Array.from(document.querySelectorAll<HTMLElement>('[data-ljpc-type-form]'))
      .filter((form) => !form.classList.contains('hidden'))
      .map((form) => form.dataset.ljpcTypeForm ?? '')

  it('shows the form of the chosen type without a reload', () => {
    initTypeChooser(document)
    const ics = document.querySelector('input[value="ics"]') as HTMLInputElement
    ics.checked = true
    ics.dispatchEvent(new Event('change', { bubbles: true }))
    expect(shown()).toEqual(['ics'])
    const account = document.querySelector('input[value="account"]') as HTMLInputElement
    account.checked = true
    account.dispatchEvent(new Event('change', { bubbles: true }))
    expect(shown()).toEqual(['account'])
  })

  it('hides the continue button that submits the type without javascript', () => {
    initTypeChooser(document)
    expect(document.querySelector('[data-ljpc-type-continue]')?.classList.contains('hidden')).toBe(true)
    expect(shown()).toEqual(['local'])
  })
})
