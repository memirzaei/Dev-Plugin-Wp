# Formula Price Sync — Cline Project Rules

Use the FPS skills under .agents/skills/ for task-specific guidance.

Always:
- inspect implementation and tests before editing;
- reproduce bugs or add the smallest regression test;
- make the smallest safe change;
- run targeted tests and the relevant regression/WAMP gate;
- inspect git diff/status before completion.

Never:
- bypass gates with engineering/test flags;
- change pricing, rounding, provider fallback, locking, or bulk semantics without evidence;
- change DB schema without DB version + migration coverage;
- commit buyer-only license/product tokens;
- claim PASS without actual command output.

For local WordPress/WooCommerce verification, use the real WAMP certification site and bin\\run-wamp-gate.ps1.
