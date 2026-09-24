# Formula Price Sync – Playwright E2E Tests

## Prerequisites

- Node.js 18+
- A running WordPress site with:
  - WooCommerce active
  - Formula Price Sync plugin active
  - An administrator account

Recommended local environments:
- [Local WP](https://localwp.com/)
- `@wordpress/env` (`npx wp-env start`)
- WordPress Playground CLI
- DevKinsta / Docker

## Install

From the **plugin root**:

```bash
npm init -y
npm install --save-dev @playwright/test
npx playwright install chromium
```

## Configuration

Set environment variables (or export before running):

| Variable | Default | Description |
|----------|---------|-------------|
| `WP_BASE_URL` | `http://localhost:8889` | Site URL (no trailing slash) |
| `WP_ADMIN_USER` | `admin` | Admin username |
| `WP_ADMIN_PASS` | `password` | Admin password |
| `WP_ADMIN_PATH` | `/wp-admin` | Admin path |

Example:

```bash
export WP_BASE_URL=http://localhost:10003
export WP_ADMIN_USER=admin
export WP_ADMIN_PASS='your-password'
```

## Run tests

```bash
# All E2E tests
npx playwright test

# Headed (see the browser)
npx playwright test --headed

# Only login tests
npx playwright test admin-login

# Only settings page
npx playwright test plugin-settings

# UI mode (interactive)
npx playwright test --ui

# Debug one test
npx playwright test plugin-settings --debug
```

## Test files

| File | What it covers |
|------|----------------|
| `admin-login.spec.ts` | wp-login.php, successful admin login, admin bar |
| `plugin-menu.spec.ts` | Top-level menu + all 6 FPS admin pages load without fatal errors |
| `plugin-settings.spec.ts` | Settings form, currency API, display unit, license field, schedule, save |
| `helpers/wp.ts` | Shared login helper, page URLs, error assertions |

## Pages under test

| Page | URL |
|------|-----|
| Dashboard | `/wp-admin/admin.php?page=formula-price-sync` |
| Settings | `/wp-admin/admin.php?page=fps-settings` |
| Bulk Sync | `/wp-admin/admin.php?page=fps-bulk` |
| Price Logs | `/wp-admin/admin.php?page=fps-logs` |
| Rate History | `/wp-admin/admin.php?page=fps-history` |
| System Health | `/wp-admin/admin.php?page=fps-health` |

## CI example (GitHub Actions snippet)

```yaml
- name: E2E
  env:
    WP_BASE_URL: http://localhost:8889
    WP_ADMIN_USER: admin
    WP_ADMIN_PASS: password
  run: |
    npx playwright install --with-deps chromium
    npx playwright test
```

## Notes

- Tests are written to be resilient to Persian/English UI strings.
- They assert **no critical/fatal errors** and presence of expected form fields rather than brittle pixel-perfect selectors.
- License field is expected as `type="password"` when present.
- After `composer install` / plugin activation, run these against a real WooCommerce site for full confidence.
