# Batch B1 — D4 Inventory + D3 Pricing: owner decisions to implement

Read `00-README.md` → "Batch mode". The C1–C3 commit (`1ad1540`) is received
and will be verified in the D4 review.

## D4 — decisions (implement them, no longer NEEDS-DECISION)

**D4-OBS-001 → abandoned checkout releases stock after 30 minutes, with a
safety net.**
- `createCheckoutSession`: set `expires_at` = now + 30 minutes (Stripe's
  minimum). Make the value configurable
  (`services.stripe.checkout_expires_minutes`, default 30, clamp 30–1440).
- Add a scheduled command (every 10 min, registered in `routes/console.php`)
  that finds `pending_payment` / `unpaid` orders whose session should have
  expired more than 5 minutes ago. For each order:
  1. Retrieve the Stripe session.
  2. `open` → expire it, then cancel the order.
  3. `expired` → cancel the order.
  4. `complete` → do nothing (the webhook owns it) and log a warning.
  5. Provider error → skip and retry next run.

  Cancel with the same locked, conditional pattern as the expiry webhook, so
  stock is released once. The command must be safe to run twice at the same
  time (`withoutOverlapping` plus conditional updates).
- Tests: open → expired + cancelled + stock back; complete → untouched;
  provider error → untouched; run twice → stock released once.
- Add it to the ops handover: the server needs the Laravel scheduler cron.

**D4-OBS-002 → restock only if the goods never shipped.**
- Release stock on `cancelled`/`refunded` only when the order was never
  shipped. Use a reliable marker (e.g. `shipped_at` or a shipment record). If
  none exists, decide in the result file which field proves "never shipped".
- A refund of a `shipped`/`delivered` order does not touch stock. The admin
  adds stock manually when goods come back (existing adjust-stock endpoint,
  audited).
- Tests: refund before shipping → restocked once; refund after shipping or
  delivery → stock unchanged; the admin adjustment afterwards works.
- Frontend impact: none (behaviour only). Say so in the report.

## D3 — decisions (implement them)

**L-PAY-010 → total = 0 skips Stripe; 0 < total < Stripe minimum is blocked.**
- **Total exactly 0** (after discount, including shipping): create the order
  directly as paid with **no Stripe session**:
  - `processing` / `paid`
  - a `payments` row with provider `none` (or `free`), amount 0, status
    `completed`
  - stock reserved once, coupon usage recorded once
  - paid mail and admin alert sent once

  Choose the response shape so the frontend knows no redirect is needed, e.g.
  `checkout_url: null` plus `payment_required: false`. List it under
  `## Frontend impact`.
- **0 < total < minimum** (configurable, default 0.50 in the store currency):
  return 422 with a clear message and no order created, no stock reserved and
  no coupon used.
- Abuse guard: a 0-total order still goes through every coupon limit (global,
  per user, expiry). Test "second use of a single-use 100% coupon is
  rejected".
- The admin refund endpoint on a free order returns 409 (there is no
  PaymentIntent). Test it.

**D3-FS-01 → the free-shipping threshold uses subtotal − discount.**
- Check the current code. If it differs, fix it. Test at threshold − 0.01,
  exactly at the threshold, and when a coupon pushes the order under the
  threshold.

## Carry on
After B1, start B2 immediately (see batch table).
