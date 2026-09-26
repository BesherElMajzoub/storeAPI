# Phase 02 — Business Logic Review

**Output:** one file per domain: `results/02-D<n>-<name>.md`
**Mode:** find → failing test → fix → passing test.

Review **one domain at a time**, in the order below (highest risk first is D5,
D4, D3 — do those first). After each domain, set it `READY-FOR-REVIEW` in
`PROGRESS.md` and stop.

## Method (for every domain)

1. List every entry point: routes, controllers, jobs, commands, webhooks,
   observers, listeners, events that touch the domain.
2. Trace each entry point to the database. Read the code line by line — do not
   skim.
3. For each rule in the domain checklist below, decide: **correct**,
   **bug** (write finding), or **unclear** (NEEDS-DECISION).
4. Check the generic questions on every write path:
   - Is it inside a DB transaction where it must be atomic?
   - What happens if it runs **twice** (double click, retry, duplicate webhook)?
   - What happens if two users run it **at the same time**?
   - What happens if an external call (Stripe, EasyPost, mail) fails half-way?
   - Is every input validated server-side (never trust client prices,
     totals, stock, user ids)?
   - Are money values integers in cents (or decimal) — never float math?
   - Does a user only ever see/modify **their own** records?
5. Every existing test for the domain: does it actually assert the rule, or
   only a 200 status? Weak tests → strengthen them.

---

## D1 — Auth & accounts
Files: `Auth/AuthController`, `OtpService`, `GoogleAuthService`, `OtpCode`,
`SocialAccount`, `User`, `Role`, `Permission`, `EnsureAdminRole`,
mails `OtpCodeMail`, `ResetPasswordMail`. Contract: `docs/AUTHENTICATION_CONTRACT.md`.
- Register/login/logout/me; SPA cookie auth and token auth both behave per contract.
- OTP: expiry, single use, attempt limit, brute-force throttle, code not logged.
- Password reset: token expiry, single use, no user enumeration in responses.
- Google login: account linking cannot hijack an existing email account.
- Admin gate: `admin-access` cannot be reached by a normal user; role changes
  take effect immediately (tokens/sessions).
- Disabled/deleted users cannot log in or keep using existing tokens.

## D2 — Catalog
Files: `ProductService`, `CategoryService`, `SkuGeneratorService`,
`ProductImportService`, `ReviewService`, `ReviewObserver`, product/category/
media/sku/review controllers (public + admin). Doc: `docs/PRODUCT_IMPORT_CSV.md`.
- Inactive/draft products and categories never appear publicly (list, detail,
  search, category pages, related products).
- Variants: price/stock per variant, default variant, deleting a variant that
  is in an order.
