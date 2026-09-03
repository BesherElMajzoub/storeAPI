# Frontend API Integration Handoff

**Project:** Otantik Queen e-commerce  
**API version:** v1  
**Prepared:** 2026-09-03  
**Source request:** `backend-open-items.md`

## 1. Purpose and release status

This document is the frontend handoff for the backend work requested in
`backend-open-items.md`. It describes the final API contracts, the changes the
frontend must adopt, compatibility aliases, error handling, and the status of
every open-item ID.

The changes in this document are implemented and covered by the repository test
suite. They are not a claim that the production deployment gates are complete.
The frontend can integrate against a local or staging deployment containing this
revision. Do not assume that the live API has these contracts until the backend
migration and release have been deployed and the production checklist has been
signed off.

Current automated evidence:

- `php artisan test --compact`: **124 tests passed, 644 assertions**.
- API inventory: **121 API v1 routes, 73 admin routes**.
- All admin routes are protected by authentication, admin authorization,
  throttling, and audit middleware.
- Swagger JSON has been regenerated at `storage/api-docs/api-docs.json`.

## 2. Frontend action list

The frontend should make these integration changes:

1. Request shipping rates with `address + items`. Do not calculate or submit a
   parcel or shipping price from the browser.
2. Keep the selected `rate_id` and send it as `shipping_rate_id` when creating
   the order. A quote lasts 15 minutes and is bound to the exact address and cart.
3. Request fresh rates whenever the cart, quantity, variant, or shipping address
   changes, and when a quote expires.
4. Treat HTTP 503 from rate lookup or checkout repricing as a temporary provider
   outage. Never continue checkout with zero shipping.
5. Add the nullable `shipment` object to customer and admin order DTOs. It is
   `null` before label purchase.
6. Never expect `label_url`, Stripe IDs, or EasyPost IDs in customer order
   responses. `label_url` is admin-only.
7. Replace the hardcoded public tracking page with
   `POST /api/v1/orders/track`.
8. In the admin order screen, show the label action only for an order whose
   `payment_status` is `paid` and `status` is `processing`.
9. Add product shipping inputs in ounces/inches. A product cannot be published
   without a category, price, weight, and all three dimensions.
10. Use the canonical image ordering endpoint and body:
    `POST /api/v1/admin/products/{id}/images/order` with `image_ids`.
11. Use the documented transactional variant diff when editing products.
12. For catalogue upload, run CSV import first with `dry_run=true`, display all
    row results, and only then repeat with `dry_run=false` after user confirmation.

## 3. Shared API conventions

Examples below use this production base URL:

```text
https://apis.otantikqueen.com/api/v1
```

Use the environment-specific API origin for local and staging builds.

Authenticated customer and admin requests use:

```http
Authorization: Bearer <sanctum-token>
Accept: application/json
```

The new JSON endpoints use this envelope:

```json
{
  "success": true,
  "message": "Human-readable message.",
  "data": {},
  "errors": null
}
```

