import { Page, expect } from '@playwright/test';

export const WP = {
  baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
  adminUser: process.env.WP_ADMIN_USER || 'admin',
  adminPass: process.env.WP_ADMIN_PASS || 'password',
  adminPath: process.env.WP_ADMIN_PATH || '/wp-admin',
};

/** Plugin admin page URLs (relative to site root). */
export const FPS = {
  dashboard: '/wp-admin/admin.php?page=formula-price-sync',
  settings: '/wp-admin/admin.php?page=fps-settings',
  bulk: '/wp-admin/admin.php?page=fps-bulk',
  logs: '/wp-admin/admin.php?page=fps-logs',
  history: '/wp-admin/admin.php?page=fps-history',
  health: '/wp-admin/admin.php?page=fps-health',
};

/**
 * Log in to WordPress admin.
 * Skips if already logged in (cookie present).
 */
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${WP.adminPath}/`, { waitUntil: 'domcontentloaded' });

  // Already logged in?
  if (page.url().includes('wp-admin') && !page.url().includes('wp-login')) {
    const body = page.locator('#wpadminbar, #wpbody-content');
    if (await body.first().isVisible().catch(() => false)) {
      return;
    }
  }

  // Login form
  await page.goto(`${WP.adminPath}/../wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', WP.adminUser);
  await page.fill('#user_pass', WP.adminPass);
  await page.click('#wp-submit');

  // Wait for admin bar or dashboard
  await expect(page.locator('#wpadminbar, #wpbody')).toBeVisible({ timeout: 20_000 });
}

/**
 * Navigate to a plugin admin page and wait for the wrap container.
 */
export async function goToPluginPage(page: Page, path: string): Promise<void> {
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.wrap, .fps-admin-wrap, #wpbody-content')).toBeVisible({
    timeout: 15_000,
  });
}

/**
 * Assert that the current page is not a WP error / capability denied page.
 */
export async function assertNoAdminError(page: Page): Promise<void> {
  const bodyText = await page.locator('body').innerText();
  expect(bodyText).not.toMatch(/You need a higher level of permission/i);
  expect(bodyText).not.toMatch(/Sorry, you are not allowed/i);
  expect(bodyText).not.toMatch(/There has been a critical error/i);
  // Fatal PHP white-screen check
  const html = await page.content();
  expect(html.length).toBeGreaterThan(500);
}
