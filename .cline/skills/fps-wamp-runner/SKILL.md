---
name: fps-wamp-runner
description: Use for Formula Price Sync integration verification against the real local WAMP WordPress/WooCommerce environment.
---

# FPS WAMP Runner

Verify C:\\wamp64\\www\\fps-cert and the plugin checkout before running. Use bin\\run-wamp-gate.ps1, capture output and exit code, isolate the first failure, rerun the smallest failed gate, then rerun the full gate. Never fabricate results or enable test bypasses to obtain PASS.
