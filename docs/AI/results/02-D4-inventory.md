# 02 D4 Inventory — results

## Entry points

- `OrderController::store` calls `OrderInventoryService::quoteAndReserve` inside the order transaction.
- `OrderInventoryService::reserveExistingOrder` reserves persisted order lines when an order enters a reserved status.
- `OrderObserver` calls `release` on `cancelled` and `refunded`, and reserves on `processing`, `shipped`, or `delivered` when needed.
- Admin product stock adjustment calls `adjustStock`, which locks the product and optional variant.
- Stripe/session expiry, cancellation approval, failed checkout rollback, and admin refunds reach the observer/release path.

## Rules & state table

| State/event | Product stock | Variant stock | Order marker |
|---|---:|---:|---|
| Pending checkout created | decrement under product/variant row locks | decrement when selected | `stock_reserved_at` |
| Processing/shipped/delivered | already reserved; observer reserves only if marker absent | same | marker remains |
| Cancelled/refunded/failed checkout | increment once under order/product/variant locks | increment once when selected | `stock_released_at` |
| Admin adjustment | signed delta under product-first locks | same delta when variant supplied | audit log at controller |

Product stock is the aggregate availability gate and variant stock is additionally checked/decremented for selected variants. Both rows are locked in a deterministic product-first order.

## Findings

### D4-OBS-001 — Abandoned checkout release policy

- **Severity:** P2
- **Status:** NEEDS-DECISION
- **Scenario:** a customer reserves stock and never completes Checkout; local release occurs when Stripe sends `checkout.session.expired` or checkout creation rolls back, but no local scheduler is defined if provider delivery is delayed or lost.
- **Decision needed:** rely on Stripe expiry delivery, or add a scheduled reconciliation window and policy for abandoned `pending_payment` orders. No behavior is guessed in this phase.

### D4-OBS-002 — Restock after refund of shipped goods

- **Severity:** P1
- **Status:** NEEDS-DECISION (carried from D5 review)
- **Scenario:** `OrderObserver` releases product and variant stock for every `refunded` order, including shipped/delivered orders whose goods may still be with the customer.
- **Decision needed:** restock only before shipment, or require confirmed return before restocking.

## Verified OK

- Concurrent last-unit reservation is covered by `ConcurrentInventoryTest` and uses row locks.
- Release is idempotent through the `stock_released_at` guard and is tested for cancellation approval and refunds.
- Negative admin deltas are rejected by locked stock arithmetic; cross-product variants are rejected by the service.
- Failed checkout rollback releases inventory and removes the failed order side effects.

## Tests added/strengthened

- D5 condition tests in `StripeWebhookSecurityTest` are the first D4 commit (`1ad1540`), including stock unchanged after late payment and full refund replay.
- Existing inventory coverage: `ConcurrentInventoryTest`, `OrderStockTest`, `CancellationRequestInventoryTest`, `AdminProductContractTest`.

## Current verification

```text
Focused D5/D4 boundary run: 12 passed (51 assertions)
Pint: passed
PHPStan level 5: [OK] No errors
```

This domain is `IN-PROGRESS`; no D4 approval is requested yet.
