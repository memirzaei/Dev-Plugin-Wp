---
name: fps-engineering
description: Use when implementing, debugging, refactoring, reviewing, or testing Formula Price Sync code or its WordPress/WooCommerce integration.
---

# Formula Price Sync Engineering

1. Identify the affected contract and read the current implementation and tests.
2. Reproduce bugs or add the smallest regression test before changing behavior.
3. Implement the smallest safe change.
4. Run targeted tests, then regression/smoke gates.
5. Run the real WAMP gate for WordPress/WooCommerce behavior.
6. Review git diff for unrelated changes, debug code, secrets, and bypasses.

Protected behavior: preserve formula semantics, provider fallback/Circuit Breaker behavior, cursor/chunk/lock behavior, and marketplace-adapter isolation unless the task explicitly changes them.

Bulk processing must remain bounded, preserve cursor state, respect execution budget, use locking, and use WP-Cron continuation where required.

Database/schema changes require a DB version bump, idempotent migration, existing-installation coverage, and schema verification.

Definition of done: relevant tests/gates pass and the final diff is reviewed.
