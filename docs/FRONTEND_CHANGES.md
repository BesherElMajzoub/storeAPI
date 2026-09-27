# Backend Changes Affecting the Frontend

**Source:** internal backend quality pass, branch `ai/quality-pass`.
**Date:** 2026-09-27.
**Scope:** every change made during this pass that changes a response,
status code, field, or previously-broken behavior the frontend depends on.
Pure internal refactors, new tests, and static-analysis cleanup are left out
— they have no effect on any API call.

No route was removed and no existing successful response's shape changed
unless stated below. Everything here is additive (a new possible status
code, a new optional field) or a bug fix that makes a documented behavior
finally work.

---

## 1. Two previously-broken endpoints now work

These were completely non-functional before this pass. If the frontend
built any workaround (hid a button, disabled a flow, silently ignored the
error) because the endpoint "never worked," it can now be used as designed.

### 1.1 Cancel my order
`POST /api/v1/orders/{id}/cancel` — direct, no-approval cancellation of an
order that hasn't been paid yet.

- **Before:** always returned `400 Only pending orders can be cancelled
  directly.`, for every order, unconditionally. Broken since checkout
  order-statuses were introduced.
- **After:** works within the documented 3-hour window for an order that's
  still awaiting payment. No contract change — this is the endpoint as it
  was always documented.

### 1.2 Edit / delete my review
`PUT` and `DELETE /api/v1/products/{product}/reviews/{review}`

- **Before:** both routes returned a fatal `500` for **everyone**, including
  the reviewer editing/deleting their own review. Completely broken, no
  exceptions.
- **After:** works normally — the review's owner can edit (within a 30-day
  window) or delete it; anyone else gets `403`.

### 1.3 Reorder admin categories
`POST /api/v1/admin/categories/reorder`

- **Before:** threw a database error (`500`) whenever it tried to persist an
  order change, because the underlying bulk-update statement omitted
  required fields.
- **After:** works normally.

---

## 2. Admin dashboard numbers were wrong

`GET /api/v1/admin/dashboard`

- `current_orders_count` and `alerts.pending_orders` always returned `0`,
  regardless of how many orders were actually awaiting payment (same root
  cause as 1.1 — a stale internal status value).
- **Field names, types, and route are unchanged** — only the value is now
  correct.

---

## 3. Payments — refund and cancellation responses

`POST /api/v1/admin/orders/{order}/refund`

| Status | Meaning |
|---|---|
| `200` | Refund confirmed immediately by Stripe. |
| **`202` (new)** | Stripe accepted the refund but hasn't confirmed it yet. Body: `{ "success": true, "message": "Refund is pending confirmation from Stripe.", "data": { "order_id": ..., "refund_status": "pending" } }`. The order is **not yet** marked refunded — that happens automatically once Stripe confirms (via webhook). Treat `202` as "in progress," not an error; poll or refresh the order later to see the final state. |
| `409` | Order isn't eligible for a refund (unpaid, already refunded, no payment on file). |
| `502` | Stripe itself failed the request. |

Admin single/bulk order cancellation (`POST /api/v1/admin/orders/{id}/status`,
`POST /api/v1/admin/orders/bulk-status`) can now also return:

| Status | Meaning |
|---|---|
| `409` | Cancelling an order whose Stripe Checkout Session already completed — the customer's payment beat the cancel. The order is **left unchanged**; do not treat this as "cancelled anyway." |
| `502` | A Stripe/provider error while trying to close the session. Order unchanged. |

**Business rule, confirmed by the owner:** approving a cancellation on a
*paid* order never triggers an automatic refund. Cancelling and refunding
are two separate admin actions — the UI should not assume "cancelled" implies
"money returned."

`payments.status` (only present when the payment relation is loaded, e.g. on
admin order detail) can now also be `requires_refund` or `partially_refunded`,
in addition to the existing `pending`/`completed`/`failed`/`refunded`.
`requires_refund` means a payment was confirmed for an order that could no
longer accept it (e.g. it was cancelled moments before payment landed) — it
needs a manual admin refund and is not a customer-facing state.

---

## 4. Pricing — free orders, minimum charge, free-shipping math

`POST /api/v1/orders`

- **Zero-total orders** (e.g. a 100%-off coupon): the order is created and
  confirmed **immediately, with no Stripe redirect**. Response includes
  `"checkout_url": null` and `"payment_required": false` — check this flag
  instead of assuming every successful order response has a checkout URL to
  redirect to.
- **Orders priced above 0 but below Stripe's minimum charge (~$0.50):**
  rejected with `422` and error code `minimum_charge`. Show the customer a
  clear "order total is below the minimum" message rather than a generic
  validation error.
- **Free-shipping threshold** is now calculated on `subtotal − discount`
  (i.e., what the customer actually pays before shipping), not the raw
  subtotal. Any "spend $X more for free shipping" progress indicator must use
  the discounted subtotal to match what the backend will actually charge.

---

## 5. Auth — profile update

`PUT /api/v1/auth/me`

- No longer accepts `password`/`password_confirmation` — it silently ignores
  them now (no error). This field never worked correctly (it bypassed the
  current-password check) and password changes must go through the existing
  `POST /api/v1/auth/change-password`, which was already the documented way.
  **If the frontend's profile form submits a password field to this
  endpoint, update it to call `/auth/change-password` instead** — it will no
  longer have any effect here.
- `avatar` upload field removed the same way (it was validated but never
  actually stored by any endpoint — no behavior change from the frontend's
  perspective, it was always a no-op).
- **Changing `email` now resets verification.** After a successful email
  change, the account needs to re-verify (OTP, purpose
  `email_verification`) before it can check out again — checkout already
  requires a verified email. Route the user to the verify-email step
  immediately after a successful email change.

---

## 6. Public forms are now rate-limited

`POST /api/v1/contact-messages` and `POST /api/v1/inspired-leads`

- Now return `429 Too Many Requests` after 5 submissions/minute from the
  same IP (shared limit across both endpoints — 5 total between the two, not
  5 each). Same response shape as the existing `429` on login/OTP.
- A real visitor submitting one of these forms is unaffected. Only
  automated/burst traffic will ever see this.

---

## 7. Unauthenticated API requests always return clean JSON now

Any request to a protected route without a valid session/token used to
occasionally return a raw `500` error page instead of a `401` — specifically
when the request didn't send `Accept: application/json` (most frontend
`fetch`/`axios` calls already do, so this mainly affected direct
browser navigation, some webviews, or bare `curl`/tooling). This is now
always a clean `401` JSON response with the same shape used everywhere else.
No frontend code change needed unless something was working around the old
crash.

---

## Nothing else changed

Everything else covered by this pass (inventory, shipping tracking,
duplicate/out-of-order Stripe webhooks, coupon/quote release on cancellation,
authorization checks, static-analysis cleanup) was either invisible to the
frontend by design or already matched documented behavior — just newly
verified and protected by tests. If something you rely on isn't listed here,
its contract did not change.

Full technical detail and evidence for every item above lives in
`docs/AI/results/` and `docs/AI/reviews/` in this repo, organized by domain
(payments, inventory, pricing, auth, shipping, orders, catalog, security).
