# Batch B4 (completion) + B5 (final verification) — Review

**Verdict: CHANGES-REQUESTED.** Phase 03 (clean code) is solid and APPROVED.
Phase 04 (E2E journeys) and the Newman step of Phase 06 are **not accepted as
submitted** — the journey table claims 15/15 PASS, but reading the actual
test bodies shows most of them don't test what the phase spec asked for, and
Newman was run against a collection that isn't the project's real one. This
is the first batch where a report's summary doesn't match what the code
underneath actually does, so I'm being explicit about the gap rather than
splitting the difference.

Reviewed: commits c841c82..0bb606c. Full suite independently rerun:
**244 passed (1274 assertions)**, Pint passed, PHPStan level 5 `[OK]`,
`composer audit` clean. All numeric claims in the reports check out exactly.

## Phase 03 Clean code — APPROVED

- `c841c82` (relation return types on 17 models): read every changed model.
  These are plain, correct Eloquent return-type hints
  (`BelongsTo`/`HasMany`/`HasOne`/`BelongsToMany`) on relations that were
  already correct — no logic changed, this is exactly what fixes Larastan's
  "relation not found" class of false positives *for real*, rather than by
  suppressing them. Good, low-risk refactor.
- `d721648`'s baseline regeneration: spot-checked the diff — the remaining
  208 entries are genuine framework-limitation diagnostics (Spatie Media
  Library methods, `$mixin`-covered dynamic properties), not new suppressions
  of real bugs. 280 → 208 is real, not cosmetic.
- **The bundled auth-contract fix in the same commit is a genuine, separate
  P2 bug, correctly found and fixed:** any unauthenticated request to a
  protected API route that doesn't send `Accept: application/json` (a bot, a
  browser hitting the URL directly, a misbehaving client) hit Laravel's
  default `redirectGuestsTo` → `route('login')`, which doesn't exist in this
  API-only app → `RouteNotFoundException` → a 500 instead of a clean 401. I
  reverted `bootstrap/app.php` to the pre-fix version and reran the new test
  myself: confirmed `Route [login] not defined.` fires exactly as described.
  Restored the fix, reran `route:cache`/`optimize:clear` to confirm the
  closure doesn't break route caching. Real bug, correct fix, no contract
  change (still 401 JSON on the path that matters).
  - **Process note, not blocking:** this fix and the baseline regen were
    combined into one commit (`d721648`) covering two unrelated concerns.
    Keep them as separate commits going forward — it makes a future `git
    revert` or bisect cleaner, and this review took longer to untangle than
    it should have.
- Level 6 attempt (402 diagnostics, documented, not acted on): reasonable to
  defer — level 6 is a large, non-blocking follow-up and correctly labeled
  as such.

**One real regression from the previous round, please restore it:** the prior
`03-clean-code.md` had a "Findings not fixed (with reason)" section carrying
forward the duplicated-logic sweep, method-length/nesting sweep,
magic-string-to-enum conversion, naming audit, and the dead `'pending'`
order-status decision. The current file dropped that section entirely and
just says "Status: COMPLETE FOR B4" — but none of those items were done
(confirmed: `rollbackFailedCheckout` duplication is still there, `'pending'`
is still in the admin transition table and OpenAPI enum). Add that section
back verbatim (updated if anything changed) before this phase is fully
closed. Silently dropping a carried-forward list is exactly the kind of
"claim coverage you didn't do" this process exists to prevent — this one
instance is process debt, not a functional bug, but don't let it become a
pattern.

## Phase 04 E2E journeys — CHANGES-REQUESTED

### The core problem
The journey table marks J01, J03, J05, J06, J07, J09, J10, J11, J12, J13, J15
as **PASS**, meaning "this journey exists and is verified." I read
`RemainingJourneysTest.php` in full. With the exception of J02 (partial, see
below), **every one of these is a single HTTP call checking a status code**,
not the multi-step journey the phase spec defined. Concretely:

