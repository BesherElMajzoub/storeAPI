# Backend Remediation Plan

**Project:** Otantik Queen E-commerce (`apis.otantikqueen.com`)
**Prepared:** September 25, 2026
**Inputs:** `docs/frontend-response-to-api-handoff.md`, `docs/backend-requests-and-clarifications (2).md`
**Method:** Every item below was checked against the current repository (Laravel 12, PHP ^8.2) — routes, controllers, FormRequests, services, models, resources, migrations, seeders, config, and tests. No production requests were made (read-only, no-network verification only). No credential values are reproduced anywhere in this document.

---

## Implementation Progress — September 26, 2026

The first implementation batch is complete and verified:

- **Completed:** P0-2 test isolation; the code portion of P0-3/NEW-1; BE-1; BE-2; BE-5; omitted-field preservation for ADM-EP3; Q12; Q30; Q31; Q20 admin-user search and explicit resources; Q8 fake shipping driver; and the DEP-1 versioned health endpoint.
- **P2 completed locally:** Q24 resume payment is implemented at `POST /api/v1/orders/{id}/checkout-session`. It reuses open Stripe sessions, replaces only confirmed-expired sessions, serializes concurrent attempts, enforces owner/state checks, and ignores stale expired-session webhooks. The frontend contract is documented in `docs/PAYMENT_CHECKOUT_CONTRACT.md`.
- **Documentation completed locally:** all six requested Section 5 files now exist: `PRODUCTION_READINESS_TEST_EVIDENCE.md`, `BACKEND_PRODUCTION_READINESS_RESPONSE.md`, `API_V1_ROUTE_MIDDLEWARE.md`, `PRODUCTION_DEPLOYMENT_CHECKLIST.md`, `SHIPPING_CONTRACT.md`, and `PRODUCT_IMPORT_CSV.md`. Production/DevOps evidence is explicitly marked pending rather than inferred. `PAYMENT_CHECKOUT_CONTRACT.md` is an additional integration contract for Q24.
- **P3 API enhancements completed locally:** Q5 product sitemap feed; Q6b filtered admin audit-log access with recursive sensitive-key redaction; Q7 atomic stock delta adjustment; Q15 batched product slugs and idempotent guest-wishlist merge; Q17 contact-message status/search filters; and Q29 all-or-nothing bulk order status updates.
- **Contract closure completed locally:** ADM-EP2 image mutation responses and the Q18 nested paginator/`page` behavior now have regression coverage; Q13, Q16, Q18, Q19, and Q27 are answered in `BACKEND_PRODUCTION_READINESS_RESPONSE.md`; companion-document references now use the repository's actual filename.
- **Defense in depth:** PHPUnit now forces Telegram credentials to empty values, and `StripeWebhookSecurityTest` explicitly fakes `SendAdminAlert`.
- **Production readiness:** `app:production-readiness` now fails when any published product has missing or non-positive shipping data.
- **Correction to this plan:** NEW-2 was a false positive. `OrderObserver` is registered in `AppServiceProvider` and calls the idempotent `OrderInventoryService::release()` whenever an order moves to `cancelled` or `refunded`. A new regression test proves that accepting a cancellation restores inventory.
- **Verification:** Laravel Pint passed, `git diff --check` passed, and the full suite passed with **159 tests / 901 assertions** after the P2/P3 local implementation batches. No test contacted Telegram.
- **BE-1 defense in depth:** inactive-category products are now excluded from public list/detail responses and rejected by both shipping-rate normalization and final inventory reservation, preventing stale carts or direct product IDs from bypassing the storefront filter.
- **Still operationally blocked:** production credential rotation, product-measurement backfill, demo-data cleanup, EasyPost/warehouse configuration, production readiness output, and the end-to-end sandbox lifecycle require production/operations access and catalog-owner data.
- **Still decision-blocked:** tax, public geo behavior, and explicit variant-`null` semantics (product sign-off still wanted even though the code now preserves-unless-provided). *(Update — batch 3: tax and variant-`null` semantics are now resolved; see below. `geo/me` remains open.)*

## Implementation Progress — September 26, 2026 (batch 2)

A second local batch closes three of the six items previously listed as decision-blocked. **Q3 (free shipping) and Q21 (guest checkout) are now implemented**, and **Q22 (httpOnly cookies) is now implemented as an additive authentication mode**, not a replacement:

- **Q3 — Free shipping, both methods:**
  - **Automatic threshold:** `GET/PUT /api/v1/admin/settings/shipping` lets an admin enable/disable automatic free shipping and set a minimum-subtotal threshold (stored in the existing `settings` table via `App\Models\Setting`, not hardcoded). `App\Services\FreeShippingService::subtotalQualifies()` evaluates it against the order's raw subtotal (`>=` threshold, inclusive). **Business default: enabled at a $100 threshold** until an admin changes it — implemented as an application-level default in `FreeShippingService` (falls back to `true`/`100.0` when no `settings` row exists yet) rather than a migration-seeded row, after a migration-based seed proved order-dependent/flaky under `RefreshDatabase` in the test suite.
  - **Free-shipping coupon:** `Coupon.type` now accepts `free_shipping` alongside `percentage`/`fixed` (admin create/update endpoints and `CouponService::calculateDiscount()` updated; a free-shipping coupon produces a `$0` subtotal discount, not a shipping override by itself, and still enforces every existing coupon rule — active, expiry, usage limits, `minimum_order_amount`).
  - **Either condition waives customer shipping to $0.** The real EasyPost quote amount is preserved separately in a new `orders.carrier_shipping_cost` column (admin-only, `AdminOrderResource`) for accounting; `orders.free_shipping_reason` (`threshold`|`coupon`|`null`) records why, and is customer-visible via `OrderResource`.
  - `OrderController::store()`'s calculation was reordered to match the requested sequence literally: subtotal → discount → free-shipping eligibility → shipping → tax (still `0.0`, pending the Q1/Q2 decision) → total.
  - The public `POST /coupons/validate` response and `CouponResource` both now report a `free_shipping` boolean.
