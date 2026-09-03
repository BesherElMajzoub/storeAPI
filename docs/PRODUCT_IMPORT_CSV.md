# Product CSV import contract

`POST /api/v1/admin/products/import` accepts `multipart/form-data` with:

- `file`: UTF-8 CSV, maximum 5 MB and 5,000 data rows.
- `dry_run`: required boolean. `true` validates and previews without writes;
  `false` commits only when every row is valid.

The import is atomic. A validation error on any row returns HTTP 422 with every
row's number, action, and field errors, and nothing is written.

## Required headers

Every file must contain `type`, `sku`, and `name`. Rows can appear in any order.

### Product rows

Use `type=product`. Supported columns are:

`sku`, `name`, `slug`, `description`, `price`, `discount_price`, `stock_qty`,
`status`, `category_slug`, `in_stock`, `is_featured`, `meta_title`,
`meta_description`, `options`, `weight_oz`, `length_in`, `width_in`, `height_in`.

- `sku`, `name`, and `price` are required.
- New products default to `draft` when `status` is omitted.
- `published` rows require a valid `category_slug` and all four shipping fields.
- `options` is a JSON array/object encoded inside the CSV cell.

### Variant rows

Use `type=variant`. Supported columns are:

`sku`, `parent_sku`, `name`, `price`, `stock_qty`, `attributes`, `weight_oz`,
`length_in`, `width_in`, `height_in`.

- `sku`, `parent_sku`, and `name` are required.
- `parent_sku` may reference an existing product or a product row in the file.
- `attributes` is a JSON object encoded inside the CSV cell.
- Shipping measurements are optional overrides; omitted values inherit from the product.

## Upsert behavior

- Product rows upsert by product SKU; variant rows upsert by variant SKU.
- A SKU cannot be shared by a product and variant or repeated in the same file.
- Omitted columns keep their existing value on updates. A literal `NULL` clears
  a nullable value.
- Rows do not delete omitted products or variants, and the importer never downloads images.
- Product images are managed through the dedicated `/admin/products/{id}/images` endpoints.

## Example

```csv
type,sku,name,parent_sku,price,stock_qty,status,category_slug,weight_oz,length_in,width_in,height_in
product,DRESS-1,Evening Dress,,120,8,published,dresses,12,10,8,3
variant,DRESS-1-BLK,Black / M,DRESS-1,125,3,,,,,,
```
