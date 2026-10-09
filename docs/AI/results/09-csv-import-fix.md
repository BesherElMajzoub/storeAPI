# Phase 09 — CSV Product Import: Correctness & Hardening — Result

- **Branch:** `fix/csv-import` (from `main` @ `46e8219`). Not pushed, not merged.
- **Plan:** `docs/AI/09-csv-import-fix.md`
- **Environment:** Windows 11, PHP 8.5.2, Laravel 12, MySQL `storeapi_testing` (127.0.0.1:3308).
- **Status:** READY-FOR-REVIEW

## Commits

| SHA | Subject |
|---|---|
| `4dcf82f` | fix(import): validate final product state, variant ownership and stock [CSV-A1..A4, CSV-C1] |
| `44c443d` | fix(import): final-state price rules, clear row errors and 409 on commit conflicts [CSV-B1..B5] |
| `06e792b` | fix(import): header, delimiter, encoding, warnings, change preview and audit log [CSV-D1..D7] |
| `5bac3fc` | fix(import): drop unnecessary nullsafe access flagged by PHPStan; cover NULL in all non-nullable columns [CSV-X1] |
| _docs commit_ | docs(import): contract, plan and phase 09 result (this file) |

## Method

All tests in `tests/Feature/ProductImportTest.php` for A, B, C and D (plus the
two extra findings X1/X2) were written first and run **against the unchanged
`main` service and controller** in a single run. Output saved verbatim; the
relevant excerpts are quoted under each finding. Summary line of that run:

```
  ⨯ update without category slug keeps existing category                                                         0.49s
  ✓ null category slug clears category on draft product                                                          0.49s
  ⨯ null category slug on published product is rejected                                                          0.18s
  ⨯ existing variant sku cannot move to another product                                                          0.07s
  ⨯ null dimension on published product is rejected                                                              0.09s
  ⨯ publishing existing product uses stored dimensions and category                                              0.16s
  ⨯ creating published product without dimensions is rejected                                                    0.09s
  ⨯ variant stock sum exceeding product stock is rejected                                                        0.12s
  ⨯ variant stock check includes existing variants not in csv                                                    0.08s
  ⨯ lowering product stock below existing variant sum is rejected                                                0.09s
  ⨯ lowering price below existing discount is rejected                                                           0.13s
  ⨯ update row without price keeps existing price                                                                0.06s
  ✓ create row without price is rejected                                                                         0.06s
  ⨯ invalid type reports only type error                                                                         0.09s
  ⨯ variant parent sku matches csv product case insensitively                                                    0.05s
  ⨯ unique conflict during commit returns 409 without writes                                                     0.17s
  ⨯ malformed json reports valid json message                                                                    0.13s
  ⨯ null in non nullable column is a row error                                                                   0.07s
  ⨯ five thousand row import uses bounded queries                                                               21.73s
  ⨯ headers are case insensitive                                                                                 0.06s
  ⨯ semicolon delimiter is detected                                                                              0.11s
  ⨯ non utf8 file is rejected                                                                                    1.40s
  ⨯ unknown columns are reported as warnings                                                                     0.10s
  ⨯ preview reports changes for update rows                                                                      0.15s
  ⨯ successful commit is audit logged                                                                            0.10s
  ⨯ row numbers match spreadsheet rows with blank lines and multiline cells                                      0.09s
  Tests:    24 failed, 6 passed (71 assertions)
```

(The 4 pre-existing tests passed in that run; `test_database_errors_are_not_reported_as_csv_file_errors`
and `test_rows_without_sku_and_numeric_skus_are_handled` were added later — see CSV-X2 and Verified OK.)

Two fixtures were adjusted after this run, both before any fix landed for them, and both
without loosening an assertion: `test_update_row_without_price_keeps_existing_price` and
`test_preview_reports_changes_for_update_rows` create their product with `status => draft`.
The factory default is `published` **without a category**, which the new final-state publish rule
(A3) correctly rejects on any update — the tests are about price and `changes`, not publishing.
`test_preview_reports_changes_for_update_rows` also gained a variant update row and a no-change
re-import (more assertions, none removed).

---

## Part A — Data corruption (P1)

### CSV-A1 — Update without `category_slug` wiped the product's category

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Services/ProductImportService.php` (old line 128 `$row['category_id'] = $category?->id` + `upsertProduct()` `array_key_exists('category_id')`)
- **Problem:** `category_id` was always put in the row (null when the column was omitted), and the upsert always wrote it.
- **Scenario:** Published `P-1` in `dresses`; import `product,P-1,Renamed,10,5` (no `category_slug` column) → 200, `category_id` NULL, status still `published`.
- **Test:** `test_update_without_category_slug_keeps_existing_category` (failed before), `test_null_category_slug_clears_category_on_draft_product` (passed before — behaviour preserved), `test_null_category_slug_on_published_product_is_rejected` (failed before).
- **Fix:** `validateProductRow()` adds `category_id` only when the `category_slug` key exists in the normalized row (`array_key_exists`, so a literal `NULL` → `null`, an omitted/empty cell → key absent → stored category kept). Clearing on a product whose final status is published is rejected by the A3 state check. Commit: `4dcf82f`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > update without category slug keeps existing category
  Failed asserting that null is identical to 2.

  FAILED  Tests\Feature\ProductImportTest > null category slug on published product is rejected
  Expected response status code [422] but received 200.
  ```
  After (`4dcf82f`): `✓ update without category slug keeps existing category`, `✓ null category slug clears category on draft product`, `✓ null category slug on published product is rejected` (full final run in Part F).

