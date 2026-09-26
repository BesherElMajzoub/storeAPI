# Progress Tracker

> Batch mode active. B1 (D4 + D3) is handed over for review. B2 (D1 + D6 + D7) has started immediately; see `reviews/B1-instructions.md`.

Executor updates this after every step. Reviewer updates the Review column.

Statuses: `NOT-STARTED` -> `IN-PROGRESS` -> `READY-FOR-REVIEW` ->
`APPROVED` | `CHANGES-REQUESTED`

| Phase | Result file(s) | Status | Review | Open P0/P1 |
|---|---|---|---|---|
| 01 Baseline | `results/01-baseline.md` | APPROVED | `reviews/01-baseline-review.md` (round 2) | 0 |
| 02 Logic - D1 Auth | `results/02-D1-auth.md` | IN-PROGRESS | - | - |
| 02 Logic - D2 Catalog | `results/02-D2-catalog.md` | NOT-STARTED | - | - |
| 02 Logic - D3 Pricing | `results/02-D3-pricing.md` | READY-FOR-REVIEW | - | 0 |
| 02 Logic - D4 Inventory | `results/02-D4-inventory.md` | READY-FOR-REVIEW | - | 0 |
| 02 Logic - D5 Payments | `results/02-D5-payments.md` | APPROVED (with conditions C1-C3 -> first D4 commit) | `reviews/02-D5-payments-review.md` (round 4) | 0 |
| 02 Logic - D6 Shipping | `results/02-D6-shipping.md` | IN-PROGRESS | - | - |
| 02 Logic - D7 Order lifecycle | `results/02-D7-order-lifecycle.md` | IN-PROGRESS | - | - |
| 02 Logic - D8 Misc | `results/02-D8-misc.md` | NOT-STARTED | - | - |
| 03 Clean code | `results/03-clean-code.md` | NOT-STARTED | - | - |
| 04 E2E journeys | `results/04-e2e-journeys.md` | NOT-STARTED | - | - |
| 05 Security | `results/05-security.md` | NOT-STARTED | - | - |
| 06 Final verification | `results/06-final-verification.md` | NOT-STARTED | - | - |

## Open decisions (NEEDS-DECISION)

| Finding ID | Question | Options | Owner answer |
|---|---|---|---|
| L-PAY-005 | Stripe asynchronous-payment support | A) synchronous card-only Checkout; B) handle async success/failure events | **A - card-only** (2026-09-26) |
| L-PAY-006 | Stripe webhook rate-limit policy | A) dedicated provider limiter; B) exempt from generic API limiter | **A - dedicated provider limiter** (2026-09-26) |
| L-PAY-007 | Payment arrives for an order that is already cancelled | A) auto-refund + alert; B) alert + mark for admin refund | **B - no automatic refund; alert admin, admin refunds manually** (2026-09-26) |
| L-PAY-010 | Orders with total 0 or below Stripe minimum | Decide in D3 | **Total = 0 -> confirm without Stripe. 0 < total < Stripe minimum -> 422.** (2026-09-26) |
| L-PAY-011 | Admin refund when Stripe returns pending | A) 202 Accepted; B) 502 | **A - 202 Accepted; webhook finalizes** (2026-09-26) |
| D4-OBS-001 | Abandoned checkout stock release | A) 30-min session + scheduled safety net; B) Stripe default 24h | **A - 30-min session and 10-min safety job** (2026-09-26) |
| D4-OBS-002 | Restock when shipped/delivered order is refunded | A) restock only if not shipped; B) always | **A - shipped/delivered refunds do not restock** (2026-09-26) |
| D3-FS-01 | Free-shipping threshold basis | before/after discount | **After coupon discount** (2026-09-26) |
| BR-01 | Cancelling/refunding a paid or shipped order | - | **Admin approval; refund is a separate manual action.** (2026-09-26) |

## Log

2026-09-26 - Phase 01 baseline completed; approved - 835c9ae
2026-09-26 - D5 Payments reviewed and fixed; approved with conditions - 1433afe
2026-09-26 - B1 D4 Inventory + D3 Pricing implemented; 209 passed (1147 assertions) in three consecutive full-suite runs; B2 started - 78df983
