# Backend Action Items, Defect Reports & Contract Clarifications

**Project:** Otantik Queen E-commerce (`apis.otantikqueen.com`)  
**From:** Frontend Team  
**To:** Backend Team / Engineering Lead  
**Date:** September 2026 — **Revision 2, September 24, 2026**  
**Reference:** Live API verification (September 13 and September 24, 2026), your handoff `FRONTEND_API_INTEGRATION_HANDOFF.md` (September 3, 2026) & Frontend Integration Plan (`docs/frontend-integration-plan.md`)  
**Purpose:** Consolidated tracker for critical production blockers, live API defects, missing endpoint contracts, and technical clarifications required to achieve a clean production launch.

---

## What Changed in Revision 2

This revision replaces the first version. We re-checked the live API on September 24 and read our requests against your September 3 handoff again. As a result:

- **New blocker [BLK-3]:** the shared test admin account must be removed from production.
- **New section 3 item [DEP-1]:** the bulk and image admin routes now respond on production (401, not 404). Please confirm what is deployed.
- **New defect [BE-5]:** failed validation redirects (302) instead of returning a JSON 422 when the `Accept` header is missing.
- **Rewritten [ADM-EP1..3]:** your handoff already answered most of these questions. Only the parts it did not cover are still asked.
- **New items:** Q29 (bulk order status), Q30 (customer emails), and **Section 5** (backend documents we have not received).
- **Withdrawn September 25:** Q9, Q10, Q11, Q14, Q23 and Q26. Your September 3 handoff already answers them; details are in `frontend-response-to-api-handoff.md`.
- **No change** on September 24 for BLK-1, BE-1, BE-2, BE-3 and BE-4. All five were re-verified and are still open.

---

## How to Use This Document & Response Format

Each item in this register carries a unique **ID**, an **impact summary**, and an **actionable ask**. Please provide your response directly under each item using one of the four standard statuses:

- **`[Fixed now]`** — Implemented and deployed. Please provide the endpoint details or commit/PR reference.
- **`[Confirmed]`** — Verified to work as described in the contract.
- **`[Will do by <Date>]`** — Accepted and scheduled for delivery by the specified date.
- **`[N/A]`** — Not applicable / rejected. Please provide a brief technical rationale so the risk can be recorded.

---

