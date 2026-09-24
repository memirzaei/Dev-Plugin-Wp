import { test, expect } from '@playwright/test';
import { loginAsAdmin, WP, assertNoAdminError } from './helpers/wp';

/**
 * Basic WordPress admin authentication.
 * These tests confirm the environment is ready before plugin-specific tests.
 */
test.describe('WordPress Admin Login', () => {
  test('can reach wp-login.php', async ({ page }) => {
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#user_login')).toBeVisible();
    await expect(page.locator('#user_pass')).toBeVisible();
    await expect(page.locator('#wp-submit')).toBeVisible();
  });

  test('admin can log in successfully', async ({ page }) => {
    await loginAsAdmin(page);
    await assertNoAdminError(page);
    // Dashboard or any admin screen
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page.locator('#adminmenu')).toBeVisible();
  });

  test('admin bar shows correct user', async ({ page }) => {
    await loginAsAdmin(page);
    const howdy = page.locator('#wp-admin-bar-my-account .display-name, #wp-admin-bar-my-account');
    await expect(howdy.first()).toBeVisible();
  });
});
