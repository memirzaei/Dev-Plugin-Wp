# Formula Price Sync – Test Suite

## Overview

This directory contains the automated test harness required by the redevelopment roadmap (P0-2).

### Structure

```
tests/
├── bootstrap/
│   └── bootstrap.php          # Lightweight WP stubs + option/transient store
├── Unit/
│   ├── AtomicOptionLockTest.php
│   ├── CalculatorTest.php
│   ├── FormulaParserSecurityTest.php
│   ├── ProductUpdateResultTest.php
│   └── ZhaketGuardTest.php
├── Integration/
│   ├── FailureSemanticsTest.php
│   └── ProcessChunkLicenseGateTest.php
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

#### Preferred (with PHPUnit + Composer)

```bash
# From the plugin root
composer require --dev phpunit/phpunit:^9.6 brain/monkey:^2.6
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
