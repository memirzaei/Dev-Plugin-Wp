# Formula Price Sync 2.0.0 — Redevelopment Hardening

- **Post-R17 hardening:** bumped schema version to `2.0.1`; removed the `FPS_ENGINEERING_CANDIDATE` release bypass; added configurable `License_Guard` + `Rastchin_Adapter` server-side licensing; normalized external booleans safely; removed buyer-facing build instructions.


## Final hardening stages

- **R14 — Static Quality:** release source is gated by PHPCS in CI; the release build no longer treats skipped tests as a normal release path.
- **R15 — Release Reproducibility:** release packaging uses normalized timestamps and sorted file manifests, with a double-build SHA-256 comparison in CI.
- **R16 — Documentation Consistency:** plugin version, stable tag, changelog, build metadata, test commands, and release instructions are validated by an automated metadata consistency check.

## R09/R11/R12/R13

- Discovery uses bounded SQL-side filtering without per-product taxonomy queries.
- Upgrade migration persists cursor state and resumes in bounded batches.
- Queue completion/run state uses bounded retention cleanup.
- Run-level health summaries expose state, cursor, snapshot, counts, outcomes, and last error.

## R05/R10

- Real integration fixtures cover simple/variable products and variation isolation.
- Variation lock state is respected before calculation and immediately before save.
- HPOS-enabled and HPOS-disabled integration paths are represented in CI.

## R04/R06

- License-blocked work is persisted as deferred instead of being silently dropped.
- Retry scheduling uses bounded exponential backoff.
- Lock renewal uses compare-and-swap semantics to prevent stale-owner overwrite.

## R03/R02

- Each sync run owns one immutable rate snapshot.
- Production queue processing uses a monotonic cursor and bounded continuation.

## R01

- Release autoload packaging includes the Composer runtime files required by the shipped `vendor/autoload.php`.

# R07/R08 Hardening — 2026-09-20

| File | Function / Area | Reason | Before | After | Covering Test / Evidence |
|---|---|---|---|---|---|
| `includes/Engine/Formula_Parser.php` | parser/evaluator | FPS-R07 safety | Invalid arithmetic collapsed ambiguous zero results | Detailed validity contract distinguishes valid zero from unsafe/div-by-zero/negative/non-finite results; input bounds added | `tests/run-r07r08.php`, `FormulaParserSecurityTest` |
| `includes/Engine/Calculator.php` | calculation boundary | FPS-R07 financial safety | Extension filters could override an errored calculation | Error-marked calculations hard-stop at zero before final-price filters; custom formula exposes parser failure | `tests/run-r07r08.php` |
| `includes/Engine/Product_Update_Result.php` | outcome taxonomy | FPS-R08 failure semantics | Product failures were plain booleans | Explicit updated/unchanged/locked/disabled/invalid_rate/validation_error/retryable_error/permanent_error taxonomy | `ProductUpdateResultTest`, `run-r07r08.php` |
| `includes/Queue/Action_Scheduler_Handler.php` | retry + run summary | FPS-R08 retry contract | Product exceptions were swallowed without bounded retry | Retryable failures get isolated product actions with exponential backoff and max attempts; run outcome counters persist | `FailureSemanticsTest`, `run-r07r08.php` |
| `includes/API/Rate_Snapshot_Store.php` | run rate consistency | FPS-R03 preservation | Worker path could refetch rates | Run snapshot is created once and passed to chunk/retry workers | `run-r07r08.php` |
| `includes/Queue/Action_Scheduler_Handler.php` | cursor continuation | FPS-R02 preservation | Candidate regression used full-catalog `array_chunk()` scheduling | Production entry point uses `next_id`, bounded continuation and SQL-side taxonomy filtering | `run-r07r08.php` + static regression scan |

# Change Log — Current Redevelopment Stage

| File | Function / Area | Reason | Before | After | Covering Test / Evidence |
|---|---|---|---|---|---|
| `includes/Admin/Metaboxes.php` | Variation UI/save | P4-V currency variation tax mode | No currency-only variation tax-mode control/persistence | Currency variation tax percent + profit-only control; allow-listed persistence | P4 contract + offline smoke |
| `assets/js/admin-app.js` | Variation source toggling | P4-V UI visibility | Tax mode UI absent | Currency-only tax mode row shown/hidden by source | JS syntax + contract review |
| `includes/Helpers/Jalali.php` | validation/format | P2-J | Weak validation / no seconds token | digit normalization, bounds, explicit acceptance rejection, round-trip validation, seconds | P2 offline smoke |
| `includes/Admin/History_Page.php` | date filter | P2-J | Invalid filter could pass through | Jalali validation before Gregorian query boundary | P2 contract |
| `includes/Admin/Product_Columns.php` | timestamp presentation | P2-J | Gregorian `wp_date()` | `Jalali::format()` | P2 contract |
| `includes/Admin/System_Health_Page.php` | timestamp presentation | P2-J | `mysql2date()` | `Jalali::format()` | P2 contract |
| `readme.txt` | release metadata | version consistency | Stable tag 2.0.0 | Stable tag 2.0.0 | final audit |
| `phpunit.xml.dist` | test suites | REG-E infrastructure | missing/unstable suite contract | `unit`, `integration`, `e2e` | static audit |
| `tests/*` | unit/integration/e2e/fixtures/real | REG-E coverage | absent | canonical test/runner contracts | syntax + policy + smoke |