### CSV-A2 — An existing variant SKU could be moved to another product

- **Severity:** P1
- **Status:** FIXED
- **Location:** `upsertVariant()` (`firstOrNew(['sku'])` + `associate($product)`)
- **Problem:** A variant row matching an existing variant SKU re-parented it to whatever `parent_sku` said.
- **Scenario:** `V-1` belongs to `A`; import `variant,V-1,V,B` → 200, `action=update`, `V-1` now under `B`.
- **Test:** `test_existing_variant_sku_cannot_move_to_another_product` — asserts 422, message, and `product_id` unchanged.
- **Fix:** The variant preload joins the owning product (`products.sku as owner_sku`). `validateVariantRow()` errors with `SKU belongs to a variant of another product (<owner sku>).` when the existing variant's `product_id` differs from the resolved, non-trashed parent id — which also covers a parent that is a new product in the same CSV (its id is null). At commit, `upsertVariant()` re-checks ownership inside the transaction and throws the 409 conflict exception if it changed meanwhile. Commits: `4dcf82f`, `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > existing variant sku cannot move to another product
  Expected response status code [422] but received 200.
  ```
  After: `✓ existing variant sku cannot move to another product`.

### CSV-A3 — Publish rules were checked on the CSV row, not the final product

- **Severity:** P1
- **Status:** FIXED
- **Location:** `productRules()` (`required_if:status,published` on category and dimensions)
- **Problem:** Too lax (explicit `NULL` on a published product passed because `status` was not in the row) and too strict (publishing a product that already has category + dimensions required repeating them).
- **Scenario 1:** Published `D-1` with dimensions; `product,D-1,D,10,x,NULL` (`weight_oz=NULL`) → 200, weight cleared, still published.
- **Scenario 2:** Draft `E-1` with category + dimensions; `product,E-1,E,10,published` → 422 (rules asked for the columns again).
- **Test:** `test_null_dimension_on_published_product_is_rejected`, `test_publishing_existing_product_uses_stored_dimensions_and_category`, `test_creating_published_product_without_dimensions_is_rejected` (kept behaviour; it failed before only because the message was the generic `required_if` one).
- **Fix:** Removed every `required_if:status,published`. `effectiveProductState()` = stored attributes (or create defaults: `draft`, stock 0, everything else null) overlaid with the row's provided keys including explicit nulls; `productStateErrors()` applies the publish rule to that state with the exact `UpdateProductRequest` messages, keyed `category_slug` / each dimension. If `category_slug` was given but not found, the "requires a category" error is suppressed (one clear error instead of two). Commit: `4dcf82f`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > null dimension on published product is rejected
  Expected response status code [422] but received 200.

  FAILED  Tests\Feature\ProductImportTest > publishing existing product uses stored dimensions and category
  Expected response status code [200] but received 422.

  FAILED  Tests\Feature\ProductImportTest > creating published product without dimensions is rejected
  -'Published products require complete shipping weight and dimensions.'
  +'The weight oz field is required when status is published.'
  ```
  After: all three `✓`.

### CSV-A4 — Variant stock could exceed product stock