- **Q21 — Guest checkout: confirmed NOT supported, by design.** `POST /api/v1/orders` already required `auth:sanctum` before this batch; no code path allows an unauthenticated order. This is now covered by an explicit regression test rather than being an implicit side effect of routing.
- **Q22 — Web SPA auth migrated to Sanctum stateful httpOnly cookies, Bearer tokens fully preserved:**
  - `bootstrap/app.php` now calls `$middleware->statefulApi()`, prepending Sanctum's `EnsureFrontendRequestsAreStateful` to the `api` middleware group. For requests whose `Referer`/`Origin` matches `SANCTUM_STATEFUL_DOMAINS`, this self-contained pipeline adds session, CSRF (`ValidateCsrfToken`), and cookie-encryption middleware automatically; every other request (mobile apps, Postman, server-to-server) is completely unaffected and continues to authenticate via Bearer token exactly as before.
  - `AuthController@login/@register/@googleLogin` now also call `Auth::login($user)` + `$request->session()->regenerate()` (guarded by `$request->hasSession()`, a no-op for non-frontend requests), establishing a first-party session cookie alongside the still-issued Bearer token.
  - `AuthController@logout` now also invalidates the session and rotates the CSRF token for stateful requests (`Auth::guard('web')->logout()`, `session()->invalidate()`, `session()->regenerateToken()`).
  - **Found and fixed a real bug during this work:** `logout()` previously called `$user->currentAccessToken()?->delete()` unconditionally. A cookie-only-authenticated user's `currentAccessToken()` returns Sanctum's `TransientToken` placeholder, which has no `delete()` method — this crashed with a 500 the first time a purely cookie-authenticated user logged out. Fixed by only calling `delete()` when the resolved token is a real `PersonalAccessToken`.
  - `SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, and `SANCTUM_STATEFUL_DOMAINS` were added to `.env.example` with guidance comments; CORS (`supports_credentials: true`, `sanctum/csrf-cookie` in `paths`) was already correctly configured from prior work and needed no changes.
- **Tests added:** `tests/Feature/FreeShippingTest.php` (14 tests — admin settings CRUD/authorization, automatic threshold on/off/boundary, free-shipping coupon, both methods together, minimum-order enforcement, public validate flag, admin coupon creation, and a resume-payment interaction test) and `tests/Feature/SpaAuthTest.php` (7 tests — stateful middleware wiring, httpOnly/SameSite cookie flags on login, session-only authentication, a full login→access→logout→revoked cookie round trip, non-frontend requests are unaffected and Bearer auth still works, unauthenticated checkout is 401, and order ownership/IDOR).
- **Verification:** Laravel Pint applied to every file touched in this batch (pre-existing style debt elsewhere in the repo was left untouched — out of scope); `git diff --check` clean; full suite passed with **183 tests / 1032 assertions**, zero failures, no test contacted Telegram/Stripe/EasyPost.
- **Still open:** BE-3 (`geo/me`) remains a genuine product decision with no code change.
- **Resolved — September 26, 2026 (batch 3):** three of the four remaining decision-blocked items now have a confirmed business answer, with no further code change needed:
  - **Q1/Q2 (tax):** confirmed as an explicit launch decision, not an unresolved blocker — tax stays `0` for the current launch; no tax system is being built yet. `orders.tax` remains hardcoded to `0` intentionally.
  - **ADM-EP3/Q28 (variant `null` semantics):** approved as the permanent, official API contract — omitted field = leave unchanged, explicit `null` = clear the value. Q28 is closed.
  - **Q3 (free shipping default):** the $100 automatic threshold is confirmed as correct and ships enabled by default at launch. Both mechanisms (automatic threshold + `free_shipping` coupon) stay as implemented.
  - **EasyPost package weight capacities (§4 of `docs/backend-remediation-remaining.md`):** the assumed capacities (`bag_small` 24 oz, `box_small` 32 oz, `bag_large` 48 oz, `box_medium` 64 oz, `box_large` 112 oz) are accepted for launch. They remain documented as operational assumptions, not supplier-certified limits, and should still be sanity-checked against real fulfillment when convenient.

---

## 1. Executive Summary

**Launch readiness: not ready.** Checkout is genuinely broken in production today, and the root cause is narrower than the two source documents suggest — it is not just "products are missing data," it is a **specific admin code path that bypasses a safeguard that already exists everywhere else.**

The single highest-value fact this plan adds to the record: `StoreProductRequest` and `UpdateProductRequest` (single-product create/update) and `ProductImportService` (CSV import) **all** enforce "a published product must have `weight_oz`/`length_in`/`width_in`/`height_in`" via `required_if:status,published`. `BulkUpdateProductsRequest` — the endpoint the admin bulk-actions UI actually uses to flip products to `published` — **enforces no such rule** (`app/Http/Requests/Api/V1/Admin/BulkUpdateProductsRequest.php:9-20`, `app/Http/Controllers/Api/V1/Admin/ProductController.php:461-488`). This is almost certainly how 44 products got published with null dimensions: someone bulk-published a batch. **BLK-1 is therefore both a data blocker (needs a backfill) and a P1 code defect (needs the gap closed), not a pure data-entry accident.**

Four other findings materially change the risk picture the source documents present:

1. **Tax is not a rounding edge case — it does not exist.** `orders.tax` is written nowhere in the codebase; every order total is `subtotal + shipping - discount`. This needs a product decision, not just a bug fix.
2. **The `502`-on-Stripe-failure cleanup the frontend asked about (Q31) is confirmed non-atomic.** Order creation (stock reservation, coupon increment, quote consumption) commits in its own transaction *before* Stripe is called. When Stripe session creation then fails, the order is soft-deleted, but the shipping quote's `consumed_at` is never reset and the coupon's `used_count`/`CouponUsage` row is never reversed. A customer whose card session fails burns a coupon use and a shipping quote for nothing.
3. **Cancellation inventory recovery is already implemented indirectly.** The original review missed the registered `OrderObserver`: changing an order to `cancelled` invokes `OrderInventoryService::release()`. A regression test now proves that accepting a cancellation restores inventory, so no duplicate release call was added.
4. **The test suite can reach the real Telegram API.** `tests/Feature/StripeWebhookSecurityTest.php` exercises a webhook path that dispatches `SendAdminAlert` (a queued job that posts to `api.telegram.org`) without `Queue::fake()`/`Http::fake()`. Combined with `phpunit.xml` setting `QUEUE_CONNECTION=sync` and no `.env.testing` overriding Telegram credentials, running this test with real `.env` credentials present sends a real Telegram message. Every other service-touching test in the suite (Stripe, EasyPost, mail) is correctly faked — this one file is the exception.

Conversely, several items the frontend still lists as open are **already correctly implemented** and just need a documentation reply: the label-purchase 409/422 split, `POST /orders/track`, the admin bulk-update contract (ADM-EP1), the admin products paginator shape (Q18), CSV `dry_run` normalization (Q19), and the full order/payment status enums (Q13). Closing these out costs nothing but a reply and shrinks the open-items list materially.

Some claims in the source documents are themselves stale: the specific demo-data identifiers in BE-4 (`iphone-15-pro-max`, `electronics`, etc.) and the specific test account `admin@store.com`/`password123` in BLK-3 do not exist in the current seeders, which now produce `demo-*`-prefixed data via a `@demo.test` account domain. The underlying *concern* in both items is still valid (demo seeders run unconditionally in any non-production environment; a production database has never been directly inspected), but the documents need a rewrite, and BLK-3's credential-rotation ask should be broadened rather than closed.

---

## 2. Evidence and Classification Matrix

Legend — **Class**: `CD` = Confirmed code defect · `PD` = Confirmed production data/configuration blocker · `AI` = Already implemented, docs need updating · `PDec` = Contract/product decision required · `ME` = Missing evidence / cannot verify · `W` = Withdrawn/obsolete.

| ID | Class | Evidence (current code) | Disposition |
|---|---|---|---|
| **BLK-1** Missing shipping dimensions | PD + CD | Columns nullable, no default: `database/migrations/2026_09_03_000001_add_shipping_quotes_and_physical_fields.php:12-15`. Guard exists on create/update: `StoreProductRequest.php:78-82`, `UpdateProductRequest.php:86-90`. Guard exists on CSV import: `ProductImportService.php:215,222-225` (`required_if:status,published`). **Guard absent on bulk update**: `BulkUpdateProductsRequest.php:9-20`, `ProductController.php:461-488` — `set.status` can be set to `published` with no dimension check. | Backfill + close the bulk-update gap (P0/P1). |
| **BLK-2** EasyPost/warehouse setup | PD | `config/services.php:75-91` — API key, webhook secret, warehouse `store_origin` (with code-committed placeholder defaults, e.g. "123 Main Street"), and 3 hardcoded package presets. `.env.example:45,88-89`. `app/Console/Commands/ProductionReadinessCheck.php:88-96` already checks the origin isn't still the placeholder. No DB-backed origin/settings table. | Production config task (P0). |
| **BLK-3** Shared admin@store.com account | W (as stated) + PD (as concern) | No seeder creates `admin@store.com`. Current: `database/seeders/DemoAccessSeeder.php:14` (`PASSWORD = 'Demo1234!'`), accounts at `*@demo.test` (lines 60-84). `admin@store.com` survives only as a Swagger example (`AuthController.php:112,450`) and as a legacy-detection literal in `ProductionReadinessCheck.php:57-69`, which **also already matches `%@demo.test`** — i.e., the automated check already covers the current seeder's account pattern. Seeders are blocked only in `production` (`DatabaseSeeder.php:11-13`), not in staging. | Rewrite the doc to name current identifiers; still run the credential-rotation task against production (P0) since the check has never been executed there per available evidence. |
| **BE-1** Inactive category leaks | CD | `app/Models/Product.php:118-121` (`scopePublished`) and `:123-161` (`scopeFilter`) never reference `categories.is_active`. `ProductController.php:57-64` (index) and `:85-97` (show) call `published()`/`filter()` with no category join. `Category::is_active` is a real cast boolean (`app/Models/Category.php:18-23`). The doc's specific repro fixture (`CL-HID-INAC`) does not exist — `DemoCatalogSeeder.php:86-88` deliberately excludes the inactive category from product assignment, so there is currently no seeded product to demonstrate this live. | Fix scope + add a regression fixture (P1). |
| **BE-2** `in_stock=true` fails validation | CD | `app/Http/Requests/Api/V1/ListProductsRequest.php:23`: `'in_stock' => ['sometimes','boolean']`. Laravel's `boolean` rule accepts only `[true,false,0,1,'0','1']` (strict), not `"true"`/`"false"` strings. Downstream, `Product.php:154` (`scopeFilter`) already normalizes correctly via `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — the bug is isolated to the FormRequest rule. | One-line fix, same pattern already used for `dry_run` (P1). |
| **BE-3** Public `geo/me` 404 | CD/PDec | `routes/api.php:125` registers `geo/me` only inside the admin group, using `Admin\GeoController` (`Admin/GeoController.php:14`). No non-admin `GeoController` exists. | Needs a product decision (open publicly vs. tell frontend to use something else), then a route (P1). |
| **BE-4** Demo catalog in production | W (specific identifiers) + PD (concern) | `grep -ri "iphone\|macbook"` — zero matches. Current seeder produces `demo-product-{n}-{type}` / `DEMO-P-{n}` (`DemoCatalogSeeder.php:110-114`), not the cited names. `ProductionReadinessCheck.php:78-85`'s `demoContentCount()` still checks legacy `'Extra Demo Product%'` by name but its SKU check (`'DEMO-%'`) and category check (`slug like 'demo-%'`) already match current seeder output. | Update doc with current identifiers; run the (already-built) detection check against production as the real verification (P0). |
| **BE-5** 302 instead of JSON 422 | CD | `bootstrap/app.php:63-65` — `withExceptions` closure is empty; no `shouldRenderJsonWhen()` override. No JSON-forcing middleware exists anywhere in `app/Http/Middleware`. `routes/api.php:39` only applies `throttle:api`. Laravel's default `Handler::shouldReturnJson()` (vendor) falls back to `$request->expectsJson()`, which is false with no `Accept`/`X-Requested-With` header, so `ValidationException` renders via `invalid()` → 302 redirect. Confirmed for Laravel 12's `bootstrap/app.php`-based exception handling (this app does not use the old `app/Exceptions/Handler.php` override pattern). | One targeted fix in `bootstrap/app.php` (P1). |
| **DEP-1** deployed routes | AI | All four routes exist inside `Route::prefix('admin')->middleware(['auth:sanctum','can:admin-access','audit.admin'])`: `products/bulk`, `products/{product}/images`, `products/{product}/images/order` (aliased with `/reorder`), and `DELETE products/{product}/images/{media}`. | Reply to frontend confirming what is live after deployment. |
| **DEP-1 (health endpoint ask)** | Completed locally | `GET /api/v1/health` now returns deployment version and timestamp metadata and has feature coverage. | Deploy and use it for future release confirmation. |
| **ADM-EP1** Bulk product update | AI (contract) + CD (dimension gap, see BLK-1) | `ProductController.php:461-488`: `DB::transaction` + `lockForUpdate()`, response shape `[{id,status:'updated',product}]` exact match (481-485). Cap of 100 + `distinct`+`exists` enforced in `BulkUpdateProductsRequest.php:12-13`. `set` accepts exactly `status, in_stock, is_featured, category_id` (14-18). | Confirm contract to frontend; fix the dimension-guard gap under P1. |
| **ADM-EP2** Image mutation responses (Q16) | AI (needs docs) | `MediaController.php`: append (`uploadProductImages`, 70-92) returns an **array of uploaded image objects** (`id, file_name, mime_type, size, order, url`), not the product. Reorder (`reorderProductGallery`, 119-132) and delete (`destroyProductImage`, 178-188) both return `success(null, ...)` — **empty data**. None return the refreshed product or gallery. | Answer Q16 with actual shapes now (P2); optionally return the fresh gallery to cut the frontend's extra refetch (P2 enhancement, not required). |
| **ADM-EP3** Variant null semantics (Q28) | CD | `ProductController.php` `update()`, existing-variant branch (~352-378): `'price' => $variant['price'] ?? null`, `'stock_qty' => $variant['stock_qty'] ?? 0`, then `->update($attributes)` — **not** `array_filter`. An explicit `null` (or an omitted key) on an existing variant **clears price to null / resets stock_qty to 0**, it does not leave the value unchanged. This is undocumented (the OA docblock at 285-300 only covers `id`/`_delete` semantics) and dangerous: an admin leaving a field blank on an edit form can silently zero out stock. | Needs a product decision on intended semantics, but the current behavior is a real data-loss risk regardless of intent — treat as P1. |
| **Q1/Q2** Tax calculation | CD + PDec | `OrderController.php:250`: `$total = max(0.0, $subtotal + $shippingCost - $discount)` — no tax term. `orders.tax` defaults to `0` (`create_store_tables.php:169`) and is never assigned anywhere in `app/` (grep confirms only reads/casts). `CouponService::applyCouponToOrder` (98-109) reads `$order->tax` but it's always 0. | Product decision needed (flat/destination/provider/none), then implementation (P2). |
| **Q3** Free shipping promotion | Completed locally | Both mechanisms implemented: automatic admin-configurable threshold (`GET/PUT /api/v1/admin/settings/shipping`, `FreeShippingService`, stored in `settings` table — not hardcoded) and a `free_shipping` coupon type (`Coupon`/`CouponService`/admin coupon endpoints). Either qualifies → customer `shipping_cost` is `0`; the real carrier cost is preserved in `orders.carrier_shipping_cost` for accounting. Ships enabled at a **$100 default threshold**, editable by an admin at any time. 14 regression tests in `FreeShippingTest.php`. | Deploy; business can adjust or disable the $100 default via the admin endpoint. |
| **Q12** Rate quote `expires_at` | CD | Stored: `ShippingQuoteService.php:34` (`now()->addMinutes(config('services.easypost.quote_ttl_minutes',15))`), column `database/migrations/2026_09_03_000001_...:47`. **Not returned**: primary response (`ShippingController.php:212-223`) only has `rate_id, carrier, service, amount, eta_days`; legacy `ShippingRateResource.php:10-21` also omits it. | Add the field to both response paths (P1 — cheap, closes a real client-drift bug). |
| **Q13** Status enums | AI | `status`: `pending, pending_payment, processing, shipped, delivered, cancelled, refunded` (migration + ALTER migration). `payment_status`: `unpaid, paid, failed, refunded`. Confirmed also used in `Admin/OrderController.php:159-180` transition maps. | Reply with the list (P2, docs only). |
| **Q21** Guest checkout | Confirmed by design | `routes/api.php`: `POST orders` sits inside `middleware('auth:sanctum')`; no unauthenticated path exists or was added. Decision: no guest checkout, per the explicit requirement. Covered by a regression test (`SpaAuthTest::test_unauthenticated_checkout_is_rejected`). | Reply to frontend confirming this is final (docs only). |
| **Q22** httpOnly cookies | Completed locally | `bootstrap/app.php` now calls `$middleware->statefulApi()`. `AuthController` login/register/googleLogin establish a stateful session (`Auth::login()` + `session()->regenerate()`, guarded by `hasSession()`) alongside the still-issued Bearer token; `logout()` invalidates the session and rotates the CSRF token. Bearer-token clients are completely unaffected. 7 regression tests in `SpaAuthTest.php`, including a real login→access→logout→revoked cookie round trip. | Deploy; set `SANCTUM_STATEFUL_DOMAINS`/`SESSION_SECURE_COOKIE` for production (see `.env.example`). |
| **Q24** Resume `pending_payment` | Completed locally | `POST /api/v1/orders/{id}/checkout-session` now enforces ownership and payable state, reuses an open Stripe session, replaces only a confirmed-expired session, and protects state races. Stale expiration webhooks are ignored when their session ID is no longer current. The registered `OrderObserver` releases stock when a matching expired session cancels the order. | Deploy the endpoint and integrate the frontend using `docs/PAYMENT_CHECKOUT_CONTRACT.md`. |
| **Q25** Refund rules | AI (contract) | `CancellationRequestController::accept()` sets `status = cancelled` but does not call Stripe, so refund remains a separate action. Inventory is released by the registered `OrderObserver`, which calls the idempotent `OrderInventoryService::release()` for cancelled/refunded states; `CancellationRequestInventoryTest` now covers this path. Guard for `POST admin/orders/{order}/refund` remains: 409 if not paid, already refunded, or missing `stripe_payment_intent_id`; there is no order-status restriction. | Reply on the refund contract. No inventory fix is required. |
| **Q30** Customer emails | CD (missing feature) | Only 4 `Mail` classes exist: `OrderPaidMail` (sent on `checkout.session.completed`, `StripeWebhookController.php:123`), `CancellationRequestDecidedMail` (accept/reject), `OtpCodeMail`, `ResetPasswordMail`. **No order-confirmation-on-creation, no shipped/tracking email, no delivered email** — the label-purchase flow (`Admin/ShippingController::createShipment`, 90-169) has no `Mail::`/`Notification::` call. No `ar` locale mail views found in `resources/lang` or `resources/views/emails`. | The storefront FAQ promises a shipping email that does not exist — build it (P1/P2, customer-facing broken promise). Locale support is a separate product decision (P3). |
| **Q31** Stripe failure cleanup | CD | `OrderController.php:233-341`: stock reservation, coupon `used_count` increment + `CouponUsage` row, items, and quote `consumed_at` marking all commit inside `DB::transaction` (235-292) **before** Stripe is called (319-341, outside any transaction). On failure, the `catch` block (325) sets `cancelled`/`failed` and soft-deletes the order (332-333) but **does not** reset the quote's `consumed_at`/`order_id`, decrement `coupon.used_count`, or delete the `CouponUsage` row. | Confirmed non-atomic compensating cleanup, matches frontend's suspicion exactly. Fix required (P1). |
| **Label 409/422** (Q11, withdrawn) | AI | `Admin/ShippingController.php`: 409 when not `processing`+paid (104-108); 422 for missing/mismatched quote (113-125), rate mismatch (130-132), and generic purchase failure (162-168). | Confirm closure with frontend (docs only). |
| **`POST /orders/track`** (Q14, withdrawn) | AI | `PublicOrderTrackingController.php:31-53`, public route (`routes/api.php:72-73`). Response shape matches exactly (`order_number, status, estimated_delivery, events`). Rate limits: 5/min per IP **and** 3/min per order+email pair, both enforced simultaneously (`AppServiceProvider.php:91-99`). Identical generic 404 for not-found and email-mismatch. | Confirm closure with frontend (docs only). |
| **Q17** Contact message filter | Completed locally | `GET /api/v1/admin/contact-messages` now accepts validated `status` and `search` filters, preserves them in paginator links, and searches name/email/phone/subject/message. | Deploy and wire the unread-message view to `status=new`. |
| **Q18** Admin products paginator | AI | `ProductController.php:66-95`: `->paginate($perPage)` (89, so `page` works natively), then wrapped: `ProductDetailResource::collection($products)->response()->getData(true)` (92) inside the app's own `success()` envelope. Final shape: `{success,message,data:{data:[...],links,meta},errors}` — resource-collection envelope, not a flat paginator, and one level deeper than a bare Resource collection. | Reply with the exact shape (P2, docs only). |
| **Q19** CSV `dry_run` | AI | `ImportProductsRequest.php:20` (`'dry_run' => ['required','boolean']`) plus `prepareForValidation()` (7-14) normalizing via `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` before validation runs. `"1"`/`"0"` from multipart form data work correctly. | Reply confirming it works (P2, docs only). |
| **Q20** Admin users search/relations | Completed locally | Admin list search covers name/email/phone. `AdminUserResource`, `AdminWishlistItemResource`, and `AddressResource` now define the returned fields explicitly while preserving paginator and tab response shapes. Contract tests assert count fields and absence of password, token, raw stock, user ID, and legacy address fields. | Deploy and confirm the admin user tabs against the frontend. |
| **Q27** Admin filter `page` param | AI | Standard `->paginate()` (see Q18) already accepts `page` natively. | Confirm to frontend, tie to DEP-1 reply (docs only). |
| **Q29** Bulk order status | Completed locally | `POST /api/v1/admin/orders/bulk-status` validates up to 100 distinct active order IDs, locks them in ID order, applies the single-order transition map, and returns a per-ID result. One invalid transition produces `409` with zero writes. | Deploy and move the admin bulk action to the new endpoint. |
| **Q5** Dynamic sitemap | Completed locally | `GET /api/v1/products/sitemap` returns up to 50,000 published/active-category product slugs with `updated_at`, excluding drafts and inactive categories. | Point the frontend/server sitemap generator at the feed. |
| **Q6b** Audit log read access | Completed locally | `GET /api/v1/admin/audit-logs` supports action, causer, search, date, and pagination filters. It is behind all admin middleware and recursively redacts password/token/secret/authorization/cookie keys in `changes`. | Deploy and add the admin dashboard view. |
| **Q7** Stock delta adjustment | Completed locally | `POST /api/v1/admin/products/{product}/stock/adjust` applies a non-zero delta under product-first row locks. Variant adjustments update product and variant totals atomically; negative outcomes and cross-product variants are rejected without writes. | Use this endpoint for bazaar/POS deltas instead of absolute stock writes. |
| **Q8** Mock/stub shipping driver | Completed locally | Shipping consumers now depend on `EasyPostServiceInterface`. `EASYPOST_DRIVER=fake` selects a cache-backed non-production implementation covering address, rates, label, tracking, and signed webhook contracts. Production resolution rejects the fake/unknown drivers, and readiness requires `easypost`. | Use it for staging integration, then run the real provider sandbox lifecycle before launch. |
| **Q15** Batch products by slugs | Completed locally | `GET /api/v1/products?slugs=a,b` accepts up to 100 unique slugs and keeps normal public visibility rules. `POST /api/v1/wishlist/merge` idempotently merges up to 100 guest product IDs and reports added/existing/rejected IDs. | Deploy and replace frontend request fan-out. |
| **Q9, Q10, Q11, Q14, Q23, Q26** | W | All six are marked withdrawn in the frontend's response doc (§5) with the answering section cited; code review above independently confirms Q11 (label 409/422) and Q14 (tracking) as correctly implemented, consistent with withdrawal. | No action; close in tracker. |
| **Section 5 documents** | Completed locally | All six requested files now exist in `docs/`. The unavailable source `backend-production-readiness.md` and production/DevOps evidence are called out explicitly rather than inferred. | Send the documents; attach production evidence to the readiness response/checklist when available. |
| **Filename mismatch** | Completed locally | References now consistently use the existing `backend-requests-and-clarifications (2).md` filename. | No further action. |
| **NEW-1** Bulk-update bypasses dimension guard | CD | See BLK-1 row — `BulkUpdateProductsRequest.php` has no `required_if:status,published` for the four dimension fields, unlike every other write path. | P1, this is likely the actual root cause of BLK-1. |
| **NEW-2** Cancellation acceptance doesn't restock | W (false positive) | `Order::observe(OrderObserver::class)` is registered in `AppServiceProvider`; the observer releases inventory on `cancelled`/`refunded`. `CancellationRequestInventoryTest` proves the acceptance path restores stock. | Closed; retain regression coverage. |
| **NEW-3** Test can reach real Telegram API | CD | `tests/Feature/StripeWebhookSecurityTest.php` dispatches `SendAdminAlert` (via `StripeWebhookController.php:122`) with no `Queue::fake()`/`Http::fake()`. `phpunit.xml:32` sets `QUEUE_CONNECTION=sync` (so the job runs inline) and has no override for `TELEGRAM_BOT_TOKEN`/`TELEGRAM_ADMIN_CHAT_ID`; no `.env.testing` exists, so real `.env` values apply. Other tests (`TelegramNotificationTest.php`, `ApiHygieneTest.php:39`) correctly use `Queue::fake([SendAdminAlert::class])` around the same trigger — this one file is the outlier. | P0 — test hygiene / credential-exposure risk. |

