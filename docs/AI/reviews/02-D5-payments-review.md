# 02 D5 Payments — Review (round 1)

**Verdict: CHANGES-REQUESTED**. I found one new P0, one new P1, and the
L-PAY-003 fix brings in a new problem.

Reviewed: `results/02-D5-payments.md`, commits `185f0d4`…`f1562ff`, and the
current code in `StripeWebhookController`, `StripeCheckoutService`,
`Admin/OrderController` (refund + status transitions),
`V1/OrderController` (checkout/cancel), `OrderObserver` and
`OrderInventoryService::release`.

## Independently verified — OK

- Suite: **187 passed (1047 assertions)**. Pint passed. PHPStan `[OK]`, and
  the baseline shrank by 24 lines. Matches the report.
- L-PAY-001: the refund amount now only goes up, under a row lock. Correct.
- L-PAY-002: the `payments` row is written inside the same locked transaction
  as the paid transition. Correct.
- L-PAY-004: amount, currency and session are rechecked under the lock, and the
  transition is conditional (`pending_payment`/`unpaid` → `processing`/`paid`).
  Side effects only fire for the transaction that wins. Correct and well tested.
- `OrderInventoryService::release` is idempotent (`stock_released_at` guard
  under lock), so running it twice is safe.
- `default => true` for unhandled events avoids pointless Stripe retries. Good.

## Required changes

### R1 — NEW P0 (`L-PAY-007`): a cancelled order can still be paid
**Where:** `Admin/OrderController::statusTransitions()` allows
`pending_payment → cancelled` (single and bulk). Nothing in `app/` expires the
Stripe Checkout Session when that happens: `expireCheckoutSession` is only used
in the resume race at `V1/OrderController.php:184`.

**Scenario:**
1. The customer opens Checkout.
2. The admin cancels the order, or bulk-cancels it. Stock is released.
3. The session is still open (Stripe default is 24h), and the customer pays.
4. `checkout.session.completed` hits a conditional update that finds 0 rows,
   so the handler returns `false` and responds **422**.
5. Stripe retries for 3 days. **The customer is charged, the order stays
   cancelled, the stock has been sold again, and nobody is told.**

**Required:**
1. Every path that cancels a `pending_payment` order must first expire its
   Stripe session. If Stripe says the session is already `complete`, the
   cancel must fail with 409 and must not cancel. Paths: admin single status
   update, bulk update, and any other path you find.
2. Defence in depth in the webhook: a verified, amount-matching
   `checkout.session.completed` for an order that can no longer accept payment
   must not return 422. It must:
   - return 200 (stop the retries)
   - log `critical`
   - dispatch an admin alert
   - record the payment in `payments` with a status such as
     `requires_refund`

   Whether to also **auto-refund** is a business rule. Log it as
   `NEEDS-DECISION` (my recommendation: auto-refund plus alert).
3. Add tests for both parts. The webhook test must use a real signed payload,
   like the existing ones.

### R2 — NEW P1 (`L-PAY-008`): the expired-session handler has the same race as L-PAY-004
**Where:** `StripeWebhookController::handleSessionExpired`. It compares the
session id without a lock and then runs an unconditional `update()`.

**Scenario:**
1. `checkout.session.expired` arrives for `cs_old` and passes the session check.
2. The customer resumes, and `stripe_session_id` becomes `cs_new`.
3. The handler cancels the order and releases the stock.
4. The customer pays `cs_new`, and R1 happens.

**Required:** apply the L-PAY-004 pattern:
- lock the row
- recheck the session id under the lock
- use a conditional update `where status = pending_payment and
  payment_status = unpaid`

Add a test mirroring
`test_completed_webhook_is_rejected_when_its_session_is_replaced_during_processing`.

### R3 — L-PAY-003 is reopened: a pending refund is reported as a failure
The fix stops the order from being marked refunded too early, which is right.
But it adds two new problems:
- **The admin is told "Stripe refund failed" (502) while Stripe has actually
  created the refund.** The money is on its way back, but the UI says it
  failed. If the admin clicks again, Stripe errors or, for partial states,
  refunds again.
- **There is no idempotency key and no lock.** A double-click sends two
  `Refund::create` calls at the same time.

**Required:**
1. Pass an idempotency key to `Refund::create`, e.g. `refund-order-{id}`,
   via the request options. Add a unit or feature assertion that it is sent.
2. A `pending` refund is not a failure. Changing the response (for example
   202 "refund pending") touches the admin contract, so log it as
   `NEEDS-DECISION` and propose the response. Until it's decided, the message
   must at least not say "failed" for a pending refund.
