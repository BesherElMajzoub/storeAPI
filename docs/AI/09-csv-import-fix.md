# Phase 09 — CSV Product Import: Correctness & Hardening

You are the **executor**. A reviewer AI will check your work when you are done.
Read this entire file before writing any code. Follow `00-README.md` rules
(evidence, findings format, `Verified OK` section), except where this file
says otherwise.

## Scope

Endpoint: `POST /api/v1/admin/products/import` (`multipart/form-data`: `file`, `dry_run`).

Files in scope (do not change anything outside this list unless a step says so):

| File | Role |
|---|---|
| `app/Services/ProductImportService.php` | All parsing, validation, commit logic — **main work here** |
| `app/Http/Controllers/Api/V1/Admin/ProductController.php` | `import()` method only (~line 553–600) + its OpenAPI attributes |
| `app/Http/Requests/Api/V1/Admin/ImportProductsRequest.php` | Only if needed |
| `tests/Feature/ProductImportTest.php` | Add tests; keep the 4 existing ones passing unchanged |
| `docs/PRODUCT_IMPORT_CSV.md` | Frontend contract — update to match new behavior |
| `docs/AI/results/09-csv-import-fix.md` | Your result file (create) |
| `docs/AI/PROGRESS.md` | Add a row for phase 09 |

Do **not** touch: other controllers/requests, migrations, `ProductService`,
models, untracked files already in the working tree
(`docs/backend-answers-2026-10-09.md`, `storage/**`).

## Environment facts (verified)

- Laravel 12, PHP, tests run against **MySQL** database `storeapi_testing`
  (see `phpunit.xml`). MySQL default collation is **case-insensitive**, so
  `where('sku', 'abc')` matches `ABC`. In-PHP comparisons must therefore
  normalize with `mb_strtolower` to agree with the DB.
- Run tests: `php artisan test --filter=ProductImportTest`
  (first run takes ~40 s for migrations).
- Formatter: `vendor/bin/pint <changed files>`.
- Schema: `products.sku` unique nullable, `products.stock_qty` default 0,
  `products.status` default `draft`, `products.category_id` nullable FK;
  `product_variants.sku` unique (index `product_variants_sku_unique`),
  `product_variants.stock_qty` default 0. `Product` and `Category` use
  `SoftDeletes`; `ProductVariant` does not.
- Business rules that the regular admin endpoints already enforce and the
  import must match — read them before coding:
  - `app/Http/Requests/Api/V1/Admin/StoreProductRequest.php` (`withValidator`)
  - `app/Http/Requests/Api/V1/Admin/UpdateProductRequest.php` (`withValidator`)
  Rules: published ⇒ category + `weight_oz,length_in,width_in,height_in` all
  numeric > 0; `discount_price < price`; sum of variant `stock_qty` ≤ product
  `stock_qty`; if `in_stock` not given but `stock_qty` given, `in_stock =
  stock_qty > 0`.
- Activity logging: `App\Traits\LogsActivity::logActivity($action, $description, $changes)`
  — already `use`d by `ProductController`.

## Git

