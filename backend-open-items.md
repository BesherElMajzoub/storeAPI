# Backend — Open Items Register

**Project:** Otantik Queen e-commerce (`apis.otantikqueen.com`)
**From:** Frontend team
**Date:** 2026-09-01
**Purpose:** Single tracker for every outstanding backend question, confirmation,
and build request blocking client delivery.

---

## How to use this document

Every item has an **ID**, a **priority**, and an **ask**. Please reply against
the ID list — mark each one:

- **Confirmed** — already works as described (attach evidence where the item asks for it)
- **Fixed now** — was missing, now implemented (say what changed)
- **Will do by `<date>`** — accepted, scheduled
- **N/A** — not applicable, **with a one-line reason** so we can record the risk explicitly

Items marked *(evidence required)* are not satisfiable by "the framework handles
it" — we need the test actually run and the output pasted.

The security/ops response and remaining deployment gates live in
`docs/BACKEND_PRODUCTION_READINESS_RESPONSE.md` and
`docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`; §D below indexes them so nothing is lost.

**Legend:** 🔴 blocks delivery · 🟠 blocks admin usability · 🟡 quality/cleanup

## Backend response — 2026-09-03

Automated baseline: `php artisan test --compact` — **124 passed, 644 assertions**.

Route inventory: **121 API v1 routes / 73 admin routes**; every admin route has
`auth:sanctum`, `throttle:api`, `can:admin-access`, and `audit.admin`.

| ID | Status | Resolution / evidence |
|---|---|---|
| A1 | **Fixed now** | Canonical `address + items` contract returns a rate array from EasyPost; product dimensions and configured packages are server-side. Validation/unserviceable failures return 422 and provider outages return 503. The old `address + parcel` response remains deprecated in v1. |
| A2 | **Fixed now** | Orders require a stored 15-minute `shipping_rate_id`; address/cart/parcel fingerprints are checked and the EasyPost rate is retrieved again. Invalid, expired, consumed, or changed quotes fail closed. |
| A3 | **Fixed now** | Customer/admin order resources return `shipment` or `null`. Label/provider IDs are admin-only. Tracking enum is `unknown`, `pre_transit`, `in_transit`, `out_for_delivery`, `available_for_pickup`, `delivered`, `return_to_sender`, `failure`, `cancelled`, `error`. |
| A4 | **Fixed now** | Explicit idempotent `POST /api/v1/admin/orders/{id}/label`; only paid processing orders are eligible. `/ship` remains a deprecated alias. |
| A5 | **Fixed now** | Public `POST /api/v1/orders/track` requires order number + email, exposes only the tracking snapshot, returns identical generic 404s, and has dedicated 5/IP + 3/query per-minute limits. |
| A6 | **Will do by `<production date>`** | Code/config/schema safeguards are complete. Deployment owner must enter the real date and attach the real EasyPost US rates → order → payment → label → webhook → tracking run. Non-US shipping is intentionally N/A for v1 because customs data is not implemented. |
| B1 | **Fixed now** | Search, filters, allowlisted sorting, and bounded pagination are implemented and tested. |
| B2 | **Fixed now** | Append/delete/reorder endpoints and stable media IDs are implemented. Both `/images/order` and deprecated `/images/reorder` are available. |
| B3 | **Fixed now** | Transactional variant diff: ID updates, no ID creates, `_delete` deletes, omitted variants remain. Cross-product IDs are rejected. |
| B4 | **Confirmed** | Create/update enforce `draft`, `published`, or `archived`; publishing now also requires category and shipping measurements. |
| B5 | **Fixed now** | Bulk update is all-or-nothing and returns an `updated` result per ID; validation failure performs no writes. |
| B6 | **Fixed now** | CSV preview/import supports product and variant rows, SKU upsert, 5 MB/5,000-row limits, per-row errors, and atomic commit. Images remain on the dedicated media endpoints. |
| B7 | **Confirmed** | Authenticated API ceiling is 120/minute; importer uses one request. Product SKU is unique with clean 422 validation. Request/image limits are 45 MB, 8 images, and 5 MB/image. |
| C1 | **Confirmed** | Product discount/stock/meta fields are persisted and returned. |
| C2 | **Fixed now** | Resolved by B3. |
| C3 | **Confirmed** | Category `meta_description` is accepted, persisted, and returned. |
| C4 | **Confirmed** | Admin/public category trees order siblings by `sort_order`. |
| C5 | **Confirmed** | Literal PATCH moderation route exists and is admin-guarded. |
| D1 | **Fixed now** | Sanctum expiry/revocation, password/OTP/reset hardening, Google token verification, enumeration resistance, and endpoint limiters are covered by the readiness suite. |
| D2 | **Confirmed** | Dynamic sweep rejects a customer on all 73 admin routes; two-account IDOR test covers orders, addresses, wishlist, and profile. |
| D3 | **Fixed now** | Allowlists, pagination caps, request limits, bound queries, Unicode, and stored-text behavior are tested. |
| D4 | **Fixed now** | Trusted prices, transactional coupons/inventory, constrained states, and signed/idempotent Stripe flows are tested. |
| D5 | **Fixed now** | MIME/image validation, UUID names, limits, re-encoding, and non-executable storage defenses are tested. |
| D6 | **Fixed now** | Restricted CORS, production error posture, security headers, global throttling, and removal of test routes are covered. |
| D7 | **Will do by `<production date>`** | Code indexes/transactions/query checks pass; deployment owner must enter the date and attach a successful off-server backup restore. |
| D8 | **Will do by `<production date>`** | Safe defaults/runbook exist; secret rotation, HTTPS, supervised workers/scheduler, monitoring, and alert delivery require host-side evidence. |
| D9 | **Will do by `<production date>`** | Automated Stripe/EasyPost failure and happy-path contracts pass; deployed live/test credential and carrier evidence remains. |
| D-E1 | **Confirmed** | Dynamic test sweeps every registered admin route; all 73 return 401/403 for a regular customer. |
| D-E2 | **Fixed now** | Expanded two-customer test covers order read/cancel, address update/delete, wishlist count/check/delete, and profile read/update. |
| D-E3 | **Confirmed** | Two synchronized PHP processes compete for the last unit; exactly one succeeds. |
| D-E4 | **Confirmed** | Renamed PHP is rejected; JPEG/PHP polyglot is stored under a safe name and served only after re-encoding. |
| D-E5 | **Will do by `<production date>`** | Backup restore and deployed demo-account removal cannot be certified from the repository; follow the production checklist and attach evidence. |
| D10 | **Will do by `<production date>`** | Live read-only check still finds `Extra Demo Product`, `Electronics`, and `Empty Category`. Back up first, import the real catalogue as drafts, verify it, then remove demo data and rerun preflight. |

