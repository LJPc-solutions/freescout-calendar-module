import { defineConfig, devices } from '@playwright/test'

// Runs against the local ddev FreeScout; seed it before every run (Tests/e2e/seed.sh), which the global setup checks.
// The browser's zone (Tokyo) differs from every profile zone on purpose: any use of the browser's timezone shows up as
// a wrong time.
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://freescout.ddev.site',
    ignoreHTTPSErrors: true,
    locale: 'en-US',
    timezoneId: 'Asia/Tokyo',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], locale: 'en-US', timezoneId: 'Asia/Tokyo' } }],
})
