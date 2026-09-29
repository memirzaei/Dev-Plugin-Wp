---
name: fps-release-gate
description: Use when preparing, validating, packaging, tagging, or reviewing a Formula Price Sync release or buyer ZIP.
---

# Formula Price Sync Release Gate

Run in order: git status/diff; metadata/version consistency; Composer/autoload; syntax/static checks; targeted PHPUnit; full PHPUnit; smoke/regression gates; real WAMP WordPress + WooCommerce + HPOS gate; PHPCS/PHPStan when configured; ZIP inspection; secret/debug/bypass scan.

Release blockers: failed required gate; undocumented skipped required test; production bypass flag; missing DB migration or DB version bump; buyer-only license/product token in distributable; development credentials/artifacts in ZIP.

Zhaket License Guard remains buyer-configured. Buyer product tokens must not be committed into the distributable. Rastchin and other marketplace adapters remain isolated from the pricing engine.

Report actual commands, exit codes, and result counts. Never convert not-run into PASS.