Detailed evidence: `docs/PRODUCTION_READINESS_TEST_EVIDENCE.md`. Complete route list:
`docs/API_V1_ROUTE_MIDDLEWARE.md`. Deployment-only actions:
`docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`.

Frontend integration handoff and consolidated response:
`docs/FRONTEND_API_INTEGRATION_HANDOFF.md`.

---

## A. Shipping, Tracking & EasyPost 🔴

The shipping integration is the largest functional gap. The frontend UI exists
and is wired; the API side is unverified or missing.

### A1 — Confirm the shipping rates endpoint 🔴

The checkout already calls this on every address change (debounced 400ms).
Please confirm it exists, is live, and matches this contract exactly.

**Request** — `POST /api/v1/shipping/rates`

```json
{
  "address": {
    "line1": "123 Main St",
    "city": "Pasadena",
    "state": "CA",
    "postal_code": "91101",
    "country": "US"
  },
  "items": [
    { "product_id": 12, "variant_id": 34, "quantity": 2 }
  ]
}
```

**Expected response** — standard envelope, `data` as an array:

```json
{
  "data": [
    {
      "rate_id": "rate_abc123",
      "carrier": "USPS",
      "service": "Priority",
      "amount": 8.45,
      "eta_days": 3
    }
  ]
}
```

Notes: `amount` may be a number or a numeric string (the frontend coerces both).
`eta_days` may be `null`. An empty array is rendered to the customer as
"no shipping options available", so please return a real error status rather
than `[]` when the lookup itself fails.

- [ ] Endpoint exists and is reachable at this path
- [ ] Response shape matches (field names and nesting)
- [ ] Rates are computed from real EasyPost data, not placeholders
- [ ] Behaviour defined for: unserviceable address, EasyPost timeout/outage,
      empty cart, product with no weight/dimensions configured

### A2 — Confirm `shipping_rate_id` is honoured on order create 🔴

