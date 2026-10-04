# Architecture Constraints (DO NOT VIOLATE)

These rules are hard constraints. Violating any of them is considered a failure of the current task.

## 1. Forbidden Modifications

Do **NOT** edit or refactor these folders/files unless the current task in `CURRENT_TASK.md` explicitly allows it:

- `includes/Core/*`
- `includes/Engine/*`
- `includes/API/*`
- `includes/Licensing/*`
- `includes/Queue/*`
- `includes/Admin/*` (except minor registration if required by Frontend bootstrap)
- `bin/*`
- `tests/*` (unless adding new tests for Frontend)

## 2. License Gate (Mandatory)

All public-facing output (shortcodes, product breakdown, AJAX responses, Elementor widgets) **must** respect:

```php
\FormulaPriceSync\Licensing\License_Guard::should_block()
```

If blocked, show a clean message or return empty/safe fallback. Never expose calculation logic or rates when license is invalid.

## 3. Mandatory Reuse

Always prefer existing classes instead of rewriting logic:

- `\FormulaPriceSync\Engine\Calculator`
- `\FormulaPriceSync\API\API_Manager`
- `\FormulaPriceSync\Helpers\Formatter`
- `\FormulaPriceSync\Helpers\Jalali`
- `\FormulaPriceSync\Licensing\License_Guard`
- `\FormulaPriceSync\Core\Logger` (for errors/warnings)

## 4. Allowed New Code Locations Only

- `includes/Frontend/**`
- `includes/Widgets/**`
- `assets/css/frontend.css`
- `assets/js/frontend.js`
- Small safe registration lines in `formula-price-sync.php` (inside `fps_init()`)

## 5. Coding & Style Rules

- Follow existing WordPress Coding Standards used in the project.
- Use type hints and clear docblocks.
- Namespace: only `FormulaPriceSync\Frontend` and `FormulaPriceSync\Widgets`.
- Prefix all CSS classes and JS with `fps-`.
- No `eval()`, no dynamic code execution from user input.
- Keep functions small and single-responsibility.

## 6. Task Discipline

- Work on **only one task** at a time (the one in `CURRENT_TASK.md`).
- Do not mark a task as done until all Acceptance Criteria are met.
- After finishing a task, update `PROGRESS.md` and advance `CURRENT_TASK.md`.
