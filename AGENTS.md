# Formula Price Sync — Agent Contract

These instructions apply to the Formula Price Sync plugin under formula-price-sync/.

## Required workflow
1. Read the task-relevant FPS skill in .agents/skills/.
2. Inspect the current implementation before changing behavior.
3. Find or add the smallest relevant test.
4. Reproduce the failure when fixing a bug.
5. Make the smallest safe change.
6. Run targeted tests, then relevant regression/smoke gates.
7. For WordPress/WooCommerce behavior, prefer the real WAMP environment at C:\\wamp64\\www\\fps-cert.
8. Inspect git diff and git status before declaring completion.

## Non-negotiable rules
- Do not change pricing semantics, source selection, rounding, locking, or bulk-processing behavior without evidence and tests.
- Do not bypass gates with engineering/test environment flags.
- Database/schema changes require a DB version bump and migration coverage.
- Never put product/license secrets or buyer-only tokens into the distributable repository.
- Never claim a gate passed without actual command output and exit status.
- Avoid broad refactors during targeted bug fixes.

## Task routing
- General plugin change -> fps-engineering
- Pricing/rate/calculation safety -> fps-price-safety
- Release/package readiness -> fps-release-gate
- Local WordPress/WooCommerce verification -> fps-wamp-runner
