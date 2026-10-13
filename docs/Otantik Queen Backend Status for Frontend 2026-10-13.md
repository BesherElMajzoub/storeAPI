# Otantik Queen · Backend status for the frontend, 13 Oct 2026

Companion to "Otantik Queen Handover List.md". It lists every backend item (B1–B4, S4–S7, L1, L2, L4), says what is done, and gives the frontend a check for each one it can run after deploy.

**Important:** B1–B3 are fixed in code and covered by tests (396 backend tests pass), but they are **not committed or deployed yet**. Nothing below counts as "live" until the API is deployed and `php artisan migrate` has been run.

## Stage 1 · backend items

### B1 · Express pinned to UPS 2nd Day Air — DONE (code), not deployed
- `POST /api/v1/shipping/rates` now returns UPS **2nd Day Air** as `express` whenever UPS quotes it. 2nd Day Air AM and Next Day Air are no longer picked over it. If UPS does not quote it, the old rule applies (fastest non-Ground UPS rate).
- Frontend check: on the test address, the `express` rate has service `2ndDayAir` and is cheaper than the old $27.76. Delivery days will read 2, not 1, so copy that promises "next day" must not be hard-coded.
- Still open (owner): confirm the decision message of 12 Oct was actually sent.

### B2 · Failed refund no longer reads `payment_status: refunded` — DONE (code), not deployed
- Cause: Stripe sends `charge.refunded` when the refund is created, so the order showed "refunded" before the refund failed.
- Now, on `refund.failed` (or `refund.updated` / `charge.refund.updated` with status failed), the order returns to `payment_status: paid`, `refunded_amount: 0`, `refunded_at: null`. A cancelled order stays `cancelled`. `refund.status` is `failed` with `failure_code` and the message.
- Frontend check (this also closes F4): an order whose refund failed must show **only** "Refund failed", never "Refunded" beside it. Orders already in that state (e.g. ORD-PEQ6SARTFB) are not fixed retroactively; retry the refund or correct them by hand.

### B3 · Retried refund failure is recorded — FIXED (code), not deployed; one Stripe check left
- Cause: the refund used a fixed idempotency key, so Stripe replayed the first refund on Retry instead of creating a new one. The new key includes a failure counter (new column `orders.refund_failures`).
- Frontend check: after a failed refund, press Retry in the admin; the order should go to `pending`, then `succeeded` or `failed` by webhook, matching what Stripe shows.
- Still open (backend/owner): look in the Stripe dashboard at the retried refund on ORD-PEQ6SARTFB to see what it really did.

### B4 · Catalogue for the client's test — OPEN (owner decision)
- Public data is almost all `demo-*`, with QA products among it. Backend cannot proceed until the owner chooses: test on demo data, or load part of the real catalogue first.

## Stage 2 · server and launch items — all OPEN (done on the Contabo server, not in code)

| Item | What | Breaks payments if missed |
|---|---|---|
| S4 | Scheduler cron (captures card holds) and queue workers under Supervisor | Yes |
| S5 | Stripe webhook endpoint and signing secret, EasyPost webhook, Google sign-in origins and redirects, CORS origins, `APP_URL` | Yes |
| S6 | Move database and media; SPF, DKIM, DMARC; nightly off-server backups | No |
| S7 | Firewall, SSH keys only, automatic updates, rate limits, uptime monitoring | No |
| L1 | `ORDER_DIRECT_CANCEL_WINDOW_MINUTES` must be 180 in production (10 on the test server) | No |
| L2 | Stripe and EasyPost live keys, one real low-value order, refunded after | Yes |
| L4 | Product availability flag ("V2") for PDP-03 | No |

Notes for the frontend: S5 and CORS decide whether the browser can call the API at all, so the API origin must be agreed before the frontend build and CSP are finalised (handover item S3).

## How the frontend can confirm the backend is good (after deploy)
1. `GET /api/v1/shipping/rates` on the test address: `express` is `2ndDayAir`.
2. Place and cancel an order, then force a refund failure in Stripe test mode: the admin shows only "Refund failed", `payment_status` is `paid`.
3. Press Retry on that order: a new refund is created and its outcome is shown correctly.
4. Admin status menu: moves the server refuses (F5) are not offered; the backend is unchanged for this item.

## Summary
- Done in code, waiting for deploy: B1, B2, B3.
- Waiting on the owner: B4, plus the Stripe check for B3.
- Waiting on the Contabo move: S4, S5, S6, S7, L1, L2, L4.
