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


## Agent Command Center
Use the canonical runner from the repository root:
- `.ormula-price-syncinps-agent.ps1 status`
- `.ormula-price-syncinps-agent.ps1 test`
- `.ormula-price-syncinps-agent.ps1 smoke`
- `.ormula-price-syncinps-agent.ps1 wamp`
- `.ormula-price-syncinps-agent.ps1 gate`
- `.ormula-price-syncinps-agent.ps1 release-check`

Agents must prefer these commands over inventing alternate local gate sequences. The WAMP command uses the real certification site and the existing `run-wamp-gate.ps1` runner with real integration enabled.