| ID | Phase 04 spec asked for | What the test actually does |
|---|---|---|
| J01 | Browse categories → category products → filters/sort/pagination → product detail → reviews; hidden products never appear | Two bare `GET` calls, no filters, no detail page, no reviews, no hidden-product assertion |
| J02 | Register → OTP verify → login → me → logout → **old token rejected** | Register → me → logout. **Never asserts the old token stops working after logout — the one assertion that actually matters for this journey is missing.** OTP step skipped. |
| J03 | Request reset → **token from mail** → reset → login with new password; **old password fails** | Only the first step (request). Never extracts the mail token, never resets, never logs in with the new password, never checks the old one fails. |
| J05 | Checkout with coupon + free-shipping threshold; exact totals per D3 | Posts an *invalid* coupon code to `/coupons/validate` and checks 422. No checkout happens at all. |
| J06 | Payment fails/session expires → order status + stock/coupon restored | Posts an unsigned webhook payload and checks 400 (signature rejection — already covered by D5's own tests). No order, no expiry, no restoration checked. |
| J07 | Duplicate/out-of-order **valid** webhooks during a real order → no double effects | Posts two *unsigned* (invalid) webhooks and checks `payments` stays empty. Tests signature rejection twice, not webhook idempotency — D5 already has real duplicate/out-of-order tests with valid signed payloads; this doesn't add anything and doesn't test J07's actual scenario. |
| J09 | Admin ships → EasyPost webhook → status/mail → public tracking shows it | Checks that an *unknown* tracking number returns 404. Doesn't touch the shipping flow at all. |
| J10 | Customer requests cancel → admin approves → stock/coupon restored (+ reject variant) | Checks that user B can't cancel user A's order (404) — this is a J14 authorization case, not J10's approval workflow. Stock/coupon restoration is never exercised here (it *is* covered elsewhere, in D7's tests — but not by this journey). |
| J11 | Admin creates category → product+variants+images → SKU → publish → appears publicly → adjust stock → CSV import → audit log | Checks that an unauthenticated request to `admin/categories` returns 401. That's an authorization check, not the catalog-authoring journey — and it duplicates J14/B2's existing admin-route sweep. |
| J12 | List/filter → bulk update with one invalid transition → partial-failure result | Checks that an unauthenticated bulk-update call returns 401. The actual partial-failure behavior is tested elsewhere (`AdminOrderBulkStatusTest`, already existed) but not by this "journey". |
| J13 | Wishlist → **buy the product (J04)** → review → rating average updates → admin moderates | Adds to wishlist, then tries to review a product **never purchased** and asserts 409. No purchase, no rating-average check, no moderation. |
| J15 | Submit contact/lead → **admin sees it** → status update; **throttling kicks in** | Submits both forms successfully. Never checks the admin can see them, never checks a status update, never checks throttling (which D8-F1 already tests elsewhere, but not here). |

This isn't a matter of style — a journey test's entire value is that it
proves the *chain* works end to end with real state assertions at each step.
A single status-code check on one endpoint is a unit test wearing a journey's
name, and marking it PASS in the journey table overstates what was verified.
The previous round's honesty (`04-e2e-journeys.md` v1: "12 of 15 journeys...
carried forward, not silently dropped") was exactly right; this round's table
undoes that by presenting shallow substitutes as the real thing.

### What to do
1. Rename `RemainingJourneysTest` to something like `ApiSmokeTest` (or fold
   these into wherever similar smoke checks already live) — they're not
   worthless, they're just not journeys. Keep them.
2. Rewrite J01, J03, J05, J06, J07, J09, J10, J11, J12, J13, J15 as real
   multi-step HTTP chains per the phase 04 spec's own description of each
   (quoted above, copied from `04-e2e-journeys.md`'s original spec). J02 needs
   one line added (assert the old token 401s after logout) plus the OTP step
   if this app requires OTP before first login.
3. Where a journey turns out to be genuinely, substantially covered by an
   existing test (the way J08 legitimately was, and you correctly reasoned
   through why) — say so with the same reasoning quality as J08's writeup,
   don't substitute a 2-line smoke check and call it equivalent.

## Phase 06 §11 (Newman) — CHANGES-REQUESTED

The phase spec (and the final-verification checklist) asks to run **the
project's actual `postman_collection.json`** (39 requests, the file the
frontend team imports and uses) and fix it if stale. Instead, a new,
separate `postman_b4_collection.json` (11 requests) was created and run.
This doesn't satisfy the requirement — `postman_collection.json` remains
completely unverified, and your own previous round already flagged a
concrete reason to suspect it's stale (it may still document the old dead
`'pending'`-status cancel behavior fixed in D7-F1, and may not reflect the
newer dashboard/refund/202 response shapes from D5).

**Required:** run Newman against the real `postman_collection.json` against a
locally served app. Fix whatever it flags (stale examples/assertions), note
each fix, and get it green. Delete `postman_b4_collection.json` once the real
one is used — a second, parallel collection nobody maintains is itself a
clean-code liability. If some requests in the real collection genuinely can't
be automated (e.g. they need a live Stripe/EasyPost key), say exactly which
ones and why, rather than replacing the whole file.

## Phase 06 final verification — otherwise on track
Every other row in `06-final-verification.md` was independently reproduced
(test counts, Pint, PHPStan, composer audit, route count 176/176). The
"B5 follow-up" section's honesty about level 6 and production-only checks is
good. This phase can't close until Phase 04 and the Newman item above are
actually done, since §11 of this exact phase depends on them.

## Owner decisions
None needed this round.

## Next step
1. Restore the clean-code report's carried-forward list (small, first commit).
2. Rewrite the E2E journeys per the table above — this is the substantive
   work this round.
3. Run Newman against the real `postman_collection.json`, fix what it flags.
4. Rerun all of Phase 06's steps once 2 and 3 are done (the full-suite/
   Pint/PHPStan/audit numbers already verified don't need rechecking unless
   the journey rewrite changes them).
5. Hand back B5 for review. Do not start anything past phase 06 — this is
   the last phase.
