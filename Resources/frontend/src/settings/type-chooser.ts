/**
 * The new-calendar type chooser: ticking a type shows its form at once. Without JavaScript the server renders the form
 * of the type in the address, and "Continue" submits the ticked type; with this script that button is not needed.
 */
export function initTypeChooser(root: ParentNode): void {
  const chooser = root.querySelector<HTMLFormElement>('[data-ljpc-type-chooser]')
  if (!chooser) {
    return
  }
  chooser.querySelector<HTMLElement>('[data-ljpc-type-continue]')?.classList.add('hidden')
  chooser.addEventListener('change', (event) => {
    const radio = event.target as HTMLInputElement
    if (!radio.matches('[data-ljpc-type]')) {
      return
    }
    root.querySelectorAll<HTMLElement>('[data-ljpc-type-form]').forEach((form) => {
      form.classList.toggle('hidden', form.dataset.ljpcTypeForm !== radio.value)
    })
  })
}
