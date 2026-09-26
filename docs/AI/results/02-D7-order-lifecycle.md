# 02 D7 Order lifecycle - results

Status: IN-PROGRESS (B2).

## Entry points

- `Api/V1/OrderController`: `store`, `cancel`, `requestCancellation`, `tracking`.
- `Api/V1/Admin/OrderController`: `index`, `show`, `updateStatus`, `bulkUpdateStatus`, `refund`.
- `Api/V1/Admin/CancellationRequestController` (accept/reject).
- `Observers/OrderObserver` (stock + coupon/quote side effects on status change).

## Findings

### D7-F1 — P1: the customer-facing "cancel my pending order" endpoint was unreachable for every real order

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/V1/OrderController.php:622` (`cancel`)
- **Problem:** `cancel()` only allowed cancelling an order whose `status === 'pending'`. But `store()` (the only code path that creates an order today) always creates it with `status = 'pending_payment'` (`OrderController.php:433`) — `'pending'` is a leftover value from before Stripe was added to the status enum (see migration `2026_05_19_000002_add_stripe_fields_to_orders_table.php`) and nothing sets it anymore. Every direct-cancel request on a real order therefore fell into the `!== 'pending'` branch and returned `400 Only pending orders can be cancelled directly.` — the endpoint has been completely dead since Stripe checkout was introduced. The only existing test against this route checked a 404-for-another-user's-order case, never the success path, so this shipped unnoticed.
- **Scenario:** A customer places an order, immediately regrets it, and calls `POST /api/v1/orders/{id}/cancel` within the advertised 3-hour window. They always get a 400 and are forced into the "submit a cancellation request, wait for admin review" flow instead, even though the contract/description promises immediate self-service cancellation for a still-unpaid order.
- **Test:** `tests/Feature/OrderCancelTest.php::test_customer_can_directly_cancel_a_real_pending_payment_order_within_the_window` — creates a real order through `POST /api/v1/orders` (so it has the actual `pending_payment` status) and fails on pre-fix code with `Expected response status code [200] but received 400`. `::test_customer_cannot_directly_cancel_after_the_three_hour_window` and `::test_customer_cannot_directly_cancel_a_processing_order` are regression guards for the window and status checks, which were already correct.
- **Fix:** Check `status === 'pending_payment' && payment_status === 'unpaid'` instead of the dead `'pending'` value, and set `payment_status = 'failed'` alongside `status = 'cancelled'` (matching what the abandoned-checkout job already does), so downstream logic that keys off `payment_status` — including the D7-F2 fix below — treats a direct customer cancel the same as an abandoned/expired one. Commit: (this batch).
- **Evidence:**
  ```
  # pre-fix
  FAILED Tests\Feature\OrderCancelTest > customer can directly cancel a real pending payment order within the wind…
  Expected response status code [200] but received 400.
  Tests: 1 failed, 2 passed (9 assertions)

  # post-fix
  PASS  Tests\Feature\OrderCancelTest
  ✓ customer can directly cancel a real pending payment order within the window
  ✓ customer cannot directly cancel after the three hour window
  ✓ cancelling an unpaid order releases its coupon usage and shipping quote
  ✓ customer cannot directly cancel a processing order
  Tests: 4 passed (16 assertions)
  ```

### D7-F2 — P1: cancelling an order (direct or abandoned-checkout) never released its coupon usage or shipping quote

- **Severity:** P1
- **Status:** FIXED (for the never-paid case; the paid-then-refunded case is a separate NEEDS-DECISION, see below)
- **Location:** `app/Observers/OrderObserver.php`, `app/Http/Controllers/Api/V1/OrderController.php` (`cancel`), `app/Console/Commands/ExpireAbandonedCheckouts.php`
- **Problem:** Coupon usage (`CouponUsage` row + `Coupon::used_count`) and the consumed `ShippingRateQuote` were only released in `OrderController::rollbackFailedCheckout`, a private method called **exclusively** when Stripe session creation itself fails at the moment of order creation. Every other way an unpaid order ends up cancelled — the customer's own direct cancel (D7-F1, previously unreachable, now fixed), and `orders:expire-abandoned-checkouts` (`ExpireAbandonedCheckouts.php`) expiring a stale Stripe session — left the coupon usage row and `used_count` increment in place forever, and the shipping quote marked `consumed_at` forever, even though the customer received zero value from either.
- **Scenario:** A customer applies a single-use-per-user or nearly-exhausted global coupon, starts checkout, then either cancels immediately or simply abandons the Stripe payment page. The coupon is now permanently burned — the customer (or the next customer, for a global usage cap) can never use it, despite no order ever completing.
- **Test:** `tests/Feature/OrderCancelTest.php::test_cancelling_an_unpaid_order_releases_its_coupon_usage_and_shipping_quote` — fails on pre-fix `OrderObserver` (`Failed asserting that 1 is identical to 0`, i.e. `used_count` never decremented and the usage row survives), passes after.
- **Fix:** Moved the release logic out of the one-off `rollbackFailedCheckout` path and into `OrderObserver::updated()`, so it runs for **every** transition to `cancelled` where `payment_status !== 'paid'` (the order never actually completed payment) — covering direct customer cancel, admin cancel via `updateStatus`/`bulkUpdateStatus`, and the abandoned-checkout job uniformly, in one place. `rollbackFailedCheckout` still does its own release too (redundant now but harmless — the observer's query is a no-op on rows already deleted) and was left untouched to avoid disturbing its existing, already-tested behavior.
- **Evidence:**
  ```
  # pre-fix
  FAILED Tests\Feature\OrderCancelTest > cancelling an unpaid order releases its coupon usage and shipping quote
  Failed asserting that 1 is identical to 0.
  Tests: 1 failed (2 assertions)

  # post-fix
  PASS  Tests\Feature\OrderCancelTest
  ✓ cancelling an unpaid order releases its coupon usage and shipping quote
  Tests: 4 passed (16 assertions)
  ```
- **Decision needed (NEEDS-DECISION) — D7-OBS-001:** should a coupon/shipping-quote also be released when a **paid** order is later `cancelled`/`refunded`? The fix above deliberately only covers `payment_status !== 'paid'` (never charged) to avoid guessing on refund policy, consistent with `BR-01` (paid/shipped cancellation is an admin-approved, manually-refunded action). **Options:** A) never release for a paid order — the customer received the discount/shipping value when the sale happened, full stop (recommended: this matches `D4-OBS-002`'s "shipped/delivered refunds do not restock" precedent of not undoing completed value); B) release the coupon (but not necessarily the shipping quote, which is provider-consumed regardless) whenever a paid order is fully refunded, symmetric with a full monetary refund. Recommended: **A**. Not blocking — no code change needed unless the owner picks B.

## Verified OK

- **`requestCancellation`** blocks `shipped`/`delivered`/`cancelled` orders and a second `pending` request for the same order with a `422`, matching the domain checklist's "duplicate requests" and "which statuses" rules. Covered by `tests/Feature/TelegramNotificationTest.php`.
- **Bulk update status** is all-or-nothing (one invalid transition aborts the whole batch, no partial writes) and marks `shipped_at`/refund-does-not-restock correctly for both the single and bulk admin endpoints — `tests/Feature/AdminOrderBulkStatusTest.php` (pre-existing, still green, unaffected by this batch's changes).
- **Generic admin status route cannot set `payment_status` directly to `paid`/`refunded`** — those are Stripe/webhook/refund-endpoint-only transitions (`test_generic_admin_status_route_cannot_mark_an_order_paid_or_refunded`, pre-existing).
- **Stock release on cancellation is unaffected by this batch's `OrderObserver` change**: the new coupon/quote branch runs *before* the existing `shipped_at`/previous-status guard and `return`s only out of its own `if`, so the inventory-release control flow is byte-for-byte the same as before for every status; `tests/Feature/OrderStockTest.php`, `CancellationRequestInventoryTest.php`, `ConcurrentInventoryTest.php`, and `AdminOrderBulkStatusTest.php` all still pass unmodified (see evidence below).

## Tests added/strengthened

- `tests/Feature/OrderCancelTest.php` (new file):
  - `test_customer_can_directly_cancel_a_real_pending_payment_order_within_the_window`
  - `test_customer_cannot_directly_cancel_after_the_three_hour_window`
  - `test_cancelling_an_unpaid_order_releases_its_coupon_usage_and_shipping_quote`
  - `test_customer_cannot_directly_cancel_a_processing_order`

## Regression check (inventory/coupon suites, unmodified by this batch)

```
PASS  Tests\Feature\OrderStockTest (4 tests)
PASS  Tests\Feature\CancellationRequestInventoryTest (1 test)
PASS  Tests\Feature\ConcurrentInventoryTest (1 test)
PASS  Tests\Feature\AdminCouponTest (9 tests)
PASS  Tests\Feature\AdminOrderBulkStatusTest (7 tests)
Tests: 22 passed (111 assertions)
```

## Frontend impact

- `POST /api/v1/orders/{id}/cancel` now actually succeeds for an unpaid order within 3 hours of creation instead of always returning 400 — this is a bug fix restoring documented behavior, not a new contract. If the frontend built a workaround (e.g. always routing to the cancellation-request flow because direct cancel "never worked"), it can now use the direct-cancel button/flow as originally designed.

## Test suite output

Full suite, run at the end of B2 (D1+D6+D7), three consecutive runs:
```
Tests:    224 passed (1205 assertions)   Duration: 46.61s
Tests:    224 passed (1205 assertions)   Duration: 58.78s
Tests:    224 passed (1205 assertions)   Duration: 46.97s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`
