/** "Select all" per permission column: a header checkbox data-ljpc-select-all="<group>:<right>" drives the column. */
export function initSelectAll(root: ParentNode): void {
  root.querySelectorAll<HTMLInputElement>('[data-ljpc-select-all]').forEach((header) => {
    const column = header.dataset.ljpcSelectAll ?? ''
    const cells = (): HTMLInputElement[] => Array.from(root.querySelectorAll<HTMLInputElement>(`[data-ljpc-right="${column}"]`))
    const sync = (): void => {
      const all = cells()
      const checked = all.filter((cell) => cell.checked).length
      header.checked = all.length > 0 && checked === all.length
      header.indeterminate = checked > 0 && checked < all.length
    }

    header.classList.remove('hidden')
    header.addEventListener('change', () => {
      cells().forEach((cell) => {
        cell.checked = header.checked
      })
    })
    cells().forEach((cell) => cell.addEventListener('change', sync))
    sync()
  })
}
