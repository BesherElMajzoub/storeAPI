# Review — Phase 09 CSV import fix

**Verdict: APPROVED** (one reviewer fix applied, R1)
**Branch:** `fix/csv-import` · **Date:** 2026-10-09

## What was checked

- Read the full new `ProductImportService.php`, the controller `import()` diff,
  `ProductImportConflictException`, and the new tests.
- Re-ran `ProductImportTest` + `RemainingJourneysTest`: **46 passed (283 assertions)**
  after R1 (34 import incl. 2 new reviewer tests, 12 journeys).
- Independent real-world probe (temporary test, deleted afterwards):
  - `docs/TEST_PRODUCTS_2026-09-27.csv`: 5 creates; re-importing the same file → 5 updates, `changes` empty.
  - Contract doc example (product + JSON-attributes variant) → 200, committed, variant attached.
  - Excel-style file (UTF-8 BOM, `;` delimiter, CRLF, mixed-case headers, trailing `;;;;;` row) → parsed
    correctly; decimal comma `12,50` rejected as non-numeric (correct — no locale guessing).
- Original reproductions of A1–A4 are each covered by a regression test that failed on `main`
  (evidence in `results/09-csv-import-fix.md`).
- No queries inside the per-row loop; preloads are chunked `whereIn`; commit locks parent products.

## R1 — Publish rule blocked unrelated updates to legacy published products (fixed by reviewer)

- **Problem:** the executor (following the plan literally) ran the full publish check whenever the
  final status was `published`. A product that is already published with a gap (no category or a
  missing dimension) could not receive *any* import update, e.g. a stock change. This is stricter
  than `UpdateProductRequest`, which only checks when `status=published` is sent.
- **Fix:** full check on create or when the row sends `status`; otherwise only the publish fields
  the row itself sets are checked. A1/A3 protection is unchanged (clearing a category or dimension
  on a published product is still rejected).
- **Tests:** `test_legacy_published_product_with_gaps_accepts_unrelated_updates`,
  `test_sending_published_status_checks_all_publish_fields_on_update`.
- **Docs:** `docs/PRODUCT_IMPORT_CSV.md` "Publish rules use the final product state" updated.

## Accepted deviations

All deviations listed in the result file are accepted: C1 merged into the A commit, B4 test with
a real conflict, X1 (NULL in NOT NULL columns → row error; `status=NULL` no longer means "keep"),
X2 (DB errors → 500 instead of 422 leaking SQL), delimiter-only rows skipped, `changes: {}` for
no-op updates.

## Notes (not blocking)

- Full suite at the default 128M memory limit stops in `ProductImageUrlsTest` (GD); with
  `-d memory_limit=512M` the only failures are the 3 already recorded in `results/08`. Not caused
  by this branch (none of those files are touched).
- `changes` reports `category_id` (ids), not slugs; slug changes are not previewed.