1. `git checkout -b fix/csv-import` from `main`.
2. Small, focused commits (one per step group below is fine). Commit message
   style: `fix(import): ...`, `test(import): ...`, `docs(import): ...`.
   End every commit message with:
   `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
3. Do **not** push. Do **not** merge into `main`.
4. Commit this plan file (`docs/AI/09-csv-import-fix.md`) together with your
   result file in the docs commit.

## Method — test first

For every bug in Part A: write the failing test first, run it, paste the
failing output into the result file, then fix, then paste the passing output.

---

## Part A — Confirmed data-corruption bugs (P1)

All four were reproduced by the reviewer against the real endpoint on
2026-10-09. Each needs a regression test.

### A1 — Updating a product without `category_slug` wipes its category (even when published)

- **Location:** `ProductImportService.php:128` sets `$row['category_id'] = $category?->id`
  unconditionally; `upsertProduct()` line ~254 then always writes it because
  `array_key_exists` is true for a null value.
- **Reproduction:** published product `P-1` in category `dresses`; import
  `type,sku,name,price,stock_qty` / `product,P-1,Renamed,10,5` → 200,
  `category_id` becomes `NULL`, status stays `published`.
- **Decision (made by owner/reviewer, implement it):** align with every other
  column: **empty / omitted `category_slug` = keep current category;
  literal `NULL` = clear the category.** Clearing must be rejected if the
  product's final status is `published` (see A3).
- **Implementation:** only put `category_id` into the row when the
  `category_slug` key exists in the normalized row (value string → resolved
  id, value `null` → `null`). Note `normalize()` drops empty cells but keeps
  `NULL` as a key with value null — use `array_key_exists`, not `isset`.
  The current `isset($row['category_slug'])` check treats `NULL` like
  "missing"; fix that too.
- **Tests:**
  - `test_update_without_category_slug_keeps_existing_category`
  - `test_null_category_slug_clears_category_on_draft_product`
  - `test_null_category_slug_on_published_product_is_rejected`

### A2 — A variant SKU is silently moved to another parent product

- **Location:** `upsertVariant()` does `firstOrNew(['sku'])` then
  `associate($product)`.
- **Reproduction:** variant `V-1` belongs to product `A`; import
  `variant,V-1,V,B` → 200, `action=update`, variant now belongs to `B`.
- **Fix:** in analysis, when the variant SKU already exists and its
  `product_id` differs from the resolved parent product id → row error on
  `sku`: `"SKU belongs to a variant of another product (<parent sku>)."`.
  Note the parent may be a **new** product created in the same CSV — an
  existing variant can never legitimately belong to a not-yet-created
  product, so that case is also an error.
- **Test:** `test_existing_variant_sku_cannot_move_to_another_product`
  (assert 422, assert `product_id` unchanged).

### A3 — Publish rules are checked against the CSV row, not the final product state

- **Reproduction 1:** published product with full dimensions; import
  `type,sku,name,price,category_slug,weight_oz` /
  `product,D-1,D,10,x,NULL` → 200, `weight_oz` becomes NULL, still published.
- **Reproduction 2 (too strict):** existing product already has category and
  all dimensions; a row with only `status=published` (no dimension columns)
  is rejected, while `UpdateProductRequest` would accept it.
- **Fix:** for product rows, compute the **effective final state** =
  existing DB attributes (if updating) overlaid with the row's provided
  values (including explicit nulls). Validate publish rules on that merged
  state: if final `status === 'published'` ⇒ final `category_id` not null and
  all 4 dimensions numeric > 0. Remove the `required_if:status,published`
  rules from `productRules()` and replace them with this merged check
  (keep `nullable|numeric|gt:0|max:…` per-field format rules).
  Error keys stay field-based (`category_slug`, `weight_oz`, …) with messages
  identical to the update request: `"Published products require a category."`,
  `"Published products require complete shipping weight and dimensions."`.
- **Tests:**
  - `test_null_dimension_on_published_product_is_rejected`
  - `test_publishing_existing_product_uses_stored_dimensions_and_category`
  - Keep: creating a new published product without dimensions is still rejected.

### A4 — Variant stock may exceed product stock

- **Reproduction:** `product,S-1,S,,10,1,draft` + `variant,S-1-A,A,S-1,,50,` → committed.
- **Rule (same as Store/UpdateProductRequest):** for every parent product
  touched by the CSV (a product row or a parent of a variant row):
  `sum(final variant stock_qty) ≤ final product stock_qty`.
  - Final product stock = row `stock_qty` if provided, else DB value, else 0
    (new product, column default).
  - Final variant set = existing DB variants of that product, with any
    variant whose SKU appears in the CSV replaced by its CSV row
    (CSV `stock_qty` if provided, else that variant's DB stock, else 0),
    plus new CSV variants for that parent.
  - Only check when the product ends up with ≥ 1 variant.
- **Error placement:** add the error to **every variant row** of that parent
  in the CSV (key `stock_qty`), and to the product row if it is in the CSV:
  `"The total stock quantity of variants (X) cannot exceed the product's stock quantity (Y)."`
- **Tests:**
  - `test_variant_stock_sum_exceeding_product_stock_is_rejected`
  - `test_variant_stock_check_includes_existing_variants_not_in_csv`
  - `test_lowering_product_stock_below_existing_variant_sum_is_rejected`

---

## Part B — Correctness & robustness (P2)

### B1 — Discount checked against the final price
`lt:price` only compares within the row. Use the merged state from A3:
if final `discount_price` is not null and ≥ final `price` → error on
`discount_price` "The discount price must be less than price.".
Also make **`price` required only when creating** a product (on update,
fall back to DB price). Remove `required` from `price` in `productRules()`
and enforce "required on create" in the merged check.
Tests: `test_lowering_price_below_existing_discount_is_rejected`,
`test_update_row_without_price_keeps_existing_price`,
`test_create_row_without_price_is_rejected`.

### B2 — Invalid `type` produces misleading errors
A row with `type=prodcut` currently gets both a `type` error and a
`parent_sku` error. If `type` is not `product`/`variant`, report only
`type: "Type must be product or variant."` and skip all other checks for
that row (still count it as an error row).
Test: `test_invalid_type_reports_only_type_error`.

### B3 — Consistent SKU normalization
Normalize once: trim SKUs and `parent_sku`; use `mb_strtolower(sku)` as the
key for every in-memory map (CSV duplicates, CSV product set, DB lookups).
The current in-file parent lookup (lines ~139–140) compares **raw**
unnormalized `type`/`sku` with `===` — replace it with the normalized map.
Test: `test_variant_parent_sku_matches_csv_product_case_insensitively`
(product row `ABC-1`, variant row with `parent_sku=abc-1`, `type=Product`).

### B4 — Commit-time conflicts must not return 500/404
Analysis runs before the transaction, so concurrent writes can cause
`Illuminate\Database\UniqueConstraintViolationException` (500) or
`ModelNotFoundException` from `firstOrFail()` in `upsertVariant()` (404).
- In `process()`, catch `UniqueConstraintViolationException` around the
  transaction and throw a dedicated exception (e.g. a small
  `App\Exceptions\ProductImportConflictException extends RuntimeException`)
  that the controller maps to **409** with message
  `"The catalog changed during import; run the preview again."`.
- In `upsertVariant()`, resolve the parent from the id map built during
  commit (products upserted in the same transaction) instead of
  `firstOrFail()` by SKU; if missing, throw the same conflict exception.
- The controller currently catches `RuntimeException` → 422; make sure the
  conflict exception is caught **before** it (or is not a subclass) so it
  returns 409.
Test: `test_unique_conflict_during_commit_returns_409_without_writes` —
simulate by registering a `Product::creating` listener in the test that
inserts a conflicting row via `DB::table(...)->insert` (or another reliable
way); assert 409 and that no imported rows exist. If you cannot produce a
reliable test, document why in the result file and cover the mapping with a
unit-level test of the controller/exception mapping instead.

### B5 — Clear message for malformed JSON
When `options`/`attributes` is a non-empty string that fails `json_decode`,
report `"<field> must be valid JSON."` (instead of the generic "must be an
array"). Valid JSON that is not an array/object (e.g. `5`, `"x"`) still
gets "must be an array/object". Update the existing duplicate/JSON test
expectations only if counts change (they must not: still 2 error rows).

---

## Part C — Performance (P2)

### C1 — Remove N+1 queries and the O(n²) parent scan
Currently each row runs 2–4 queries and each variant row scans all rows
(`collect($rows)->contains(...)`). At 5,000 rows that is ~15–20k queries
and up to 25M closure calls.

Restructure `analyze()` into two passes:
1. **Pass 1 (in memory):** normalize every row, collect sets of SKUs,
   parent SKUs, category slugs.
2. **Preload with batched `whereIn`** (chunk arrays of ≤ 1,000 values):
   - categories by slug (non-trashed) → `slug => id`
   - products by SKU **withTrashed** → `lower(sku) => Product` (select only
     needed columns: id, sku, status, category_id, price, discount_price,
     stock_qty, weight_oz, length_in, width_in, height_in, deleted_at)
   - variants by SKU (CSV variant SKUs **and** CSV product SKUs, for
     collision checks) → `lower(sku) => ProductVariant`
   - existing variants of every touched parent product id (for A4):
     `product_id, sku, stock_qty`
3. **Pass 2:** validate each row against the maps — no queries inside the loop.

Commit phase: keep `ProductService::generateUniqueSlug` (it queries; fine),
but build a `lower(sku) => product id` map while upserting products and use
it for variants.

Test: `test_five_thousand_row_import_uses_bounded_queries` — 4,000 product
rows + 1,000 variant rows, `dry_run=true`, wrap in `DB::enableQueryLog()`,
assert total queries for the analysis < 50 (pick a bound you can justify;
document the measured number). Also record the wall-clock time in the result file.

---

## Part D — Usability (P3)

### D1 — Case-insensitive headers
Lowercase + trim headers (after BOM removal). `Type,SKU,Name` must work.
Uniqueness check applies after lowercasing.

### D2 — Delimiter detection
Detect the delimiter from the header line: count `,` `;` `\t` outside
quotes, pick the most frequent (default `,`). Apply with
`$csv->setCsvControl($delimiter)`. Excel in many locales exports `;`.

### D3 — Reject non-UTF-8 files
Before parsing, `mb_check_encoding(file_get_contents(...), 'UTF-8')`;
if false → `RuntimeException('CSV must be UTF-8 encoded. In Excel use "CSV UTF-8 (Comma delimited)".')` (422).

### D4 — Unknown columns as warnings
Known columns = union of product + variant fields + `type, sku, parent_sku, slug, category_slug`.
Return `data.warnings: ["Unknown column \"stok_qty\" is ignored."]`
(empty array when none). Warnings never block the import.

### D5 — Preview shows what will change
For `update` rows add `changes: { field: [old, new], ... }` containing only
fields whose value actually changes (compare normalized: decimals as
strings with 2 decimals, booleans as bool, JSON arrays by value). For
`create` rows `changes` is `null`. Optional but recommended: for product
creates, include `slug` preview is **not** required (slug generation needs
the DB inside the transaction) — skip it.

### D6 — Audit log
After a successful commit, the controller calls
`$this->logActivity('import_products', "Imported products from CSV", ['summary' => ..., 'skus' => <first 100 SKUs>])`.
Not logged on dry run or failure.

### D7 — Row numbers
Verify `row` numbers match the physical spreadsheet row when the file has
blank lines and quoted multi-line cells. If `SplFileObject::key()` drifts,
fix it (e.g. track line numbers yourself) or document the exact semantics
in `docs/PRODUCT_IMPORT_CSV.md`. Add a test with a blank line in the middle.

Tests for D1–D6: one test each, named `test_<what>`.

---

## Part E — Docs & contract

Update `docs/PRODUCT_IMPORT_CSV.md` ("Last verified" → 2026-10-09):
- `category_slug`: omitted keeps, `NULL` clears (replace the "Important
  update behavior" paragraph).
- `price`: required on create, optional on update.
- Publish rules evaluated on final state (existing values count).
- Variant stock rule; variant cannot move between products.
- Header case-insensitive; `,` `;` tab delimiters; UTF-8 required.
- New response fields: `data.warnings`, `rows[].changes`; new 409 status.
Update the controller's OpenAPI attributes: add `#[OA\Response(response: 409, ...)]`.

