# Batch B1 — Review (D4 Inventory + D3 Pricing + D5 conditions)

Reviewed: commits `1ad1540`, `3b0b6cb`, `4160d85`, `7c88b22`, `ef77a33`,
`78df983`, `4409e96`, `f2c2d27`. Full suite rerun by the reviewer:
**209 passed (1147 assertions)**. Matches.

Per batch mode, all fixes below go in as the **first commits of B2**.

---

## D5 conditions C1–C3
- C1 ✅ stock and `stock_released_at` are asserted after the admin refund.
- C2 ✅ `test_pending_admin_refund_then_signed_full_refund_releases_stock_once`:
  202 → signed full refund → stock once → replay unchanged.
- **C3 ❌ not done.** `results/02-D5-payments.md` still has 4× `â€`
  (`grep -c "â€"` → 4), yet the commit message says C1–C3 are complete. Fix
  it, and don't mark items done without checking them.

---

## D4 Inventory — **CHANGES-REQUESTED** (2 × P1)

### Verified OK
- `expires_at` is 30 min, configurable and clamped. The scheduler runs every
  10 min with `withoutOverlapping`. The cancel is locked and conditional
  (status, payment status, same session id). A provider error means skip. ✅
- "Run twice → released once" is covered inside the open-session test. ✅
- An observer skip on `shipped_at` works for the ship-endpoint path. ✅

### D4-F1 — P1: the job cancels a checkout the customer just resumed
The job selects orders by **`created_at` ≤ now − 35 min**, not by the
*current* session's expiry.

**Scenario:**
1. The order is created at 10:00 and its first session expires unpaid.
2. At 10:50 the customer resumes, so `stripe_session_id = cs_new`, which is
   open until 11:20.
3. The 10:50 or 11:00 job run picks the order (created 10:00), retrieves
   `cs_new` (status `open`), **expires it and cancels the order while the
   customer is on the payment page.**

**Fix:** after retrieving the session, only act when the session has actually
passed its expiry: `status === 'expired'`, or `status === 'open'` and
`$session->expires_at <= now()->timestamp`. An open session that hasn't
expired yet → skip. Keeping `created_at` as a coarse pre-filter is fine.

**Test:** an order created 2h ago whose current session is `open` with
`expires_at` 20 min in the future → no expire call, and the order is
untouched.

### D4-F2 — P1: a manual "shipped" status bypasses D4-OBS-002
`shipped_at` is only set by `Admin/ShippingController.php:148` (the ship
endpoint). `Admin/OrderController::updateStatus` and `bulkUpdateStatus` allow
`processing → shipped` without setting it.

**Scenario:**
1. The admin marks the order `shipped` from the status dropdown, so
   `shipped_at` stays null.
2. The customer returns nothing, and the admin refunds.
3. The observer sees `shipped_at === null` and **restocks** goods that are
   with the customer.

The new test `test_refunding_a_shipped_order_does_not_restock_inventory`
misses this because it sets `shipped_at` directly in the factory.

**Fix (both):**
- (a) Set `shipped_at = now()` when an order moves into `shipped`, single
  and bulk, if it is null.
- (b) Defence in depth in `OrderObserver`: skip the release when
  `shipped_at` is set **or** the previous status
  (`$order->getOriginal('status')`) was `shipped`/`delivered`.

**Test:** through the **HTTP admin endpoints**:
- `processing → shipped` via `POST admin/orders/{id}/status`
- then refund (mock Stripe `succeeded`)
- stock unchanged, and `shipped_at` not null

Do the same for bulk.

---

## D3 Pricing — **APPROVED WITH CONDITIONS** (verify in the B2 review)

### Verified OK
- The free-shipping threshold uses `max(0, subtotal − discount)`. ✅
- Below-minimum → 422 `minimum_charge` is thrown **before** `Order::create`,
  inside the transaction, so no order, stock or coupon usage. Tested. ✅
- A free order has no Stripe session, a `free` payment row, `processing`/`paid`,
  and the response has `checkout_url: null` and `payment_required: false`.
  Tested, including the single-use coupon and admin refund → 409. ✅

### D3-C1 — P2: the free-order finalization is not atomic
In `OrderController::store`, the free path runs
`$order->update(...)` and `Payment::create(...)` **outside** a transaction.
If the payment insert fails, the order is `paid` with no ledger row. Wrap both
in `DB::transaction`, and dispatch the mail and alert after the commit.

### D3-C2 — P2: free-shipping boundary tests are missing
The B1 instructions asked for three cases. Only "coupon pushes under the
threshold" exists. Add:
- **threshold − 0.01** → shipping charged
- **exactly at the threshold** → free (this pins `>=` vs `>`)

### D3-C3 — Frontend impact is incomplete
The free-shipping rule change is frontend-visible: any "X more for free
shipping" indicator must now use **subtotal − discount**. Add it to
`## Frontend impact` and to the frontend response doc.

### Note for phase 03 (no action now)
Minimum-charge rejection reuses `ShippingValidationException`. It works, but a
pricing error shouldn't be a shipping exception. Log it for phase 03.

---

## Summary
| Domain | Verdict | Blocking items |
|---|---|---|
| D5 | APPROVED (condition C3 still open) | C3 |
| D4 | CHANGES-REQUESTED | D4-F1, D4-F2 (P1) |
| D3 | APPROVED WITH CONDITIONS | D3-C1, C2, C3 |
