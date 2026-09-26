# 02 D5 Payments — results

## Entry points

- `POST /api/v1/orders`: creates a pending order, reserves stock, and creates the initial Checkout Session.
- `POST /api/v1/orders/{id}/checkout-session`: owned-user resume flow; serializes session reuse/replacement.
- `POST /api/v1/webhooks/stripe`: verifies Stripe signatures and handles completion, expiry, and refunds.
- `POST /api/v1/admin/orders/{order}/refund`: admin-only full-refund initiation.
- `OrderObserver`, `OrderPaidMail`, and `SendAdminAlert`: indirect payment side effects.
- `Payment` is now written by verified payment confirmation. `WalletTransaction` has no non-seeder payment path.

## Rules & state tables (what the code actually does)

| Trigger | Preconditions | Database transition | Side effects |
|---|---|---|---|
| Signed `checkout.session.completed` | Current session, minor-unit amount, and currency match; locked order remains `pending_payment` / `unpaid` | `processing` / `paid`; PaymentIntent and `payments` record written | One queued paid mail and one queued alert |
| Completed-event replay | Locked order already `paid` | None | None |
| Signed `checkout.session.expired` | Session is current and order is undecided | `cancelled` / `failed` | Observer releases stock once |
| `charge.refunded` partial | PaymentIntent matches; provider cumulative amount is higher | Updates `refunded_amount` | No restock or terminal state |
| `charge.refunded` full | Cumulative amount reaches total | `refunded` / `refunded` | Observer releases stock once |
| Admin full refund | Paid order, PaymentIntent, Stripe status `succeeded` | `refunded` / `refunded` | Observer releases stock once |
| Admin refund pending/failed | Stripe status is not `succeeded`, or request throws | None; 502 | No local side effects |

Checkout line items use the configured currency and minor-unit amounts. Product items plus shipping, less the one-time Stripe discount, equal the persisted order total; the webhook independently verifies Stripe's amount and currency.

## Findings

### L-PAY-001 — Out-of-order partial refund webhook reduced the recorded refund

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/StripeWebhookController.php:handleChargeRefunded`
- **Problem:** A delayed event with a smaller cumulative `amount_refunded` overwrote a newer amount.
- **Scenario:** Stripe reports 50.00 refunded, then an older event reports 25.00; the order showed 25.00.
- **Test:** `StripeWebhookSecurityTest::test_out_of_order_partial_refund_webhooks_do_not_reduce_the_recorded_refund_amount` — failed with `25.00`, then passed.
- **Fix:** Lock the order and ignore a refund amount that is not greater than the stored cumulative amount. Commit: `185f0d4`.
- **Evidence:** `1 failed (3 assertions)` before; `1 passed (3 assertions)` after.

### L-PAY-002 — Verified Stripe payments were absent from the payments ledger

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/StripeWebhookController.php:handleSessionCompleted`
- **Problem:** Completed webhooks updated `orders` but left the existing `payments` table empty.
- **Scenario:** A valid 100.00 Stripe payment marked the order paid but produced no payment transaction record.
- **Test:** `StripeWebhookSecurityTest::test_a_real_signed_checkout_webhook_marks_the_matching_amount_and_currency_paid` — initially found an empty `payments` table, then passed.
- **Fix:** Upsert a completed Stripe payment with PaymentIntent and persisted amount under the order lock. Commit: `0754662`.
- **Evidence:** `1 failed (3 assertions)` before; affected regression tests pass afterward.

### L-PAY-003 — Pending Stripe refunds were treated as completed refunds

