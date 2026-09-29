---
name: fps-wamp-runner
description: Use when running or debugging Formula Price Sync against the real local WAMP WordPress/WooCommerce environment.
---

# Formula Price Sync WAMP Runner

Default certification site: C:\\wamp64\\www\\fps-cert
Default working checkout: C:\\wamp64\\www\\Dev-Plugin-Wp-test\\formula-price-sync

Verify paths before running commands. Verify PHP, Composer, WP-CLI, WordPress, WooCommerce, and HPOS. Inspect bin\\run-wamp-gate.ps1 before changing it. Run the real WAMP gate, capture output and exit code, isolate the first failure, run the smallest failed gate after a fix, then rerun the full gate.

Never fabricate WAMP results. Never replace required integration verification with stubs. Never enable engineering/test bypasses to obtain a green release gate. If the runner or WordPress path is missing, stop and report the exact blocker.

Previously verified environment: PHP 8.2.13, Composer 2.10.3, WP-CLI 2.12.0, PHPUnit 9.6.37, WordPress, WooCommerce, HPOS. Re-check because local environments change.