3. Make the webhook side consistent, **with a source**. Check Stripe's API docs
   for whether `charge.amount_refunded` (in `charge.refunded`) includes
   **pending** refunds, and whether a refund can later fail
   (`refund.failed` / `charge.refund.updated`). Put the doc links in the result
   file. If a counted refund can fail later, `handleChargeRefunded` currently
   marks the order refunded and releases stock for money that never went back.
   Handle the failure event, or document why it can't happen with card-only
   Checkout. This ties into L-PAY-005.

### R4 — P2 (`L-PAY-009`): the payments ledger ignores refunds
L-PAY-002 added the `payments` row, but refunds (admin and webhook) never
update it. It stays `completed` for the full amount after a full refund.
Update the payment record's status and amount on partial and full refunds, or
document why the ledger is intentionally write-once.

### R5 — P2, check only: zero or tiny totals
What happens in `createCheckoutSession` when the order total is **0** (100%
coupon or free product) or **below Stripe's minimum charge** (≈ $0.50 USD)?
Stripe rejects those sessions. Write a test for each. If the order ends up
stuck or broken → finding. Coordinate the rule with D3.

## Notes carried to later domains (not blocking D5)
- **D7:** the admin can move a **paid** `processing` order to `cancelled`, and
  `CancellationRequestController.php:81` also sets `cancelled`. Neither issues
  a Stripe refund, so the money is kept unless someone refunds manually. D7
  must define and test "cancel after payment ⇒ refund?" (NEEDS-DECISION if
  unclear).
- **D3:** `createCheckoutSession` creates a new Stripe `Coupon` object for
  every discounted checkout, and again on every resume. Harmless for
  correctness, but they pile up in the Stripe dashboard. Worth a note.

## Owner decisions
Recorded in `PROGRESS.md` once the owner answers:
- L-PAY-005 (async payments)
- L-PAY-006 (webhook throttle)
- the new ones from R1 and R3

## Next step
Fix R1–R4 and answer R5. Append `## Round 2 response` to
`results/02-D5-payments.md`, set the status to `READY-FOR-REVIEW`, and stop.
Commit this review file with your first commit.

---

## Addendum — owner decisions (2026-09-26)

These are now business rules. Implement them in the round 2 fixes. They are
also recorded in `PROGRESS.md`.

- **L-PAY-005 → A: card-only Checkout.** Set `payment_method_types: ['card']`
  in `createCheckoutSession`. Apple Pay and Google Pay are still offered by
  Checkout as card wallets. Add a test asserting the parameter is sent.
  Async-payment events stay out of scope.
- **L-PAY-006 → A: dedicated provider limiter.** Give `webhooks/stripe` and
  `webhooks/easypost` their own named limiter with a much higher limit than
  `api` (propose the number with a reason), instead of the generic one. Update
  `docs/API_V1_ROUTE_MIDDLEWARE.md`. This change is owner-approved, so the
  frozen-contract rule doesn't block it.
- **L-PAY-007 (R1 part 2) → B: no automatic refund.** A verified payment for an
  order that can no longer accept it must:
  - return 200 to Stripe
  - log `critical`
  - send an admin alert
  - record the payment as `requires_refund` (store the PaymentIntent on the
    order/payment so a refund is possible)

  **The admin refunds manually.** Make sure the admin refund endpoint accepts
  this case. Today it requires `isPaid()`, which a cancelled/unpaid order won't
  satisfy. Test the full path: payment on cancelled order → alert → admin
  refund → refunded.

- **BR-01 — a paid or shipped order is never cancelled or refunded without
  admin approval.**
  - The customer can cancel directly **only** while the order is unpaid.
    After payment, the only option is a cancellation request.
  - **Admin approval cancels the order** and releases stock. It does **not**
    refund automatically. The payment stays `paid` until the admin presses
    **Refund** as a separate step.
  - Because the refund is a separate step, a paid order can be left cancelled
    without its refund. Make sure admins can find these orders: status
    `cancelled` with payment still `paid`, via the existing admin order
    filters. Add a test for that filter, and send an admin alert on approval
    that says "refund pending". If a proper list needs a new endpoint or
    response field, log it as NEEDS-DECISION rather than building it.
  - Refunds done in the Stripe dashboard arrive via `charge.refunded` and
    count as admin actions. Existing handling applies (subject to R3).
  - Verify and test BR-01 fully in **D7**. For D5, make sure nothing in the
    payment code refunds automatically.

**Still open (executor proposes, owner decides):** the admin refund response
for a `pending` Stripe refund (R3 item 2).

