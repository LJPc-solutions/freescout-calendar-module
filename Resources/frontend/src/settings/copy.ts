/** Copy buttons for the export links: data-ljpc-copy="<input id>", data-ljpc-copied="<translated 'Copied'>". */
export function initCopyButtons(root: ParentNode): void {
  root.querySelectorAll<HTMLButtonElement>('[data-ljpc-copy]').forEach((button) => {
    // Without JavaScript the button does nothing, so the server renders it hidden.
    button.classList.remove('hidden')
    button.addEventListener('click', async () => {
      const input = document.getElementById(button.dataset.ljpcCopy ?? '') as HTMLInputElement | null
      if (!input) {
        return
      }
      try {
        await navigator.clipboard.writeText(input.value)
      } catch {
        input.select()
        return
      }
      const label = button.textContent
      button.textContent = button.dataset.ljpcCopied ?? label
      window.setTimeout(() => {
        button.textContent = label
      }, 1500)
    })
  })
}
