# Backend reply to the Handover List · 13 Oct 2026

Reply to "Otantik Queen Handover List.md" (status on 13 Oct 2026). Item IDs match that list. The code below is in `bf094ee` on `main`. Nothing counts as live until the API is deployed, `php artisan migrate` has run, and the queue workers are restarted (`php artisan queue:restart`). 396 backend tests pass.

## Stage 1 · backend items

| ID | Status | Summary |
|---|---|---|
| B1 | Done in code | Express is pinned to UPS 2nd Day Air. |
| B2 | Done in code | A failed refund no longer reads `payment_status: refunded`. |
| B3 | Fixed in code, one Stripe check open | The Retry reused the first refund; now it creates a new one. |
| B4 | Open, owner decision | Backend waits for demo data or real catalogue. |

### B1 · Pin Express to UPS 2nd Day Air
`POST /api/v1/shipping/rates` now returns UPS `2ndDayAir` as `express` whenever UPS quotes it. `2ndDayAirAM` and Next Day Air are not chosen over it. If UPS does not quote it, the old rule applies (fastest non-Ground UPS rate). Express delivery days will read 2, so the frontend must not hard-code "next day" copy.
Open: the owner confirms the 12 Oct decision message was sent.

### B2 · Failed refund still read `payment_status: refunded`
Cause: Stripe sends `charge.refunded` when a refund is created, so the order read "refunded" before the refund failed.
Now, on `refund.failed` (and `refund.updated` / `charge.refund.updated` with status `failed`) the order goes back to `payment_status: paid`, `refunded_amount: 0`, `refunded_at: null`. A cancelled order stays `cancelled`. `refund.status` is `failed` with `failure_code` and message, as before.
This is the cause behind F4. After deploy the admin never receives "refunded" and "failed" together. Orders already in that state (ORD-PEQ6SARTFB) are not corrected retroactively: retry the refund or fix them by hand.

### B3 · Was the retried refund's failure recorded?
Cause found in code: the refund was created with the fixed idempotency key `refund-order-{id}`, so Stripe replayed the first refund on Retry instead of creating a new one. That matches the symptom (the "new" refund still read `succeeded`).
Fix: new column `orders.refund_failures` counts failures and enters the key (`refund-order-12-1`). Each Retry after a failure creates a real new refund, and a later failure is recorded on the order.
Open: the ORD-PEQ6SARTFB refund has not been checked in the Stripe dashboard. This is a reading of the code, not something seen live.

### B4 · Catalogue for the client's test
No backend change. Waiting for the owner: test on demo data, or load part of the real catalogue first. Either choice needs backend work (M).

## Frontend items that depend on the backend
- **F1:** no backend change needed. The coupon API already supports free shipping; the admin only needs the push.
- **F4:** fixed at the source by B2. The frontend change on the branch stays as a safeguard.
- **F5:** the server already rejects the invalid status moves; unchanged.

## Stage 2 · server and launch (all open, done on Contabo)

| ID | Item | Breaks payments |
|---|---|---|
| S4 | Scheduler cron and queue workers under Supervisor | Yes |
| S5 | Stripe webhook endpoint + new signing secret, EasyPost webhook, Google sign-in origins and redirects, CORS origins, `APP_URL` | Yes |
| S6 | Move database and media; SPF, DKIM, DMARC; nightly off-server backups | No |
| S7 | Firewall, SSH keys only, automatic updates, rate limits, uptime monitoring | No |
| L1 | `ORDER_DIRECT_CANCEL_WINDOW_MINUTES=180` in production (`.env.example` already says so; the test server uses 10) | No |
| L2 | Stripe and EasyPost live keys; one real low-value order, refunded after | Yes |
| L3 | Real catalogue in, test data out (backend side: delete demo/QA products, test orders, coupons, accounts) | No |
| L4 | Product availability flag ("V2") for PDP-03 | No |

The frontend needs from the backend for S1–S3: the final API address (it sets the CORS origin and the frontend CSP), and the exact `www` redirect rule so the API keeps accepting the browser origin.

## Deploy steps for this release
1. Pull `main` on the API server.
2. `php artisan migrate` (adds `orders.refund_failures`; without it, a failed refund or a retry errors).
3. `php artisan queue:restart`.

## How the frontend can check it
1. `POST /api/v1/shipping/rates` on the test address: `express` is `2ndDayAir`.
2. Cancel a paid order and make its refund fail in Stripe test mode: the admin shows only "Refund failed", and `payment_status` is `paid`.
3. Press Retry on that order: a new refund is created and its outcome shows correctly.