Validation and business-rule failures normally use:

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": null,
  "errors": {
    "field": ["Error message."]
  }
}
```

Important statuses for frontend handling:

| Status | Meaning |
|---|---|
| `401` | Missing or invalid customer/admin authentication. |
| `403` | Forbidden, or email verification is required before checkout. |
| `404` | Resource not found. Public tracking deliberately uses the same 404 for an unknown order and an email mismatch. |
| `409` | State conflict, such as insufficient stock or an order that is not eligible for label purchase. |
| `422` | Validation or shipping business-rule failure. Read `errors` and, for shipping, `errors.code`. |
| `429` | Rate limit reached. |
| `502` | Stripe checkout session creation failed. |
| `503` | EasyPost is unavailable or timed out during rate lookup/repricing. Retry later; do not use free shipping. |

## 4. Checkout and shipping

### 4.1 Get shipping rates — changed contract

`POST /api/v1/shipping/rates`  
Authentication: public  
Destination support in v1: United States only

Canonical request:

```json
{
  "address": {
    "name": "Jane Customer",
    "line1": "123 Main St",
    "line2": null,
    "city": "Pasadena",
    "state": "CA",
    "postal_code": "91101",
    "country": "US",
    "phone": "+13105550123"
  },
  "items": [
    {
      "product_id": 12,
      "variant_id": 34,
      "quantity": 2
    }
  ]
}
```

`variant_id` is nullable. If present, it must belong to `product_id`. A request
may contain up to 50 item lines and each quantity must be between 1 and 100.

Success response:

```json
{
  "success": true,
  "message": "Shipping rates retrieved.",
  "data": [
    {
      "rate_id": "rate_abc123",
      "carrier": "USPS",
      "service": "Priority",
      "amount": 8.45,
      "eta_days": 3
    }
  ],
  "errors": null
}
```

`eta_days` may be `null`. The server validates product availability, variant
ownership, stock, shipping measurements, and package fit before calling
EasyPost. It selects a configured warehouse package from server-side product
data. The browser must not send package measurements for the canonical flow.

Shipping 422 codes currently returned in `errors.code` include:

| Code | Frontend behavior |
|---|---|
| `unsupported_destination` | Explain that v1 ships only within the US. |
| `unserviceable_address` | Ask the user to review or replace the address. |
| `shipping_configuration` | Product/package data needs admin correction; block checkout. |
| `unavailable_product` | Refresh the cart because a product is unavailable. |
| `invalid_variant` | Refresh product/cart data because the variant no longer matches. |
| `insufficient_stock` | Refresh quantities and show an out-of-stock message. |

An EasyPost failure returns 503, not a successful empty array.

Quote lifecycle:

- A returned rate is valid for 15 minutes.
- It is bound to normalized address, items, quantities, variants, and the
  server-selected package.
- Any address/cart change requires a new rate request.
- A rate is single-use once it is consumed by an order.

### 4.2 Create order — `shipping_rate_id` is now required

`POST /api/v1/orders`  
Authentication: customer Bearer token; verified email required

Every order in the current v1 catalogue is treated as physical and must include
the selected shipping rate:

```json
{
  "items": [
    {
      "product_id": 12,
      "variant_id": 34,
      "quantity": 2
    }
  ],
  "shipping_address": {
    "name": "Jane Customer",
    "line1": "123 Main St",
    "line2": null,
    "city": "Pasadena",
    "state": "CA",
    "postal_code": "91101",
    "country": "US",
    "phone": "+13105550123"
  },
  "billing_address": null,
  "coupon_code": null,
  "shipping_rate_id": "rate_abc123"
}
```

The `items` and `shipping_address` must match the data used to obtain the rate.
Do not send a client-computed shipping amount. The backend retrieves the rate
from EasyPost again and persists the trusted amount.

Success response, HTTP 201:

```json
{
  "success": true,
  "message": "Order created. Redirect to Stripe checkout.",
  "data": {
    "order": {
      "id": 501,
      "order_number": "ORD-ABCDEFGHIJ",
      "status": "pending_payment",
      "payment_status": "unpaid",
      "shipping_cost": 8.45,
      "total": 248.45,
      "shipment": null
    },
    "checkout_url": "https://checkout.stripe.com/c/pay/...",
    "payment": {
      "session_id": "cs_test_..."
    }
  },
  "errors": null
}
```

Redirect the browser to `data.checkout_url` after a successful response.

Quote validation failures are HTTP 422 and may use these additional codes:

- `invalid_shipping_rate`
- `shipping_address_changed`
- `shipping_items_changed`
- `shipping_parcel_changed`

On these errors, discard the selected rate and return the customer to shipping
rate selection. On HTTP 503, preserve the cart/address and offer retry.

### 4.3 Legacy shipping compatibility — deprecated

The older `address + parcel` request is still accepted during API v1. It returns
the older `data.shipment_id + data.rates` shape and a `Deprecation: true`
response header. New frontend code must not use it.

The legacy aliases `street1`, `street2`, and `zip` are also accepted by the
rates endpoint. New code should use `line1`, `line2`, and `postal_code` so the
same address object can be passed to order creation.

The old optional `easypost_shipment_id` field is still accepted on order-create
requests for transition compatibility, but the server does not trust it as a
replacement for the stored quote. New frontend code should omit it.

## 5. Order shipment and tracking

### 5.1 Customer order resource — changed

These endpoints now expose the shipment contract:

- `GET /api/v1/orders`
- `GET /api/v1/orders/{id}`

Before a label is purchased:

```json
{
  "shipment": null
}
```

After label purchase:

```json
{
  "shipment": {
    "tracking_number": "9400111899223197428490",
    "carrier": "USPS",
    "service": "Priority",
    "tracking_url": "https://tools.usps.com/go/TrackConfirmAction?...",
    "shipped_at": "2026-09-03T14:32:00+00:00",
    "estimated_delivery": "2026-09-06",
    "status": "in_transit"
  }
}
```

The customer resource never returns `label_url`, `easypost_shipment_id`,
`shipping_rate_id`, `stripe_session_id`, or `stripe_payment_intent_id`.
The old top-level `tracking_number` remains in v1 for compatibility but should
be treated as deprecated; use `shipment.tracking_number`.

Shipment status is separate from the commercial order status. Supported
shipment values are:

```text
unknown
pre_transit
in_transit
out_for_delivery
available_for_pickup
delivered
return_to_sender
failure
cancelled
error
```

Frontend status labels must include a fallback for `unknown`.

### 5.2 Public tracking — new

`POST /api/v1/orders/track`  
Authentication: none

Request:

```json
{
  "order_number": "ORD-ABCDEFGHIJ",
  "email": "customer@example.com"
}
```

Success response:

```json
{
  "success": true,
  "message": "Tracking information retrieved.",
  "data": {
    "order_number": "ORD-ABCDEFGHIJ",
    "status": "in_transit",
    "estimated_delivery": "2026-09-06",
    "events": [
      {
        "status": "in_transit",
        "description": "Departed USPS facility",
        "location": "Los Angeles, CA, US",
        "occurred_at": "2026-09-03T20:11:00+00:00"
      }
    ]
  },
  "errors": null
}
```

The endpoint reads the stored tracking snapshot, so the public page can still
work while EasyPost is temporarily unavailable. It deliberately does not expose
the tracking number, addresses, customer details, payment details, or item data.

Unknown order and email mismatch both return exactly:

```json
{
  "success": false,
  "message": "Order tracking information was not found.",
  "data": null,
  "errors": null
}
```

Both cases use HTTP 404. Do not show different UI messages for them.

Rate limits are five attempts per IP and three attempts per normalized
order-number/email pair per minute. Handle HTTP 429 with a wait-and-retry message.
Tracking responses include `Cache-Control: no-store, private`.

### 5.3 Authenticated live tracking — existing endpoint, updated persistence

`GET /api/v1/orders/{id}/tracking` remains available to the owning customer.
It refreshes tracking from EasyPost and saves the normalized snapshot. The
regular order resource and public tracking endpoint should be the primary UI
sources because they can use the last stored snapshot during an outage.

## 6. Admin shipping

### 6.1 Purchase label — new canonical endpoint

`POST /api/v1/admin/orders/{id}/label`  
Authentication: admin Bearer token

Eligibility:

- `payment_status` must be `paid`.
- `status` must be `processing`.
- The order must have the shipment/rate selected during checkout.

Normally send an empty JSON object:

```json
{}
```

An optional `rate_id` may be sent, but it must equal the rate belonging to the
order. `shipment_id` is accepted only as a deprecated compatibility field. The
recommended frontend must omit both and let the server use the checkout rate.

Success response:

```json
{
  "success": true,
  "message": "Order shipped successfully.",
  "data": {
    "id": 501,
    "status": "shipped",
    "easypost_shipment_id": "shp_abc123",
    "tracking_number": "9400111899223197428490",
    "label_url": "https://easypost-files.example/label.pdf",
    "shipment": {
      "tracking_number": "9400111899223197428490",
      "carrier": "USPS",
      "service": "Priority",
      "tracking_url": "https://tools.usps.com/go/TrackConfirmAction?...",
      "label_url": "https://easypost-files.example/label.pdf",
      "shipped_at": "2026-09-03T14:32:00+00:00",
      "estimated_delivery": "2026-09-06",
      "status": "pre_transit"
    }
  },
  "errors": null
}
```

The operation is idempotent. Repeating it after success returns the existing
shipment with message `Shipping label already exists.` and does not buy a second
label.

Error handling:

- HTTP 409: order is not both paid and processing.
- HTTP 422: missing/foreign rate or shipment, expired provider data, bad address,
  insufficient carrier wallet balance, or another label purchase failure.
- Display the returned `message` and field errors to the admin; do not present a
  generic server-error screen.

`POST /api/v1/admin/orders/{id}/ship` remains a deprecated v1 alias. New code
must use `/label`.

### 6.2 Admin order resource — changed

`GET /api/v1/admin/orders` and `GET /api/v1/admin/orders/{id}` return the same
base order data plus admin-only fields, including `shipping_rate_id`,
`easypost_shipment_id`, payment provider IDs, and `shipment.label_url`.

`GET /api/v1/admin/orders/{id}/tracking` refreshes EasyPost tracking, stores the
normalized event snapshot, and returns both provider tracking details and
normalized `events`.

## 7. Product management

### 7.1 Admin list filters — changed

`GET /api/v1/admin/products` now accepts:

| Query parameter | Accepted values |
|---|---|
| `search` | Partial name, SKU, or slug; maximum 255 characters. |
| `category_id` | Existing category ID. |
| `status` | `draft`, `published`, `archived`. |
| `is_featured` | Boolean. |
| `sort` | `created_desc`, `price_asc`, `price_desc`, `stock_asc`, `name_asc`. |
| `per_page` | `1` to `100`; default `20`. |

Example:

```http
GET /api/v1/admin/products?search=dress&status=published&sort=stock_asc&per_page=50
```

### 7.2 Product and variant shipping fields — changed

Product create/update/read and CSV import now support:

```text
weight_oz
length_in
width_in
height_in
```

Values must be positive. Maximums are 2,400 oz for weight and 200 inches for
each dimension. Product variants expose the same nullable fields as overrides;
when an override is omitted, shipping inherits the product value.

Product status is strictly one of `draft`, `published`, or `archived`.
Publishing requires:

- a valid category;
- a valid price;
- `weight_oz`;
- `length_in`;
- `width_in`;
- `height_in`.

New catalogue imports default to `draft` when status is omitted.

### 7.3 Variant update semantics — finalized

Product updates containing `variants` are transactional:

- A variant with `id` updates that variant.
- A variant without `id` creates a new variant.
- A variant with `id` and `_delete: true` is deleted.
- Existing variants omitted from the request are kept.
- An ID belonging to another product is rejected with HTTP 422.
- `_delete: true` without an ID is rejected with HTTP 422.

Example:

```json
{
  "variants": [
    {
      "id": 34,
      "name": "Black / M",
      "sku": "DRESS-1-BLK-M",
      "price": 125,
      "stock_qty": 4,
      "attributes": { "color": "black", "size": "M" }
    },
    {
      "name": "Black / L",
      "sku": "DRESS-1-BLK-L",
      "price": 125,
      "stock_qty": 3,
      "attributes": { "color": "black", "size": "L" }
    },
    {
      "id": 35,
      "_delete": true
    }
  ]
}
```

For multipart product updates, continue using:

```text
POST /api/v1/admin/products/{id}
Content-Type: multipart/form-data
_method=PATCH
```

Encode `variants` and `options` as JSON strings when they are sent inside
`FormData`.

### 7.4 Product gallery — new canonical ordering name

| Action | Endpoint | Body |
|---|---|---|
| Append images | `POST /api/v1/admin/products/{id}/images` | Multipart `images[]`; up to 8 total, 5 MB each. |
| Delete one image | `DELETE /api/v1/admin/products/{id}/images/{imageId}` | None. |
| Reorder images | `POST /api/v1/admin/products/{id}/images/order` | `{ "image_ids": [3, 1, 2] }` |

The first image ID becomes primary. Product reads expose stable media IDs.
Every supplied image ID must belong to the product.

`POST /api/v1/admin/products/{id}/images/reorder` with body key `order` remains
a deprecated v1 alias.

### 7.5 Atomic bulk update — changed result contract

`POST /api/v1/admin/products/bulk`

```json
{
  "ids": [12, 13],
  "set": {
    "status": "archived",
    "is_featured": false
  }
}
```

Supported fields in `set` are `status`, `in_stock`, `is_featured`, and
`category_id`. Up to 100 distinct product IDs are accepted. Validation happens
before writing, and the update is all-or-nothing.

Success data is indexed as a result for every product:

```json
{
  "success": true,
  "message": "Products updated atomically.",
  "data": [
    {
      "id": 12,
      "status": "updated",
      "product": {}
    },
    {
      "id": 13,
      "status": "updated",
      "product": {}
    }
  ],
  "errors": null
}
```

An invalid ID or value returns HTTP 422 and no product is changed.

### 7.6 CSV preview/import — new

`POST /api/v1/admin/products/import`  
Content type: `multipart/form-data`

| Field | Description |
|---|---|
| `file` | Required UTF-8 CSV, maximum 5 MB and 5,000 data rows. |
| `dry_run` | Required boolean. `true` previews; `false` commits atomically. |

Use exactly the same file for preview and commit. A response contains:

```json
{
  "success": true,
  "message": "CSV preview completed.",
  "data": {
    "summary": {
      "rows": 2,
      "creates": 1,
      "updates": 1,
      "errors": 0
    },
    "rows": [
      {
        "row": 2,
        "type": "product",
        "sku": "DRESS-1",
        "action": "create",
        "errors": []
      },
      {
        "row": 3,
        "type": "variant",
        "sku": "DRESS-1-BLK",
        "action": "update",
        "errors": []
      }
    ],
    "committed": false
  },
  "errors": null
}
```

When any row is invalid, the endpoint returns HTTP 422, every row remains in
`data.rows`, invalid rows have `action: "error"` and field-level `errors`,
`data.committed` is false, and nothing is written. Commit succeeds only when all
rows are valid.

CSV supports product and variant rows and upserts by SKU. It never deletes
records omitted from the file and never downloads image URLs. See
`docs/PRODUCT_IMPORT_CSV.md` for the complete column contract and an example.

### 7.7 Other product/category confirmations

- Product `discount_price`, `in_stock`, `meta_title`, and `meta_description` are
  persisted and returned.
- Category `meta_description` is accepted, persisted, and returned.
- Admin and public category trees order siblings by `sort_order`.
- `PATCH /api/v1/admin/reviews/{id}/moderate` accepts a literal PATCH request.
- Product SKU uniqueness failures return HTTP 422.
- Authenticated API rate limit is 120 requests per minute per user.
- Request limit is 45 MB; product galleries allow 8 images and 5 MB per image.

## 8. Suggested TypeScript contracts

These interfaces cover the newly changed frontend surface. Merge them into the
project's existing order and product DTOs rather than duplicating the complete
models.

```ts
export interface ShippingAddress {
  name?: string;
  line1: string;
  line2?: string | null;
  city: string;
  state: string;
  postal_code: string;
  country: string;
  phone?: string | null;
}

