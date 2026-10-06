# Backlog — Frontend MVP Phase 1 (3-Day)

**Source of truth for scope.**  
Do not implement anything outside this backlog during the 3-day phase.

---

## Day 1 — Structure + Shortcodes + Static Breakdown

### 1.1 Create Frontend structure
- Create `includes/Frontend/` + `Frontend.php` bootstrap
- Register in `fps_init()`
- Prepare asset enqueue hooks

### 1.2 Shortcodes class
Implement these shortcodes:

| Shortcode | Parameters | Output |
|-----------|------------|--------|
| `[fps_gold_price]` | `type="18k\|24k\|coin"` (default 18k) | Formatted gold price per gram |
| `[fps_rate]` | `type="usd\|eur\|..."` | Formatted currency rate |
| `[fps_price]` | `id="123"` (optional) | Final product price |
| `[fps_product_breakdown]` | `id="123"` | Full price breakdown |
| `[fps_last_update]` | — | Last rates update time |

Requirements:
- Reuse `API_Manager` and `Calculator`
- Respect license gate
- Support simple & variable products
- Safe output (escaping)

### 1.3 Product_Display (static breakdown)
- Hook into `woocommerce_single_product_summary`
- Show breakdown only when `_fps_enable = yes`
- Use Calculator with current rates
- Clean HTML with `fps-breakdown` classes

---

## Day 2 — Live AJAX + Assets

### 2.1 Ajax_Frontend
- Endpoints: `wp_ajax_fps_get_live_price` + nopriv
- Input: product_id, variation_id (optional), nonce
- Output JSON with final_price, formatted_price, breakdown, timestamp
- License + nonce checks

### 2.2 frontend.js
- Load only on single product pages
- Poll every 60 seconds when tab is visible
- Update `.fps-live-price` and `.fps-breakdown` elements
- Add `fps-updating` class while loading

### 2.3 frontend.css
- Styles for `.fps-breakdown`
- Basic dark mode support
- Use project font (Tanha) where possible

---

## Day 3 — Elementor + Polish

### 3.1 Elementor_Manager
- Bootstrap only if Elementor is active
- Register widget category “نرخ‌ماتیک”

### 3.2 Price Ticker Widget (simple)
- Shows gold 18k + USD rates
- Configurable title
- Safe when Elementor is missing

### 3.3 Final polish + CHANGELOG
- Full test on simple & variable products
- License blocked state
- Update CHANGELOG.md

### 3.4 Short documentation
- List of shortcodes and basic usage (can be in README or separate doc)

---

## Out of Scope (Do Not Implement Now)

- Advanced Elementor widgets
- Dokan support
- Import/Export
- Sticky ticker / floating widget
- Price history charts
- Minimum price / fluctuation margin controls
