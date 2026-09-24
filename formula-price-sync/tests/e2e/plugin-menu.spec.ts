import { test, expect } from '@playwright/test';
import { loginAsAdmin, goToPluginPage, FPS, assertNoAdminError } from './helpers/wp';

/**
 * Verify top-level menu and all FPS sub-pages load without fatal errors.
 */
test.describe('Formula Price Sync – Admin Menu', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
  });

  test('top-level menu item exists in admin sidebar', async ({ page }) => {
    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    // Menu may be registered as "طلا ارز پرو" or "Formula Price Sync"
    const menu = page.locator(
      '#adminmenu a[href*="page=formula-price-sync"], #adminmenu .wp-menu-name'
    );
    // At least one FPS-related link should appear
    const fpsLink = page.locator('a[href*="page=formula-price-sync"], a[href*="page=fps-"]');
    await expect(fpsLink.first()).toBeVisible({ timeout: 10_000 });
  });

  const pages: { name: string; path: string; heading?: RegExp }[] = [
    { name: 'Dashboard', path: FPS.dashboard, heading: /داشبورد|Dashboard|طلا|فرمول|قیمت/i },
    { name: 'Settings', path: FPS.settings, heading: /تنظیمات|Settings/i },
    { name: 'Bulk Sync', path: FPS.bulk, heading: /همگام|دسته‌ای|Bulk/i },
    { name: 'Price Logs', path: FPS.logs, heading: /تاریخچه|لاگ|Log/i },
    { name: 'Rate History', path: FPS.history, heading: /تاریخچه|نرخ|History/i },
    { name: 'System Health', path: FPS.health, heading: /وضعیت|سیستم|Health/i },
  ];

  for (const p of pages) {
    test(`${p.name} page loads without critical error`, async ({ page }) => {
      await goToPluginPage(page, p.path);
      await assertNoAdminError(page);

      // Page should have a heading or FPS wrap
      const wrap = page.locator('.wrap, .fps-admin-wrap, #wpbody-content h1, #wpbody-content h2');
      await expect(wrap.first()).toBeVisible();

      if (p.heading) {
        const text = await page.locator('body').innerText();
        expect(text).toMatch(p.heading);
      }
    });
  }
});