`POST /api/v1/orders` already sends `shipping_rate_id` (the `rate_id` the
customer selected).

- [ ] The server **re-prices** the selected rate server-side and does not trust
      any client-sent shipping amount
- [ ] An expired, unknown, or tampered `rate_id` is rejected — never silently
      defaulted to $0 shipping
- [ ] `shipping_cost` on the returned order reflects the selected rate

### A3 — Tracking fields on the order resource 🔴 *(new — nothing exists today)*

We searched the entire frontend: there is **no tracking number anywhere in the
codebase**, because the API has never exposed one. `OrderDetailDto` carries
`shipping_cost` but no shipment data. Without this, a customer who has paid
cannot see where their parcel is, and the admin cannot tell them.

Please add to `GET /api/v1/orders/{id}` and `GET /api/v1/admin/orders/{id}`:

```json
{
  "shipment": {
    "tracking_number": "9400111899223197428490",
    "carrier": "USPS",
    "service": "Priority",
    "tracking_url": "https://tools.usps.com/go/TrackConfirmAction?tLabels=...",
    "label_url": "https://easypost-files.s3.amazonaws.com/.../label.pdf",
    "shipped_at": "2026-09-01T14:32:00Z",
    "estimated_delivery": "2026-09-04",
    "status": "in_transit"
  }
}
```

- [ ] `shipment` is `null` (not omitted, not `{}`) until a label is purchased
- [ ] `label_url` is exposed on the **admin** endpoint only, never to customers
- [ ] `status` uses a documented, fixed enum — please list the exact values so
      we can map them to translated labels in EN and AR

### A4 — Label purchase: who triggers it, and where 🔴

Undecided and unbuilt. Please confirm the intended flow:

- [ ] Is the label bought automatically when an admin moves the order to
      `processing`/`shipped`, or from an explicit "Buy shipping label" action?
- [ ] If explicit, we need `POST /api/v1/admin/orders/{id}/label` returning the
      `shipment` object above — please confirm the path and request body
- [ ] What happens on a failed purchase (insufficient EasyPost wallet balance,
      invalid address)? The admin needs a readable error message, not a 500

### A5 — Public order tracking endpoint 🔴 *(new)*

`src/modules/static/pages/TrackOrderPage.tsx` is currently **hardcoded demo
data** — it ignores the order number the customer types and always renders the
same fake shipment. It is publicly linked from the site footer, so it must be
wired to real data or removed before launch. To wire it we need:

**Request** — `POST /api/v1/orders/track`

```json
{ "order_number": "OQ-10234", "email": "customer@example.com" }
```

Requiring the email alongside the order number prevents enumeration of other
people's shipments from a guessable order number.

**Expected response**

```json
{
  "data": {
    "order_number": "OQ-10234",
    "status": "in_transit",
    "estimated_delivery": "2026-09-04",
    "events": [
      {
        "status": "shipped",
        "description": "Departed USPS facility",
        "location": "Los Angeles, CA",
        "occurred_at": "2026-09-01T20:11:00Z"
      }
    ]
  }
}
```

- [ ] Endpoint implemented — **or confirm we should delete the page instead**
- [ ] Rate limited (it is unauthenticated)
- [ ] Returns an identical generic response for "not found" and "email mismatch"
      so shipments cannot be enumerated

### A6 — EasyPost account configuration 🔴

For the record, so responsibilities are unambiguous:

- [ ] Production **and test** EasyPost API keys are held server-side only and
      are never sent to the browser
- [ ] The origin/warehouse address is configured
- [ ] Product **weight and dimensions** exist in the database and are passed to
      EasyPost — rates cannot be accurate without them. Please confirm these
      columns exist. If they do not, this is a schema addition and the admin
      product form needs matching inputs — tell us and we will add them.
- [ ] A test-mode order has been run end-to-end: rates → order → payment →
      label → tracking number visible on the order

> **Billing note (verified against EasyPost official sources, September 2026):**
> EasyPost's API does **not** require a paid plan. The **Free Access (EasyPost
> Wallet Carriers)** plan is $0/month and includes 3,000 labels/month, then
> $0.08/label. The $20/month charge is the **BYOCA** plan, which EasyPost
> **auto-enrols** accounts into when a payment method is on file and no plan was
> explicitly selected. The account owner should switch to Free Access under
> *Account Settings → Billing → Subscriptions → Manage*, and fund the wallet by
> **bank account (free)** rather than card (3.75% processing fee).

