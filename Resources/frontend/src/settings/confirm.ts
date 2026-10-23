/** Destructive forms carry data-ljpc-confirm="<translated question>"; the browser's confirm dialog guards them. */
export function initConfirm(root: ParentNode): void {
  root.querySelectorAll<HTMLFormElement>('form[data-ljpc-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.ljpcConfirm ?? '')) {
        event.preventDefault()
      }
    })
  })
}