- **Severity:** P1
- **Status:** FIXED
- **Location:** `analyze()` (no stock-sum rule at all)
- **Problem:** The import skipped the `sum(variant stock) ≤ product stock` rule enforced by `StoreProductRequest`/`UpdateProductRequest`.
- **Scenario:** `product,S-1,S,,10,1,draft` + `variant,S-1-A,A,S-1,,50,` → committed with 50 > 1.
- **Test:** `test_variant_stock_sum_exceeding_product_stock_is_rejected` (error on both rows, nothing written), `test_variant_stock_check_includes_existing_variants_not_in_csv` (8 stored + 5 new > 10), `test_lowering_product_stock_below_existing_variant_sum_is_rejected` (stock 10 → 5 with 8 in variants; stock unchanged).
- **Fix:** `validateVariantStock()` + `groupByParent()` run after the per-row pass, entirely in memory. Final product stock = row value, else stored, else 0. Final variant set = stored variants of the parent (preloaded `product_id, sku, stock_qty`), each replaced by its CSV row when present (row stock, else stored stock, else 0), plus new CSV variants. Checked only when ≥ 1 variant; skipped when any involved value is not an integer (that row already has a format error). The error goes on every CSV variant row of that parent and on the product row if present, key `stock_qty`, message `The total stock quantity of variants (X) cannot exceed the product's stock quantity (Y).` Commit: `4dcf82f`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > variant stock sum exceeding product stock is rejected
  Expected response status code [422] but received 200.
  FAILED  Tests\Feature\ProductImportTest > variant stock check includes existing variants not in csv
  Expected response status code [422] but received 200.
  FAILED  Tests\Feature\ProductImportTest > lowering product stock below existing variant sum is rejected
  Expected response status code [422] but received 200.
  ```
  After: all three `✓`.

---

## Part B — Correctness & robustness (P2)

### CSV-B1 — Discount checked only within the row; price required on update

- **Severity:** P2
- **Status:** FIXED
- **Location:** `productRules()` (`'price' => required`, `'discount_price' => lt:price`)
- **Scenario:** Stored price 100 / discount 80; `product,B-1,B,50` → 200 with discount 80 ≥ price 50. And `product,B-2,Renamed` (no price) → 422 although the product exists.
- **Test:** `test_lowering_price_below_existing_discount_is_rejected`, `test_update_row_without_price_keeps_existing_price`, `test_create_row_without_price_is_rejected` (passed before; keeps the create rule).
- **Fix:** `price` → `sometimes|numeric|min:0`; `lt:price` removed. `productStateErrors()` adds `The price field is required.` only when creating and the row has no `price` key, and `The discount price must be less than price.` when final discount ≥ final price. Commit: `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > lowering price below existing discount is rejected
  Expected response status code [422] but received 200.
  FAILED  Tests\Feature\ProductImportTest > update row without price keeps existing price
  Expected response status code [200] but received 422.
  ```
  After: all three `✓`.

### CSV-B2 — Invalid `type` produced misleading extra errors

- **Severity:** P2
- **Status:** FIXED
- **Scenario:** `prodcut,T-1,T,,10` → `type` error **and** `parent_sku: Parent product SKU was not found.`
- **Test:** `test_invalid_type_reports_only_type_error` (asserts the whole `errors` object equals `{type: ["Type must be product or variant."]}` and the row counts as an error).
- **Fix:** `analyze()` short-circuits rows whose normalized type is not `product`/`variant`; they are also excluded from the SKU-duplicate map, the preload sets and the stock groups. Commit: `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > invalid type reports only type error
  +        0 => 'The selected type is invalid.',
  +    'parent_sku' => Array &2 [
  +        0 => 'Parent product SKU was not found.',
  ```
  After: `✓ invalid type reports only type error`.

### CSV-B3 — Inconsistent SKU normalization (raw in-file parent lookup)

- **Severity:** P2
- **Status:** FIXED
- **Location:** old lines 139–140 (`collect($rows)->contains(... ['type'] === 'product' && ['sku'] === parent_sku)` on raw values)
- **Scenario:** `Product,ABC-1,...` + `variant,ABC-1-RED,Red,abc-1,...` → 422 `Parent product SKU was not found.`
- **Test:** `test_variant_parent_sku_matches_csv_product_case_insensitively` (commit succeeds, variant attached to the product).
- **Fix:** `normalize()` trims every cell and computes `key = mb_strtolower(sku)` and `parent_key = mb_strtolower(parent_sku)` once; every in-memory map (duplicates, CSV product set, preloaded products/variants/categories, stock groups, commit id map) is keyed by them. Landed with the C1 restructure in `4dcf82f`; the regression test was committed in `44c443d` (it was green from `4dcf82f` on).
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > variant parent sku matches csv product case insensitively
  Expected response status code [200] but received 422.
  ```
  After: `✓`.

### CSV-B4 — Commit-time conflicts returned 422 with raw SQL (plan expected 500/404)

- **Severity:** P2
- **Status:** FIXED
- **Location:** `process()` / `upsertVariant()` / `ProductController::import()`
- **Problem:** Analysis runs before the transaction. A concurrent insert of the same SKU raised `UniqueConstraintViolationException`. Because that class extends `QueryException → PDOException → RuntimeException`, the controller's `catch (\RuntimeException)` turned it into a **422 file error containing the SQL statement, host, port and database name** (not a 500 as the plan assumed). `firstOrFail()` in `upsertVariant()` would have been a 404 for a parent deleted meanwhile.
- **Scenario:** `Product::creating` listener inserts `RACE-2` just before the import's own insert → see evidence.
- **Test:** `test_unique_conflict_during_commit_returns_409_without_writes` — real conflict (the plan's preferred approach, no mocking): asserts 409, `success=false`, the message, and that neither `RACE-1` (written earlier in the same transaction) nor the `Second` row exists.
- **Fix:** New `App\Exceptions\ProductImportConflictException` (extends `RuntimeException`, fixed message). `process()` wraps the transaction and converts `UniqueConstraintViolationException`. `commit()` builds `lower(sku) => id` from existing parents (one batched `whereIn ... lockForUpdate`) plus each upserted product; `upsertVariant(array $row, int $productId)` takes the id from that map (missing → conflict) and throws the conflict if the variant now belongs to another product. The controller catches the conflict **first** and returns `409` via the existing `error()` envelope (`errors: null`). Commit: `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > unique conflict during commit returns 409 without writes
  Expected response status code [409] but received 422.
  {
      "success": false,
      "message": "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'RACE-2' for key 'products.products_sku_unique' (Connection: mysql, Host: 127.0.0.1, Port: 3308, Database: storeapi_testing, SQL: insert into `products` ...
  ```
  After: `✓ unique conflict during commit returns 409 without writes`.

### CSV-B5 — Malformed JSON reported as "must be an array"

- **Severity:** P3
- **Status:** FIXED
- **Test:** `test_malformed_json_reports_valid_json_message` (`{bad` → `options must be valid JSON.` only; `5` → `The options field must be an array.`). The pre-existing `test_duplicate_sku_and_malformed_json_are_reported` still reports exactly 2 error rows (unchanged).
- **Fix:** `normalize()` records fields whose `json_decode` fails in `invalid_json`; `validationErrors()` reports `<field> must be valid JSON.` for them and removes them from the validator input so the array rule does not add a second message. Commit: `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > malformed json reports valid json message
  +    0 => 'The options field must be an array.',
  ```
  After: `✓`.

### CSV-X1 — `NULL` in a NOT NULL column caused a database error (found during the work)

- **Severity:** P2
- **Status:** FIXED
- **Location:** `productRules()`/`variantRules()` (`stock_qty`/`status` were `nullable`), `normalize()` (`filter_var(null, BOOLEAN)` silently turned `in_stock=NULL` into `false`)
- **Problem:** The contract says `NULL` clears a field, but `products.stock_qty`, `status`, `in_stock`, `is_featured` and `product_variants.stock_qty` are NOT NULL. The row passed validation and the commit hit `Column 'stock_qty' cannot be null`, which the controller reported as a 422 **file** error containing SQL (same mechanism as B4).
- **Scenario:** Existing `N-1` (stock 7); `product,N-1,N,10,NULL` → 422 with SQL text in `errors.file`, `data: null`.
- **Test:** `test_null_in_non_nullable_column_is_a_row_error` — row error `The stock qty field must be an integer.`, stock still 7; then `NULL` in `name, price, status, in_stock, is_featured` (product) and `stock_qty` (variant) each produce a row error.
- **Fix:** `stock_qty` (product + variant) and `status` → `sometimes`; booleans are only normalized when non-null, so `NULL` fails `boolean`. Commits: `44c443d`, `5bac3fc` (extended assertions).
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > null in non nullable column is a row error
  Failed asserting that null is identical to 'The stock qty field must be an integer.'.
  ```
  After: `✓ null in non nullable column is a row error`.

### CSV-X2 — Any database error was reported as a 422 "file" error with raw SQL

- **Severity:** P2 (information disclosure: SQL, DB host/port/name in the response body even with `APP_DEBUG=false`)
- **Status:** FIXED
- **Location:** `ProductController::import()` — `catch (\RuntimeException $e)` also catches `Illuminate\Database\QueryException`.
- **Test:** `test_database_errors_are_not_reported_as_csv_file_errors` — the service is mocked to throw a `QueryException`; asserts 500 and no `errors.file`. Run against the old controller (service left at the new version) before the controller fix:
- **Fix:** `catch (QueryException $e) { throw $e; }` between the conflict catch and the generic `RuntimeException` catch, so database failures go through the framework handler (500, message hidden in production). Commit: `44c443d`.
- **Evidence:**
  ```
  FAILED  Tests\Feature\ProductImportTest > database errors are not reported as csv file errors
  Expected response status code [500] but received 422.
  {
      "success": false,
      "message": "SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into `products`)",
      "data": null,
      "errors": {
          "file": [
              "SQLSTATE[HY000]: General error (Connection: mysql, SQL: insert into `products`)"
  ```
  After: `✓ database errors are not reported as csv file errors`.

---

## Part C — Performance (P2)

### CSV-C1 — N+1 queries and O(n²) parent scan

- **Severity:** P2
- **Status:** FIXED
- **Location:** old `analyze()` (2–4 queries per row, `collect($rows)->contains()` per variant row)
- **Scenario:** 4,000 product rows + 1,000 variant rows, `dry_run=true`.
- **Test:** `test_five_thousand_row_import_uses_bounded_queries` — query log around the whole HTTP request; asserts `< 50` queries, 5,000 creates, 0 errors. Timing printed when `IMPORT_PERF=1`.
- **Fix:** `analyze()` is two-pass. Pass 1 normalizes every row (`normalize()`); `preload()` runs batched `whereIn` (chunks of 1,000 via `chunked()`) for categories by slug, products by SKU `withTrashed` (CSV product SKUs ∪ parent SKUs ∪ variant SKUs, for the collision checks), variants by SKU joined to the owner SKU (CSV variant SKUs ∪ product SKUs), and existing variants of every touched parent id (for A4). Pass 2 validates against those maps — **no query inside the per-row loop** (the Laravel validator rules used have no DB rules). Commit: `4dcf82f`.
- **Bound justification:** rows ≤ 5,000, so distinct product-preload values (row SKU + parent SKU) ≤ 10,000 → ≤ 10 chunks; variant preload ≤ 5 chunks; parent-variant preload ≤ 5; categories ≤ 5; plus the admin role check and the generic `AuditAdminActions` insert → worst case ≈ 27 < 50.
- **Measured (this test, final code):** 13 queries — 1 role check, 5 product chunks, 5 variant chunks, 1 category query, 1 `audit_logs` insert from the existing `AuditAdminActions` middleware (no existing products, so no parent-variant query). Wall clock **5.3–6.7 s** across 8 runs during the work (5.66 s in the final run) (before: **15,002 queries, 21.57 s**).
- **Where the remaining time goes:** the Laravel validator. A standalone benchmark (bootstrapped app, same product rules, no DB) takes 5.46 s for 5,000 `Validator::make()->errors()` calls; reusing one validator with `setData()` was not faster (6.61 s). Replacing the validator with hand-written checks was out of scope.
- **Evidence:**
  ```
  before:  [import-perf] queries=15002 time=21.57s
           Failed asserting that 15002 is less than 50.
  after:   [import-perf] queries=13 time=5.36s
           ✓ five thousand row import uses bounded queries                       5.47s
  ```
  Query list from an instrumented run (instrumentation not committed):
  ```
  [import-perf] queries=13 time=6.06s
  select exists(select * from `roles` inner join `role_user` ... [bindings=5]
  select `id`, `sku`, `category_id`, `deleted_at`, `name`, `description`, `price`, ... [bindings=1000]   (x5)
  select `product_variants`.`id`, `product_variants`.`product_id`, ... [bindings=1000]                   (x5)
  select `id`, `slug` from `categories` where `slug` in (?) and `categories`.`deleted_at` is null [bindings=1]
  insert into `audit_logs` (`causer_id`, ... ) values (?, ?, ?, ? [bindings=8]
  ```

---

## Part D — Usability (P3)

### CSV-D1 — Headers were case-sensitive

- **Severity:** P3 · **Status:** FIXED · **Commit:** `06e792b`
- **Scenario:** `Type,SKU,Name,PRICE` → 422 `CSV must include type, sku, and name headers.`
- **Test:** `test_headers_are_case_insensitive` (header ` Type ,SKU,Name,PRICE` creates the product; also asserts `data.warnings == []`).
- **Fix:** `readHeader()` strips the BOM, trims and `mb_strtolower`s every header, then checks uniqueness/non-empty.
- **Evidence:** before `Expected response status code [200] but received 422.` / `"message": "CSV must include type, sku, and name headers."`; after `✓`.

### CSV-D2 — Only comma was supported

- **Severity:** P3 · **Status:** FIXED · **Commit:** `06e792b`
- **Scenario:** `type;sku;name;price;description` → 422 missing headers.
- **Test:** `test_semicolon_delimiter_is_detected` (also checks a quoted `"a, b"` cell survives).
- **Fix:** `detectDelimiter()` counts `,` `;` `\t` outside quotes on the header line, most frequent wins (stable sort → `,` on ties/none); applied with `setCsvControl($delimiter, '"', '\\')` — the escape argument is passed explicitly because PHP 8.5 deprecates relying on its default (the value is the old default, so parsing is unchanged).
- **Evidence:** before `Expected response status code [200] but received 422.`; after `✓`.

### CSV-D3 — Non-UTF-8 files caused a 500

- **Severity:** P3 (was a 500 on a reachable path) · **Status:** FIXED · **Commit:** `06e792b`
- **Scenario:** Latin-1 `Caf\xE9` → `InvalidArgumentException: Malformed UTF-8 characters` while encoding the JSON response → 500.
- **Test:** `test_non_utf8_file_is_rejected` (422 + exact message, nothing written).
- **Fix:** `readCsv()` checks `mb_check_encoding($contents, 'UTF-8')` before parsing.
- **Evidence:**
  ```
  Expected response status code [422] but received 500.
  InvalidArgumentException: Malformed UTF-8 characters, possibly incorrectly encoded in
  D:\store\PROJECT\storeAPI\vendor\laravel\framework\src\Illuminate\Http\JsonResponse.php:91
  ```
  after `✓`.

### CSV-D4 — Unknown columns were silently ignored

- **Severity:** P3 · **Status:** FIXED · **Commit:** `06e792b` (column filtering itself landed in `4dcf82f`)
- **Test:** `test_unknown_columns_are_reported_as_warnings` (`stok_qty` → `data.warnings == ['Unknown column "stok_qty" is ignored.']`, still 0 errors).
- **Fix:** `unknownColumnWarnings()` compares the header with `KNOWN_COLUMNS` (identity columns ∪ product fields ∪ variant fields); `normalize()` drops unknown columns from the row so they can never reach the model (e.g. a stray `category_id` column).
- **Evidence:** before `Failed asserting that null is identical to Array &0 [ 0 => 'Unknown column "stok_qty" is ignored.', ]`; after `✓`.

### CSV-D5 — Preview did not show what will change

- **Severity:** P3 · **Status:** FIXED · **Commit:** `06e792b`
- **Test:** `test_preview_reports_changes_for_update_rows` — product update → `{name: ["Old","New Name"], is_featured: [false,true]}` (price `100.00` vs `100`, same stock, same options and derived `in_stock` are **not** reported); variant update → `{price: ["20.00","20.50"], stock_qty: [2,3]}`; product and variant creates → `null`; a no-op update → `{}`.
- **Fix:** `summarize()` adds `changes`; `diff()` compares the values that will actually be written — `productData()` is shared with `upsertProduct()`, so derived `in_stock` and `category_id` are included — via `sameValue()`/`displayValue()`: decimals as 2-decimal strings, `stock_qty`/`category_id` as ints, booleans as bool, arrays by value (`==`, key-order independent). Empty diff is returned as `{}` (stdClass) so the type is always an object; creates and error rows are `null`. `slug` is not previewed (plan allows skipping).
- **Evidence:** before `Failed asserting that null is identical to Array &0 [ 'name' => ..., 'is_featured' => ... ]`; after `✓`.

### CSV-D6 — Imports were not audit-logged specifically

- **Severity:** P3 · **Status:** FIXED · **Commit:** `06e792b`
- **Test:** `test_successful_commit_is_audit_logged` — dry run leaves no `import_products` row; commit writes exactly one (`sole()`) with description `Imported products from CSV`, `changes.skus == ['AU-1']`, `changes.summary.creates == 1`.
- **Fix:** `ProductController::import()` calls `logActivity('import_products', 'Imported products from CSV', ['summary' => ..., 'skus' => first 100 row SKUs])` only when `committed` is true (failures return earlier or have `committed=false`).
- **Evidence:** before `ModelNotFoundException  No query results for model [App\Models\AuditLog].`; after `✓`.

### CSV-D7 — Row numbers with blank lines / multi-line cells

- **Severity:** P3 · **Status:** VERIFIED (no drift) + small FIX for delimiter-only rows · **Commit:** `06e792b`
- **Finding:** `SplFileObject::key()` with `READ_CSV|SKIP_EMPTY|DROP_NEW_LINE` is the record index, which already equals the spreadsheet row: a blank line counts as a row, a quoted multi-line cell is one row. Checked standalone on PHP 8.5.2 before changing anything:
  ```
  1 => ["type","sku","name","description"]
  2 => ["product","R-1","One","line a\nline b"]
  3 => ["product","R-2","Two","x"]
  5 => ["product","R-3","Three","y"]
  ```
  (line 4 of the file was blank). The semantics are now documented in `docs/PRODUCT_IMPORT_CSV.md` ("Row numbers") and in a comment in `readCsv()`.
- **Fix:** rows that contain only delimiters/whitespace (`,,,,` — what Excel writes for formatted-but-empty rows) were counted as data rows and failed with `type`/`sku`/`name` errors; they are now skipped like blank lines (still counted for numbering).
- **Test:** `test_row_numbers_match_spreadsheet_rows_with_blank_lines_and_multiline_cells` — multi-line cell in row 2, row 3, blank row 4, `,,,,` row 5, error row reported as **6**, `summary.rows == 3`.
- **Evidence:** before `Failed asserting that 4 is identical to 3.` (the `,,,,` row was counted as a data row; the row numbers themselves were already right); after `✓`.

---

## Verified OK

- **BOM handling:** `readHeader()` strips `\xEF\xBB\xBF` from the first header before trimming/lower-casing; `detectDelimiter()` strips it before counting.
- **5,000-row limit:** unchanged check in `readCsv()` (counts data rows only, blank/delimiter-only rows excluded); `test_files_over_five_thousand_rows_are_rejected_without_writes` still green with its original assertions.
- **All-or-nothing:** any row error → no transaction is opened (`process()` returns before `DB::transaction`); a failure inside the transaction rolls everything back — proven by `test_any_invalid_row_prevents_all_writes_and_reports_row_number` and by `test_unique_conflict_during_commit_returns_409_without_writes` (`RACE-1`, written before the failing row, is absent).
- **Products before variants:** `commit()` upserts every product row before any variant row and resolves variant parents from the id map built in that order — `test_preview_and_commit_share_the_same_product_and_variant_analysis` and `test_variant_parent_sku_matches_csv_product_case_insensitively` create a product and its variant in one file.
- **Preview/commit parity:** commit uses exactly the normalized rows produced by the same analysis (`process()` keeps `normalized` from `analyze()`); nothing is re-validated differently.
- **No query in the per-row loop:** `validateProductRow()`, `validateVariantRow()`, `validationErrors()` (no `exists`/`unique` rules), `validateVariantStock()`, `diff()` only read the preloaded maps; the C1 test bounds the total.
- **Rows without SKU / numeric SKUs:** `skuKey()` returns null for a missing SKU and `find()` never uses a null array offset (deprecated in PHP 8.5); numeric SKUs (`12345`) become integer array keys consistently on both sides — `test_rows_without_sku_and_numeric_skus_are_handled`.
- **Existing SKU casing preserved on update:** `firstOrNew(['sku' => ...])` matches case-insensitively in MySQL and `productData()` never writes `sku`, so `abc-1` in a CSV does not rename `ABC-1`.
- **Soft-deleted parents/categories:** parent lookups ignore trashed products (`Parent product SKU was not found.`); `Category::whereIn` uses the default soft-delete scope, so a trashed category slug is "not found".
- **Generic admin audit middleware:** `AuditAdminActions` already logs every non-GET admin request (`post_admin_api`) including dry runs; D6 adds the import-specific entry only on commit. The D6 test checks `import_products` specifically.
- **J11 journey** (admin catalog → import preview → audit logs) still green — Part F.
- **Existing 4 tests** in `ProductImportTest` unchanged and green (no assertion removed: `git diff main -- tests | grep '^-.*assert'` is empty — see Part F).

## Deviations

1. **C1 landed with Part A (`4dcf82f`), not as its own commit.** A3 and A4 need the stored product state, existing variants and variant owners; implementing them with per-row queries and then rewriting for C1 would have been throw-away work. The C1 test is in the same commit. B3 is a by-product of the same restructure, so its test passed from `4dcf82f`; it failed against `main` (evidence above).
2. **B4 original behaviour was 422 with raw SQL, not 500.** `UniqueConstraintViolationException` is a `RuntimeException`; the controller's catch turned it into a 422 file error. The plan's test approach (real `Product::creating` conflict) worked reliably, so no unit-level fallback was needed. I also added the `QueryException` rethrow (CSV-X2) because the same catch leaked SQL for every other database error.
3. **Extra finding CSV-X1** (NULL in NOT NULL columns) — not in the plan, fixed in-scope in the service; `status=NULL` used to mean "keep current status" and is now a row error (`The selected status is invalid.`). Documented in the contract.
4. **Preload selects more product columns than the plan's list** (`name, description, in_stock, is_featured, meta_title, meta_description, options` in addition) because D5 needs the stored value of every importable field. Variant preload likewise selects all variant fields plus `owner_sku`.
5. **Delimiter-only rows are skipped** (D7) — small behaviour change beyond "verify", documented.
6. **`commit()` locks existing parents** (`lockForUpdate` in one batched query) so a parent deleted between analysis and commit becomes a 409 instead of a foreign-key 500. Not in the plan; one extra query per 1,000 parents at commit time.
7. **409 body uses the controller's existing `error()` envelope** (`errors: null`), not `errors.file`. The plan only fixed the status and message.
8. **`changes` for an update with nothing to change is `{}`**, not omitted, so the field is always an object for updates and `null` for creates/errors.
9. **PHPStan / full suite on `main` not re-run here.** The 2 PHPStan errors in `ProductImportService.php` were fixed (`5bac3fc`). The whole-project run still has 41 errors in files this branch does not touch (`git diff main --stat -- app` lists only the 3 import files); phase 08 already recorded project-wide PHPStan failures on `main`. To prove the 128M full-suite crash and the 41 PHPStan errors are identical on `main`, I tried two things: a `main` worktree (PHPStan failed to bootstrap there; removed), and temporarily checking out `main`'s versions of the 4 import files in this tree (the tool permission system refused that action). So "pre-existing" rests on the phase 08 record and on which files are affected, not on a fresh `main` run — see Part F3/F4b.
10. **Full suite needed `-d memory_limit=512M` to complete** (Part F3). No config or `php.ini` was changed.

## Frontend impact

- `data.warnings: string[]` — new, always present on 200/422-with-data responses.
- `data.rows[].changes` — new: object `{field: [old, new]}` for `update`, `null` for `create`/`error`.
- New status **409** `The catalog changed during import; run the preview again.` (`data: null`, `errors: null`).
- Database errors are now **500** (were 422 with SQL in `errors.file`).
- Non-UTF-8 files: **422** (were 500).
- Behaviour: omitted `category_slug` keeps the category (`NULL` clears); `price` optional on update; publish rules on final state; variant stock rule; variants cannot change parent; `NULL` in `name/price/stock_qty/status/in_stock/is_featured` is a row error; headers case-insensitive; `;`/tab delimiters; delimiter-only rows skipped.
- Documented in `docs/PRODUCT_IMPORT_CSV.md` (last verified 2026-10-09) and the controller's OpenAPI attributes (`409` response added).

## Part F — Final verification

All runs on the final code (`5bac3fc`; the docs commit changes no PHP).

### F1 — `php artisan test --filter=ProductImportTest` (with `IMPORT_PERF=1`)

```
[import-perf] queries=13 time=5.66s
   PASS  Tests\Feature\ProductImportTest
  ✓ preview and commit share the same product and variant analysis                                              11.17s
  ✓ any invalid row prevents all writes and reports row number                                                   0.06s
  ✓ duplicate sku and malformed json are reported                                                                0.06s
  ✓ files over five thousand rows are rejected without writes                                                    0.08s
  ✓ update without category slug keeps existing category                                                         0.06s
  ✓ null category slug clears category on draft product                                                          0.06s
  ✓ null category slug on published product is rejected                                                          0.06s
  ✓ existing variant sku cannot move to another product                                                          0.06s
  ✓ null dimension on published product is rejected                                                              0.07s
  ✓ publishing existing product uses stored dimensions and category                                              0.07s
  ✓ creating published product without dimensions is rejected                                                    0.05s
  ✓ variant stock sum exceeding product stock is rejected                                                        0.05s
  ✓ variant stock check includes existing variants not in csv                                                    0.06s
  ✓ lowering product stock below existing variant sum is rejected                                                0.06s
  ✓ lowering price below existing discount is rejected                                                           0.07s
  ✓ update row without price keeps existing price                                                                0.07s
  ✓ create row without price is rejected                                                                         0.07s
  ✓ invalid type reports only type error                                                                         0.06s
  ✓ variant parent sku matches csv product case insensitively                                                    0.06s
  ✓ unique conflict during commit returns 409 without writes                                                     0.07s
  ✓ malformed json reports valid json message                                                                    0.05s
  ✓ null in non nullable column is a row error                                                                   0.06s
  ✓ database errors are not reported as csv file errors                                                          0.07s
  ✓ rows without sku and numeric skus are handled                                                                0.06s
  ✓ five thousand row import uses bounded queries                                                                5.72s
  ✓ headers are case insensitive                                                                                 0.06s
  ✓ semicolon delimiter is detected                                                                              0.10s
  ✓ non utf8 file is rejected                                                                                    0.05s
  ✓ unknown columns are reported as warnings                                                                     0.05s
  ✓ preview reports changes for update rows                                                                      0.08s
  ✓ successful commit is audit logged                                                                            0.08s
  ✓ row numbers match spreadsheet rows with blank lines and multiline cells                                      0.07s
  Tests:    32 passed (140 assertions)
  Duration: 19.12s
```

### F2 — `php artisan test --filter=RemainingJourneysTest`

```
   PASS  Tests\Feature\Journeys\RemainingJourneysTest
  ✓ j01 guest browses category filters detail and reviews without hidden products                                9.45s
  ✓ j02 registration otp endpoint login me logout and old token rejection                                        0.15s
  ✓ j03 password reset uses the mail token and invalidates old password                                          0.11s
  ✓ j05 coupon checkout applies free shipping and records exact totals                                           0.23s
  ✓ j06 expired payment webhook cancels order and restores stock and coupon                                      0.13s
  ✓ j07 valid duplicate and out of order webhooks are idempotent                                                 0.11s
  ✓ j09 admin shipping webhook and public tracking chain                                                         0.11s
  ✓ j10 customer cancel request admin accepts and rejects with invariants                                        0.16s
  ✓ j11 admin catalog authoring publish stock import and audit chain                                             2.12s
  ✓ j12 admin order filter and bulk update reports invalid transition without partial write                      0.10s
  ✓ j13 wishlist purchase review moderation and rating chain                                                     0.29s
  ✓ j15 contact and lead are visible to admin status updated and throttled                                       0.11s
  Tests:    12 passed (136 assertions)
  Duration: 13.35s
```

### F3 — full suite

**Plain `php artisan test`** (CLI `memory_limit` = 128M from `C:\Program Files\php-8.5.2\php.ini`) did **not finish**: the PHPUnit process hit the memory limit inside `ProductImageUrlsTest` (image conversions via GD), which runs before `ProductImportTest` alphabetically:

```
  ✓ free shipping threshold exactly is free                                                                      0.10s

In GdDriver.php line 64:
  Allowed memory size of 134217728 bytes exhausted (tried to allocate 10240001 bytes)
```

`ProductImageUrlsTest` alone passes (`3 passed (31 assertions)`). None of the tests that run before the crash point use the import except J11's one-row preview, so this is very unlikely to come from this branch — but I could **not** prove it pre-exists on `main` (a `main` comparison run in this working tree was not permitted, see Deviations). The phase 08 audit ran 292 tests under the same 128M limit; the suite now has 362.

**Same suite with more memory, `php artisan test -d memory_limit=512M`** (the `-d` is passed through to PHPUnit; no config changed):

```
   FAILED  Tests\Feature\CreateStaffAccountCommandTest > refuses non staff or missing role
  Failed asserting that 2 is identical to 0.
   FAILED  Tests\Feature\CreateStaffAccountCommandTest > refuses weak or mismatched password
  Failed asserting that 2 is identical to 0.
   FAILED  Tests\Feature\TelegramNotificationTest > telegram notifier sends alert
  An expected request was not recorded.
Failed asserting that false is true.
  Tests:    3 failed, 359 passed (1960 assertions)
  Duration: 281.70s
```

The 3 failures are the same tests with the same messages that `results/08-post-approval-audit.md` already recorded as failing on `main` ("Full suite" section: `Tests: 3 failed, 289 passed`). None of them touch import code: `CreateStaffAccountCommandTest` tests an artisan command and passes alone (`4 passed (24 assertions)`, rerun today); `TelegramNotificationTest` tests the Telegram notifier HTTP fake. All 32 `ProductImportTest` tests and all 12 `RemainingJourneysTest` tests passed inside this full run.

### F4 — Pint

```
> vendor/bin/pint --test app/Services/ProductImportService.php app/Http/Controllers/Api/V1/Admin/ProductController.php tests/Feature/ProductImportTest.php
{"tool":"pint","result":"passed"}
pint exit=0
```

### F4b — PHPStan (level 5, project config)

Changed files:

```
> vendor/bin/phpstan analyse --memory-limit=2G --no-progress app/Services/ProductImportService.php app/Http/Controllers/Api/V1/Admin/ProductController.php app/Exceptions/ProductImportConflictException.php
 [OK] No errors
exit=0
```

Whole project (`--memory-limit=2G`) before `5bac3fc` reported `[ERROR] Found 43 errors`: 2 in `ProductImportService.php` (`nullsafe.neverNull` on `?->stock_qty` lines 377/384, fixed in `5bac3fc`) and 41 in 16 files this branch does not touch (resources, `OrderController`s, `Order`, `EasyPostService`, `GoogleAuthService`, `OrderInventoryService`, mails; 4 of them `ignore.unmatched` baseline entries). `results/08-post-approval-audit.md` already recorded the project-wide PHPStan run as failing on `main` (27 errors then, same files such as `OrderResource`, `OrderController`, `Order`, `EasyPostService`). A final whole-project rerun after `5bac3fc` died with `Child process timed out after 600.0 seconds` (parallel worker), so there is no full-project number for the final commit; the changed-file run above is clean.

### F5 — `git log --oneline main..fix/csv-import`

```
5bac3fc fix(import): drop unnecessary nullsafe access flagged by PHPStan; cover NULL in all non-nullable columns [CSV-X1]
06e792b fix(import): header, delimiter, encoding, warnings, change preview and audit log [CSV-D1..D7]
44c443d fix(import): final-state price rules, clear row errors and 409 on commit conflicts [CSV-B1..B5]
4dcf82f fix(import): validate final product state, variant ownership and stock [CSV-A1..A4, CSV-C1]
```

plus the docs commit that adds this file, the plan, the contract update and the PROGRESS row.

### Removed assertions

```
> git diff main -- tests | grep '^-.*assert'
(no output, exit=1)
```
