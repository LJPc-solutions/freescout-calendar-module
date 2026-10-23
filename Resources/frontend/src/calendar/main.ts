import { createApp } from 'vue'
import '@fullcalendar/vue3/skeleton.css'
import '@fullcalendar/vue3/themes/classic/theme.css'
import '@fullcalendar/vue3/themes/classic/palette.css'
import './calendar.css'
import { readConfig } from '../shared/config'
import { setStrings } from '../shared/i18n'
import App from './App.vue'
import { loadLocale } from './locales'
import type { PageConfig } from './page-config'

const config = readConfig<PageConfig>('ljpc-calendar-config')
setStrings(config.strings)
// A locale chunk that fails to load (blocked, offline) falls back to FullCalendar's English instead of a blank page.
const locale = await loadLocale(config.locale).catch((error: unknown) => {
  console.error(error)

  return null
})
createApp(App, { config, locale }).mount('#ljpc-calendar-app')