- **Severity:** P0
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/V1/Admin/OrderController.php:refund`
- **Problem:** Any Stripe refund response, including `pending`, immediately marked the order refunded and released inventory.
- **Scenario:** Stripe returns `pending`; API returned 200 and order became refunded before funds were confirmed.
- **Test:** `StripeCheckoutTest::test_admin_does_not_mark_an_order_refunded_until_stripe_confirms_the_refund` — expected 502 but received 200 before the fix; passes after.
- **Fix:** Transition locally only when Stripe returns `succeeded`; other statuses return 502 unchanged. Commit: `a5e8142`.
- **Evidence:** `1 failed (1 assertion)` before; `2 passed (6 assertions)` after.

### L-PAY-004 — A replaced Checkout Session could still mark an order paid

- **Severity:** P0
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/StripeWebhookController.php:handleSessionCompleted`
- **Problem:** Session, amount, and currency were checked before the database lock but not after it.
- **Scenario:** Webhook reads `cs_original`; resume replaces it with `cs_replacement`; stale webhook marked the order paid.
- **Test:** `StripeWebhookSecurityTest::test_completed_webhook_is_rejected_when_its_session_is_replaced_during_processing` — expected 422 but received 200 before the fix; passes after.
- **Fix:** Revalidate under the row lock and use a conditional pending/unpaid transition. Commits: `720774e`, `1433afe`.
- **Evidence:** `1 failed (1 assertion)` before; `3 passed (11 assertions)` after.

### L-PAY-005 — Stripe asynchronous-payment policy is undefined

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `app/Services/StripeCheckoutService.php:52`
- **Problem:** Checkout does not restrict payment methods, while the webhook flow only defines immediate completion/expiry/refund handling.
- **Scenario:** If account configuration enables an asynchronous payment method, delayed success/failure behavior is undefined.
- **Decision needed:** A) synchronous card-only Checkout; B) support Stripe asynchronous success/failure events and define pending behavior.

### L-PAY-006 — Provider webhook throttle policy needs an explicit decision

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `routes/api.php:46`
- **Problem:** Stripe webhooks use the generic API throttle; changing middleware is a frozen contract change.
- **Scenario:** Provider retries exceed the generic limit and timely reconciliation is delayed.
- **Decision needed:** A) dedicated provider limiter; B) exemption from generic API limiter plus signature verification and infrastructure limits.

## Verified OK

- Checkout creation/resume require Sanctum ownership; another user's order returns 404.
- Open sessions are reused, expired sessions replaced, and completed sessions return 409 while confirmation is pending.
- Invalid signatures return 400; signed tests exercise the real signature verifier.
- Client redirects cannot mark an order paid; only the verified webhook may do so. Admin status updates reject direct paid/refunded assignment.
- Stale expiry events are ignored; expiry after paid/refunded/cancelled is ignored.
- Full and partial refund behavior is exact-value tested; partial refunds do not restock or close the order.
- Currency is normalized in config and checked against the provider event.

## Tests added/strengthened

- `StripeWebhookSecurityTest::test_out_of_order_partial_refund_webhooks_do_not_reduce_the_recorded_refund_amount`
- `StripeWebhookSecurityTest::test_a_real_signed_checkout_webhook_marks_the_matching_amount_and_currency_paid` (payment-ledger assertion)
- `StripeCheckoutTest::test_admin_does_not_mark_an_order_refunded_until_stripe_confirms_the_refund`
- `StripeWebhookSecurityTest::test_completed_webhook_is_rejected_when_its_session_is_replaced_during_processing`

## Test suite output (full run at end of domain)

```text
Stripe-focused run
Tests:    26 passed (81 assertions)
Duration: 9.88s

Full suite
Tests:    187 passed (1047 assertions)
Duration: 50.88s

{"tool":"pint","result":"passed"}
[OK] No errors
```

## Round 2 response

R1--R4 are fixed: `1268ddf` applies card-only Checkout, a dedicated 1,000/minute provider limiter, and a stable Stripe refund idempotency key. `e952972` expires Checkout Sessions before pending-payment cancellations, reconciles a verified late payment as `requires_refund` with an urgent admin alert, locks the expiry handler, records refund ledger states, and handles `refund.failed` without closing the order. Focused verification: **34 passed (109 assertions)**, Pint passed, PHPStan level 5 passed.

L-PAY-010 remains a D3 decision: zero and one-cent totals are sent to Stripe and rejected, but the existing provider-failure rollback releases and soft-deletes the new order so it does not stick. L-PAY-011 remains an owner decision: pending refunds now use the neutral message `Stripe refund is pending confirmation.` while retaining the frozen 502 contract; proposed replacement is 202 Accepted with webhook reconciliation. Stripe sources: [idempotency](https://docs.stripe.com/api/idempotent_requests), [refund creation](https://docs.stripe.com/api/refunds/create), and [event types](https://docs.stripe.com/api/events/types), which include `refund.failed` and `refund.updated`.
