import { createApp, h, ref } from 'vue'
import './conversation.css'
import { readConfig } from '../shared/config'
import { setStrings } from '../shared/i18n'
import AddToCalendar from './AddToCalendar.vue'
import type { ConversationConfig } from './conversation-config'

const config = readConfig<ConversationConfig>('ljpc-conversation-config')
setStrings(config.strings)

const open = ref(false)
const mount = document.createElement('div')
document.body.appendChild(mount)
createApp({ render: () => (open.value ? h(AddToCalendar, { config, onClose: () => (open.value = false) }) : null) }).mount(mount)

function isButton(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest('.ljpc-add-to-calendar') !== null
}

document.addEventListener('click', (event) => {
  if (isButton(event.target)) {
    open.value = true
  }
})
document.addEventListener('keydown', (event) => {
  if ((event.key === 'Enter' || event.key === ' ') && isButton(event.target)) {
    event.preventDefault()
    open.value = true
  }
})
