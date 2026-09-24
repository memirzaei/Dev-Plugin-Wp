# Formula Price Sync / طلا ارز پرو — v2.0.0 (Developer Source)

WooCommerce automated pricing by gold & currency formulas (Iranian guild rules).

## Packages

| File | Purpose |
|------|---------|
| `formula-price-sync-2.0.0.zip` | Marketplace release (no tests/bin) |
| `formula-price-sync-2.0.0-dev.zip` | Full developer tree (tests, CI, build scripts) |

## Requirements

- PHP ≥ 7.4
- WordPress ≥ 5.8
- WooCommerce ≥ 6.0 (HPOS supported)

## Quick tests

```bash
# From plugin root
php tests/run-smoke.php
php tests/smoke-install-hpos.php

# PHPUnit is a release gate
composer install
composer test:phpunit

# Local WP + WooCommerce smoke (WP-CLI required)
bash bin/run-smoke-local.sh --path=/path/to/wordpress

# Real WooCommerce + HPOS + variation isolation E2E (dev tree)
# The local runner prepares a real variable product, schedules an Action Scheduler job,
# runs it, and verifies prices, lock isolation, audit log, cache purge and completion state.
```

## Release build (publisher/CI only)

```bash
composer install --no-interaction
bash bin/build-release.sh
# → build/formula-price-sync-2.0.0.zip
```

## License token (server only — never commit)

```php
// wp-config.php
define( 'FPS_ZHAKET_PRODUCT_TOKEN', 'your-token' );
```

## CI

GitHub Actions: `.github/workflows/ci.yml`
