# 03 Clean code - results

Status: IN-PROGRESS (B4 started; safe, high-confidence items done this pass — see "Not completed" for what's carried forward, stated honestly rather than claimed done).

## Summary

| Metric | Before | After |
|---|---|---|
| `phpstan-baseline.neon` entries (`message:` count) | 281 | 280 (unchanged since the J14 fix — see below) |
| PHPStan level 5 (with baseline) | `[OK] No errors` | `[OK] No errors` |
| N+1 protection in non-production | not configured | `Model::preventLazyLoading()` enabled; 0 violations across the full suite |
| Root-level dead scratch files | 4 (`map_routes.php`, `mapping_result.txt`, `pasted-text.txt`, `routes.json`) | 0 |
| `env()` calls outside `config/*` | 0 (already clean) | 0 |
| Missing app-specific keys in `.env.example` | Stripe (6 keys), Google (2), OTP (6) entirely absent | added, with safe placeholders |
| Root setup docs | 2 conflicting (`README.md` = unmodified Laravel skeleton, `INSTRUCTIONS.md` = real but incomplete steps) | 1 accurate `README.md` |

## Changes

- `8457c4d` — removed 4 unreferenced root-level scratch files (`map_routes.php`, `mapping_result.txt`, `pasted-text.txt`, `routes.json`). Confirmed zero references via `grep` before deletion; each is fully recoverable from git history if needed.
- `85bfd55` — enabled `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider::boot`. This is a **safety net going forward**, not a fix for an existing problem: the full suite already passes with it on, meaning the codebase already eager-loads correctly everywhere the tests exercise. From now on, a future N+1 introduced anywhere will throw in dev/testing instead of silently degrading production.
- `716e895` — added the Stripe, Google, and OTP env vars to `.env.example`. `config/services.php` reads `STRIPE_SECRET`/`STRIPE_PUBLISHABLE_KEY`/`STRIPE_WEBHOOK_SECRET`/`STRIPE_CURRENCY`/`STRIPE_CHECKOUT_EXPIRES_MINUTES`/`STRIPE_MINIMUM_CHARGE` and `GOOGLE_CLIENT_ID`/`GOOGLE_PLACES_API_KEY`, and `config/otp.php` reads six `OTP_*` keys, none of which were listed anywhere in `.env.example` — a new deploy/dev setup had zero indication these existed or were required, despite Stripe being the single highest-risk domain in this entire quality pass.
- `2965a3e` — consolidated `README.md` (previously the untouched Laravel framework skeleton — literally the "About Laravel" marketing boilerplate, no project info) and `INSTRUCTIONS.md` (real but incomplete: missing required service keys, the test suite's dedicated MySQL requirement, and static-analysis commands) into one accurate `README.md`, and removed `INSTRUCTIONS.md`.
- Verified, no change needed: zero `env()` calls anywhere in `app/` (checklist item "every `env()` call is only in `config/*`" was already satisfied).

## Findings not fixed (with reason)

### `phpstan-baseline.neon` reduction (280 entries) — NOT ATTEMPTED THIS PASS
The checklist asks to reduce the baseline to zero at level 5, then attempt
level 6. 280 suppressed errors is a large body of work — each entry needs
individual inspection (is it a real bug PHPStan caught, or a false positive
worth an inline `@phpstan-ignore` with a reason, or a genuine baseline-worthy
framework limitation) and the fix for each needs its own green-test-before-
and-after treatment per the phase 03 rule ("green test suite before and after
**every** commit"). Time-boxed this session in favor of the E2E journey work
in phase 04, which surfaces user-facing bugs rather than static-analysis
noise. Recommended as its own follow-up pass: bucket the 280 entries by rule
name first (`grep -c "identifier:" phpstan-baseline.neon | sort | uniq -c`
equivalent), fix the buckets that are cheap and mechanical (e.g. missing
return types) in bulk commits, and leave framework-limitation buckets
(Eloquent magic methods, etc.) in the baseline with a comment explaining why.

### Duplicated-logic sweep, method-length/nesting sweep, magic-string-to-enum
conversion, consistent-naming audit — NOT ATTEMPTED THIS PASS
Each of these requires reading the entire `app/` tree systematically (not
just the entry points already read during phases 01/02), which this session's
time budget did not allow after the higher-priority correctness/security work
in B1-B3. Not claimed as done. The obvious duplicated-logic candidate already
noted by the reviewer (`rollbackFailedCheckout`'s manual coupon/quote release
now duplicating `OrderObserver`'s, see B2-review.md) is a good starting point
for the follow-up pass.

### `Order` status `'pending'` still referenced in dead branches
Per the B2 review's note: `'pending'` is fully dead on the `orders` table
(nothing creates it since Stripe checkout replaced it with
`'pending_payment'` — see D7-F1), but is still *read* (as an always-false
comparison) in `Admin/DashboardController` (fixed this batch, D2-F2 — but
that fix changed the value being compared, not removed the dead branch
pattern elsewhere), `Admin/OrderController::statusTransitions()`, and
`ShipmentTrackingService`/`EasyPostWebhookController`'s transition guards.
Harmless today (each is an always-false branch, not a bug), but the enum
value and every comparison against it should be either removed or explicitly
justified (kept for old/imported data?) in the follow-up pass — this needs a
decision on whether any historical order data actually has `status='pending'`
in production before removing it from the DB enum.

## Final test + Pint + PHPStan output

```
Tests:    229 passed (1224 assertions)   Duration: 45.07s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`
