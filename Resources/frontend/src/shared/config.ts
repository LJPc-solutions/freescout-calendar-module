/** Reads the server config from a non-executed JSON script tag (the server never prints Blade inside JavaScript). */
export function readConfig<T>(id: string): T {
  const element = document.getElementById(id)
  if (!element || !element.textContent) {
    throw new Error(`Missing config element #${id}`)
  }

  return JSON.parse(element.textContent) as T
}

/** Fields every bundle receives (see Http/FrontendConfig.php). */
export interface BaseConfig {
  locale: string
  rtl: boolean
  timezone: string
  hour12: boolean
  csrf: string
  strings: Record<string, string>
}