- SKU uniqueness under concurrency.
- CSV import: validation per row, partial failure behaviour, idempotent re-import.
- Reviews: only buyers (if that's the rule), one per product per user, rating
  range, average recalculation on create/update/delete/moderation.
- Category reorder and tree (no cycles, no orphan children).

## D3 — Cart → order pricing
Files: `OrderController` (store), `CouponService`, `FreeShippingService`,
`CouponController`, `Coupon`, `CouponUsage`, `Setting`.
- Order total is computed **server-side** from DB prices; client-sent prices
  are ignored.
- Subtotal, discount, shipping, tax (if any), total: formula written down in
  the result file and asserted by a test with real numbers.
- Rounding: consistent, in cents; no float drift (test with prices like 19.99 × 3).
- Coupons: expiry, start date, min order, max uses global, max uses per user,
  inactive, percentage vs fixed, fixed discount > subtotal, applies to which
  items, usage recorded only on successful payment (or per documented rule),
  usage released on cancellation/failed payment.
- Free shipping threshold: before or after discount? Document and test.
- Coupon usage race: two orders using the last available use at the same time.

## D4 — Inventory
Files: `OrderInventoryService`, `InsufficientStockException`,
`AdjustProductStockRequest`, cancellation code. Tests to extend:
`ConcurrentInventoryTest`, `OrderStockTest`, `CancellationRequestInventoryTest`,
`tests/Support/reserve-order-worker.php`.
- When is stock reserved / decremented / released? Draw the state table in the
  result file.
- Stock never goes negative, even under concurrency (row locks / atomic update).
- Abandoned checkout (payment never completed): is reserved stock released?
  By what? When?
- Cancellation / refund / failed payment restores stock exactly once.
- Admin manual adjustment is logged and cannot race an order.
- Variant stock vs product stock: which one is the source of truth?

## D5 — Payments (Stripe) — highest risk
Files: `StripeCheckoutService`, `Api/StripeWebhookController`, `Payment`,
`WalletTransaction`, `OrderPaidMail`. Contract: `docs/PAYMENT_CHECKOUT_CONTRACT.md`.
Tests: `StripeCheckoutTest`, `StripeWebhookSecurityTest`.
- Amount sent to Stripe == order total in DB, same currency, in minor units.
- Webhook signature verified; unsigned/invalid → 400, nothing changes.
- Webhooks idempotent: same event twice → one state change, one mail, one
  stock change.
- Out-of-order events (e.g. `payment_failed` after `completed`) cannot move an
  order backwards.
- The paid amount in the event is checked against the order total.
- Order can't be marked paid from the client side / success URL alone.
- Session expiry → order state and stock handled.
- Refunds (if supported): partial vs full, stock, coupon usage, statuses.
- Wallet transactions (if used): balance can't go negative, double spend.

## D6 — Shipping
Files: `ShippingQuoteService`, `EasyPostService`, `FakeEasyPostService`,
`ShipmentTrackingService`, `EasyPostWebhookController`, `TrackShipments`
command, `PublicOrderTrackingController`, `ShippingRateQuote`, `GeoapifyService`,
`GooglePlacesService`, `GeoLocationService`, `AddressController`.
Contract: `docs/SHIPPING_CONTRACT.md`.
- Quote: expiry (15 min), single use, fingerprint of address/cart/parcel
  enforced at order time (see `backend-open-items.md` A2).
- Provider down → 503 per contract; invalid address → 422.
- EasyPost webhook: authenticity check, idempotent, can't regress status.
- Public tracking: requires order number + second factor (email?), no data
  leak, rate-limited.
- Tracking command: safe to rerun, handles provider errors, doesn't hammer API.

## D7 — Order lifecycle & admin operations
Files: `OrderController` (public + admin), `CancellationRequestController`,
`OrderObserver`, `BulkUpdateOrderStatusRequest`, `UpdateOrderStatusRequest`,
`OrderCancellationRequest`, `AuditAdminActions`, `AuditLog`, `SendAdminAlert`,
`TelegramNotifier`, mails `OrderShippedMail`, `CancellationRequestDecidedMail`.
- Write down the allowed status transition table. Every transition not in the
  table must be rejected (single and bulk update). Test each forbidden one.
- Side effects per transition (stock, coupon, mail, telegram, audit) happen
  exactly once and are queued, not blocking the request.
- Cancellation request: who can request, in which statuses, duplicate
  requests, approve/reject effects.
- Bulk update: partial failure behaviour is defined and reported.
- Audit log records actor, action, before/after for every admin write.

## D8 — Everything else
Wishlist (+ events/analytics), `AnalyticsEventController`, `LogEventJob`,
`LogPageViewJob`, `TrackVisitorSession`, `Visitor*`, `PageView`,
`ContactMessageController`, `InspiredLeadController`, `SettingController`,
`DashboardController`, `AdminAnalyticsController`, `GeoController`,
`MediaController`, `UserController` (admin), `Campaign`, `Post`,
`MigrateImagesToSpatie` command.
- Public write endpoints (contact, leads, analytics) are validated and throttled.
- Dashboard/analytics numbers: pick 3 metrics, seed known data, assert exact values.
- Admin user management can't demote/delete the last admin or yourself.
- Settings: typed, validated, cached correctly (cache invalidated on update).
- Dead models/code (`Campaign`, `Post`, …): report if unused (removal in phase 03).

## Result file structure (per domain)

```markdown
# 02 D<n> <Domain> — results
## Entry points (list)
## Rules & state tables (what the code actually does)
## Findings (templates/finding.md format)
## Verified OK (rule — one-line reason)
## Tests added/strengthened (file::method list)
## Test suite output (full run at end of domain)
```
