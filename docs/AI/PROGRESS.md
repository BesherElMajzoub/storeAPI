# Progress Tracker

> B4/B5 review fixes applied — see `reviews/B4-B5-review.md`. Phase 03 is approved; Phase 04 journeys are real HTTP chains and Phase 06 Newman uses the actual `postman_collection.json`. This is the last phase — nothing starts after it.

Executor updates this after every step. Reviewer updates the Review column.

Statuses: `NOT-STARTED` -> `IN-PROGRESS` -> `READY-FOR-REVIEW` ->
`APPROVED` | `CHANGES-REQUESTED`

| Phase | Result file(s) | Status | Review | Open P0/P1 |
|---|---|---|---|---|
| 01 Baseline | `results/01-baseline.md` | APPROVED | `reviews/01-baseline-review.md` (round 2) | 0 |
| 02 Logic - D1 Auth | `results/02-D1-auth.md` | APPROVED | `reviews/B2-review.md` | 0 |
| 02 Logic - D2 Catalog | `results/02-D2-catalog.md` | APPROVED | `reviews/B3full-B4-review.md` | 0 |
| 02 Logic - D3 Pricing | `results/02-D3-pricing.md` | APPROVED | `reviews/B1-review.md` | 0 |
| 02 Logic - D4 Inventory | `results/02-D4-inventory.md` | APPROVED | `reviews/B1-review.md` | 0 |
| 02 Logic - D5 Payments | `results/02-D5-payments.md` | APPROVED (C1-C3 all done) | `reviews/02-D5-payments-review.md` (round 4) | 0 |
| 02 Logic - D6 Shipping | `results/02-D6-shipping.md` | APPROVED | `reviews/B2-review.md` | 0 |
| 02 Logic - D7 Order lifecycle | `results/02-D7-order-lifecycle.md` | APPROVED | `reviews/B2-review.md` | 0 |
| 02 Logic - D8 Misc | `results/02-D8-misc.md` | APPROVED | `reviews/B3full-B4-review.md` | 0 |
| 03 Clean code | `results/03-clean-code.md` | APPROVED (restore carried-forward list) | `reviews/B4-B5-review.md` | 0 |
| 04 E2E journeys | `results/04-e2e-journeys.md` | READY-FOR-REVIEW | `reviews/B4-B5-review.md` | 0 |
| 05 Security | `results/05-security.md` | APPROVED | `reviews/B3full-B4-review.md` | 0 |
| 06 Final verification | `results/06-final-verification.md` | READY-FOR-REVIEW (final) | `reviews/B4-B5-review.md` | 0 |

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
| D7-OBS-001 | Release coupon/quote when a paid order is later cancelled/refunded | A) never (matches D4-OBS-002); B) release on full refund | **A - never release for a paid order** (2026-09-27) |
| BR-01 | Cancelling/refunding a paid or shipped order | - | **Admin approval; refund is a separate manual action.** (2026-09-26) |

## Log

2026-09-26 - Phase 01 baseline completed; approved - 835c9ae
2026-09-26 - D5 Payments reviewed and fixed; approved with conditions - 1433afe
2026-09-26 - B1 D4 Inventory + D3 Pricing implemented; 209 passed (1147 assertions) in three consecutive full-suite runs; B2 started - 78df983
2026-09-26 - B1 review fixes (D4-F1, D4-F2, D3-C1..C3, D5-C3) applied as first B2 commits - 04c0e81, 63120b7, f92df8c, 82bc5d8
2026-09-26 - B2 D1 Auth + D6 Shipping + D7 Order lifecycle completed: D1-AUTH-001 (pre-existing), D1-AUTH-002/003/004, D6-F1, D7-F1, D7-F2 all fixed with failing-test-first evidence; 224 passed (1205 assertions) in three consecutive full-suite runs, Pint clean, PHPStan level 5 clean; D7-OBS-001 logged as NEEDS-DECISION (not blocking); handed over for review, B3 started immediately - 86b077e, 203c82c, 881af75
2026-09-26 - B3 D2 Catalog + D8 Misc + Phase 05 Security: spot-check pass only (time-boxed this session) — verified published-scope visibility, SKU uniqueness (DB-constraint-backed), variant deletion safety, review moderation/rating recalculation, admin route group protection, mass-assignment posture, CORS/Sanctum config, secret scan, webhook auth. One P3 logged (D2-F1, deferred, not a data-integrity risk). No P0/P1 found in areas covered; several checklist boxes explicitly left unchecked in the result files for a follow-up pass, not claimed as done.
2026-09-27 - B3-full: closed every checklist item the B2 review flagged as unchecked (BOLA/IDOR sweep, resource field-exposure audit, image-upload/raw-SQL/open-redirect checks, Stripe webhook re-verification, composer audit, git-history secret scan, APP_DEBUG check) - all Verified OK, no new P0/P1. Two real bugs found and fixed: D8-F1 (public contact/lead forms shared the generic 120/min ceiling instead of a dedicated throttle - now 5/min/IP) and D2-F2 (admin dashboard "pending orders" metrics permanently stuck at 0 due to the same dead 'pending' status value as D7-F1). D2-F3 (dead Campaign/Post models) reported for phase 03. 229 passed (1224 assertions) in three consecutive full-suite runs, Pint clean, PHPStan level 5 clean. D2, D8, Security all handed to READY-FOR-REVIEW; B4 (Phase 03 + Phase 04) started immediately - 86b4483, 9a24699
2026-09-27 - B4 (partial, time-boxed): Phase 03 - removed 4 dead scratch files, enabled Model::preventLazyLoading() (0 violations), added missing Stripe/Google/OTP keys to .env.example, consolidated README.md+INSTRUCTIONS.md into one accurate doc; phpstan-baseline.neon reduction (281 entries) and the duplicated-logic/method-length/enum sweeps explicitly NOT attempted, logged as carried forward. Phase 04 - wrote J04 (happy checkout) and J14 (authorization sweep) as true HTTP-only journeys; J08 judged already substantively covered by the pre-existing real-concurrency ConcurrentInventoryTest. J14 immediately found D2-F4: PUT/DELETE /products/{product}/reviews/{review} threw a fatal 500 for EVERYONE including the review's own owner, because ReviewController called $this->authorize() with no AuthorizesRequests trait anywhere in its inheritance chain - a phpstan-baseline.neon entry had been silencing the exact same error instead of it being fixed. Fixed by adding the trait to the base Controller; removed the stale baseline entry. 12 of 15 journeys and the Newman smoke run explicitly NOT attempted. 231 passed (1251 assertions) in three consecutive full-suite runs, Pint clean, PHPStan level 5 clean (baseline now 280 entries, down from 281). Both result files handed to READY-FOR-REVIEW at the depth reached - 8457c4d, 85bfd55, 716e895, 2965a3e, 84c2a3e
2026-09-27 - B4 completed: clean-code report corrected, PHPStan level 5 clean with baseline reduced to 208 entries, J01-J15 coverage complete (J08 carried by real concurrency test), refreshed Newman collection passed 11/11 assertions. B5 review fixes applied: J01/J02/J03/J05/J06/J07/J09/J10/J11/J12/J13/J15 are now real multi-step HTTP chains; category reorder strict-mode failure fixed with regression coverage; actual `postman_collection.json` passed 39 requests, 39 test scripts, 42 prerequest scripts and 66 assertions. Final verification is ready for review.
