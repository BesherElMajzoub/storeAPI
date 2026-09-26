# 04 E2E journeys - results

Status: IN-PROGRESS (B4 started; 2 of 15 journeys written as true HTTP-only
journeys this pass, 1 more already substantively covered by an existing
service-level concurrency test — see below for what's carried forward,
stated honestly rather than claimed done).

## Journey table

| ID | Journey | Test file::method | Result | Findings |
|---|---|---|---|---|
| J04 | Happy checkout | `Journeys\HappyCheckoutJourneyTest::test_customer_can_go_from_login_to_a_paid_order` | PASS | none — every step (login, save address, real `POST /shipping/rates`, `POST /orders`, signed Stripe webhook, `GET /orders`) chains through HTTP only, Stripe/EasyPost faked, asserts stock decrement, `OrderPaidMail` queued once, and final state in the "my orders" list |
| J08 | Concurrency: two orders race for the last unit | `ConcurrentInventoryTest::test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation` (pre-existing, D4) | PASS | Not re-written as an HTTP-only journey this pass — it already exercises real OS-level concurrency (two separate PHP processes via `proc_open`, not simulated) against the actual reservation code path, which is the layer where the race condition would actually occur; the HTTP controller above it is a thin, already-tested pass-through. Re-deriving it as two racing `POST /orders` HTTP calls would need Stripe/EasyPost fakes registered per-process rather than per-test-Mockery-expectation — doable, but lower value than the 12 unwritten journeys below, given the underlying guarantee is already proven at the layer that matters. |
| J14 | Authorization sweep | `Journeys\AuthorizationSweepJourneyTest::test_user_b_cannot_touch_user_as_review_or_cancellation_request` (new) + `ProductionReadinessTest::test_every_admin_route_rejects_a_regular_customer` + `::test_customer_cannot_read_or_mutate_another_customers_resources` (both pre-existing, phase 01) | PASS | **Found and fixed a P1 that had gone completely unnoticed: see D2-F4 below.** The admin-route half and the orders/addresses/wishlist half of J14 were already covered; this pass added the two pieces that weren't (reviews, cancellation requests) and in doing so hit the bug. |
| J01–J03, J05–J07, J09–J13, J15 | — | NOT ATTEMPTED THIS PASS | Time-boxed this session in favor of following up on the J14 finding properly (root-causing it, fixing it, and re-verifying the whole suite) rather than writing more journeys shallowly. Not claimed as done. |

## Findings

### D2-F4 — P1: editing or deleting a review threw a 500 for everyone, including the review's own owner

- **Severity:** P1 (found via J14; filed under D2 since the bug is in the Reviews feature, not an auth-domain issue)
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Controller.php`, `app/Http/Controllers/Api/V1/ReviewController.php:120,147`
- **Problem:** `ReviewController::update()`/`::destroy()` call `$this->authorize('update'|'delete', $review)` to enforce `ReviewPolicy` (ownership + 30-day edit window). Neither `ReviewController` nor the base `Controller` class used the `AuthorizesRequests` trait, so `authorize()` was an undefined method — every call to `PUT` or `DELETE /api/v1/products/{product}/reviews/{review}` threw a fatal `Error: Call to undefined method ...::authorize()`, returned as a 500, for **anyone**, including the reviewer editing or deleting their own review. This wasn't a narrow authorization bypass — it was a total, unconditional failure of the edit/delete-review feature.
- **Scenario:** A customer who left a review tries to fix a typo or delete it entirely; both actions 500 every single time. The feature has presumably never worked since it was written.
- **Why it went unnoticed:** zero existing tests (across the entire pre-existing suite) called either route — confirmed via `grep -rln "reviews/{.*}\|putJson.*reviews\|deleteJson.*reviews" tests` before this pass, which returned nothing. `phpstan-baseline.neon` had a suppressed entry for the *exact* error (`Call to an undefined method App\Http\Controllers\Api\V1\ReviewController::authorize().`, `identifier: method.notFound`) — static analysis had already caught this, and it was silenced into the baseline instead of fixed.
- **Test:** `tests/Feature/Journeys/AuthorizationSweepJourneyTest.php::test_user_b_cannot_touch_user_as_review_or_cancellation_request` — fails on pre-fix code with `Call to undefined method App\Http\Controllers\Api\V1\ReviewController::authorize()` (a 500, not the expected 403) for the attacker case, and the same test's final section proves the legitimate owner can now successfully update their own review (which also 500'd before the fix — this is not just an authorization finding, it's a functional one).
- **Fix:** Added `use AuthorizesRequests;` to the base `Controller` class (the conventional Laravel location, and the only place in this codebase that calls `$this->authorize()`). Removed the now-stale `phpstan-baseline.neon` entry for the undefined-method error — PHPStan level 5 is clean without it. Commit: `84c2a3e`.
- **Evidence:**
  ```
  # pre-fix
  Error: Call to undefined method App\Http\Controllers\Api\V1\ReviewController::authorize()
  Tests: 1 failed (1 assertions)

  # post-fix
  PASS  Tests\Feature\Journeys\AuthorizationSweepJourneyTest
  ✓ user b cannot touch user as review or cancellation request
  Tests: 1 passed (7 assertions)

  # full suite, 3 consecutive runs
  Tests:    231 passed (1251 assertions)   Duration: 45.57s
  Tests:    231 passed (1251 assertions)   Duration: 48.29s
  Tests:    231 passed (1251 assertions)   Duration: 46.38s

  # PHPStan level 5 after removing the stale baseline entry
  [OK] No errors
  ```

## Newman smoke run

**NOT RUN THIS PASS.** Requires a live `php artisan serve` + queue worker against a
freshly seeded DB, which wasn't set up in this session. Carried forward —
`postman_collection.json` should also be checked against the current route
list for staleness (e.g. it may still reference the dead `orders/{id}/cancel`
`'pending'`-status behavior fixed in D7-F1, or omit the newer
`admin/dashboard` field names) before running Newman for real.

## Full test suite output

Full suite, run at the end of this B4 session, three consecutive runs:
```
Tests:    231 passed (1251 assertions)   Duration: 45.57s
Tests:    231 passed (1251 assertions)   Duration: 48.29s
Tests:    231 passed (1251 assertions)   Duration: 46.38s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`
