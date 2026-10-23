import type { LocaleInput } from '@fullcalendar/vue3'

// One small chunk per language FreeScout offers; English is built into FullCalendar.
const loaders: Record<string, () => Promise<{ default: LocaleInput }>> = {
  ar: () => import('@fullcalendar/vue3/locales/ar'),
  az: () => import('@fullcalendar/vue3/locales/az'),
  ca: () => import('@fullcalendar/vue3/locales/ca'),
  cs: () => import('@fullcalendar/vue3/locales/cs'),
  da: () => import('@fullcalendar/vue3/locales/da'),
  de: () => import('@fullcalendar/vue3/locales/de'),
  es: () => import('@fullcalendar/vue3/locales/es'),
  fa: () => import('@fullcalendar/vue3/locales/fa'),
  fi: () => import('@fullcalendar/vue3/locales/fi'),
  fr: () => import('@fullcalendar/vue3/locales/fr'),
  he: () => import('@fullcalendar/vue3/locales/he'),
  hr: () => import('@fullcalendar/vue3/locales/hr'),
  hu: () => import('@fullcalendar/vue3/locales/hu'),
  it: () => import('@fullcalendar/vue3/locales/it'),
  ja: () => import('@fullcalendar/vue3/locales/ja'),
  kk: () => import('@fullcalendar/vue3/locales/kk'),
  ko: () => import('@fullcalendar/vue3/locales/ko'),
  nb: () => import('@fullcalendar/vue3/locales/nb'),
  nl: () => import('@fullcalendar/vue3/locales/nl'),
  pl: () => import('@fullcalendar/vue3/locales/pl'),
  pt: () => import('@fullcalendar/vue3/locales/pt'),
  'pt-br': () => import('@fullcalendar/vue3/locales/pt-br'),
  ro: () => import('@fullcalendar/vue3/locales/ro'),
  ru: () => import('@fullcalendar/vue3/locales/ru'),
  sk: () => import('@fullcalendar/vue3/locales/sk'),
  sl: () => import('@fullcalendar/vue3/locales/sl'),
  sv: () => import('@fullcalendar/vue3/locales/sv'),
  tr: () => import('@fullcalendar/vue3/locales/tr'),
  uk: () => import('@fullcalendar/vue3/locales/uk'),
  'zh-cn': () => import('@fullcalendar/vue3/locales/zh-cn'),
  'zh-tw': () => import('@fullcalendar/vue3/locales/zh-tw'),
}

// FreeScout codes that FullCalendar names differently.
const ALIASES: Record<string, string> = { kz: 'kk', no: 'nb', 'pt-pt': 'pt' }

/** The FullCalendar locale for a FreeScout locale, or null for FullCalendar's built-in English. */
export function localeCode(freescoutLocale: string): string | null {
  const code = freescoutLocale.toLowerCase()
  const mapped = ALIASES[code] ?? code
  if (mapped in loaders) {
    return mapped
  }
  const base = mapped.split('-')[0] ?? ''

  return base in loaders ? base : null
}

export async function loadLocale(freescoutLocale: string): Promise<LocaleInput | null> {
  const code = localeCode(freescoutLocale)
  const loader = code ? loaders[code] : undefined

  return loader ? (await loader()).default : null
}