export interface ShippingItem {
  product_id: number;
  variant_id?: number | null;
  quantity: number;
}

export interface ShippingRate {
  rate_id: string;
  carrier: string;
  service: string;
  amount: number;
  eta_days: number | null;
}

export type ShipmentStatus =
  | "unknown"
  | "pre_transit"
  | "in_transit"
  | "out_for_delivery"
  | "available_for_pickup"
  | "delivered"
  | "return_to_sender"
  | "failure"
  | "cancelled"
  | "error";

export interface TrackingEvent {
  status: ShipmentStatus;
  description: string;
  location: string | null;
  occurred_at: string | null;
}

export interface CustomerShipment {
  tracking_number: string;
  carrier: string | null;
  service: string | null;
  tracking_url: string | null;
  shipped_at: string | null;
  estimated_delivery: string | null;
  status: ShipmentStatus;
}

export interface AdminShipment extends CustomerShipment {
  label_url: string | null;
}

export interface PublicTrackingData {
  order_number: string;
  status: ShipmentStatus;
  estimated_delivery: string | null;
  events: TrackingEvent[];
}

export interface ShippingMeasurements {
  weight_oz: number | null;
  length_in: number | null;
  width_in: number | null;
  height_in: number | null;
}

export type CsvImportAction = "create" | "update" | "error";

