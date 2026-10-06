# Current Task

**ID:** 1.1  
**Title:** Create Frontend structure  
**Day:** 1  
**Status:** Pending  

---

## Description

Create the foundational Frontend structure for the plugin.

1. Create folder `includes/Frontend/`
2. Create `includes/Frontend/index.php` (security guard)
3. Create class `FormulaPriceSync\Frontend\Frontend` with `init()` method
4. Register the class inside `fps_init()` in the main plugin file
5. Prepare enqueue hooks for future `frontend.css` and `frontend.js` (can be empty for now)

---

## Acceptance Criteria

- [ ] Folder `includes/Frontend/` exists
- [ ] `index.php` exists and prevents direct access
- [ ] Class `Frontend` exists with public static `init()` method
- [ ] `Frontend::init()` is called from `fps_init()` in `formula-price-sync.php`
- [ ] No PHP errors or warnings
- [ ] Enqueue hooks are registered (even if callbacks are empty/placeholder)
- [ ] Code follows project style and namespace rules

---

## Files to Create / Touch

**New files:**
- `includes/Frontend/Frontend.php`
- `includes/Frontend/index.php`

**Modify:**
- `formula-price-sync.php` (only add the init call in the appropriate place)

---

## Implementation Notes for Agent

- Place the init call in `fps_init()` after Admin classes are loaded, still outside or inside license gate according to existing pattern (Frontend should load even if license is invalid, so admin can see messages).
- Use the same security pattern as other `index.php` files in the project.
- Keep the class minimal. Only bootstrap and enqueue preparation.

---

## After Finishing This Task

1. Mark all Acceptance Criteria as checked in this file.
2. Update `PROGRESS.md` (check 1.1 and log completion).
3. Replace the content of this `CURRENT_TASK.md` with Task 1.2.
4. Stop and wait for next instruction (do not start 1.2 automatically unless told).
