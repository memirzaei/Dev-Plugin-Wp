---
name: fps-price-safety
description: Use for Formula Price Sync pricing, rates, formulas, rounding, provider data, product updates, bulk sync, logs, and Circuit Breaker changes.
---

# FPS Price Safety

Treat price mutation as financial-data handling. Validate source structure, numeric validity, freshness, fallback/Circuit Breaker state, units, and target product. Do not introduce binary floating-point monetary decisions. Preserve established decimal/rounding behavior. Never turn invalid source data into zero or update products from blocked sources. Preserve WooCommerce CRUD and variation behavior. Test bulk cursor/chunk/timeout/lock/retry behavior.