---

## B. Product Management — build requests 🟠

Carried forward from `backend-production-readiness.md` §10.3, re-prioritised.
These block the admin panel from being usable with a real catalogue.

### B1 (was R1) — Product list filtering 🟠 **Critical**

`GET /admin/products` currently accepts only `per_page`. The admin cannot
search or filter, so finding one product in a real catalogue means paging
through the whole thing. This also blocks the dashboard's low-stock deep link.

Requested params, matching the pattern `/admin/orders` already uses:

| Param | Values |
|---|---|
| `search` | matches name, SKU, slug |
| `category_id` | integer |
| `status` | `draft` \| `published` \| `archived` |
| `is_featured` | boolean |
| `sort` | `created_desc` (default), `price_asc`, `price_desc`, `stock_asc`, `name_asc` |

### B2 (was R2) — Per-image management 🟠 **High**

Today `images[]` on product update **replaces the entire gallery**. To change
one photo the admin must re-upload every other photo. This is destructive and
is the single biggest source of avoidable manual work in the panel.

- [ ] `POST /admin/products/{id}/images` — append, multipart `images[]`, same
      validation as create
- [ ] `DELETE /admin/products/{id}/images/{imageId}`
- [ ] Ordering: `POST /admin/products/{id}/images/order` with
      `{ "image_ids": [3, 1, 2] }` (first = primary), **or** per-image
      `sort_order` + `is_primary`
- [ ] Product read responses expose stable image `id`s — required by all three

### B3 (was R3) — Variant update semantics 🟠 **High**

Variant editing is **disabled** in the admin because the diff contract is
undocumented. Adding one size to an existing product currently requires
recreating the whole product.

Please implement and document, executed in a transaction:

- [ ] Variants matched by `id` are updated
- [ ] Variants sent without an `id` are created
- [ ] Omitted variants — **kept or deleted?** State which.
- [ ] An explicit delete mechanism (e.g. `"_delete": true` per variant)

### B4 (was R4) — Status enum validation 🟠 *(likely one line)*

- [ ] Enforce `in:draft,published,archived` on product create **and** update so
      an off-enum status can never be written by a bad client

### B5 (was R6) — Bulk product update 🟡 **Medium**

The admin's bulk actions currently fan out one HTTP request per product —
N round-trips, non-atomic, partial failures.

- [ ] `POST /admin/products/bulk` with
      `{ "ids": [...], "set": { "status"?, "is_featured"?, "in_stock"?, "category_id"? } }`
- [ ] Atomic, returning per-id results so we can report partial failures

### B6 (was R7) — Product import endpoint 🟡 **Medium** *(raised from Low)*

Raised in priority: the client has a large catalogue to load and manual entry is
the main bottleneck.

- [ ] `POST /admin/products/import` accepting CSV
- [ ] A **dry-run/preview mode** returning per-row create/update/error without
      writing anything
- [ ] Upsert keyed on `sku`
- [ ] Per-row error reporting with row numbers

> **Interim:** we can build a client-side importer that parses the CSV in the
> browser and calls the existing `POST /admin/products` once per product, with
> no backend change at all. For that to be safe we need **B7** below.

### B7 — Rate limiting headroom for the importer 🟠 *(new)*

If we ship the client-side importer, it will issue several hundred sequential
authenticated `POST /admin/products` requests in one session.

- [ ] Confirm the admin rate limit will not throttle this, **or** tell us the
      per-minute ceiling so we can pace the requests
- [ ] Confirm `sku` uniqueness is enforced server-side and returns a clean 422 —
      we use it to make a re-run of a partially failed import idempotent
- [ ] Confirm the max request body size for multipart product creates, so we can
      cap how many images we attach per request

---

## C. Open questions — please answer each 🟠

Carried from `backend-production-readiness.md` §10.2, still unanswered.

- [ ] **C1 (was Q1)** — `POST /admin/products` accepts `discount_price`,
      `in_stock`, `meta_title`, `meta_description` per the Swagger spec, but
      **none of them appear in the Product response schema**, so we cannot tell
      whether they are stored. The frontend sends them. Are they persisted and
      returned? A silently ignored field is silent data loss for the admin.
- [ ] **C2 (was Q2)** — Variant update semantics. *Same as B3 — answering B3
      resolves this.*