---

# 02 D5 Payments — Review (round 2)

**Verdict: CHANGES-REQUESTED** (last round expected). The main fixes are
correct. I found one new P1 introduced by the `requires_refund` branch, two P2
items, one missing test, and the report is incomplete.

Reviewed: commits `1268ddf`, `e952972`, `ad9c30c`, `61270e9`.

## Independently verified — OK
- Suite **197 passed (1074 assertions)**, Pint passed, PHPStan `[OK]`. Matches.
- **L-PAY-005:** `payment_method_types: ['card']` is sent and asserted
  (`StripeCheckoutTest.php:128`). ✅
- **L-PAY-006:** `route:list` shows both webhooks with **only**
  `throttle:provider-webhook` (1,000/min per IP). `throttle:api` is really
  removed. The contract doc is updated. ✅
- **L-PAY-007 part 1:** single and bulk cancel of `pending_payment` retrieve
  the session, refuse with 409 if it is `complete`, and expire it if `open`. A
  provider error means 502 and nothing is cancelled. If the customer pays in
  the gap between retrieve and expire, `expire()` throws, which also becomes
  502, and the webhook then marks the order paid. Safe. ✅
- **L-PAY-008:** the expiry handler locks the row, rechecks the session and
  requires `pending_payment`/`unpaid`. It has its own race test. ✅
- **R3:** stable idempotency key `refund-order-{id}` (asserted); a cache lock
  guards double-clicks; the pending message is neutral. ✅
- **Migration:** a new migration (the old one is untouched). The original
  `payments.status` enum had no default, so `MODIFY` loses nothing. `down()` is
  safe. ✅

## Required changes

### R6 — NEW P1 (`L-PAY-012`): a replayed `completed` event after a refund raises a false URGENT alert and corrupts the ledger
In `handleSessionCompleted`, anything that isn't `isPaid()` and fails the
conditional update falls into the `requires_refund` branch, and that includes
orders whose `payment_status` is **`refunded`**.

**Scenario:**
1. The order is paid, then fully refunded (`payment_status = refunded`).
2. Stripe re-delivers `checkout.session.completed`. Stripe documents duplicate
   delivery, and a manual "resend" from the dashboard does the same. The
   session, amount and currency still match.
3. `isPaid()` is false and the update hits 0 rows, so the handler runs the
   `requires_refund` branch.
4. The payment row flips `refunded` → `requires_refund`, and admins get
   **"URGENT: … needs a manual refund"** for money that was already returned.

**Required:**
- Only take the `requires_refund` branch when the order **never had a
  successful payment**: status `cancelled` and payment status `unpaid` or
  `failed`.
- If the order is already `paid` or `refunded`, or the payment row already
  holds this PaymentIntent with a status other than `requires_refund`, treat it
  as a duplicate: return 200 with no side effects.
- Test: pay → full refund → replay the signed `completed` event. The payment
  status must stay `refunded`, and no alert may be queued.

### R7 — P2 (`L-PAY-013`): bulk cancel expires Stripe sessions before validating the whole batch
`bulkUpdateStatus` expires the sessions **before** the transaction that checks
every transition.

**Scenario:** bulk cancel `[A: pending_payment, B: delivered]`.
1. A's session is expired at Stripe.
2. B's transition is invalid, so the API answers **409 "no orders changed"**.
3. Stripe then sends `checkout.session.expired` for A, and the webhook cancels
   A anyway. The admin was told nothing changed.

**Required:** validate every transition first (no side effects), then expire
the sessions, then update. Test the scenario above: after the 409, no
`expire` call is made.

### R8 — P2 (`L-PAY-009` follow-up): `payments.amount` is overwritten with the refunded amount
`handleChargeRefunded` sets `payments.amount = refundedAmount`. After a
partial refund of 25.00 on a 100.00 payment, the ledger says the payment was
25.00, and the original paid amount is lost.

**Required:** keep `payments.amount` as the amount paid, and change only
`status`. The refunded amount already lives in `orders.refunded_amount`. If you
think the ledger needs its own column, log it as NEEDS-DECISION instead of
adding it. Update the partial-refund test to assert that `amount` stays 100.00.

### R9 — Missing test required by the addendum
"Test the full path: payment on cancelled order → alert → admin refund →
refunded." The current test (`test_signed_payment_for_a_cancelled_order_is_recorded_for_manual_refund`)
stops at `requires_refund`. Extend it, or add a test, that calls
`POST admin/orders/{id}/refund` and asserts:
- the Stripe refund is requested with the PaymentIntent
- the order ends `refunded`
- the payment ends `refunded`
- stock is not released twice

