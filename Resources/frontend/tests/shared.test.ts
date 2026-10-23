import { afterEach, describe, expect, it, vi } from 'vitest'
import { notify } from '../src/shared/alerts'
import { ApiError, asksToDropExceptions, createApi, routeWith } from '../src/shared/api'
import { setStrings, t } from '../src/shared/i18n'

afterEach(() => {
  vi.unstubAllGlobals()
  setStrings({})
})

describe('i18n', () => {
  it('translates and fills Laravel-style placeholders, longest name first', () => {
    setStrings({ 'Every :count weeks': 'Elke :count weken', ':counter of :count': ':counter van :count' })
    expect(t('Every :count weeks', { count: 2 })).toBe('Elke 2 weken')
    expect(t(':counter of :count', { count: 5, counter: 3 })).toBe('3 van 5')
    expect(t('Untranslated')).toBe('Untranslated')
  })
})

describe('notices', () => {
  it('shows a warning as an error that stays until the agent closes it', () => {
    const showFloatingAlert = vi.fn()
    vi.stubGlobal('window', { showFloatingAlert })
    notify('warning', 'It is now in both calendars.')
    notify('success', 'Event saved.')
    expect(showFloatingAlert.mock.calls).toEqual([['error', 'It is now in both calendars.', true], ['success', 'Event saved.']])
  })
})

describe('api', () => {
  it('sends JSON with the CSRF token and returns the body', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ ok: true }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    await expect(createApi('token').post('/x', { a: 1 })).resolves.toEqual({ ok: true })
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.method).toBe('POST')
    expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('token')
    expect(init.body).toBe('{"a":1}')
  })

  it('turns validation errors into field messages', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'The given data was invalid.', errors: { 'recurrence.until': ['Invalid end date.'] } }), { status: 422 })))
    const error = await createApi('t').patch('/x', {}).catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).message).toBe('Invalid end date.')
    expect((error as ApiError).fieldErrors()).toEqual({ 'recurrence.until': 'Invalid end date.' })
  })

  it('reports an expired session for 401 (a read) and 419 (a write)', async () => {
    for (const status of [401, 419]) {
      vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status })))
      await expect(createApi('t').get('/x')).rejects.toMatchObject({ status, message: 'Your session has expired. Reload the page and try again.' })
    }
  })

  it('rejects a successful response that is not JSON, except an empty 204', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<!DOCTYPE html><html><body>Login</body></html>', { status: 200, headers: { 'Content-Type': 'text/html' } })))
    const error = await createApi('t').get('/x').catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 200, message: 'Something went wrong. Try again.' })

    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))
    await expect(createApi('t').delete('/x')).resolves.toBeNull()
  })

  it('carries the response body\'s code, so a caller can recognize a question from the server', async () => {
    const message = 'Edited and deleted occurrences of this series will be reset. Continue?'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ code: 'drops_exceptions', message }), { status: 409 })))
    const error = await createApi('t').patch('/x', {}).catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 409, code: 'drops_exceptions', message })

    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Server Error' }), { status: 500 })))
    await expect(createApi('t').patch('/x', {})).rejects.toMatchObject({ status: 500, code: null })
  })

  it('never takes a provider conflict for the question whether to reset a series', async () => {
    const reset = 'Edited and deleted occurrences of this series will be reset. Continue?'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ code: 'drops_exceptions', message: reset }), { status: 409 })))
    expect(asksToDropExceptions(await createApi('t').patch('/x', {}).catch((caught: unknown) => caught))).toBe(true)

    const conflict = 'This event was changed elsewhere. Reload and try again.'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ code: 'changed_elsewhere', message: conflict }), { status: 409 })))
    const error = await createApi('t').patch('/x', {}).catch((caught: unknown) => caught)
    expect(error).toMatchObject({ status: 409, code: 'changed_elsewhere', message: conflict })
    expect(asksToDropExceptions(error)).toBe(false)
    expect(asksToDropExceptions(new ApiError(422, 'x', {}, 'drops_exceptions'))).toBe(false)
    expect(asksToDropExceptions(new Error('drops_exceptions'))).toBe(false)
  })

  it('shows the provider\'s message for a 502, and a 403\'s own reason only when it gives one', async () => {
    const unavailable = 'The calendar provider could not be reached. Try again later.'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ code: 'provider_unavailable', message: unavailable }), { status: 502 })))
    await expect(createApi('t').post('/x', {})).rejects.toMatchObject({ status: 502, code: 'provider_unavailable', message: unavailable })

    const series = 'This series can only be changed one occurrence at a time.'
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ code: 'series_not_writable', message: series }), { status: 403 })))
    await expect(createApi('t').patch('/x', {})).rejects.toMatchObject({ status: 403, message: series })
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'This action is unauthorized.' }), { status: 403 })))
    await expect(createApi('t').patch('/x', {})).rejects.toMatchObject({ status: 403, message: 'You don\'t have permission to do this.' })
  })

  it('reports a network failure as status 0', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline') }))
    await expect(createApi('t').get('/x')).rejects.toMatchObject({ status: 0 })
  })

  it('fills route placeholders', () => {
    expect(routeWith('/calendar/api/events/__ID__', 'e12')).toBe('/calendar/api/events/e12')
    expect(routeWith('/calendar/api/events/__ID__', 'a@b/c')).toBe('/calendar/api/events/a%40b%2Fc')
  })
})
