# 02 D3 Pricing - results

## Entry points and rules

- `OrderController::store` computes subtotal, coupon discount, free-shipping eligibility, shipping, tax, and total inside one transaction.
- `CouponService` locks and validates coupons, calculates capped discounts, and records one usage row per order.
- Free shipping is evaluated on `max(0, subtotal - discount)`; the exact threshold remains inclusive.
- Stripe is skipped for total `0.00`; the order is immediately processing/paid with a `free` completed payment. Positive totals below the configured Stripe minimum (`0.50` by default) return `422` before order/stock/coupon side effects.

## Findings

### L-PAY-010 - Zero and below-minimum totals

- **Status:** FIXED
- **Decision:** total `0` is a free order; `0 < total < 0.50` is rejected with error code `minimum_charge`.
- **Evidence:** `PricingBatchTest` covers free-order response/payment/mail/admin alert, minimum rejection with unchanged stock and no order, single-use 100% coupon replay, and 409 admin refund for a free order.

### D3-FS-01 - Free-shipping threshold basis

- **Status:** FIXED
- **Decision:** threshold uses discounted subtotal (`subtotal - discount`).
- **Evidence:** existing below/exact threshold tests plus `PricingBatchTest::test_free_shipping_threshold_is_based_on_discounted_subtotal`.

## Verified invariants

- Coupon validation and usage increment remain inside the order transaction, so rejected minimum totals do not consume coupons or stock.
- No Stripe provider is called for free orders; external mail/alert work remains queued.
- Currency/rounding and public order response contracts are unchanged for paid orders.

## Verification

```text
ExpireAbandonedCheckoutsTest + PricingBatchTest: 8 passed (52 assertions)
Pint: passed
```

## Full batch gate evidence

```text
Run 1: Tests: 209 passed (1147 assertions), Duration: 55.48s
Run 2: Tests: 209 passed (1147 assertions), Duration: 55.26s
Run 3: Tests: 209 passed (1147 assertions), Duration: 47.38s
Pint: {"tool":"pint","result":"passed"}
PHPStan level 5: [OK] No errors (memory-limit=512M)
Composer validate: ./composer.json is valid
Composer audit: No security vulnerability advisories found.
```

## Frontend impact

Paid checkout contracts remain unchanged. A zero-total order returns `checkout_url: null`, `payment_required: false`, and a null session id so the storefront skips the Stripe redirect. Minimum-charge rejection is HTTP 422 with error code `minimum_charge`.

This domain is `READY-FOR-REVIEW` pending the B1 full-suite gate.
