---
name: fps-release-gate
description: Use for Formula Price Sync releases, packaging, buyer ZIP validation, and release readiness.
---

# FPS Release Gate

Run actual metadata, Composer/autoload, syntax/static, PHPUnit, smoke/regression, real WAMP WordPress+WooCommerce+HPOS, PHPCS/PHPStan, ZIP and secret/bypass checks as applicable. A failed or unrun required gate is not PASS. DB changes require DB version and migration coverage. Buyer-only license/product tokens must not enter the distributable.