**44 items classified** in total (3 BLK + 5 BE + 1 DEP-1 route confirmation + 1 health-endpoint ask + 3 ADM-EP + 21 Q-items in §4 + 6 withdrawn Q-items + Section 5 docs + filename mismatch + 3 new findings).

---

## 3. P0 — Immediate Security and Checkout Recovery

| Task | Source | Action |
|---|---|---|
| **P0-1 Credential rotation** | BLK-3 | Rotate/verify: (a) any admin account matching `ProductionReadinessCheck::demoAccountCount()`'s list (`admin@store.com` and 13 other `@store.com` addresses, plus any `%@demo.test`) that exists in the **production** database; (b) treat the previously plaintext-shared password as compromised regardless of whether the account still uses it — rotate it or delete the account outright; (c) revoke every Sanctum token issued to matched accounts (`personal_access_tokens` table, filter by `tokenable_id`). Do not log or echo the credential value at any point. |
| **P0-2 Test isolation fix** | NEW-3 | Add `Queue::fake();` (or `Queue::fake([SendAdminAlert::class])`, matching the pattern already used in `TelegramNotificationTest.php`) to every test in `tests/Feature/StripeWebhookSecurityTest.php` that posts a signed webhook. As defense in depth, also consider adding `TELEGRAM_BOT_TOKEN=`/`TELEGRAM_ADMIN_CHAT_ID=` overrides to `phpunit.xml`'s `<php>` block (or a new `.env.testing`) so a missed fake can't reach the network regardless of which test misses it. |
| **P0-3 Shipping-dimension recovery** | BLK-1, NEW-1 | (a) Close the code gap: add the same `required_if:status,published` dimension rule to `BulkUpdateProductsRequest` (or reject `set.status = published` when any targeted product lacks dimensions, since bulk affects existing rows rather than new input). (b) Backfill: see §7 — do not invent universal defaults without catalog-owner sign-off. |
| **P0-4 EasyPost configuration** | BLK-2 | Wire the production API key, webhook secret, real warehouse `store_origin` (replace the "123 Main Street" placeholder — `ProductionReadinessCheck` already flags this), and confirm the 3 package presets in `config/services.php:79-83` match what Ops actually ships in. Run `php artisan app:production-readiness` (confirmed non-destructive, `ProductionReadinessCheck.php:15,47`) against the target environment and attach the output. |
| **P0-5 Demo-account/demo-catalog removal** | BLK-3, BE-4 | Run `demoAccountCount()`/`demoContentCount()` (already built into `app:production-readiness`) against production; if either is non-zero, follow the removal procedure in §7 rather than ad hoc deletion. |
| **P0-6 Checkout restoration gate** | BLK-1, BLK-2 | Do not flip `VITE_CHECKOUT_SERVER_RATES=true` until P0-3 and P0-4 are both verified — run the EasyPost sandbox lifecycle test (quote → select → order → label → tracking) end-to-end first. |

