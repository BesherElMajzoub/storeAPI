# 02 D4 Inventory - results

## Entry points

- `OrderController::store` calls `OrderInventoryService::quoteAndReserve` inside the order transaction.
- `OrderInventoryService::reserveExistingOrder` reserves persisted order lines when an order enters a reserved status.
- `OrderObserver` calls `release` on `cancelled` and `refunded`, and reserves on `processing`, `shipped`, or `delivered` when needed.
- Admin product stock adjustment calls `adjustStock`, which locks the product and optional variant.
- Stripe/session expiry, cancellation approval, failed checkout rollback, and admin refunds reach the observer/release path.

## Rules and state table

| State/event | Product stock | Variant stock | Order marker |
|---|---:|---:|---|
| Pending checkout created | decrement under product/variant row locks | decrement when selected | `stock_reserved_at` |
| Processing/shipped/delivered | already reserved; observer reserves only if marker absent | same | marker remains |
| Cancelled/refunded/failed checkout | increment once under order/product/variant locks | increment once when selected | `stock_released_at` |
| Admin adjustment | signed delta under product-first locks | same delta when variant supplied | audit log at controller |

Product stock is the aggregate availability gate and variant stock is additionally checked/decremented for selected variants. Both rows are locked in a deterministic product-first order.

## Findings

### D4-OBS-001 - Abandoned checkout release policy

- **Severity:** P2
- **Status:** FIXED
- **Decision:** Stripe Checkout sessions expire after 30 minutes (configurable and clamped to 30-1440 minutes). `orders:expire-abandoned-checkouts` runs every 10 minutes, expires open sessions, cancels the matching local order, and lets completed sessions remain for the webhook. Provider errors are logged and retried; conditional locking makes repeated runs safe.
- **Evidence:** `ExpireAbandonedCheckoutsTest` covers open, complete, provider-error, and repeated-run paths. Checkout session construction captures the configured expiry.

### D4-OBS-002 - Restock after refund of shipped goods

- **Severity:** P1
- **Status:** FIXED
- **Decision:** automatic restock occurs only when `shipped_at` is null. Shipped/delivered refunds leave stock unchanged for manual administrator adjustment after goods return.
- **Evidence:** `OrderStockTest::test_refunding_a_shipped_order_does_not_restock_inventory`; late-payment and full-refund webhook tests prove release remains idempotent.

## Verified OK

- Concurrent last-unit reservation is covered by `ConcurrentInventoryTest` and uses row locks.
- Release is idempotent through the `stock_released_at` guard and is tested for cancellation approval and refunds.
- Negative admin deltas are rejected by locked stock arithmetic; cross-product variants are rejected by the service.
- Failed checkout rollback releases inventory and removes the failed order side effects.

## Tests added/strengthened

- D5 condition tests in `StripeWebhookSecurityTest` are the first D4 commit (`1ad1540`), including stock unchanged after late payment and full refund replay.
- `ExpireAbandonedCheckoutsTest` (commit `ef77a33`) covers the scheduled safety net.
- `OrderStockTest` (commit `3b0b6cb`) covers no restock after shipment.
- Existing inventory coverage: `ConcurrentInventoryTest`, `OrderStockTest`, `CancellationRequestInventoryTest`, `AdminProductContractTest`.

## Current verification

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

No frontend-visible change for inventory. The scheduled expiry is an ops concern; production needs Laravel scheduler cron. D3's free-order response adds `checkout_url: null` and `payment_required: false` (documented in the pricing report).

This domain is `READY-FOR-REVIEW`; the owner decisions in `reviews/B1-instructions.md` are implemented and no D4 decisions remain open.
