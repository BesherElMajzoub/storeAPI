# 02 D2 Catalog - results

Status: READY-FOR-REVIEW (B3-full — checklist items the B2 review flagged as
unchecked are now covered).

## Entry points

- `Api/V1/ProductController`, `Api/V1/Admin/ProductController`, `Api/V1/CategoryController`,
  `Api/V1/Admin/CategoryController`, `ProductService`, `CategoryService`,
  `SkuGeneratorService`, `ProductImportService`, `ReviewService`, `ReviewObserver`.

## Verified OK

- **Public visibility**: `Product::scopePublished()` (`app/Models/Product.php:118`) requires
  both `status === 'published'` **and** an active category (`whereHas('category', ... is_active)`),
  and `ProductController::index/show/sitemap` all use `Product::published()` consistently — a
  draft/archived product or a product under a deactivated category never appears publicly.
- **Variant deletion in an order**: `order_items.variant_id` is `nullOnDelete()`
  (migration `2026_01_17_100000_create_store_tables.php:188`), and `OrderInventoryService::quoteAndReserve`
  snapshots `variant_name`/`variant_attributes`/`sku`/`price` onto the order item at order time
  (not just a live FK), so deleting a variant that's referenced by a past order sets the FK to
  null without corrupting or losing that order's historical display.
- **SKU uniqueness under concurrency**: `SkuGeneratorService::findUniqueSku` does a racy
  check-then-suggest (`exists()` then increment), so two concurrent admin requests generating a
  SKU for near-identical products could get the same *suggested* value. This is mitigated at the
  DB layer: both `products.sku` and `product_variants.sku` have a real unique index
  (`create_store_tables.php:94`, `add_production_query_indexes.php:21`), so the actual insert
  can't silently create a duplicate — the loser gets a DB error and must resubmit. Acceptable for
  a low-frequency, admin-only action; a nicer UX (catch-and-retry) is a phase-03 polish item, not
  a correctness bug.
- **CSV import atomic commit**: `ProductImportService::process` wraps the whole import in one
  `DB::transaction` (`ProductImportService.php:43`); already confirmed "Fixed now" in
  `backend-open-items.md` item B6 (5 MB/5,000-row limits, per-row errors, atomic commit).
- **Reviews**: one review per user per product is enforced by both the application check
  (`ReviewService::hasReviewed`) and a DB unique index (`reviews_user_product_unique`); rating
  average/count recalculation (`ReviewObserver::recalculateRating`) only counts `approved`
  reviews and re-fires on create (if pre-approved), status/rating change, and delete — verified by
  reading the observer, matches the domain checklist.
- **"Related products"**: no such feature exists anywhere in the codebase (grepped
  `ProductController` and templates) — checklist item is not applicable, not a gap to fix.

## Findings

### D2-F1 — P3 (not fixed, logged for phase 03): duplicate-review race leaks a raw DB error message

- **Severity:** P3
- **Status:** OPEN (deferred — see reasoning below)
- **Location:** `app/Http/Controllers/Api/V1/ReviewController.php:64`
- **Problem:** `ReviewController::store` catches `\Exception` generically and returns
  `$e->getMessage()` verbatim with a 409. `ReviewService::create` checks `hasReviewed()` in
  application code (no row lock) before inserting; if two requests for the same user/product
  race, the DB's unique constraint (`reviews_user_product_unique`) rejects the second insert with
  a raw `QueryException` message (e.g. `SQLSTATE[23000]: ... Duplicate entry ...`), which the
  catch-all forwards straight to the client instead of the friendly "You have already reviewed
  this product." message used by the normal (non-race) path.
- **Why deferred instead of fixed now:** data integrity is not at risk (the unique index prevents
  a real duplicate row), and reproducing the race deterministically needs two concurrent DB
  connections mid-request, which this suite has no harness for (unlike the Stripe/webhook
  idempotency tests, which race via sequential replay of the same payload, not real concurrency).
  Recommended fix for phase 03: catch `\Illuminate\Database\QueryException` in `ReviewService::create`
  specifically and rethrow the same friendly message.

### D2-F2 — P1: admin dashboard "pending orders" metrics were permanently stuck at zero

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/V1/Admin/DashboardController.php:59,65`
- **Problem:** `current_orders_count` and `alerts.pending_orders` both queried `Order::where('status', 'pending')`. As established in D7-F1 (previous batch), `'pending'` is a dead status value — nothing has created an order with it since Stripe checkout replaced it with `'pending_payment'`. Both dashboard metrics have therefore always returned `0`, regardless of how many real orders are actually awaiting payment.
- **Scenario:** An admin opens the dashboard while 50 customers have unpaid, in-progress checkouts. The dashboard shows `current_orders_count: 0` and `alerts.pending_orders: 0`, hiding exactly the actionable backlog these fields exist to surface.
- **Test:** `tests/Feature/AdminDashboardTest.php::test_dashboard_counts_orders_still_awaiting_payment` — seeds 3 real `pending_payment` orders plus a `processing` and a `cancelled` one; fails on pre-fix code (`Failed asserting that 0 is identical to 3`), passes after.
- **Fix:** Both queries now check `status === 'pending_payment'`, the actual value the checkout flow sets. Commit: (this batch).
- **Evidence:**
  ```
  # pre-fix
  FAILED Tests\Feature\AdminDashboardTest > dashboard counts orders still awaiting payment
  Failed asserting that 0 is identical to 3.
  Tests: 1 failed, 2 passed (6 assertions)

  # post-fix
  PASS  Tests\Feature\AdminDashboardTest
  ✓ dashboard counts orders still awaiting payment
  ✓ dashboard month sales total excludes unpaid and cancelled orders
  ✓ dashboard low stock alert counts products under the threshold
  Tests: 3 passed (7 assertions)
  ```

### D2-F3 — Report only: dead models `Campaign` and `Post` are entirely unused

- **Severity:** P3 (report only, per the domain checklist — "removal in phase 03")
- **Status:** OPEN (not removed here — out of scope for phase 02)
- **Location:** `app/Models/Campaign.php`, `app/Models/Post.php`
- **Problem:** `grep -rn "App\\Models\\Campaign\|App\\Models\\Post\b|Campaign::|Post::"` across `app/`, `routes/`, and `database/factories/` finds zero references outside the model files themselves — no controller, route, factory, seeder, or migration-backed table interaction touches either model in the current codebase (only false-positive substring matches like `Route::post` and `EasyPost*` appear in a naive grep). Flagged per the D8/D2 checklist for phase-03 removal, not deleted now to stay in scope.

## Tests added/strengthened

- `tests/Feature/AdminDashboardTest.php` (new file):
  - `test_dashboard_counts_orders_still_awaiting_payment`
  - `test_dashboard_month_sales_total_excludes_unpaid_and_cancelled_orders` (regression guard — already correct, seeded with known totals to pin `150.00`)
  - `test_dashboard_low_stock_alert_counts_products_under_the_threshold` (regression guard — already correct, seeded to pin `2`)

## Test suite output

Full suite, run at the end of B3-full (D2+D8+Security), three consecutive runs:
```
Tests:    229 passed (1224 assertions)   Duration: 47.29s
Tests:    229 passed (1224 assertions)   Duration: 46.19s
Tests:    229 passed (1224 assertions)   Duration: 45.54s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`

## Frontend impact

None — `current_orders_count`/`alerts.pending_orders` now return the real count instead of always `0`; the field names, types, and route are unchanged, only the (previously always-wrong) value.