---

## 4. P1 — Confirmed API Defects

| ID | Problem | Fix location |
|---|---|---|
| **BE-1** | Public product list/detail don't check parent category `is_active` | `Product::scopePublished`/`scopeFilter` (`app/Models/Product.php:118-161`); add a `whereHas('category', fn ($q) => $q->where('is_active', true))` constraint (or join), applied in both `ProductController::index` and `::show`. |
| **BE-2** | `in_stock=true`/`false` string literals rejected | `app/Http/Requests/Api/V1/ListProductsRequest.php:23` — either broaden the rule or add a `prepareForValidation()` normalization pass, mirroring `ImportProductsRequest`'s `dry_run` pattern. |
| **BE-5** | Validation 302s instead of JSON 422 without `Accept` header | `bootstrap/app.php:63-65` — add `$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())` (or equivalent middleware forcing `Accept: application/json` on the `api` route group). |
| **NEW-1** | Bulk product update can publish products with no shipping dimensions | `BulkUpdateProductsRequest.php` (see P0-3). |
| **NEW-2** | False positive: accepted cancellations already restock through the registered `OrderObserver` | No application-code change. Regression coverage added in `CancellationRequestInventoryTest`. |
| **ADM-EP3 / Q28** | Existing-variant `null` silently clears `price`/defaults `stock_qty` to 0 | `ProductController::update()` variant branch (~352-378) — replace `?? null`/`?? 0` fallback-into-update with an explicit skip-if-absent (only write keys actually present in the payload), pending the product decision in §12 on intended null semantics. |
| **Q12** | `expires_at` computed but not returned from `POST /shipping/rates` | `ShippingQuoteService::quote()` return array (56-63) and `ShippingRateResource.php:10-21` — add the field to both. |
| **Q25 / NEW-2** | Refund remains a separate action; inventory release is already covered by `OrderObserver`. |
| **Q30** | No "order shipped" customer email exists, contradicting the storefront FAQ | `Admin/ShippingController::createShipment` (90-169) — dispatch a new `OrderShippedMail` on successful label purchase, following the `OrderPaidMail` pattern. |
| **Q31** | Stripe session failure leaves shipping quote consumed and coupon usage burned | `OrderController.php:233-341` — on the Stripe-failure catch path (325-333), also reset the quote's `consumed_at`/`order_id` and reverse the coupon `used_count`/delete the `CouponUsage` row; or restructure so quote/coupon consumption happens only after Stripe session creation succeeds. |

