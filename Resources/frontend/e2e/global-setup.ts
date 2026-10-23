import { existsSync, writeFileSync } from 'node:fs'
import { SEED_COMMAND, seed } from './helpers'

const used = new URL('./.seed-used', import.meta.url)

/**
 * Every run needs a fresh seed: the suite leaves its events behind, and a second run on the same seed finds every title
 * twice. The seed removes this marker; the first run after it writes it.
 */
export default function globalSetup(): void {
  if (!seed.feedEventDate || !seed.failingCalendarId) {
    throw new Error(`This seed is older than the suite. Seed again: ${SEED_COMMAND}`)
  }
  if (existsSync(used)) {
    throw new Error(`An earlier run used this seed, and its events are still there. Seed again first: ${SEED_COMMAND}`)
  }
  writeFileSync(used, `${new Date().toISOString()}\n`)
}
