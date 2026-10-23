/** Placeholder buttons insert {{title}} or {{field:<id>}} at the cursor, and a preview shows the field names. */
export function initTemplateChips(root: ParentNode): void {
  const input = root.querySelector<HTMLInputElement>('[data-ljpc-template-input]')
  const preview = root.querySelector<HTMLElement>('[data-ljpc-template-preview]')
  if (!input) {
    return
  }
  const labels = new Map<string, string>()
  root.querySelectorAll<HTMLButtonElement>('[data-ljpc-insert]').forEach((button) => {
    const token = button.dataset.ljpcInsert ?? ''
    labels.set(token, (button.textContent ?? '').trim())
    button.addEventListener('click', () => {
      const start = input.selectionStart ?? input.value.length
      const end = input.selectionEnd ?? start
      input.value = input.value.slice(0, start) + token + input.value.slice(end)
      input.focus()
      input.setSelectionRange(start + token.length, start + token.length)
      render()
    })
  })

  function render(): void {
    if (!preview || !input) {
      return
    }
    preview.textContent = input.value.replace(/\{\{\s*(title|field:[^}\s]+)\s*\}\}/g, (match: string, name: string) => {
      const label = labels.get(`{{${name}}}`)

      return label ? `[${label}]` : match
    })
  }

  input.addEventListener('input', render)
  render()
}