---

## 5. P2 — Contract Completion and Data Consistency

- **Q1/Q2 — Tax**: product decision required (flat/destination/provider/explicitly none for now), then implement and stop defaulting `orders.tax` to 0 silently.
- **Q12** already listed under P1 (cheap fix, bundled there for priority).
- **Q13 — Status enums**: reply to frontend with the confirmed lists (§2); no code change needed.
- **ADM-EP2 / Q16 — Image response shapes**: **completed locally.** The existing non-breaking shapes are documented and regression-tested; upload returns the new-image array, while reorder/delete return `data: null` and require a refetch.
- **Q18 — Paginator shape**: **completed locally.** The nested `{data:{data,links,meta}}` envelope and `page` behavior are documented and regression-tested.
- **Q19 — `dry_run`**: **completed locally.** The existing multipart boolean normalization is documented.
- **Q20 — Admin users**: **completed locally.** Search covers name/email/phone and explicit admin user/wishlist/address resources prevent accidental raw-model field exposure while keeping existing response envelopes.
- **Q21 — Guest checkout**: product decision (support guest orders vs. auto-register), reply either way.
- **Q22 — httpOnly cookies**: architecture decision; not blocking, track separately.
- **Q24 — Resume payment**: **completed locally.** `POST /orders/{id}/checkout-session` reuses an open session or regenerates only after Stripe confirms expiry. Ownership, payable state, concurrent resume calls, provider failure, order-state races, and stale expired-session webhooks have regression coverage. Expiry cancellation restocks through the registered `OrderObserver`, the same proven path used by cancellation acceptance.
- **Q25 — Refund rules**: reply confirming refund is a separate manual action and which statuses are refundable (any paid order, not status-restricted); inventory release already works through `OrderObserver`.
- **Q27 — `page` param**: **completed locally** with documentation and regression coverage under Q18.
- **Q8 — Mock shipping driver**: **completed locally.** A config-selected, cache-backed fake implements the complete interface for staging, validates signed mock webhooks, and fails closed in production. Real EasyPost lifecycle evidence remains required for launch.
- **Section 5 documents**: **completed locally.** All six requested files were produced; deployment owners still need to attach production-only evidence where marked pending.