## Table of Contents
1. [🚨 Section 1: Critical Blockers & Production Outage](#1-critical-blockers--production-outage-)
2. [⚠️ Section 2: Defects Identified on the Live API (BE-1 to BE-5)](#2-defects-identified-on-the-live-api-be-1-to-be-5-)
3. [🛑 Section 3: Phase 4 Admin Endpoints (Blocking `VITE_ADMIN_PRODUCT_ENDPOINTS_V2`)](#3-phase-4-admin-endpoints-blocking-vite_admin_product_endpoints_v2-)
4. [📋 Section 4: Contract Inquiries & Architectural Clarifications](#4-contract-inquiries--architectural-clarifications-)
   - [A. Checkout, Shipping & Orders](#a-checkout-shipping--orders)
   - [B. Catalog, Products & Admin Usability](#b-catalog-products--admin-usability)
   - [C. Architecture, Performance & Storefront](#c-architecture-performance--storefront)
5. [📄 Section 5: Backend Documents Requested](#5-backend-documents-requested-)
6. [🚀 Section 6: Go-Live Gate & Deployment Readiness Checklist](#6-go-live-gate--deployment-readiness-checklist-)

---

## 1. Critical Blockers & Production Outage 🚨

> [!CAUTION]
> **Active Production Outage:** Production (`https://apis.otantikqueen.com`) **cannot complete any checkout today**. The backend's new checkout validation is deployed, but product catalogue data prevents quotes from succeeding.

### [ID: BLK-1] Checkout Outage: All Published Products Missing Shipping Dimensions (Q4)
* **Problem:**
  1. The backend has deployed a contract change making `shipping_rate_id` strictly **required** on `POST /api/v1/orders`.
  2. Simultaneously, every single one of the 44 published products in the production database currently returns `null` for all dimensional attributes: `weight_oz`, `length_in`, `width_in`, `height_in`.
  3. Consequently, calling `POST /api/v1/shipping/rates` fails with HTTP `422 Unprocessable Entity` for every cart:
     ```json
     {
       "errors": {
         "shipping": ["Product SKU AB-CLS-BLK is missing shipping dimensions."],
         "code": "shipping_configuration"
       }
     }
     ```
  4. **Impact:** No customer can retrieve a shipping rate quote, and because a rate is required to submit an order, **no customer can place an order in production right now**.
* **Required Backend Action:**
  - [ ] **Immediate Data Backfill:** Execute a database migration to populate sensible default dimensions and weights across all existing published products.
  - [ ] **Or Provide an Admin Filter/Endpoint:** Provide a way for store managers to filter and isolate products missing shipping data (e.g., `GET /admin/products?missing_shipping_data=true`).
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BLK-2] Complete EasyPost Account & Warehouse Origin Setup (Item A6)
* **Problem:** Production shipping rates and label generation are blocked awaiting EasyPost configuration and warehouse origin details.
* **Required Backend / Deployment Owner Action:**
  - [ ] Generate and wire the **EasyPost Test API Key** in staging/dev.
  - [ ] Define the physical **Warehouse Origin Address** (sender location).
  - [ ] Define the **Standard Package Definitions** used by the server to calculate parcel fits.
  - [ ] Verify an end-to-end sandbox lifecycle: quote rates → select rate → create order → purchase label → verify tracking number on the order.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BLK-3] Remove the Shared Test Admin Account from Production (D-E5)
* **Problem:** The admin account `admin@store.com` / `password123` was shared in plain text during testing. Your handoff marks its removal as "Will do by production date" (D-E5), and we have no confirmation that it has been done. While it exists, anyone who has seen that password has full admin access to production: catalogue, orders, customer data and refunds.
* **Required Backend / Deployment Owner Action:**
  - [ ] Delete the account, or change both its email and its password to strong, unshared values.
  - [ ] Revoke every token issued to it.
  - [ ] Confirm that no other test or demo accounts (customer or admin) remain in the production database.
  - [ ] Reply with the date this was done. Do **not** send any credentials in the reply.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

## 2. Defects Identified on the Live API (BE-1 to BE-5) ⚠️

These issues were verified against the deployed API at `https://apis.otantikqueen.com`. BE-1 to BE-4 were found on September 13, 2026 and were **re-verified as unchanged on September 24, 2026**. BE-5 is new on September 24:

### [ID: BE-1] Inactive Categories & Hidden Products Leaking into Public Storefront
* **Affected Routes:** `GET /api/v1/products` and `GET /api/v1/products/{slug}`
* **Finding:** Both endpoints return `hidden-inactive-product` (SKU `CL-HID-INAC`), whose description states that items in disabled categories *"must not show up"*. Its parent category (`inactive-category`) is correctly omitted from `GET /api/v1/categories`, but the product list query does not filter by active parent categories.
* **Impact:** Public customers can view and purchase inactive products; the sitemap and prerender engine include them (e.g., `/product/hidden-inactive-product` is indexed).
* **Required Action:** Ensure public product queries enforce `is_active = true` on both the product and its parent category.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BE-2] `in_stock=true` Query Parameter Fails Validator (422)
* **Affected Route:** `GET /api/v1/products?in_stock=true`
* **Finding:** The endpoint responds with `422 Unprocessable Entity`:  
  `"The in stock field must be true or false."`  
  The validator only accepts `1` or `0`, rejecting standard boolean literals sent by HTTP clients (such as Axios).
* **Required Action:** Update the validation rule to accept boolean literals `true` / `false` in addition to `1` / `0`. *(Frontend has added a client-side conversion to `1/0` as a temporary defensive measure).*
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BE-3] Public Geolocation Endpoint Returns 404
* **Affected Route:** `GET /api/v1/geo/me`
* **Finding:** Returns `404 Not Found: route could not be found`. The swagger export only includes `GET /api/v1/admin/geo/me`.
* **Required Action:** Confirm what public route storefront customers should query to detect their country, or confirm whether `GET /api/v1/geo/me` will be opened to unauthenticated traffic.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BE-4] Demo Catalog Remains in Production Database
* **Finding:** Production contains demo products and categories:  
  `iphone-15-pro-max`, `macbook-pro-m3-max`, `extra-demo-product-1...20`, `out-of-stock-abaya`, `electronics`, `empty-category`.
* **Impact:** Dummy data is indexed in sitemaps and visible to public visitors.
* **Required Action:** Take a full database backup, wipe dummy entries, and import the authentic product catalog.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: BE-5] Validation Failures Return a 302 Redirect Instead of JSON 422
* **Affected Routes:** All public API routes with validated query parameters (observed on `GET /api/v1/products`)
* **Finding:** If a request does not send `Accept: application/json`, a failed validation returns **`302 Found` → `https://apis.otantikqueen.com/`** (Laravel's web-form redirect-back behaviour) instead of a JSON `422`. Reproduce:
  ```bash
  curl -i "https://apis.otantikqueen.com/api/v1/products?in_stock=true"   # 302 → /
  curl -i "https://apis.otantikqueen.com/api/v1/products?per_page=abc"    # 302 → /
  ```
  The same requests return the correct JSON `422` when `Accept: application/json` is sent.
* **Impact:** The storefront always sends the header, so customers are not affected today. However:
  - any other client (webhooks, monitoring, the build-time prerenderer, third-party integrations) receives a redirect to an HTML page instead of an error;
  - this contradicts the "consistent error contract" confirmed under D6.
* **Required Action:** Make every `/api/*` route always return JSON. For example, force `Accept: application/json` in API middleware, or render `ValidationException` as JSON for `api/*` in the exception handler.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

## 3. Phase 4 Admin Endpoints (Blocking `VITE_ADMIN_PRODUCT_ENDPOINTS_V2`) 🛑

> The frontend code for the improved admin product and gallery management is fully built and tested against mocks. It stays behind the environment flag `VITE_ADMIN_PRODUCT_ENDPOINTS_V2=false` until we have confirmed that these endpoints are live on production.
>
> Your September 3 handoff (§7.3–§7.5) already documents most of the contract. The items below therefore ask only for (a) confirmation that it is **deployed** and (b) the details the handoff did not cover.

### [ID: DEP-1] Confirm What Is Deployed on Production
* **Finding (September 24, 2026):** Routes that were `404` on September 13 now return **`401 Unauthenticated`**, which means they exist:
  - `POST /api/v1/admin/products/bulk`
  - `POST /api/v1/admin/products/{id}/images`
  - `POST /api/v1/admin/products/{id}/images/order`
  - `DELETE /api/v1/admin/products/{id}/images/{imageId}`

  (For comparison, an unknown route such as `/admin/nonexistent` still returns `404` before authentication.) It looks as if the September 3 revision, or part of it, was deployed, but we were not told.
* **Required Action:**
  - [ ] Confirm which backend revision (commit or tag) is live, and whether all of its migrations have run.
  - [ ] Confirm that everything in handoff §7 is live: the B1 admin list filters, the B2 image endpoints, the B3 variant diff, the B5 bulk update and the B6 CSV import. (This also answers Q27.)
  - [ ] **Going forward:** please (1) notify the frontend team before each production deploy, and (2) expose a small public endpoint such as `GET /api/v1/health` → `{ "version": "<commit>", "deployed_at": "<ISO-8601>" }`, so we can check this ourselves.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: ADM-EP1] Atomic Bulk Product Update Endpoint
* **Route:** `POST /api/v1/admin/products/bulk`
* **Already answered by the handoff (§7.5), no reply needed:**
  - all-or-nothing, with `422` and zero writes on any invalid ID or value;
  - success returns `[{ "id", "status": "updated", "product" }]`;
  - capped at 100 distinct IDs;
  - `set` accepts `status`, `in_stock`, `is_featured` and `category_id`.
* **Still needed:**
  - [ ] Covered by **DEP-1**: confirm it is live on production.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: ADM-EP2] Dedicated Product Image Management Endpoints
* **Routes:**
  - append: `POST /api/v1/admin/products/{id}/images`
  - delete: `DELETE /api/v1/admin/products/{id}/images/{imageId}`
  - reorder: `POST /api/v1/admin/products/{id}/images/order` with `{ "image_ids": [...] }`
* **Already answered by the handoff (§7.4), no reply needed:**
  - image IDs are stable;
  - `image_ids` on `/images/order` is canonical, and `/images/reorder` with `order` is deprecated;
  - the first ID becomes the primary image;
  - limits are 8 images and 5 MB per image.
* **Still needed (Q16):**
  - [ ] What does each of the three mutations **return**? Is it the refreshed product, just the gallery, or nothing? Today we refetch the product after every image action. If the response carries the updated gallery, we can drop that extra request.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

### [ID: ADM-EP3] Transactional Variant Diff Contract (B3)
* **Route:** `POST /api/v1/admin/products/{id}` with `_method=PATCH` (multipart, `variants` as a JSON string)
* **Already answered by the handoff (§7.3), no reply needed:**
  - `id` → update;
  - no `id` → create;
  - `id` + `_delete: true` → delete;
  - **omitted variants are kept**;
  - the whole update runs in one transaction;
  - IDs belonging to another product → `422`.
* **Still needed (Q28):**
  - [ ] For an **existing** variant, when the admin leaves a field blank we send `price: null` or `stock_qty: null`. Does `null` mean "leave unchanged" or "clear the value"? If it clears the value, we will omit the key instead.
* **Backend Reply:**
  - **Status:** `[ ] Confirmed  [ ] Fixed now  [ ] Will do by: _______  [ ] N/A`
  - **Details:**

---

## 4. Contract Inquiries & Architectural Clarifications 📋

### A. Checkout, Shipping & Orders

#### [Q1 & Q2] Server-Side Tax Calculation & Order Total Breakdown
1. How is `tax` computed on `POST /orders`? Is it a flat rate, destination-based, or handled via a tax provider?
2. Can the estimated tax amount be provided pre-checkout (e.g., via the rates endpoint or a dedicated `POST /cart/quote`), so the customer doesn't encounter a discrepancy between the frontend review screen and the Stripe charge?
3. What is the authoritative formula for `total`? Confirm whether it strictly follows: `subtotal - discount + shipping_cost + tax`.
* **Backend Reply:**

---

#### [Q3] Free Shipping Promotion Handling
* The frontend has removed client-side overrides (e.g., `subtotal >= 150 ? 0 : shipping`) because the server enforces authoritative rate selection.
* How should marketing free-shipping promotions function? As a coupon code (`coupon_code`), or as an automatic server-side shipping rate override?
* **Backend Reply:**

---

#### [Q9] Is `errors.code` Present on Every Rates Error? — ✅ Withdrawn
> **Withdrawn September 25:** handoff §4.1 lists all six codes under `errors.code`. No reply needed. See `frontend-response-to-api-handoff.md`.

* We verified live that `POST /shipping/rates` returns `errors.code` **and** `errors.shipping[]` together for `shipping_configuration`.
* Is `errors.code` also present on the other five business-error codes the endpoint can return? If so, we can remove our fallback that matches on message text.
* **Backend Reply:**

---

#### [Q10] 422 Error Codes on Order Creation (Quote Mismatch) — ✅ Withdrawn
> **Withdrawn September 25:** handoff §3 + §4.2: the codes arrive in `errors.code` on a 422. No reply needed. See `frontend-response-to-api-handoff.md`.

* When calling `POST /api/v1/orders`, what is the exact error structure when a quote expires or the cart changes?
* Please confirm whether the following codes are returned under `errors.code` or under `errors.shipping_rate_id`:
  `invalid_shipping_rate`, `shipping_address_changed`, `shipping_items_changed`, `shipping_parcel_changed`.
* **Backend Reply:**

---

#### [Q11] Label Purchase Response & Error Codes — ✅ Withdrawn
> **Withdrawn September 25:** handoff §6.1: 409 = not paid+processing, 422 = purchase failure; the response carries both flat fields and `shipment`. No reply needed. See `frontend-response-to-api-handoff.md`.

* **Route:** `POST /api/v1/admin/orders/{id}/label`
1. Does a successful response return the order with an embedded `shipment` object, or does it return flat fields under `data` (`tracking_number`, `label_url`, `easypost_shipment_id`)?
2. When label purchase is rejected because an order is in an invalid state (e.g., unpaid or not in `processing`), does the API return **`409 Conflict`** or **`422 Unprocessable Entity`**? *(The UI needs this to distinguish "not ready yet" from "failed charge").*
* **Backend Reply:**

---

#### [Q12] Absolute Rate Expiry Timestamp
* Rates are valid for 15 minutes. The frontend currently approximates this using a client timer, which drifts.
* Can the server include an explicit ISO-8601 `expires_at` timestamp on each rate object returned by `POST /shipping/rates`?
* **Backend Reply:**

---

#### [Q13] Complete Enum Values for Order & Payment Statuses
* A new status `pending_payment` has appeared in the backend handoff.
* Please provide the comprehensive list of enum values for:
  1. `Order.status` (e.g., `pending_payment`, `pending`, `processing`, `shipped`, `delivered`, `cancelled`).
  2. `Order.payment_status` (e.g., `unpaid`, `paid`, `refunded`, `partially_refunded`).
* **Backend Reply:**

---

#### [Q23] `billing_address` Schema on `POST /orders` — ✅ Withdrawn
> **Withdrawn September 25:** handoff §4.2 sends `billing_address: null`; the frontend now does the same. No reply needed. See `frontend-response-to-api-handoff.md`.

* In the swagger spec, `billing_address` uses keys `first_name`, `last_name`, `address_line_1`, whereas `shipping_address` uses `name`, `line1`, `line2`, `city`, `state`, `postal_code`, `country`.
* The frontend currently sends identical structures for both. Does the validator accept this? And does the server accept `billing_address: null` when billing matches shipping?
* **Backend Reply:**

---

#### [Q24] Resuming Payment for `pending_payment` Orders
* If a customer closes their Stripe checkout tab or the session expires, the order remains in `pending_payment` and the cart is cleared.
* Can `GET /orders/{id}` return `checkout_url` while the session is alive?
* Is there an endpoint to generate a new Stripe session for an existing order (e.g., `POST /orders/{id}/checkout-session`)?
* How does the backend handle the `checkout.session.expired` webhook (does it cancel and restock the order)?
* **Backend Reply:**

---

#### [Q26] Is Email Verification Required for Checkout? — ✅ Withdrawn
> **Withdrawn September 25:** handoff §3: `403` means verification is required; the frontend now routes the customer to OTP. No reply needed. See `frontend-response-to-api-handoff.md`.

* Does `POST /api/v1/orders` reject orders if `email_verified_at` is `null`?
* If rejected, what is the exact HTTP status and error code so the frontend can redirect the user to OTP verification?
* **Backend Reply:**

---

#### [Q30] Customer Transactional Emails
* The storefront FAQ tells customers: *"Once your order ships, you'll receive an email with a tracking number."* We have no documentation of which emails the backend sends.
1. Which customer emails exist today? For example: order confirmation, payment received, shipped (with tracking link), delivered, cancellation or refund, and OTP / password reset.
2. Are they branded (logo, colours, store address), and who owns their content?
3. Are they sent in **Arabic** to customers who shop on `/ar`? If so, how does the backend know the customer's language? We can send a `locale` field on registration and order creation if needed.
4. Which address are they sent from, and is SPF/DKIM configured for it so they do not land in spam?
* **Backend Reply:**

---

### B. Catalog, Products & Admin Usability

#### [Q17] Contact Message Status Filtering or Work Counts
* `GET /api/v1/admin/contact-messages` currently only accepts `limit`. To display unread message badges, the frontend must fetch 100 items and count client-side.
* We request either:
  1. A `status` query filter (`GET /admin/contact-messages?status=new`), or
  2. A lightweight summary endpoint `GET /api/v1/admin/work-counts` returning:
     ```json
     {
       "orders_needing_fulfillment": 5,
       "pending_cancellations": 2,
       "pending_reviews": 8,
       "new_messages": 3
     }
     ```
* **Backend Reply:**

---

#### [Q18] Admin Products Paginator Contract
* Please confirm whether `GET /api/v1/admin/products` returns the flat `LengthAwarePaginator` (`data`, `current_page`, `last_page`, `total`) or the standard resource envelope (`{ data, links, meta }`), and confirm support for the `page` query parameter.
* **Backend Reply:**

---

#### [Q19] Product Import CSV Column Specification
* `docs/PRODUCT_IMPORT_CSV.md` is in the backend repository. Please send it (see Section 5) so we can document the import template for the store administrator.
* Handoff §7.6 says `dry_run` is a boolean. We send `"1"` / `"0"` in the multipart body, which Laravel's `boolean` rule accepts. Please confirm this works; no reply is needed if it does.
* **Backend Reply:**

---

#### [Q20] `GET /admin/users` Search & Relation Schemas
* For `GET /api/v1/admin/users`:
  1. Does it support server-side search via `?search=` across name, email, and phone?
  2. Please confirm the exact attribute names for user statistics (e.g., `orders_count`, `reviews_count`, `email_verified_at`).
  3. What is the response structure for `/admin/users/{id}/addresses` and `/admin/users/{id}/wishlist`?
* **Backend Reply:**

---

#### [Q25] Order Refund Endpoint Rules
* For `POST /api/v1/admin/orders/{order}/refund`:
  1. Does accepting a cancellation request for a paid order trigger a Stripe refund automatically, or must the admin execute a refund separately?
  2. Which order statuses permit refunds (unfulfilled only, or also `shipped`/`delivered`)?
* **Backend Reply:**

---

#### [Q27] Confirmation of B1 Product List Filters
* Handoff §7.1 documents the filters. What is still open is whether they are **deployed**, which is answered under **DEP-1**.
* One addition: the admin filter bar also sends `page`. Please confirm `page` is accepted alongside `per_page` (see also Q18).
* **Backend Reply:**

---

#### [Q29] Bulk Order Status Update Endpoint
* The admin orders list has a bulk "Mark as…" action. With no bulk endpoint, it sends one `PATCH /admin/orders/{id}/status` per order and reports partial failures. The 120 requests/minute limit is also shared with the rest of the admin session.
* Request: `POST /api/v1/admin/orders/bulk-status` with `{ "ids": [..], "status": "shipped" }`. It should follow the product bulk contract: all-or-nothing, the same state-machine rules as the single-order endpoint, and a per-ID result.
* Not blocking launch.
* **Backend Reply:**

---

### C. Architecture, Performance & Storefront

#### [Q5] Dynamic Server-Side Sitemap
* Sitemaps are currently generated during the frontend build, delaying search engine indexing for new products until the next deployment.
* Can the API provide a dynamic `sitemap.xml` or a lightweight feed `GET /api/v1/products/sitemap` returning slugs and `updated_at`?
* **Backend Reply:**

---

#### [Q6b] Read Access to Admin Audit Logs
* An audit middleware is active across 73 admin endpoints.
* Is this log exposed through an admin query endpoint (e.g., `GET /api/v1/admin/audit-logs`) for display in the dashboard?
* **Backend Reply:**

---

#### [Q7] Stock Delta Adjustment for In-Person Bazaar POS
* In-person sales currently update stock by writing absolute quantities. If an online purchase happens concurrently, stock figures can be overwritten.
* Can we have a delta adjustment route:
  `POST /api/v1/admin/products/{id}/stock/adjust` with payload: `{ "delta": -2, "variant_id": 15 }`?
* **Backend Reply:**

---

#### [Q8] Staging Stub / Mock Shipping Driver
* While awaiting EasyPost account activation, does the backend have a mock/stub driver setting to return simulated shipping rates so end-to-end checkout testing can proceed?
* **Backend Reply:**

---

#### [Q14] Public Order Tracking Schema & Rate Limits — ✅ Withdrawn
> **Withdrawn September 25:** handoff §5.2 documents the shape and the limits (5/IP, 3/pair per minute). No reply needed. See `frontend-response-to-api-handoff.md`.

* For `POST /api/v1/orders/track`:
  1. Confirm the expected response shape:
     ```json
     {
       "order_number": "OQ-1001",
       "status": "in_transit",
       "estimated_delivery": "2026-09-20",
       "events": [
         {
           "status": "shipped",
           "description": "Departed USPS Facility",
           "location": "Pasadena, CA",
           "occurred_at": "2026-09-15T12:00:00Z"
         }
       ]
     }
     ```
  2. Confirm the rate limits applied (frontend is configured for 5 req/IP and 3 req/order-email pair per minute).
* **Backend Reply:**

---

#### [Q15] Batch Endpoints for Cart Revalidation & Guest Wishlists
* To eliminate excessive network fan-out, could the backend support:
  1. `GET /api/v1/products?slugs=abaya-1,dress-2` (revalidates the entire cart on mount in 1 request rather than N requests).
  2. A bulk endpoint to merge guest wishlist items on user login.
* **Backend Reply:**

---

#### [Q21] Guest Checkout Capability
* `POST /api/v1/orders` currently requires a Bearer token.
* Will guest checkout be supported directly (unauthenticated order placement with guest email), or should the frontend automatically register a guest account from the shipping details?
* **Backend Reply:**

---

#### [Q22] Migration to HttpOnly Session Cookies
* Access tokens are currently stored in `localStorage`.
* Can the auth API issue tokens via secure `httpOnly, SameSite` cookies to protect sessions against XSS?
* **Backend Reply:**

---

## 5. Backend Documents Requested 📄

Section 12 of your September 3 handoff lists these documents in the backend repository. We have not received any of them. Please send them as files or links. Several of your "Confirmed" answers (D1–D6, D-E1–D-E4) point to them as the evidence, and our July request for the route list with middleware is still open without them.

| Document | Why we need it |
| :--- | :--- |
| `docs/PRODUCTION_READINESS_TEST_EVIDENCE.md` | The evidence behind D-E1 (admin sweep), D-E2 (IDOR), D-E3 (last-unit race) and D-E4 (upload) |
| `docs/BACKEND_PRODUCTION_READINESS_RESPONSE.md` | Your item-by-item response to `backend-production-readiness.md` §1–§9 |
| `docs/API_V1_ROUTE_MIDDLEWARE.md` | The route list with middleware requested in July. Lets us cross-check guard coverage |
| `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md` | The remaining production gates and who owns each (feeds Section 6) |
| `docs/SHIPPING_CONTRACT.md` | The concise shipping contract, including all rate error codes (Q9, Q10, Q12) |
| `docs/PRODUCT_IMPORT_CSV.md` | CSV column rules for the store administrator's import template (Q19) |

* **Backend Reply:**
  - **Status:** `[ ] Sent  [ ] Will send by: _______`
  - **Details:**

---

## 6. Go-Live Gate & Deployment Readiness Checklist 🚀

The following operational tasks must be completed and signed off before public launch:

| Item | Requirement & Description | Assigned Owner | Status |
| :--- | :--- | :--- | :--- |
| **Q4 / BLK-1** | Resolve missing shipping measurements on 44 published products to restore checkout | Backend + Catalog Owner | `[ ] Pending` |
| **A6 / BLK-2** | Wire EasyPost test/live keys, warehouse origin address, and parcel configurations | Deployment Owner / Ops | `[ ] Pending` |
| **BLK-3** | Remove the shared `admin@store.com` test account and any other test/demo accounts; revoke their tokens | Deployment Owner | `[ ] Pending` |
| **BE-1** | Ensure products in disabled categories are strictly excluded from public API & sitemap | Backend Team | `[ ] Pending` |
| **BE-5** | Every `/api/*` route returns JSON errors (no 302 redirects) | Backend Team | `[ ] Pending` |
| **D10 / BE-4** | Purge dummy demo catalog items from production database and import the real catalogue | Deployment Owner | `[ ] Pending` |
| **D7 / D-E5** | Provide evidence of automated off-server encrypted backups & verified restore drill | Deployment Owner / DevOps | `[ ] Pending` |
| **D8** | Complete secret rotation, verify HTTPS/SSL, and configure supervised queue workers | Deployment Owner / DevOps | `[ ] Pending` |
| **D9** | Configure live Stripe & EasyPost credentials and record a verified live test purchase | Deployment Owner | `[ ] Pending` |
| **§11** | Run `php artisan app:production-readiness` and attach the verification log | Backend Team | `[ ] Pending` |
| **DEP-1** | Confirm the deployed backend revision; notify the frontend team before each production deploy | Backend Team | `[ ] Pending` |
| **Q30** | Confirm customer order and shipping emails are sent (and in which language) | Backend Team | `[ ] Pending` |
| **Section 5** | Send the six referenced backend documents | Backend Team | `[ ] Pending` |
| **FE-Deploy** | Set `VITE_CHECKOUT_SERVER_RATES=true` in `.github/workflows/deploy.yml` on next push | Frontend Team | `[ ] Ready` |
| **ADM-Flag** | Enable `VITE_ADMIN_PRODUCT_ENDPOINTS_V2=true` once DEP-1 confirms ADM-EP1..3 are live and a short live pass succeeds | Frontend Team | `[ ] On Hold` |

---

### Communication & Handshake
For any questions regarding DTO types, fixtures, or MSW mocks used by the frontend, please refer to the frontend integration test suite or contact the frontend lead.