export interface CsvImportRowResult {
  row: number;
  type: "product" | "variant";
  sku: string;
  action: CsvImportAction;
  errors: Record<string, string[]> | [];
}
```

The existing customer order DTO should add:

```ts
shipment: CustomerShipment | null;
```

The admin order DTO should use:

```ts
shipment: AdminShipment | null;
```

## 9. Endpoint change index

| Method | Endpoint | Change | Frontend status |
|---|---|---|---|
| POST | `/api/v1/shipping/rates` | Canonical `address + items`; returns a flat rate array. | **Required migration** |
| POST | `/api/v1/orders` | `shipping_rate_id` is required and repriced server-side. | **Required migration** |
| GET | `/api/v1/orders` | Customer order includes nullable `shipment`. | Update DTO |
| GET | `/api/v1/orders/{id}` | Customer order includes nullable `shipment`; provider secrets removed. | Update DTO |
| GET | `/api/v1/orders/{id}/tracking` | Refreshes and persists the normalized snapshot. | Optional authenticated refresh |
| POST | `/api/v1/orders/track` | New privacy-safe public tracking endpoint. | Replace demo page |
| GET | `/api/v1/admin/orders` | Admin order entries include the admin shipment/provider fields. | Update admin DTO |
| GET | `/api/v1/admin/orders/{id}` | Includes admin shipment/label and provider fields. | Update admin DTO |
| POST | `/api/v1/admin/orders/{id}/label` | New canonical idempotent label purchase. | Add admin action |
| POST | `/api/v1/admin/orders/{id}/ship` | Deprecated alias for `/label`. | Stop using |
| GET | `/api/v1/admin/orders/{id}/tracking` | Returns live details plus normalized events. | Update tracking UI if used |
| GET | `/api/v1/admin/products` | Added search/filter/sort query parameters. | Wire admin filters |
| POST | `/api/v1/admin/products` | Added product/variant shipping fields and publish rules. | Update form |
| POST | `/api/v1/admin/products/{id}` + `_method=PATCH` | Added shipping fields and transactional variant diff. | Update form logic |
| POST | `/api/v1/admin/products/bulk` | Atomic update with one result per ID. | Replace request fan-out |
| POST | `/api/v1/admin/products/import` | New CSV preview/atomic import. | Add importer |
| POST | `/api/v1/admin/products/{id}/images` | Append images without replacing gallery. | Use for gallery additions |
| DELETE | `/api/v1/admin/products/{id}/images/{imageId}` | Delete one product image. | Use stable image ID |
| POST | `/api/v1/admin/products/{id}/images/order` | Canonical gallery order endpoint using `image_ids`. | Use canonical endpoint |
| POST | `/api/v1/admin/products/{id}/images/reorder` | Deprecated alias using `order`. | Stop using |

## 10. Response to `backend-open-items.md`

This section is the consolidated backend response requested by the frontend
team. `Fixed now` and `Confirmed` mean implemented/verified in this repository
revision. `Will do by <production date>` means the remaining action requires the
deployment owner and production evidence.

### Shipping and tracking

| ID | Status | Response |
|---|---|---|
| A1 | **Fixed now** | The canonical rates endpoint accepts `address + items`, calculates the parcel from product data, and returns the requested flat rate array. Business failures use 422 and provider failures use 503. |
| A2 | **Fixed now** | Order creation requires a stored 15-minute `shipping_rate_id`, verifies address/cart/package fingerprints, and retrieves the provider rate again. Invalid or changed quotes fail closed. |
| A3 | **Fixed now** | Customer and admin orders return `shipment` or `null`; the fixed enum is documented above. Labels/provider IDs are admin-only. |
| A4 | **Fixed now** | Label purchase is the explicit, idempotent admin action at `/admin/orders/{id}/label`. Only paid processing orders are eligible. |
| A5 | **Fixed now** | Public order tracking is implemented with order number + email, generic 404 behavior, stored snapshots, PII filtering, and dedicated throttles. |
| A6 | **Will do by `<production date>`** | Schema and server-side configuration support are complete. The deployment owner must provide the real EasyPost keys/origin and attach a test-mode rates → order → payment → label → webhook → tracking run. International shipping is N/A for v1 because customs data is not implemented. |

### Product management

| ID | Status | Response |
|---|---|---|
| B1 | **Fixed now** | Admin product search, filters, allowlisted sorting, and bounded pagination are implemented. |
| B2 | **Fixed now** | Append, single-delete, and reorder endpoints are available with stable image IDs. `/images/order` is canonical; `/images/reorder` is deprecated. |
| B3 | **Fixed now** | Variant updates use the transactional ID/create/`_delete`/keep-omitted semantics documented above. Cross-product IDs are rejected. |
| B4 | **Confirmed** | Product status is restricted to `draft`, `published`, or `archived`; publish now requires category and shipping measurements. |
| B5 | **Fixed now** | Bulk update is atomic and returns an `updated` result for every ID. Invalid input causes no writes. |
| B6 | **Fixed now** | CSV preview/import supports product and variant rows, SKU upsert, row errors, limits, and atomic commit. |
| B7 | **Confirmed** | Authenticated requests are limited to 120/minute per user. SKU uniqueness returns 422. Limits are 45 MB/request, 8 images/product, and 5 MB/image. |

### Data contract questions

| ID | Status | Response |
|---|---|---|
| C1 | **Confirmed** | Product discount, stock, and metadata fields are persisted and returned. |
| C2 | **Fixed now** | Resolved by the B3 variant diff contract. |
| C3 | **Confirmed** | Category `meta_description` is accepted, persisted, and returned. |
| C4 | **Confirmed** | Category siblings are ordered by `sort_order` in both admin and public trees. |
| C5 | **Confirmed** | `PATCH /api/v1/admin/reviews/{id}/moderate` accepts literal PATCH and is admin-protected. |

### Security, data, and operations

| ID | Status | Response |
|---|---|---|
| D1 | **Fixed now** | Authentication/session expiry and revocation, password/OTP/reset protections, Google token verification, enumeration resistance, and endpoint throttles are covered by tests. |
| D2 | **Confirmed** | All 73 admin routes reject a regular customer. Two-account IDOR coverage includes orders, addresses, wishlist, and profile. |
| D3 | **Fixed now** | Allowlists, pagination caps, request limits, bound queries, Unicode, and stored-text behavior are covered. |
| D4 | **Fixed now** | Server-trusted prices, transactional coupon/inventory behavior, constrained state changes, and signed/idempotent Stripe flows are covered. |
| D5 | **Fixed now** | MIME/image validation, UUID filenames, limits, image re-encoding, and non-executable storage protections are covered. |
| D6 | **Fixed now** | Restricted CORS, production error posture, security headers, global throttling, and removal of test routes are covered. |
| D7 | **Will do by `<production date>`** | Code-side indexes, transactions, and query checks pass. An encrypted off-server backup and isolated restore must be demonstrated by the deployment owner. |
| D8 | **Will do by `<production date>`** | Runbook/defaults exist. Secret rotation, HTTPS, supervised workers/scheduler, monitoring, and alerts require host-side evidence. |
| D9 | **Will do by `<production date>`** | Automated Stripe/EasyPost happy and failure contracts pass. Deployed credentials and real carrier evidence remain. |
| D-E1 | **Confirmed** | The dynamic authorization sweep covers every registered admin route; all 73 return 401/403 to a customer. |
| D-E2 | **Fixed now** | Two-customer IDOR tests cover order read/cancel, address update/delete, wishlist count/check/delete, and profile read/update. |
| D-E3 | **Confirmed** | Two synchronized PHP processes compete for the final stock unit and exactly one succeeds. |
| D-E4 | **Confirmed** | A renamed PHP upload is rejected and a JPEG/PHP polyglot is safely renamed and re-encoded. |
| D-E5 | **Will do by `<production date>`** | Backup restore and deployed demo-account removal require production execution and attached evidence. |
| D10 | **Will do by `<production date>`** | The last read-only production check still found `Extra Demo Product`, `Electronics`, and `Empty Category`. Back up, import the real catalogue as draft, verify it, remove demo data, and rerun preflight. |

## 11. Deployment dependencies and handoff boundary

Before the frontend points its production build at these contracts, the
deployment owner must:

1. deploy the application revision and run the database migrations;
2. configure real warehouse origin, standard packages, EasyPost keys, Stripe
   keys, and webhook secrets;
3. run workers and the scheduler under supervision;
4. complete backup and isolated restore evidence;
5. run the full US EasyPost test-mode flow;
6. remove demo catalogue/accounts only after backup and real catalogue review;
7. run `php artisan app:production-readiness` and attach the result.

Until those gates pass, the correct release statement is **code ready for
frontend integration; production approval pending**.

## 12. Related backend references

- `backend-open-items.md` — original frontend request and backend status register.
- `docs/SHIPPING_CONTRACT.md` — concise shipping contract.
- `docs/PRODUCT_IMPORT_CSV.md` — complete CSV column rules.
- `docs/API_V1_ROUTE_MIDDLEWARE.md` — complete route and middleware inventory.
- `docs/PRODUCTION_READINESS_TEST_EVIDENCE.md` — automated evidence.
- `docs/BACKEND_PRODUCTION_READINESS_RESPONSE.md` — security/operations response.
- `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md` — remaining production gates.
- `storage/api-docs/api-docs.json` — generated OpenAPI document.
