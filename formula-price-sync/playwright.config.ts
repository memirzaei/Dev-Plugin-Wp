import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for Formula Price Sync E2E tests.
 *
 * Environment variables (set in .env or CI):
 *   WP_BASE_URL      – WordPress site URL (default: http://localhost:8889)
 *   WP_ADMIN_USER    – admin username (default: admin)
 *   WP_ADMIN_PASS    – admin password (default: password)
 *   WP_ADMIN_PATH    – admin path (default: /wp-admin)
 */
const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
  ],
  timeout: 60_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'fa-IR',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
