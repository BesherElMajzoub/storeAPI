# Handoff: finish `docs/backend-reply-2026-10-08.md`

You are continuing a Laravel 12 store API task (`D:\store\PROJECT\storeAPI`, Windows, PHP 8.2, Sanctum, PHPUnit 11, Pint, Larastan). The previous agent stopped partway through. Continue from where it stopped. Don't redo the finished work.

## Read first
1. `docs/backend-reply-2026-10-08.md`: the frontend's request (items G1–G11, Address, B1–B19, production switch).
2. `docs/backend-reply-plan-2026-10-08.md`: the plan (work packages WP1–WP8, decisions D1–D6).
3. `git status` and `git diff`: everything done so far is **uncommitted** on branch `ai/backend-reply-2026-10-08` (created from `ai/post-approval-audit` at `ad3b823`).

## Rules
- Don't commit or push unless the user asks. Stay on `ai/backend-reply-2026-10-08`.
- The owner said "do it", which overrides the phase-08 gate and contract freeze for the items in the reply. Don't add contract changes beyond those items.
- **Decisions already taken** (don't ask again):
  - G3: Option A. Buying a label sets `shipped`.
  - G9: add `voided`.
  - B5: the variant's own sale price if it has one, otherwise the product's discount as a **percentage** of the variant price.
  - Store timezone: `America/Los_Angeles`.
  - Stripe: add `payment_intent.canceled` only.
- Write a failing test first for each change. Match the code style around it.
- Tests run against MySQL `127.0.0.1:3308` / `storeapi_testing`. The full suite takes about 5 minutes. Run targeted files while working, for example `php artisan test --compact tests/Feature/X.php`.
- **Baseline before this work:** 289 pass, 3 fail. The 3 failures were already there (A08-QA-001): `CreateStaffAccountCommandTest` ×2 and `TelegramNotificationTest`. Don't count them as regressions.
- **Windows pitfalls:**
  - Prefer the Edit tool for code changes.
  - `sed` inserts with `\n` silently did nothing several times.
  - Python can't read `/dev/stdin`.
  - In Python strings, use raw strings for PHP namespaces (`\N`, `\U` break).
  - Never run a bare `cat` with no input; it hangs.
- **Lazy loading is off:** in resources, read relations only with `whenLoaded` or `relationLoaded`.

## Done so far (verified with targeted tests, all green)
- **WP1**
  - `config/orders.php`: `direct_cancel_window_minutes` (env `ORDER_DIRECT_CANCEL_WINDOW_MINUTES`, default 180), `stale_authorization_alert_hours`, `capture_max_attempts`, `store_timezone`. `Order::directCancelWindowMinutes()` replaces the old `CUSTOMER_CANCEL_WINDOW_HOURS` constant.
  - `server_time` in `OrderResource` and at the top level of `GET /orders`. `Date` is in CORS `exposed_headers`.
  - `HealthController` falls back to the git SHA when `APP_VERSION` is not set.
  - New command `orders:alert-stale-authorizations`, scheduled hourly.
  - B12: model 404s render as `"<Model> not found."` (`bootstrap/app.php`).
  - B13: the purchase check now runs before the already-reviewed check (`ReviewService`).
  - B9: `BaseAuthRequest` uses the first field error as the message, and register has a custom `email.unique` message.
  - B10: already covered by `ProductionReadinessTest::test_password_reset_token_is_single_use`. Answer only.
  - B11: `GoogleAuthService::markEmailVerified` on the link path.
  - B8: dashboard counts redefined, `filters` and `alerts.payment_holds` added, `low_stock` filter on admin products, `Product::LOW_STOCK_THRESHOLD`.
- **WP2**
  - Migration `2026_10_08_000001_...`: `payment_status` enum gains `voided`; adds `fulfillment_hold`, `capture_attempts`, `capture_failed_at`, `label_path`.
  - Every "cancelled before any charge" path now writes `voided`.
  - `OrderPaymentService::markCaptureFailed` / `recordCaptureError` cover G6. `SendAdminAlert` takes `email: true` (sends to `ADMIN_ALERT_EMAIL`).
  - Webhook handles `payment_intent.canceled`. The customer sees `cancellation.reason = payment_failed`.
  - G8:
    - Admin `POST /orders/{id}/status` returns `AdminOrderResource`.
    - Bulk refuses to cancel authorized/paid orders.
    - `CancellationRequestResource` has `order_id` and `order_number`.
  - B6: marking an order `shipped` needs a tracking number (label, or manual `tracking_number` + `shipping_carrier`). An authorized payment is captured first.
- **WP3**
  - G4:
    - Shipments are created with `options.label_format=PDF, label_size=4x6`.
    - `EasyPostServiceInterface::pdfLabelUrl` (real and fake implementations).
    - New `App\Services\ShippingLabelStore`: stores `labels/{order_number}.pdf` on the `local` disk. With the fake driver it writes a placeholder PDF.
    - Signed route `GET /api/v1/admin/shipments/{order}/label`, named `admin.shipments.label`.
    - `shipment.label_download_url` in admin responses.
  - B7: tracker status `unknown` becomes `pre_transit`. Public tracking returns `null` status when there is no label.
  - Tests: `tests/Feature/ShippingLabelPdfTest.php`.
- **WP4 (partly done, not yet tested)**
  - `config/mail.php` has a `brand` block. `.env.example` has `MAIL_BRAND_NAME`, `MAIL_LOGO_URL`, `MAIL_SUPPORT_ADDRESS`.
  - New layout `resources/views/emails/layouts/brand.blade.php`.
  - `OrderPaidMail` was renamed to `OrderConfirmedMail` (with `git mv`) and uses the new view `emails/order_confirmed.blade.php` (items, totals, address, "cancel free until …" in store time, order link `{FRONTEND_URL}/orders/{id}`). The old `order_paid` view was removed. References in app and tests were renamed but **not yet run**.
  - `order_shipped` and `reset` now use the layout.
- `.env.example` was updated (`APP_NAME`, `ORDER_*`, `ADMIN_ALERT_EMAIL`, `STORE_TIMEZONE`). OpenAPI annotations were updated for `server_time`, `voided`, `payment_failed`, `fulfillment_hold`, `label_download_url`, the dashboard filters and the manual tracking fields.

## Remaining work, in order
1. **Finish WP4 (G11)**
   - In `emails/otp.blade.php` and `emails/cancellation_request_decided.blade.php`, replace `config('app.name', 'Otantik')` with `config('mail.brand.name', 'Otantik Queen')`.
   - OTP wording: drop "Verification Code for {{ $purposeLabel }}". Map purposes in `OtpCodeMail`: `email_verification` → "verify your email", `password_reset` → "reset your password". Default subject: "Your Otantik Queen verification code" (`config/otp.php`).
   - Cancellation email money sentence:
     - If the order was never charged (`paid_at` null and payment authorized/voided): "No charge was made; your bank may show a pending hold for a few days."
     - If `refund_status` is `succeeded`: "Refunded, usually 5–10 business days."
     - Otherwise: "We are returning your payment."

     Pass the order to the view. The mail is queued right after accept, so decide from whether the payment was captured, not only from `refund_status`.
   - Add mail rendering tests. Run the journey, Stripe and pricing tests touched by the rename.
2. **WP5 (B1/B2)**
   - New table `contact_message_replies` (`contact_message_id`, `admin_id`, `body`, timestamps). Check whether `contact_messages` has `user_id`; if it doesn't, match by email.
   - `POST /admin/contact-messages/{id}/replies` with body `{body}` → `{id, admin_name, body, created_at}`. It queues a branded reply email and sets status to `replied`.
   - `GET /api/v1/me/contact-messages` (auth) → `{id, subject, message, status, replies[], created_at, updated_at}`.
   - Add tests and OpenAPI annotations.
3. **WP6**
   - **B3:** reproduce with a feature test that sends multipart `_method=PATCH`, `is_active="true"`, `parent_id=""`, `meta_description=""` to `POST /admin/categories/{id}`. Add `prepareForValidation` to cast `"true"`/`"false"` (`UpdateCategoryRequest`). Add `meta_description` to the OpenAPI schema. Note that the live 500 needs the live `laravel.log`.
   - **B4:** add `category_id` to `ProductResource`. Test that a product created with `category_id` appears on `GET /categories/{slug}`, and find out why category pages show so few products.
   - **B5:** add `final_price` to variants (in `ProductVariantResource`) and use it in `OrderInventoryService.php:118`, the Stripe line items, the wishlist and `CouponController.php:104`.
4. **WP7 (B14)**
   - In `Product.php`, change `product_card` to 600×800 (3:4). Keep `product_detail` square unless the frontend confirms a portrait frame.
   - Strip EXIF from stored originals.
   - Answer (d): conversions are `nonQueued()`.
   - B15: confirm the V2 image endpoints have passing tests.
5. **Clean-up**
   - `database/seeders/DemoCommerceSeeder.php:211,295` still writes `failed` for cancelled orders. Use `voided` where nothing was charged.
6. **Verification**
   - Run `./vendor/bin/pint` and check `--test` is clean for the changed files.
   - Run `./vendor/bin/phpstan analyse --level=5` and report the result honestly; the baseline was already red (A08-STA-001).
   - Run the full `php artisan test --compact` (expect only the 3 old failures).
   - Run `php artisan migrate:fresh --seed` against the **test** DB.
   - Run `php artisan route:list` (check the new signed route) and regenerate Swagger if the project does that (`php artisan l5-swagger:generate`).
7. **Docs**
   - Update `docs/FRONTEND_ORDER_CANCELLATION.md` (voided, payment_failed, server_time, configurable window, admin status response, bulk rules, manual tracking, label download) and `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md` (window back to 180, `ADMIN_ALERT_EMAIL`, `MAIL_*` brand values, the `conact-us` → `contact-us` typo, `APP_NAME`, `APP_VERSION`, the hourly stale-hold schedule, the Stripe event list incl. `payment_intent.canceled`, the label storage disk).
   - Write `docs/backend-response-2026-10-08.md` that answers **every** item (G1–G11, Address, B1–B19, Part 3) with **done / changed (how) / not doing (why)**, as the frontend asked.
   - Mark server, Stripe-dashboard and host tasks as owner/ops tasks that need someone with access: deploy, cron, queue worker, SPF/DKIM, bot challenge (B16), PHP upload limits, the two Stripe orders `ORD-WDWF8KLSUW` / `ORD-YNCIANMFTI`, addresses 31/33, the EasyPost webhook.
   - Answers that need no code:
     - G2: `/label` is final and `/ship` stays as an alias.
     - G10: "authorized" comes from the session's `capture_method` metadata set at creation, never from `session.payment_status`, and `capture()` re-reads the PaymentIntent.
     - Address: strict verification is already in the code; the live server needs a deploy.
     - B17 and B18 are deferred (P2).

## When you finish
Give the user a short report: what changed, the real test, Pint and PHPStan results, what is left for ops, and anything you could not verify.
