/**
 * Per-browser preferences. Storage can be blocked (private mode, policies), so every access is guarded, and a value
 * that fails the optional check (edited by hand, left by an older version) gives the fallback.
 */
export function readStored<T>(key: string, fallback: T, valid: (value: unknown) => boolean = () => true): T {
  try {
    const raw = window.localStorage.getItem(key)
    if (raw === null) {
      return fallback
    }
    const value: unknown = JSON.parse(raw)

    return valid(value) ? (value as T) : fallback
  } catch {
    return fallback
  }
}

export function writeStored(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // ponytail: a preference that can't be stored is simply not remembered
  }
}
