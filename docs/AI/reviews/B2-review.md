# Batch B2 — Review (D1 Auth + D6 Shipping + D7 Order lifecycle)

**Verdict: D1, D6, D7 all APPROVED.** Real bugs, well tested, well documented,
no shortcuts. This is the cleanest batch handover so far — no round 2 needed.

Reviewed: every commit 152dd5f..21dda45. Full suite independently rerun:
**224 passed (1205 assertions)**, Pint passed, PHPStan level 5 `[OK]`.
Matches the report exactly.

## D1 Auth — APPROVED
- D1-AUTH-001 (disabled user keeps a working token): `EnsureActiveUser` is
  correctly wired into the auth, orders and admin route groups. Verified with
  `route:list`.
- D1-AUTH-002 (password change via profile update with no current-password
  check): confirmed by reading `changePassword()` — the dedicated endpoint
  still requires `current_password`; the bypass is fully closed. This is a
  genuine P1 — a stolen bearer token could previously lock the real owner out
  permanently. Good catch.
- D1-AUTH-003 (email change doesn't reset `email_verified_at`): confirmed
  `updateProfile()` now nulls it on a real change and not on a same-value or
  unrelated update. Test distinguishes both cases correctly.
- D1-AUTH-004 (dead `avatar` field): correct call to remove rather than
  half-implement a feature. Frontend impact section is accurate and useful.
- All cited pre-existing "Verified OK" tests
  (`test_admin_demotion_takes_effect_with_the_existing_session`,
  `test_google_token_requires_our_audience_and_a_verified_email`,
  `test_disabled_user_cannot_use_an_existing_sanctum_token`) checked to
  actually exist and say what the report claims. Good — these weren't
  fabricated.

## D6 Shipping — APPROVED
- D6-F1 (late/duplicate EasyPost update can resurrect or re-alert a finished
  order): the fix is in the right layer (`ShipmentTrackingService::sync`
  itself gates the transition, not just the two callers), so it can't be
  bypassed by a third caller later. The alert-dedup fix (compare status
  before/after `sync()`, not the raw tracker payload) is a genuinely separate
  bug and is correctly folded into the same finding rather than ignored.
  Both new tests read as real regressions, not tautologies.

## D7 Order lifecycle — APPROVED
- D7-F1 (dead status value made direct cancel permanently return 400): this is
  the standout finding of the whole quality pass so far — a customer-facing
  documented endpoint that has silently 400'd on every real order since
  Stripe was added, caught only by tracing `'pending'` to its actual origin.
  Confirmed nothing else in the codebase sets `status = 'pending'` on an
  order (only the dead code paths I already flagged for phase 03 read it).
- D7-F2 (coupon/quote never released outside the narrow
  `rollbackFailedCheckout` path): correct diagnosis and correct fix location
  (the observer, so it now covers every cancellation path uniformly instead
  of accumulating one more special case per caller). The "moved logic,
  left the old redundant call" tradeoff is reasonable and stated honestly
  rather than hidden.
- The regression check for inventory/coupon suites running unmodified is
  good practice — it directly demonstrates the `OrderObserver` change didn't
  perturb existing control flow, which is exactly the risk a change to that
  file carries.

## Owner decision — D7-OBS-001 (2026-09-27)
**A — never release coupon/shipping-quote usage for a paid order**, even on a
full refund. Consistent with `D4-OBS-002` (no auto-restock after shipping).
No code change required; this closes the NEEDS-DECISION as "confirmed
correct as-is." Recorded in `PROGRESS.md`.

## Minor notes (not blocking, carry to phase 03)
- Order status `pending` is now fully dead on the `orders` table (nothing
  creates it) but is still read in `Admin/DashboardController` (counts),
  `Admin/OrderController::statusTransitions()`, and
  `ShipmentTrackingService`/`EasyPostWebhookController` transition guards.
  Harmless today (an always-false branch), but phase 03 should either remove
  it from the enum/checks or confirm it's kept deliberately for old data.
- `rollbackFailedCheckout` doing the same release as `OrderObserver` now is
  confirmed-harmless duplication (noted honestly in the report) — phase 03
  simplification candidate, not a bug.

---

# Batch B3 spot-check (D2 Catalog + D8 Misc + Security) — acknowledged, not a review

This was declared a spot-check, not an exhaustive pass, and the report is
honest about exactly that — unchecked checklist boxes are left unchecked
rather than papered over. I independently re-verified three of the claims
(admin route grouping, mass-assignment `$guarded` grep, CORS config) and they
hold up exactly as stated. No P0/P1 found, none disputed.

**This does not close D2, D8, or Phase 05.** They stay `IN-PROGRESS`. The
unchecked items become the mandatory content of Batch B3-full:
- D2: public write-endpoint throttling audit, 3-metric dashboard-accuracy
  check, dead-code report (`Campaign`, `Post`, …).
- D8: same throttling audit (shared with D2's contact/lead endpoints).
- Security: BOLA/IDOR sweep over every `{id}` route, `Http/Resources/*`
  field-exposure audit, image-upload audit, raw-SQL binding audit, open
  redirect check on Stripe URLs, Stripe webhook re-verification, `composer
  audit`, git-history secret scan, `APP_DEBUG`-off verification.

## Next step
Do the D7-OBS-001-closure (no code, just mark it resolved in your result
file), then continue straight into B3-full per the list above — per batch
mode, no need to stop first. Bring the same rigor as B1/B2, not the B3
spot-check's lighter touch.
