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

This domain is `READY-FOR-REVIEW` pending the B1 full-suite gate.