### R10 — The report is incomplete
The round 2 response is 6 lines. `00-README.md` requires every finding in the
`templates/finding.md` format with before/after evidence. Please add:
1. A full finding block for **L-PAY-007, 008, 009, 010, 011** (and 012, 013
   from this round): severity, scenario, test name, commit, evidence.
2. A clear answer to **R3 item 3**: does `charge.amount_refunded` include
   **pending** refunds, and what happens to it when a refund later fails?
   Quote the relevant doc sentence, not just the link.
   - If it does include pending refunds, `charge.refunded` can mark an order
     refunded (and release stock) for a refund that later fails. Given BR-01
     (manual admin handling), **an alert-only `refund.failed` is acceptable**
     as long as this limitation is written down.
3. One note on the idempotency key: Stripe caches the result for 24h, so a
   **retry within 24h after a failed refund** returns the cached failure.
   Document the admin workaround (wait, or refund from the Stripe dashboard).

## Carried to D7 (not blocking D5)
- BR-01: a test that admins can list orders with status `cancelled` and
  payment still `paid`, plus the "refund pending" alert on cancellation
  approval.

## Owner decisions
- **L-PAY-010** (zero or tiny totals) → moved to D3. Accepted. The rollback
  keeps it from sticking, but a 100%-coupon order currently can't be placed.
- **L-PAY-011** (pending refund 202 vs 502) → sent to the owner with a
  recommendation of 202.

## Next step
Fix R6–R10, append `## Round 3 response`, set the status to
`READY-FOR-REVIEW`, and stop.

## Addendum — owner decision L-PAY-011 (2026-09-26)

**A: a pending Stripe refund returns `202 Accepted`.** Implement it in round 3,
together with R6–R10:
- When `Refund::create` returns `pending` (or any status other than
  `succeeded` / `failed` / `canceled`), respond **202** with
  `success: true`, message `Refund is pending confirmation from Stripe.`, and
  `data` containing the order id and `refund_status`.
- The order and payment stay unchanged locally. The `charge.refunded` webhook
  finalizes them (existing handling).
- A refund status of `failed` or `canceled` stays **502**
  `Stripe refund failed.`
- Update the OpenAPI attributes on `refund` and any admin contract doc that
  lists this endpoint's responses. This change is owner-approved.
- Tests: `pending` → 202 with the order unchanged, then a signed
  `charge.refunded` → order and payment `refunded`, stock released exactly once.
  Update the existing test
  `test_admin_does_not_mark_an_order_refunded_until_stripe_confirms_the_refund`
  to the new 202 behaviour.

---

# 02 D5 Payments — Review (round 3)

**Verdict: CHANGES-REQUESTED (tests and docs only, no production code).**
All code fixes are correct. What remains is one weakened test, two missing
assertions, and the report item R10, which has now been requested twice.

Reviewed: commits `0ade22f`, `89ff7d5`, `c02cf38`.

## Independently verified — OK
- Full suite **199 passed (1080 assertions)**. You reported only a focused run
  (38). Always paste the full suite at the end of a round.
- **L-PAY-012 (R6):** the `requires_refund` branch now only runs for
  `cancelled` + `unpaid`/`failed`. A replay after a refund is a no-op with no
  alert, and it is tested. ✅
- **L-PAY-013 (R7):** every transition, plus missing ids, is checked before
  any Stripe call. The test asserts `shouldNotReceive` on
  retrieve/expire. ✅ (The in-transaction check stays as a second guard
  against races. Good.)
- **R8:** `payments.amount` stays the captured amount, and it is tested
  (100 after a 25 partial refund). ✅
- **L-PAY-011:** the pending refund returns 202 with `order_id` and
  `refund_status`, and nothing changes locally. `failed`/`canceled` return 502.
  OpenAPI is updated. ✅

## Required changes

### R11 — Restore the deleted assertion (rule 4: never weaken a test)
Across `0ade22f` and `89ff7d5`, the line
`$this->assertNotNull($order->fresh()->stock_released_at);` was **removed**
from `test_signed_expired_session_cancels_only_the_matching_order`. It moved
to the R9 test, failed there, and was then deleted. I put it back temporarily
and ran it: **it passes**, so there was no reason to remove it. Restore it.

### R12 — R9 still doesn't assert stock
The addendum required "stock is not released twice". The late-payment test
creates an order with no items and no `stock_reserved_at`, so it proves
nothing about stock. Give the order a product line and `stock_reserved_at`,
cancel it through the real cancel path (stock released once), then run the
late payment and the admin refund. Assert that `stock_qty` is unchanged by the
payment and by the refund, and that `stock_released_at` doesn't change.