## Part F — Final verification (paste real output in the result file)

1. `php artisan test --filter=ProductImportTest` — all green.
2. `php artisan test --filter=RemainingJourneysTest` — all green (journey J11 uses the import).
3. `php artisan test` — full suite; report totals. If anything outside import
   fails, report the failing test names and whether they touch import code
   at all. Do not use `git stash` (the working tree has untracked user files).
4. `vendor/bin/pint --test app/Services/ProductImportService.php app/Http/Controllers/Api/V1/Admin/ProductController.php tests/Feature/ProductImportTest.php` — clean.
5. `git log --oneline main..fix/csv-import` — list commits.

## Result file

Create `docs/AI/results/09-csv-import-fix.md` with:
- One finding per item (IDs `CSV-A1` … `CSV-D7`) using `templates/finding.md`.
- `## Verified OK` — things checked and correct (e.g. BOM handling, 5,000-row
  limit, all-or-nothing transaction, products-before-variants ordering).
- `## Deviations` — anything you did differently from this plan and why.
- The Part F outputs.

Set phase 09 to `READY-FOR-REVIEW` in `PROGRESS.md`, then stop.

## Quality bar (reviewer will check)

- No query inside the per-row validation loop.
- `ProductImportService` stays readable: extract private methods
  (`readCsv`, `detectDelimiter`, `preload`, `effectiveProductState`,
  `validateProductRow`, `validateVariantRow`, `validateVariantStock`,
  `diff`). No method over ~60 lines.
- Match existing code style (constructor promotion, `collect()`, short
  closures, no docblocks where the existing code has none).
- Existing response shape unchanged except the additive fields listed above.
- Every bug in Part A has a test that failed before the fix.
