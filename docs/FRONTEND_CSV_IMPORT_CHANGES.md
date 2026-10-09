# CSV Product Import — Frontend Changes

**Endpoint:** `POST /api/v1/admin/products/import` (unchanged URL, method, auth and request body)
**Date:** 2026-10-09 · **Backend branch:** `fix/csv-import` (applies once deployed)
**Full contract:** [`PRODUCT_IMPORT_CSV.md`](PRODUCT_IMPORT_CSV.md)

The request is unchanged: `multipart/form-data` with `file` and `dry_run`. Your existing
upload → preview → confirm flow keeps working. The changes below are **additive response
fields**, **one new status code**, **one changed status code**, and **validation behavior
changes** that admins will see as row errors.

---

## 1. Summary — what you need to do

| # | Change | Required? | Effort |
|---|---|---|---|
| 1 | Handle new **409** status (retry flow) | **Required** | Small |
| 2 | **500** on server/database failure (was 422 with SQL text in `errors.file`) | **Required** | Small |
| 3 | Show new `data.warnings` array | Recommended | Small |
| 4 | Show new `rows[].changes` in the preview for `update` rows | Recommended | Medium |
| 5 | Update help text / CSV template notes (behavior changes in §5) | Recommended | Small |
| 6 | Update TypeScript types | Required if you use typed responses | Small |

---

## 2. Status codes

| Status | When | Body | What the UI should do |
|---|---|---|---|
| `200` | Preview OK, or import committed | Full `data` (see §4) | Unchanged |
| `422` | File-level error | `data: null`, `errors.file: [message]` | Unchanged: show `message` |
| `422` | One or more row errors | `data` = full analysis, `errors.rows` = rows | Unchanged: show the row table |
| **`409`** | **New.** The catalog changed between validation and commit (e.g. someone created the same SKU at the same moment). Nothing was written. | `{ success: false, message: "The catalog changed during import; run the preview again.", data: null, errors: null }` | Show the message and send the user back to the preview step (re-run with `dry_run=true`, then confirm again). Don't auto-retry the commit blindly. |
| **`500`** | **Changed.** Unexpected server/database failure. Before, this came back as `422` with raw SQL inside `errors.file`. | Laravel default: `{ "message": "Server Error" }` (no `success`/`data`/`errors` keys) | Generic "Import failed, nothing was changed, try again or contact support". Don't try to read `errors.file`. |

> **Non-UTF-8 files** now return a clean `422` file error instead of a `500`:
> `CSV must be UTF-8 encoded. In Excel use "CSV UTF-8 (Comma delimited)".`
> No code change needed if you already display `message` for 422 file errors.

---

## 3. Response shape (200 / row-level 422)

New fields are marked **NEW**.

```json
{
  "success": true,
  "message": "CSV preview completed.",
  "data": {
    "summary": { "rows": 2, "creates": 1, "updates": 1, "errors": 0 },
    "rows": [
      {
        "row": 2,
        "type": "product",
        "sku": "QUEEN-BAG-001",
        "action": "update",
        "errors": [],
        "changes": { "price": ["119.00", "129.00"], "stock_qty": [12, 20] }
      },
      {
        "row": 3,
        "type": "variant",
        "sku": "QUEEN-BAG-001-BLK",
        "action": "create",
        "errors": [],
        "changes": null
      }
    ],
    "warnings": ["Unknown column \"stok_qty\" is ignored."],
    "committed": false
  },
  "errors": null
}
```

### TypeScript

```ts
type ImportAction = 'create' | 'update' | 'error';

interface ImportRowResult {
  row: number;                       // spreadsheet row number (header = 1)
  type: string;                      // 'product' | 'variant' (or the invalid value the user typed)
  sku: string | null;
  action: ImportAction;
  // Field-keyed messages. NOTE: an empty result is serialized as [] (PHP empty array), not {}.
  errors: Record<string, string[]> | [];
  // NEW: only for action === 'update'. {} when nothing changes. null for create/error rows.
  changes: Record<string, [unknown, unknown]> | null;
}

interface ImportResult {
  summary: { rows: number; creates: number; updates: number; errors: number };
  rows: ImportRowResult[];
  warnings: string[];                // NEW: always present, [] when none
  committed: boolean;
}
```

---

## 4. New fields — how to display them

### 4.1 `data.warnings` (string[])

- Lists CSV columns the backend doesn't recognize, e.g. a typo like `stok_qty`.
- **Never blocks** the import. The column is just ignored.
- UI suggestion: a yellow notice above the preview table:
  *"These columns will be ignored: …"*. This matters because a typo silently drops data
  (e.g. the stock column is never imported).

### 4.2 `rows[].changes` (preview diff)

| Row `action` | `changes` value |
|---|---|
| `create` | `null` |
| `error` | `null` |
| `update` with differences | `{ "field": [oldValue, newValue], ... }`. Only fields that actually change are included |
| `update` with no differences | `{}` (empty object) |