---

## 6. P3 — Product and Architecture Enhancements

Non-launch-blocking, confirmed absent, build only after P0–P2:

- **Q3** — free-shipping coupon type or threshold override (needs product decision first).
- **Q5** — **completed locally:** dynamic product sitemap feed.
- **Q6b** — **completed locally:** filtered, redacted `GET /admin/audit-logs` endpoint.
- **Q7** — **completed locally:** atomic `POST /admin/products/{id}/stock/adjust` delta endpoint.
- **Q15** — **completed locally:** batched slug lookup and guest-wishlist merge.
- **Q17** — **completed locally:** contact-message `status` and `search` filters.
- **Q29** — **completed locally:** all-or-nothing bulk order status endpoint with per-ID results.
- **DEP-1 health endpoint** — **completed locally** at `GET /api/v1/health`, returning deployment version and timestamp metadata.
- **Filename mismatch** — **completed locally:** references now consistently use the existing `backend-requests-and-clarifications (2).md` filename.

---

## 7. Data Migration and Rollback Strategy

**Product shipping-dimension backfill (BLK-1)**
1. Do **not** invent universal default dimensions. Export the list of published products with any null dimension field (`Product::published()->where(fn ($q) => $q->whereNull('weight_oz')->orWhereNull('length_in')->orWhereNull('width_in')->orWhereNull('height_in'))`) and hand it to the catalog owner for real per-product (or per-category) values.
2. If launch cannot wait for full catalog-owner sign-off, the only acceptable interim is a **documented temporary policy** (e.g., "unmeasured products are auto-set to draft until measured" — safer than fabricating shipping data that could misquote a real customer's rate) — this itself is a product decision, not an engineering one.
3. Ship P0-3's `BulkUpdateProductsRequest` guard **before or with** the backfill so newly bulk-published products can't regress the fix.
4. Verification: after backfill, `Product::published()->whereNull(...)->count()` must be 0; add this check to `ProductionReadinessCheck`.

**Demo-data cleanup (BE-4)**
1. Run `demoContentCount()`/`demoAccountCount()` against production via `php artisan app:production-readiness` first — it's read-only and gives an exact count before anything is touched.
2. Take a full backup (see D7/D-E5 in the source doc — evidence of this was not available to verify, treat as open per §12) before any delete.
3. Delete in dependency order: order items / cart items / wishlist entries referencing demo products → demo products → demo categories, inside a transaction, verifying counts before and after.
4. Re-run the readiness check post-cleanup to confirm zero demo rows remain.

**Demo/test account removal and token revocation (BLK-3)**
1. Identify matches via `demoAccountCount()`'s query.
2. For each match: revoke all `personal_access_tokens` rows for that `tokenable_id`, then either delete the user or rotate email+password to non-guessable values — do not log the new password.
3. Confirm zero matches via the readiness check afterward.

**Rollback**
- All of the above are additive/destructive-on-demo-data-only; take a DB snapshot immediately before running any delete step, and keep it until the post-cleanup readiness check passes cleanly in production for at least one full business day.
- The `BulkUpdateProductsRequest` validation change and `in_stock`/302/expires_at/email fixes are pure code changes — rollback is a normal revert-and-redeploy, no data migration involved.

---

## 8. Testing Strategy

- **Unit**: cover the corrected `in_stock` normalization, the `expires_at` field addition, and the variant-null-semantics fix (whichever direction §12 decides) in isolation.
- **Feature/regression** (add to `tests/Feature`, following the existing style of `AdminProductContractTest.php`/`ShippingContractTest.php`):
  - BE-1: seed a product inside an inactive category (the fixture doesn't currently exist — add it) and assert it's excluded from both list and show.
  - BE-2: assert `?in_stock=true` and `?in_stock=false` both return 200.
  - BE-5: assert a validation failure on an `api/*` route without an `Accept` header still returns JSON 422, not a 302.
  - NEW-1: assert bulk-publishing a product with a null dimension returns 422 with zero writes, matching single-product behavior.
  - NEW-2: completed — regression test asserts accepting a cancellation restocks the variant/product quantity through `OrderObserver`.
  - Q12: assert `expires_at` is present and ISO-8601 in the rates response.
  - Q31: assert that a simulated Stripe-session-creation failure leaves the coupon `used_count` and quote `consumed_at` exactly as they were before the attempt.
  - Q30: assert `OrderShippedMail` is queued on successful label purchase (`Mail::fake()` + `Mail::assertQueued`).
- **Contract**: completed for ADM-EP2 image response shapes and the Q18 paginator envelope; retain these tests as change detectors.
- **Integration/smoke**: the fake-driver lifecycle (address → quote → label → tracking → signed webhook) is automated. The EasyPost sandbox lifecycle (quote → order → label → tracking) referenced in BLK-2 must still be run manually against the real provider before go-live.
- **External-service safety (must apply to all of the above and the existing suite)**: `Queue::fake()`/`Http::fake()`/`Mail::fake()` around any path that can dispatch `SendAdminAlert`, call Stripe, or call EasyPost — per P0-2, fix the one known gap first, then add a CI-time static check (e.g., a lightweight test or code-review checklist item) that any new webhook/queued-job test includes the relevant fake.
- **Production smoke** (post-deploy, read-only where possible): `php artisan app:production-readiness`, plus a single live rate quote + tracking lookup against a known test order, using safe GET requests only.

---

## 9. Deployment Sequence

1. **Pre-deploy**: land P0-2 (test fake) and P1 code fixes (BE-1, BE-2, BE-5, NEW-1, ADM-EP3 omitted-field preservation, Q12, Q30, and Q31 compensating cleanup) behind normal CI; keep the NEW-2 observer regression test and run the full suite with confirmed external-service isolation.
2. **Migrations**: none of the P0/P1 items require a new migration (dimension columns and status/payment_status enum values already exist). If the Q1/Q2 tax decision lands code, its migration (if any — e.g., a `tax_rate` config table) ships in its own deploy, separate from this batch.
3. **Config/secrets**: apply BLK-2 EasyPost production config and confirm via `ProductionReadinessCheck` in a maintenance window before enabling `VITE_CHECKOUT_SERVER_RATES`.
4. **Data**: run the BLK-1 backfill (or the interim policy from §7) and BE-4/BLK-3 cleanup **after** code deploy but **before** flipping the checkout flag, so the new bulk-update guard is already in place to prevent regression.
5. **Cache/config clear**: standard `php artisan config:clear && php artisan route:clear && php artisan cache:clear` post-deploy given `config/services.php` changes.
6. **Queue workers**: confirm supervised queue workers are running (D8 in source doc) before relying on `OrderShippedMail`/`SendAdminAlert` queued dispatch in production.
7. **Smoke tests**: `php artisan app:production-readiness`, one live rate quote, one live tracking lookup (existing test order), verify no 302 on an intentionally-malformed request without `Accept` header.
8. **Feature flags**: frontend flips `VITE_CHECKOUT_SERVER_RATES=true` only after step 4 is verified; `VITE_ADMIN_PRODUCT_ENDPOINTS_V2=true` only after DEP-1 confirmations are sent and NEW-1/ADM-EP3 fixes are live (frontend's B3 variant-diff testing depends on knowing the real null semantics).
9. **Rollback gate**: if the post-deploy readiness check or smoke tests fail, revert the code deploy immediately (no data migration is irreversible at this stage since defaults aren't being fabricated); if a data backfill already ran, restore from the pre-cleanup snapshot taken per §7.

---

## 10. Acceptance Criteria

| Item | Acceptance criteria |
|---|---|
| P0-1 Credential rotation | Zero matches on `demoAccountCount()` in production, or all matches confirmed rotated with tokens revoked; no credential value appears in any commit, log, or chat. |
| P0-2 Test isolation | `StripeWebhookSecurityTest.php` passes with `Queue::fake()`/`Http::fake()` in place; a manual audit confirms no test in the suite can reach `api.telegram.org`, Stripe, or EasyPost live endpoints. |
| P0-3 / NEW-1 Dimension backfill + guard | `Product::published()->whereNull(dimension fields)->count() === 0` in production; bulk-publishing a product with a null dimension returns 422. |
| P0-4 EasyPost config | `php artisan app:production-readiness` reports PASS on all EasyPost/warehouse checks; one full sandbox (or live) quote→label→tracking cycle succeeds. |
| P0-5 Demo cleanup | `demoContentCount()` and `demoAccountCount()` both report 0 in production. |
| BE-1 | A product in an inactive category returns 404/is absent from both list and detail endpoints. |
| BE-2 | `?in_stock=true` and `?in_stock=false` both return 200 with correctly filtered results. |
| BE-5 | A validation failure on any `/api/v1/*` route returns JSON 422 regardless of `Accept` header. |
| NEW-2 | **Satisfied:** accepting a cancellation increases the affected product/variant's `stock_qty` by the cancelled quantity; covered by `CancellationRequestInventoryTest`. |
| ADM-EP3 | Documented and enforced: leaving a variant field blank either always preserves the existing value or always clears it — no more silent, undocumented divergence between the two. |
| Q12 | `expires_at` present and correctly computed (creation time + configured TTL) in every `POST /shipping/rates` response. |
| Q31 | Simulated Stripe failure leaves `CouponUsage`/`used_count`/quote `consumed_at` unchanged from pre-attempt state. |
| Q30 | `OrderShippedMail` (or equivalent) is queued exactly once per successful label purchase. |

---

## 11. Documentation Cleanup

- **Withdraw/close**: Q9, Q10, Q11, Q14, Q23, Q26 (already withdrawn in the frontend doc; independently confirmed correct here).
- **Rewrite BE-4** with current demo identifiers (`demo-product-*`, `DEMO-P-*` SKUs, `demo-*` category slugs) — the original consumer-electronics-style names are stale.
- **Rewrite BLK-3** to reference the current `@demo.test` account pattern rather than the specific `admin@store.com` address, while keeping the rotation ask.
- **Fix the filename reference**: the companion document is actually named `backend-requests-and-clarifications (2).md` in this repo, not `backend-requests-and-clarifications.md`.
- **Marked answered:** Q13, Q16, Q18, Q19, and Q27 are documented in `BACKEND_PRODUCTION_READINESS_RESPONSE.md`. Q21 (no guest checkout) and Q22 (httpOnly cookies added, Bearer preserved) and Q3 (both free-shipping methods) are now implemented, not just decided. The optional image-response enhancement remains open; Q25 remains documented as a separate manual refund action.
- **Section 5 documents produced:** all six requested documents now exist. Keep their production-only evidence sections current as deployment artifacts arrive.

---

## 12. Open Decisions

Only items genuinely requiring business/product/infra input. **Resolved as of batch 3 (September 26, 2026):** tax (Q1/Q2), variant-`null` semantics (Q28), the free-shipping default (Q3), and the EasyPost package weight capacities — see the batch-3 note above. What's left:

1. **BE-3 / `geo/me`**: open the endpoint publicly, or tell the frontend to use a different signal for country detection? **No decision made yet — leave `GET /api/v1/geo/me` unchanged/admin-only for now.**
2. **BLK-1 interim policy** (§7): if catalog-owner measurement can't complete before launch, what temporary policy is acceptable (e.g., auto-draft unmeasured products) — this is a launch-timeline call, not an engineering one.
3. **D7/D8/D9/D-E5 and §11 items** referencing documents this repo doesn't contain (backup/restore evidence, secret rotation, live Stripe/EasyPost credential setup, the full production-readiness command's historical output) — **missing evidence**, cannot be assessed from code alone; needs whoever holds those artifacts (or production access) to attach them.

---

## 13. Go-Live Checklist

| Item | Owner | Dependency | Evidence required | Status |
|---|---|---|---|---|
| Credential rotation (BLK-3) | Deployment Owner | — | `app:production-readiness` PASS on demo-account check | Pending |
| Test isolation fix (NEW-3) | Backend Team | — | Updated `StripeWebhookSecurityTest.php`, PHPUnit Telegram overrides, full suite green | **Completed locally** |
| Dimension backfill + bulk-guard fix (BLK-1/NEW-1) | Backend + Catalog Owner | Catalog owner sign-off (§7) | Bulk guard and readiness check complete; production backfill still required | **Code complete / data pending** |
| EasyPost/warehouse config (BLK-2) | Deployment Owner / Ops | — | `app:production-readiness` PASS; sandbox lifecycle log | Pending |
| Demo catalog/account cleanup (BE-4) | Deployment Owner | Backup taken | `app:production-readiness` PASS on demo-content checks | Pending |
| BE-1 category filter fix | Backend Team | — | Regression test passes | **Completed locally** |
| BE-2 `in_stock` fix | Backend Team | — | Regression test passes | **Completed locally** |
| BE-5 JSON-always fix | Backend Team | — | Regression test passes | **Completed locally** |
| NEW-2 restock-on-cancel | Backend Team | — | Observer path proven by regression test | **Already implemented** |
| Q31 Stripe-failure cleanup fix | Backend Team | — | Regression test passes | **Completed locally** |
| Q12 `expires_at` field | Backend Team | — | Both response paths include field | **Completed locally** |
| Q30 shipped-email | Backend Team | — | `Mail::assertQueued` test passes | **Completed locally** |
| Q24 resume payment | Backend Team | — | Owner/state checks, open-session reuse, expired-session replacement, race handling, and stale-webhook tests pass | **Completed locally / deploy pending** |
| Q5/Q6b/Q7/Q8/Q15/Q17/Q20/Q29 local API batch | Backend Team | — | Sitemap, audit log, stock delta, fake shipping, batch lookup/wishlist merge, contact filters, explicit user resources, and bulk order-status tests pass | **Completed locally / deploy pending** |
| Q3 free shipping (automatic threshold + coupon, $100 default) | Backend Team | — ($100 default confirmed) | 14 tests in `FreeShippingTest.php` pass | **Confirmed, completed locally / deploy pending** |
| Q21 guest checkout confirmed unsupported | Backend Team | — | Regression test passes | **Confirmed by design** |
| Q22 httpOnly-cookie SPA auth (Bearer preserved) | Backend Team | Set `SANCTUM_STATEFUL_DOMAINS`/`SESSION_SECURE_COOKIE` in production | 7 tests in `SpaAuthTest.php` pass, including a full login/logout cookie round trip | **Completed locally / deploy pending** |
| Backup/restore evidence (D7/D-E5) | DevOps | External to this repo | Verified restore drill log | **Missing evidence** |
| Secret rotation / HTTPS / queue workers (D8) | DevOps | — | Config audit | **Missing evidence** |
| Live Stripe/EasyPost credentials + test purchase (D9) | Deployment Owner | BLK-2 | Verified live transaction log | Pending |
| `app:production-readiness` full run (§11) | Backend Team | All above | Attached PASS output | Pending |
| DEP-1 confirmation + health endpoint | Backend Team | — | Endpoint implemented and tested; deployment/reply pending | **Code complete / deploy pending** |
| Section 5 documents | Backend Team | This plan | All six requested files exist; production-only evidence inside them remains pending | **Completed locally / external evidence pending** |
| `VITE_CHECKOUT_SERVER_RATES=true` | Frontend Team | BLK-1 + BLK-2 verified | — | On hold |
| `VITE_ADMIN_PRODUCT_ENDPOINTS_V2=true` | Frontend Team | DEP-1 confirmed, NEW-1/ADM-EP3 fixed | Live pass on a draft product | On hold |

---

*This plan was originally prepared from repository state `230c215` (branch `main`) on September 25, 2026. Its Implementation Progress and checklist sections now track the remediation changes applied afterward in the working tree.*
