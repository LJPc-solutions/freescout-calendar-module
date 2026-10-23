import { t } from './i18n'

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>
  /** The response body's machine-readable reason, such as "drops_exceptions" on a 409; null when it has none. */
  readonly code: string | null

  constructor(status: number, message: string, errors: Record<string, string[]> = {}, code: string | null = null) {
    super(message)
    this.status = status
    this.errors = errors
    this.code = code
  }

  /** First validation message per field, keyed like the server ("title", "recurrence.until", "field_values.12"). */
  fieldErrors(): Record<string, string> {
    const result: Record<string, string> = {}
    for (const [key, messages] of Object.entries(this.errors)) {
      if (messages[0]) {
        result[key] = messages[0]
      }
    }

    return result
  }
}

export interface Api {
  get<T>(url: string, query?: Record<string, string>): Promise<T>
  post<T>(url: string, body: unknown): Promise<T>
  patch<T>(url: string, body: unknown): Promise<T>
  delete<T>(url: string, body?: unknown): Promise<T>
}

interface ErrorBody {
  message?: string
  errors?: Record<string, string[]>
  code?: unknown
}

export function createApi(csrf: string): Api {
  async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    let response: Response
    try {
      response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      })
    } catch {
      throw new ApiError(0, t('The server could not be reached. Check your connection and try again.'))
    }
    const data = (await response.json().catch(() => null)) as unknown
    if (!response.ok) {
      const error = (data ?? {}) as ErrorBody
      throw new ApiError(response.status, messageFor(response.status, error), error.errors ?? {}, typeof error.code === 'string' ? error.code : null)
    }
    // Every endpoint answers with JSON; anything else (an HTML page, an empty body) would break the caller.
    if (data === null && response.status !== 204) {
      throw new ApiError(response.status, t('Something went wrong. Try again.'))
    }

    return data as T
  }

  return {
    get: (url, query) => request('GET', query ? `${url}?${new URLSearchParams(query).toString()}` : url),
    post: (url, body) => request('POST', url, body),
    patch: (url, body) => request('PATCH', url, body),
    delete: (url, body) => request('DELETE', url, body),
  }
}

function messageFor(status: number, body: ErrorBody): string {
  switch (status) {
    case 422: {
      const first = Object.values(body.errors ?? {})[0]?.[0]

      return first ?? body.message ?? t('Check the highlighted fields.')
    }
    // A refusal with a reason of its own (a series that changes one occurrence at a time) says that reason.
    case 403:
      return typeof body.code === 'string' && body.message ? body.message : t('You don\'t have permission to do this.')
    case 404:
      return t('This event or calendar no longer exists, or you don\'t have access to it.')
    // An expired session: the auth middleware answers 401, a CSRF check (on a write) 419.
    case 401:
    case 419:
      return t('Your session has expired. Reload the page and try again.')
    case 409:
    case 502:
    case 503:
      return body.message ?? t('Something went wrong. Try again.')
    default:
      return t('Something went wrong. Try again.')
  }
}

/**
 * Whether the server asks to confirm that a whole-series change resets the series' edited and deleted occurrences
 * (409 "drops_exceptions"). Any other 409, such as "changed_elsewhere" from a calendar provider, is an error.
 */
export function asksToDropExceptions(caught: unknown): caught is ApiError {
  return caught instanceof ApiError && caught.status === 409 && caught.code === 'drops_exceptions'
}

/** Fills the "__ID__" placeholder of a route from the config. */
export function routeWith(route: string, id: string | number): string {
  return route.replace('__ID__', encodeURIComponent(String(id)))
}
