---
name: fps-price-safety
description: Use when changing prices, exchange/gold/coin rates, formulas, rounding, provider data, product updates, bulk synchronization, logs, or Circuit Breaker behavior in Formula Price Sync.
---

# Formula Price Safety

Treat every price mutation as a financial-data operation.

Before mutation verify source structure, numeric validity, freshness policy, Circuit Breaker/fallback state, formula units, and target product validity.

Do not introduce binary floating-point arithmetic for monetary decisions. Preserve the established decimal and rounding strategy. Never coerce malformed, zero, negative, NaN, infinite, or otherwise invalid rates into a usable price.

Never update a product after an invalid or blocked source. Never turn a failed fetch into zero. Use WooCommerce CRUD for product/variation mutations and preserve variation behavior.

For bulk sync, test cursor continuation, chunk limits, timeout budget, lock lifecycle, duplicate prevention, partial failure/retry, and continuation.

Pricing changes should cover normal rate, fallback/manual rate, malformed payload, stale/blocked source, rounding boundary, simple product, variable product/variation, and concurrency where relevant.
