# Backend plan for `backend-reply-2026-10-08.md`

Each item from the frontend's reply is mapped to what the code does today and the work needed. Code references are from branch `ai/post-approval-audit` at `ad3b823`.

Legend: **CODE** = backend code change · **DOC** = answer or doc only · **OPS** = server, Stripe, DNS or data task (not in the repo) · **DECIDE** = needs an owner decision first.

---

## 0. Blocker: how this fits with phase 08

`docs/AI/08-post-approval-audit.md` is still at the Stage A gate: no code changes until the reviewer **and** the owner approve, and **API contracts are frozen**. Most items below add fields or routes (`server_time`, `label_download_url`, contact replies, `category_id`, variant `final_price` and others), so under the phase-08 rules each one is a `NEEDS-DECISION`.

The owner needs to choose one of these:
- **(a) Recommended:** treat this reply as owner-approved scope that unfreezes the listed contract changes. Run it as **phase 09** on a new branch from `main`, after phase 08 Stage B lands. Phase-08 findings that overlap (A08-PAY-001 = B6) are fixed once, in phase 08.
- **(b)** Merge it into phase 08 Stage B. This makes one large branch and a harder review.

---

## 1. What already matches the request (answer or deploy only)

| Item | Finding in the code | Action |
|---|---|---|
| G2 label path | Both `POST admin/orders/{order}/label` and `/ship` exist ([routes/api.php:173-174](../routes/api.php#L173-L174)). The OpenAPI docs already call `/ship` a deprecated alias. | DOC: `/label` is final. `/ship` stays until v2. |
| G3 (current behaviour) | Buying a label sets `status: shipped` ([Admin/ShippingController.php:190](../app/Http/Controllers/Api/V1/Admin/ShippingController.php#L190)). That is Option A today. | DECIDE (see §3) |
| G7 schedule | `orders:capture-authorized-payments` runs every 5 minutes with `withoutOverlapping()` ([routes/console.php](../routes/console.php)). | DOC, plus OPS to confirm the cron entry |
| G10 methods | `payment_method_types: ['card']`, `capture_method: manual` ([StripeCheckoutService.php:57,74](../app/Services/StripeCheckoutService.php#L57)). | DOC |
| Address strictness | `verifyAddress` already throws unless `verifications.delivery.success` is true ([EasyPostService.php:56](../app/Services/EasyPostService.php#L56)). The live D7/D8 results mean live is behind. | OPS: deploy, then retest D7/D8 |
| B11 Google | The new-user path sets `email_verified_at` ([GoogleAuthService.php:114](../app/Services/GoogleAuthService.php#L114)). The **link-to-existing-account** path still needs checking. | Check; probably a CODE one-liner |
| B15 V2 product endpoints | The image routes exist ([routes/api.php:142-145](../routes/api.php#L142-L145)). | Add or confirm feature tests, then tell the frontend to turn the flag on |
| B19 contact limit | `public-form` is already `perMinute(5)` per IP ([AppServiceProvider.php:127](../app/Providers/AppServiceProvider.php#L127)). The 9–10/min seen live means live is behind, or the IP is shared or proxied (check `TrustProxies`). | OPS: deploy, then retest |

---

## 2. Work packages (in order)

### WP1: P0 quick wins (small, low risk)
1. **G1 health version.** Keep `APP_VERSION`/`APP_DEPLOYED_AT`. The deploy script writes them (git SHA plus UTC time). Fallback: read `.git/HEAD` or a `storage/app/release.json` written at deploy time. Test: health returns a non-`unknown` version when one is set.
2. **G5 `server_time`.** Add `server_time` (UTC ISO 8601) to `GET /orders`, `GET /orders/{id}` and the cancel response. Also set `exposed_headers: ['Date']` in [config/cors.php:31](../config/cors.php#L31).
3. **G7 configurable window.** Replace `Order::CUSTOMER_CANCEL_WINDOW_HOURS = 3` with `config('orders.direct_cancel_window_minutes')`, set from env `ORDER_DIRECT_CANCEL_WINDOW_MINUTES` (default 180). Update `customerCancelDeadline()` ([Order.php:80-94](../app/Models/Order.php#L80-L94)) and `CaptureAuthorizedPayments` to use it. Add 180 to `PRODUCTION_DEPLOYMENT_CHECKLIST.md`.
4. **G7 stale-hold alert.** Add a scheduled check (hourly) that sends `SendAdminAlert` when an order has been `authorized` for more than 6 hours. Add a cache key so the same alert is not repeated.
5. **B12 model 404s.** In [bootstrap/app.php:76](../bootstrap/app.php#L76), render `ModelNotFoundException`/`NotFoundHttpException` on `api/*` as `{"message":"Not found."}` (or "Order not found." per model). OPS: `APP_DEBUG=false` on live.
6. **B13 review error order.** In [ReviewService.php:22-53](../app/Services/ReviewService.php#L22-L53), move the purchase check (`REVIEW_PURCHASE_REQUIRED`, 403) **before** the already-reviewed check (409).
7. **B9 duplicate email.** Confirm the register 422 includes `errors.email`. If the global handler drops `errors`, fix it there. Wording: "This email can't be used. Try signing in or resetting your password."
8. **B10 reset code.** Add a test that a second submit of the same reset OTP returns invalid or expired. Fix [OtpService.php](../app/Services/OtpService.php) if it does not consume the code.
9. **B8 dashboard.** Both `current_orders_count` and `alerts.pending_orders` count `pending_payment` ([DashboardController.php:59,65](../app/Http/Controllers/Api/V1/Admin/DashboardController.php#L59)). Redefine:
   - `current_orders_count` = `processing` with `payment_status` in `authorized, paid`
   - `pending_orders` = `pending_payment`
   - `low_stock` = `stock_qty < 3`

   Document each one as the matching order-list or product-list filter.

### WP2: P0 payments and cancellation
1. **G6 failed capture.** Today, `capture()` only handles an intent with status `canceled` ([OrderPaymentService.php:51](../app/Services/OrderPaymentService.php#L51)). Other exceptions are logged and **retried every 5 minutes forever** ([CaptureAuthorizedPayments.php:31](../app/Console/Commands/CaptureAuthorizedPayments.php#L31)). Plan:
   - Add a migration for `orders.fulfillment_hold` (bool) and `capture_failed_at`.
   - On a terminal failure (intent `canceled`, card declined, or `requires_payment_method`), set `payment_status: failed`, `fulfillment_hold: true`, and keep `processing`. Alert the admin through Telegram **and** email (admin alerts are Telegram-only today: [SendAdminAlert.php](../app/Jobs/SendAdminAlert.php)).
   - Transient errors: retry with a counter, then alert after N tries.
   - The label purchase refuses orders with `fulfillment_hold`. Admin "Cancel order" restocks.
   - Customer view: order shows "Payment problem, we will contact you" (`cancellation.mode: none`, reason `payment_failed`).
2. **G8 admin cancel and bulk.**
   - `POST /admin/orders/{id}/status` returns `AdminOrderResource`, the same shape as `show`.
   - Bulk status ([Admin/OrderController.php:148](../app/Http/Controllers/Api/V1/Admin/OrderController.php#L148)) rejects `cancelled` for orders that are `authorized` or `paid`, and reports those rows as skipped.
   - Add `order_id` and `order_number` to [CancellationRequestResource.php](../app/Http/Resources/CancellationRequestResource.php).
3. **B6 (= phase-08 A08-PAY-001)** `processing → shipped` requires `tracking_number` (from a purchased label, or a manual `carrier` + `tracking_number` in the status request) and a captured payment. Otherwise 422. This applies to single and bulk updates.
4. **G10 webhooks.**
   - The handler covers only `checkout.session.completed`, `checkout.session.expired`, `charge.refunded` and `refund.failed` ([StripeWebhookController.php:60](../app/Http/Controllers/Api/StripeWebhookController.php#L60)). Decide whether to add `payment_intent.canceled` (hold released outside the app → same path as G6) and `payment_intent.amount_capturable_updated`. Then send the exact list to the frontend.
   - Check that "authorized" comes from the PaymentIntent `requires_capture` status, not from the session's `payment_status`. If it doesn't, fix it.
   - OPS: check `ORD-WDWF8KLSUW` and `ORD-YNCIANMFTI` in the Stripe dashboard and report.
5. **G9 (P2, optional)** `voided` payment status. Recommend doing it now: the enum is new and nothing is in production yet. DECIDE.

### WP3: P0 shipping labels (G4, plus G3 if Option B)
1. In `EasyPostService::getShippingRates` ([EasyPostService.php:131](../app/Services/EasyPostService.php#L131)), pass `options: {label_format: PDF, label_size: 4x6}`. For an older non-PDF label, call `shipment->label(id, ['file_format' => 'PDF'])`.
2. After purchase, download the PDF once to the private disk at `labels/{order_number}.pdf`. Add a migration for `orders.label_path`.
3. Add a new route `GET /api/v1/admin/shipments/{order}/label` with `signed` middleware and **no** Sanctum. It streams the PDF with `inline`, `private, no-store`.
4. Add `label_download_url` (`URL::temporarySignedRoute`, 15 minutes) to `AdminOrderResource` and to the label-purchase response.
5. Tests: the PDF streams, a bad or expired signature returns 403, and the link works after `label_url` is cleared. Use `FakeEasyPostService` for the download.
6. **B7:**
   - After purchase, set `shipment_status` from the tracker (`pre_transit`), not `UNKNOWN`.
   - [PublicOrderTrackingController.php:47](../app/Http/Controllers/Api/V1/PublicOrderTrackingController.php#L47) defaults to `'pre_transit'` when there is no shipment. Return `null` instead.

### WP4: P0 emails (G11)
- OPS: set `APP_NAME="Otantik Queen"` and fix the `MAIL_FROM_ADDRESS`/`MAIL_USERNAME` typo (`conact-us` → `contact-us`) in the live `.env`. Check SPF, DKIM and DMARC.
- CODE: give all mails a shared branded layout with logo and footer. Remove "Laravel" by using `config('app.name')`.
- CODE: add **Order confirmed** mail at authorization (replacing the plain `OrderPaidMail`): items, address, total, order link, and "cancel free until {direct_until in store timezone}". Needs the store timezone config (DECIDE the zone: America/Los_Angeles?).
- CODE: the cancellation mail's money sentence depends on `refund_status`: released / succeeded / pending ([cancellation_request_decided.blade.php:121](../resources/views/emails/cancellation_request_decided.blade.php#L121)). Do the same for the direct-cancel mail if one exists.
- CODE: change the OTP subject and heading ("Your Otantik Queen verification code"; drop "Verification Code for Email verification").

### WP5: P0 contact messages (B1, B2)
- Migration `contact_message_replies` (`contact_message_id`, `admin_id`, `body`, timestamps). Add a `user_id` to `contact_messages` if it is missing, so they can be matched to signed-in customers (fallback: match by email).
- `POST /admin/contact-messages/{id}/replies` → `{id, admin_name, body, created_at}`. Queues the reply email and sets status to `replied`.
- `GET /me/contact-messages` (auth) → the customer's messages with `replies[]`.
- Feature tests and OpenAPI annotations.

### WP6: P0 catalog (B3, B4, B5)
- **B3:** Write a failing feature test that sends exactly what the frontend sends (multipart, `_method=PATCH`, `is_active="true"`, `parent_id=""`, `meta_description=""`). Locally, `"true"` fails the `boolean` rule (422, not 500), so the live 500 is probably a schema or deploy gap (e.g. `categories.description` migration not run). OPS: get the live `laravel.log`. CODE: in `prepareForValidation`, turn `"true"`/`"false"` into booleans, and add `meta_description` to the OpenAPI schema.
- **B4:** Add `category_id` to [ProductResource.php](../app/Http/Resources/ProductResource.php). Add a test that a product created with `category_id` is saved and listed on `GET /categories/{slug}`. Look into why the category page lists only 1 of 85 products (filters on `status`/`is_active`/stock?).
- **B5:** Add `final_price` to variants. Rule: the variant's own sale price if it has one, otherwise the product discount applied to the variant (DECIDE: if `discount_price` is an absolute price, does it replace the variant price, or become a ratio?). Use it in [OrderInventoryService.php:118](../app/Services/OrderInventoryService.php#L118) and in the cart, wishlist and coupon totals.

### WP7: P1 images (B14)
- Change the conversions in [Product.php:57-75](../app/Models/Product.php#L57-L75) to `product_card` 600×800 (3:4). Keep `product_detail` 3:4 only if the frontend confirms the frame. Then run `media-library:regenerate` (OPS).
- Strip EXIF from originals on upload (re-encode with Intervention/GD), or stop exposing original URLs.
- (d) answer: conversions are `nonQueued()`, so they are generated synchronously during the request and no worker is needed. Note the effect on upload time.
- (c) OPS: set `upload_max_filesize ≥ 5M` and `post_max_size ≥ 45M` on the host.

### WP8: P2 (after launch)
B17 atomic `POST /admin/bazaar/sales`, B18 free-shipping coupons (only if the owner wants them), G9 if it is deferred.

---

## 3. Decisions needed from the owner

| # | Question | Recommendation |
|---|---|---|
| D1 | Phase 08 vs this work (§0) | Phase 09 after phase 08 Stage B |
| D2 | G3: Option A (label = shipped) or B (stay `processing` until the first scan, void the label on accept) | **A for UAT.** It matches the code and is already tested. Note that by label time the payment has already been **captured**, so B would mean a refund *plus* a label void, not a hold release. Plan B as P1 if the owner wants it. |
| D3 | G9: add `voided` now? | Yes, before launch (cheap now) |
| D4 | B5: how `discount_price` applies to variants | Variant's own sale price, otherwise the product's discount **percentage** applied to the variant price |
| D5 | Store timezone for emails | America/Los_Angeles (to confirm) |
| D6 | Extra Stripe events (`payment_intent.canceled`, …) | Add `payment_intent.canceled` only |

## 4. OPS checklist (owner or host, not code)
Deploy and set `APP_VERSION` · cron `schedule:run` · queue worker under Supervisor (mails are queued) · live `.env`: `APP_NAME`, mail sender typo, `APP_DEBUG=false` · SPF/DKIM · turn the bot challenge off for both domains (B16) · PHP upload limits · Stripe webhook event list and the 2 orders · delete or fix addresses 31 and 33 (D29) · `ORDER_DIRECT_CANCEL_WINDOW_MINUTES=10` for UAT, then 180 · re-check the EasyPost webhook URL.

## 5. Process for every work package
For each work package: write a failing test first, implement, then run `php artisan test --compact`, `pint --test` and `phpstan --level=5`. Update the OpenAPI annotations and the frontend handoff docs (`FRONTEND_ORDER_CANCELLATION.md`, `FRONTEND_ADDRESS_EASYPOST.md`). Finish with a reply doc that marks each item **done / changed / not doing**, as the frontend asked.

Rough size: WP1 ~1 day · WP2 ~2 days · WP3 ~1.5 days · WP4 ~1 day · WP5 ~0.5 day · WP6 ~1 day · WP7 ~0.5 day.