- [ ] **C3 (was Q4)** — Category `meta_description` is contradictory: absent
      from the documented request fields but present in the response schema.
      The frontend still sends it. Is it persisted, or should we remove it?
      (`meta_title` was confirmed dead and has already been removed from the UI.)
- [ ] **C4 (was Q5)** — Are category siblings ordered by `sort_order` in **both**
      the admin and the public `GET /categories` responses? The storefront must
      respect the order the admin sets via `POST /admin/categories/reorder`.
- [ ] **C5 (was Q6-b)** — Does `PATCH /admin/reviews/{id}/moderate` accept the
      literal `PATCH` verb? It was missed in the earlier verb sweep. (All other
      admin JSON verbs were confirmed unaffected; the `POST` + `_method=PATCH`
      convention is scoped to the two multipart routes only.)

---

## D. Security, data & operations — still unanswered 🔴

`docs/backend-production-readiness.md` was sent on 2026-07-11 and **no section
has been returned**. It is the largest unmitigated risk in the project. The
frontend's route guards, `isAdmin` checks, price display, coupon handling and
file-type checks are **user-experience features, not security controls** — an
attacker talks to the API directly with curl or Postman, not through our React
code.

| ID | Section | Items | Focus |
|---|---|---|---|
| D1 | §1 Authentication & Session Security | 11 | token validity/revocation, rate limiting, OTP hardening, Google `id_token` verification, no user enumeration |
| D2 | §2 Authorization | 4 | **most critical** — admin role check on every `/admin/*` route, IDOR on every per-user resource |
| D3 | §3 Input Validation & Injection | 7 | parameterised queries, allowlisted sort/filter params, pagination caps, stored XSS |
| D4 | §4 Business Logic Integrity | 8 | prices from DB only, coupon rules, atomic stock, order state machine, **Stripe webhook signature verification** |
| D5 | §5 File Upload Security | 7 | magic-byte sniffing, extension allowlist, regenerated filenames, no script execution in the upload directory |
| D6 | §6 API Hygiene | 8 | CORS locked to the production origin, `APP_DEBUG=false`, error contract, no legacy/test routes |
| D7 | §7 Database | 5 | indexes, FK `ON DELETE` behaviour, transactions, N+1 audit, **tested** backup restore |
| D8 | §8 Operations & Deployment | 7 | secrets, HTTPS, logging, monitoring, queue health, timezone/currency |
| D9 | §9 Payments & Shipping | 5 | Stripe live keys, happy path, failure paths, refunds — shipping now expanded in §A above |

### The five items we need evidence for, not assurances *(evidence required)*

- [ ] **D-E1** — Admin endpoint sweep: call **every** `/admin/*` endpoint with a
      regular customer token. All must return 401/403. Full route list, not a sample.
- [ ] **D-E2** — IDOR test with two customer accounts A and B: `GET /orders/{id}`,
      order cancellation, wishlist, addresses, messages, profile.
- [ ] **D-E3** — Concurrent last-unit order test: two simultaneous orders for the
      final unit in stock. Exactly one must succeed.
- [ ] **D-E4** — Disguised-PHP upload test: a PHP file renamed `.jpg` with JPEG
      magic bytes. Must be rejected, or stored where it can never execute.
- [ ] **D-E5** — Backup restore test actually performed once, plus confirmation
      that all test/demo accounts are removed — specifically
      `admin@store.com` / `password123`, which was shared during testing.

### D10 — Production database contains demo data 🔴

The live API is serving test content into a production storefront:

- Products literally named **"Extra Demo Product N"**, with descriptions stating
  they exist to test pagination
- An **"Empty Category"**
- An **"Electronics"** category — in a modest-fashion store

- [ ] Confirm this is removed and replaced with the real catalogue before launch
- [ ] Confirm who owns loading the real catalogue (see the import discussion in §B6)

---

## Deliverable

Please return:

1. **This document**, with every ID marked Confirmed / Fixed now /
   Will do by `<date>` / N/A + reason.
2. **The route list with middleware** (`php artisan route:list` or equivalent)
   so we can cross-check guard coverage against D2.
3. **Output/evidence** for D-E1 through D-E5.
4. **Confirmation of the shipping contracts in §A** — these are the last
   functional gap before the site can take a real order.

Once §A and §D come back we can sign off the combined frontend + backend
readiness report and hand over to the client.