Value formats:

- Decimals (`price`, `discount_price`, `weight_oz`, `length_in`, `width_in`, `height_in`): strings with 2 decimals, e.g. `"20.50"`.
- `stock_qty`: integer.
- `in_stock`, `is_featured`: boolean.
- `options` / `attributes`: arrays/objects.
- Category appears as **`category_id`** with numeric ids (not slugs), e.g. `"category_id": [3, null]` means the category will be cleared.
- `null` means empty / cleared.
- `slug` is **not** included (it's generated at commit time).
- `in_stock` may appear even if the CSV didn't include it. It's derived from `stock_qty` when `in_stock` is omitted.

UI suggestions:

- In the preview table, add an expandable "Changes" cell for update rows: `price: 119.00 → 129.00`.
- Show a "No changes" badge when `changes` is `{}`. The admin can see those rows are no-ops.
- Highlight `category_id → null` and any `→ null` change, since those clear data.

---

## 5. Behavior changes admins will notice

These don't need code changes, but **update your import help text / template notes**, and
expect new row error messages in the existing error table.

### 5.1 Changes in what is accepted

| Area | Before | Now |
|---|---|---|
| Empty `category_slug` on update | **Cleared** the product's category | **Keeps** the current category. Use `NULL` to clear it |
| `price` on update rows | Always required | Required only for **new** SKUs; omitted = keep current price |
| Header names | Exact lowercase only (`Type`, `SKU` were rejected) | Case-insensitive, trimmed |
| Delimiter | Comma only | Comma, semicolon (`;`) or tab, auto-detected (Excel EU-locale exports work) |
| Trailing `,,,,` rows from Excel | Could produce errors | Skipped like blank lines |
| `status=NULL` | Meant "keep current status" | **Row error.** Leave the cell empty to keep the status |
| `NULL` in `stock_qty` (product or variant row) | Database error (422 with SQL text in `errors.file`) | Normal row error on that field |
| Non-UTF-8 file | 500 | 422 with a clear message (see §2) |
| Decimal comma (`12,50`) | Rejected | Still rejected. Numbers must use `.` |

### 5.2 New row error messages

Show them like any other entry in `errors`.

| Field key | Message | Meaning |
|---|---|---|
| `type` | `Type must be product or variant.` | Typo in `type`. It's now the **only** error for that row |
| `sku` | `SKU belongs to a variant of another product (<SKU>).` | A variant can't be moved to a different parent product via import |
| `stock_qty` | `The total stock quantity of variants (X) cannot exceed the product's stock quantity (Y).` | Shown on every variant row of that product and on the product row. Counts existing variants that aren't in the file too |
| `discount_price` | `The discount price must be less than price.` | Now also checked against the **stored** price when the row omits `price` |
| `price` | `The price field is required.` | New SKU without a price |
| `category_slug` | `Published products require a category.` | Clearing the category of a published product, or publishing without one |
| `weight_oz` / `length_in` / `width_in` / `height_in` | `Published products require complete shipping weight and dimensions.` | Same, for shipping data |
| `options` / `attributes` | `options must be valid JSON.` / `attributes must be valid JSON.` | Malformed JSON (before: generic "must be an array") |

### 5.3 Publish rules now use the product's final state

- A row with just `type,sku,name,status` = `published` can publish an existing product that already has a category and dimensions. Before, the row had to repeat all of them.
- Clearing (`NULL`) a category or dimension on a published product is rejected.
- A published product with incomplete data can still get unrelated updates (e.g. stock), as long as the row doesn't send `status`.

---

## 6. Suggested help-text snippet for the import screen

> - Save the file as **CSV UTF-8**. Comma, semicolon or tab separators all work.
> - Leave a cell **empty** to keep the current value. Type **NULL** to clear an optional value.
> - **Price** is required only for new products.
> - Use `.` for decimals (e.g. `12.50`).
> - Always run the preview first. Nothing is saved if any row has an error.

---

## 7. Quick test files

Use these to verify the UI against a backend with the change deployed.

**Warnings + no-op update** (run twice; the 2nd preview should show `update` with `changes: {}`):

```csv
type,sku,name,price,stock_qty,stok_qty
product,FE-TEST-1,Frontend Test,10,5,5
```

**Row errors** (invalid type + variant stock over product stock):

```csv
type,sku,name,parent_sku,price,stock_qty
prodcut,FE-BAD-1,Typo,,10,1
product,FE-STOCK-1,Stock Parent,,10,1
variant,FE-STOCK-1-A,Too Many,FE-STOCK-1,,50
```

**Semicolon + mixed-case headers** (should preview fine):

```csv
Type;SKU;Name;Price
product;FE-SEMI-1;Semicolon Test;9.99
```
