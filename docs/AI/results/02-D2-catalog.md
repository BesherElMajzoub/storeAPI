# 02 D2 Catalog - results

Status: IN-PROGRESS (B3 started; spot-checked, not yet exhaustive).

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

## Tests added/strengthened

None yet this pass (spot-check only, no P0/P1 found).

## Frontend impact

None identified.
