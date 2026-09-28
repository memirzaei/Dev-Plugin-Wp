# Formula Price Sync – Test Suite

## Overview

This directory contains the automated test harness required by the redevelopment roadmap (P0-2).

### Structure

```
tests/
├── bootstrap/
│   └── bootstrap.php          # Lightweight WP stubs + option/transient/post-meta store
├── Unit/
│   ├── AtomicOptionLockTest.php
│   ├── CalculatorTest.php
│   ├── FormulaParserSecurityTest.php
│   ├── ProductUpdateResultTest.php
│   └── ZhaketGuardTest.php
├── Integration/
│   ├── FailureSemanticsTest.php
│   └── ProcessChunkLicenseGateTest.php
├── e2e/
│   ├── admin-login.spec.ts
│   ├── plugin-menu.spec.ts
│   └── plugin-settings.spec.ts
├── playwright.config.ts
├── run-smoke.php
├── smoke-install-hpos.php
└── README.md
```

### Requirements covered

| Test class                        | Requirement |
|-----------------------------------|-------------|
| `AtomicOptionLockTest`            | R01 – Atomic concurrency safety |
| `CalculatorTest`                  | Gold guild formula, currency, custom formula, edge rates |
| `ZhaketGuardTest`                 | R02 – License fail-closed, rate-limit, masking, revalidate |
| `ProcessChunkLicenseGateTest`     | License gate inside `process_chunk` |

### Running the tests

#### PHPUnit

```bash
# From the plugin root
composer install --dev
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

#### Quick syntax / smoke check (no PHPUnit required)

```bash
php -l tests/bootstrap/bootstrap.php
php -l tests/Unit/*.php
php -l tests/Integration/*.php
```

### Design notes

- Production behaviour is **never** altered to make tests pass.
- `Atomic_Option_Lock` CAS path is covered by `AtomicOptionLockConcurrencyTest` (in-memory CAS mock + optional real MySQL via `FPS_INTEGRATION_MYSQL=1`).
- License adapter is injected via the `fps_license_adapter` filter so unit tests never hit the live Zhaket API.
- The bootstrap provides in-memory option/transient stores so pure unit tests run without a WordPress installation.

### Acceptance criteria (from roadmap)

- [x] `tests/` directory present
- [x] `phpunit.xml.dist` present
- [x] Unit tests for Atomic_Option_Lock (acquire / concurrent failure / renew / release)
- [x] Unit tests for Calculator (18k / 24k / coin / currency / custom / zero / negative / non-finite rates)
- [x] R07 formula-parser adversarial and financial-invariant regression runner
- [x] R08 explicit product outcome taxonomy and bounded retry runner
- [x] Unit tests for Zhaket_Guard (fail-closed / rate-limit / masking / revalidate)
- [x] Integration assertion that `process_chunk` exits early when license blocks


#### Playwright E2E

The browser suite targets a real WordPress + WooCommerce test site and is kept outside the release package.

```bash
# From the plugin root
npm install
npx playwright install
npm run test:e2e
```

Configured specs:
- `tests/e2e/admin-login.spec.ts`
- `tests/e2e/plugin-menu.spec.ts`
- `tests/e2e/plugin-settings.spec.ts`

`playwright.config.ts` is development-only and must never appear in the marketplace ZIP.

### Concurrency / MySQL integration

`tests/Integration/AtomicOptionLockConcurrencyTest.php` validates compare-and-set
behaviour under contention:

- Exactly one winner when multiple workers race for a free lock
- CAS reclaim of an expired lock admits a single winner
- Stale owner cannot release after a successful reclaim
- Renew preserves mutual exclusion
- Round-robin acquire/release cycles

The default bootstrap uses an in-memory `$wpdb` mock that enforces real CAS
semantics (`UPDATE ... WHERE option_value = old` only succeeds on match).

#### Running against a real MySQL (WordPress test suite)

```bash
# 1. Install WP test suite (wordpress-develop or wp-cli scaffold)
# 2. Point phpunit.xml bootstrap to WP tests bootstrap
# 3. Enable the parallel group:
FPS_INTEGRATION_MYSQL=1 ./vendor/bin/phpunit --group mysql
```

The `test_pcntl_parallel_acquire_against_shared_store` test is skipped unless
`FPS_INTEGRATION_MYSQL=1` is set, because child processes need a shared database.


### Release-prep CI gate

The `release/**` branch class runs the full CI pipeline, including real MySQL CAS and real WordPress/WooCommerce/HPOS integration. This branch is a validation branch only; it is not the marketplace release branch.
