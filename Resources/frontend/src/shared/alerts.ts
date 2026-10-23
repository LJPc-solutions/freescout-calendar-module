/**
 * Uses FreeScout's own floating alerts (main.js escapes the message), so notices look like the rest of the app. Core
 * knows only success and error alerts; a warning shows as an error that stays until the agent closes it.
 */
export function notify(type: 'success' | 'error' | 'warning', message: string): void {
  if (typeof window.showFloatingAlert === 'function') {
    if (type === 'warning') {
      window.showFloatingAlert('error', message, true)
    } else {
      window.showFloatingAlert(type, message)
    }
  } else {
    window.alert(message)
  }
}