### R13 — Complete the 202 flow test
The addendum required: `pending` → 202 with the order unchanged → **signed
`charge.refunded`** → order and payment `refunded`, stock released exactly
once. The test stops after the 202. Add the webhook step in the same test, or
in a new one.

### R14 — R10 (third request): the report
`results/02-D5-payments.md` still has finding blocks only for L-PAY-001…006.
Add blocks in `templates/finding.md` format for **L-PAY-007 … 013**. The
evidence is the test names and commit shas. You don't need to recreate
failing runs for fixes that are already merged; say "regression test added
after fix".

Also add the two items from R10:
- **Quote** Stripe's docs on whether `charge.amount_refunded` includes pending
  refunds, and what happens when a refund fails. Given BR-01, the alert-only
  `refund.failed` is accepted once the limitation is written down.
- The note that the idempotency key caches failures for 24h, with the admin
  workaround.

### R15 — Frontend impact note (new; needed for "ready")
This round changed what the frontend sees, and
`docs/backend-requests-and-clarifications (2).md` **Q25** asks exactly the
refund questions the owner has now answered. Add a
`## Frontend impact` section to the result file, and a short answer to Q25 in
the frontend response doc the team uses (`docs/frontend-response-to-api-handoff.md`
or the open-items register, whichever answers Q-items):
- `POST admin/orders/{order}/refund` can now return **202** (refund pending),
  besides 200, 409 and 502.
- Admin order cancel (single and bulk) can now return **409** "Checkout
  Session already completed" and **502** provider error.
- New `payments.status` values: `requires_refund`, `partially_refunded`,
  `refunded`. State whether they appear in any API response (check
  `AdminOrderResource` / payment relation).
- Q25 answer: approving a cancellation of a paid order does **not** refund
  automatically, and the admin refunds separately (BR-01). The second part
  (which statuses permit a refund) is answered by the current transition rules.
  Quote them.

## Next step
R11–R15 are tests and docs only. Do them, run the **full** suite, append
`## Round 4 response`, and stop. I expect to approve D5 after this.

---

# 02 D5 Payments — Review (round 4)

**Verdict: APPROVED WITH CONDITIONS.** All production code in D5 is correct and
verified. Three small test/doc gaps remain (C1–C3). To avoid another round
trip, do them as the **first commit of D4**. I'll check them in the D4 review,
and D4 can't be approved without them.

Reviewed: commit `3577a30`. Full suite 199 passed (1083 assertions) matches
your report.

## Verified
- R11: the assertion is restored. ✅
- R12 (first half): the late-payment path now has a real reserved line; stock
  and `stock_released_at` are unchanged by the late payment. ✅
- R14: finding summaries L-PAY-007…013, the Stripe limitation note and the
  24h idempotency note are present. ✅
- R15: frontend impact section and Q25 answer are present and accurate. ✅

## Conditions (first commit of D4)

### C1 — R12 second half: the report claims something the test doesn't check
The report says "the late payment **and subsequent refund** leave both the
product stock and release timestamp unchanged". The test only asserts stock
**before** the refund. After `->postJson(.../refund)->assertOk()`, add:
`assertSame(1, $product->fresh()->stock_qty)` and the same
`stock_released_at` equality.

### C2 — R13: there is no test for a full refund via webhook
"Covered by the existing signed `charge.refunded` path" isn't accurate. The
existing webhook refund tests are partial, replay, failed and out-of-order.
**None sends a full `charge.refunded` for a paid order with reserved stock.**
Add one test:
1. Admin refund returns `pending` → 202.
2. Signed `charge.refunded` with `amount_refunded == total`.
3. Order `refunded`/`refunded`, payment `refunded` with amount unchanged,
   stock released once.
4. Replay the same event → stock unchanged.

### C3 — Broken characters in the result file
`results/02-D5-payments.md` contains `â€”` (4×), a double-encoded em dash from
PowerShell. Replace them with `—` and save as UTF-8.

## Carried to D4 / D7 (new observation)
The refund endpoint doesn't restrict by fulfillment status (your frontend note
says so correctly). Combined with `OrderObserver`, refunding a **delivered**
order puts its items **back into stock** automatically, even though the goods
are with the customer. D4 must define the rule: "restock on refund only if the
goods never shipped, or when the admin confirms a return?". Log it as
NEEDS-DECISION if the code can't answer it.
