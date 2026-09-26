# Progress Tracker

> Reviewer round 2: D5 is `CHANGES-REQUESTED` (R6–R10 + L-PAY-011 = 202). Only open owner decision: L-PAY-010 (zero/tiny totals, D3).

Executor updates this after every step. Reviewer updates the **Review** column.

Statuses: `NOT-STARTED` -> `IN-PROGRESS` -> `READY-FOR-REVIEW` ->
`APPROVED` | `CHANGES-REQUESTED`

| Phase | Result file(s) | Status | Review | Open P0/P1 |
|---|---|---|---|---|
| 01 Baseline | `results/01-baseline.md` | APPROVED | `reviews/01-baseline-review.md` (round 2) | 0 |
| 02 Logic — D1 Auth | `results/02-D1-auth.md` | NOT-STARTED | — | — |
| 02 Logic — D2 Catalog | `results/02-D2-catalog.md` | NOT-STARTED | — | — |
| 02 Logic — D3 Pricing | `results/02-D3-pricing.md` | NOT-STARTED | — | — |
| 02 Logic — D4 Inventory | `results/02-D4-inventory.md` | NOT-STARTED | — | — |
| 02 Logic — D5 Payments | `results/02-D5-payments.md` | CHANGES-REQUESTED | `reviews/02-D5-payments-review.md` (round 2) | 1 P1 (L-PAY-012) |
| 02 Logic — D6 Shipping | `results/02-D6-shipping.md` | NOT-STARTED | — | — |
| 02 Logic — D7 Order lifecycle | `results/02-D7-order-lifecycle.md` | NOT-STARTED | — | — |
| 02 Logic — D8 Misc | `results/02-D8-misc.md` | NOT-STARTED | — | — |
| 03 Clean code | `results/03-clean-code.md` | NOT-STARTED | — | — |
| 04 E2E journeys | `results/04-e2e-journeys.md` | NOT-STARTED | — | — |
| 05 Security | `results/05-security.md` | NOT-STARTED | — | — |
| 06 Final verification | `results/06-final-verification.md` | NOT-STARTED | — | — |

## Open decisions (NEEDS-DECISION)

| Finding ID | Question | Options | Owner answer |
|---|---|---|---|
| L-PAY-005 | Stripe asynchronous-payment support | A) synchronous card-only Checkout; B) handle async success/failure events | **A — card-only** (2026-09-26) |
| L-PAY-006 | Stripe webhook rate-limit policy | A) dedicated provider limiter; B) exempt from generic API limiter | **A — dedicated provider limiter** (2026-09-26) |
| L-PAY-007 | Payment arrives for an order that is already cancelled | A) auto-refund + alert; B) alert + mark for admin refund | **B — no automatic refund; alert admin, admin refunds manually** (2026-09-26) |
| L-PAY-010 | Orders with total 0 or below Stripe minimum (≈$0.50) | Decide in D3 (skip Stripe for 0-total? minimum order value?) | pending — D3 |
| L-PAY-011 | Admin refund when Stripe returns `pending` | A) 202 Accepted "refund pending"; B) keep 502 with neutral message | **A — 202 Accepted "refund pending"; webhook finalizes** (2026-09-26) |
| BR-01 | Cancelling/refunding a paid or shipped order | — | **Only with admin approval. Approval cancels the order; the refund is a separate, manual admin action.** See `reviews/02-D5-payments-review.md` addendum. (2026-09-26) |

## Log

<!-- One line per session: date — what was done — last commit sha -->
2026-09-26 — Phase 01 baseline completed; awaiting reviewer — 835c9ae
2026-09-26 — D5 Payments reviewed and fixed; awaiting reviewer — 1433afe
