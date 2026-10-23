import './settings.css'
import { initConfirm } from './confirm'
import { initCopyButtons } from './copy'
import { initFieldBuilder } from './field-builder'
import { initSelectAll } from './select-all'
import { initSyncNow } from './sync-now'
import { initTemplateChips } from './template-chips'
import { initTypeChooser } from './type-chooser'

// Module scripts run after the document is parsed, so the server-rendered settings are in place.
const root = document.querySelector<HTMLElement>('.ljpc-settings')
if (root) {
  root.querySelectorAll<HTMLElement>('[data-ljpc-fields]').forEach(initFieldBuilder)
  initSelectAll(root)
  initTemplateChips(root)
  initCopyButtons(root)
  initConfirm(root)
  initTypeChooser(root)
  initSyncNow(root)
}
