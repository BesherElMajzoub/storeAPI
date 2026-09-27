# Otantik Queen — test-mode readiness response

**Checked:** September 27, 2026  
**Release commit in this checkout:** `3cfcc6d` (`main`, `origin/main`, and `ai/quality-pass` all point to the same commit)

## Current result

The quality-pass code is merged in Git, but it is **not the version currently
served by `apis.otantikqueen.com`**. Read-only production checks reproduced the
frontend report:

- `GET /api/v1/health` returns `200` with `version: "unknown"` and
  `deployed_at: null`.
- `GET /api/v1/auth/me` without `Accept` returns `500`.
- `POST /api/v1/orders` without `Accept` returns `500`.
- The live OpenAPI JSON at `/docs` does not contain `payment_required`,
  `minimum_charge`, `free_shipping_reason`, `requires_refund`, or
  `partially_refunded`.

Deployment cannot be performed from this checkout: the repository contains no
deployment workflow or production-server credentials. T1 remains assigned to
the deployment owner. Set `APP_VERSION=3cfcc6d` (or the final release tag) and
`APP_DEPLOYED_AT` to an ISO-8601 UTC deployment timestamp, clear cached config,
regenerate Swagger, restart workers, and verify `/health` before frontend tests.

## Test setup (T1–T10)

### T1 — deploy

**Blocked on deployment access.** Do not begin the frontend lifecycle test
until `/api/v1/health` identifies the new release and the two no-`Accept`
probes return JSON `401` instead of `500`.

### T2 — test location

Confirmed: test on `https://otantikqueen.com` with API
`https://apis.otantikqueen.com`. No domain change is required.

### T3 — Stripe return URLs

The backend constructs exactly:

- `https://otantikqueen.com/orders/{id}?stripe_status=success`
- `https://otantikqueen.com/checkout?stripe_status=cancelled`

This depends on production `FRONTEND_URL=https://otantikqueen.com`.

### T4 — Stripe test webhook

The test Stripe account currently has an enabled production-domain endpoint,
but it is registered at the legacy alias
`https://apis.otantikqueen.com/laravel_project/public/api/v1/webhooks/stripe`,
not the canonical URL requested by the frontend. The alias currently reaches
the controller, but it should be replaced with:

`https://apis.otantikqueen.com/api/v1/webhooks/stripe`

The registered event set is:

- `checkout.session.completed`
- `checkout.session.expired`
- `charge.refunded`

That is the correct success/expiry/refund-confirmation set for this backend.
The code additionally handles `refund.failed`, so subscribe to it as well to
surface an asynchronous refund failure. `refund.updated` is not used by this
implementation. The production host's signing-secret match cannot be proven
from local configuration and must be verified after the canonical endpoint is
created/updated.

### T5 — EasyPost test setup

- Local config resolves `EASYPOST_DRIVER` to `easypost` and has an EasyPost API
  key and webhook secret, but this does not prove production config.
- The test EasyPost account webhook is currently registered at
  `https://apis.otantikqueen.com/api/webhooks/easypost`. That URL returns `404`.
  It must be changed to
  `https://apis.otantikqueen.com/api/v1/webhooks/easypost`, with its webhook
  secret copied to production `EASYPOST_WEBHOOK_SECRET`.
- The local warehouse origin is still the placeholder `123 Main Street` and
  fails readiness. Production must use the real warehouse address before any
  quote is treated as meaningful.
- The EasyPost credentials available here are forbidden from listing carrier
  accounts (`403`), so enabled carriers, including whether the account is
  USPS-only, require an account-owner/dashboard check.
- Test tracking is deterministic; do not try to advance one test label through
  states. Create/use the EasyPost test codes for separate states:
  `EZ2000000002` (`in_transit`), `EZ3000000003`
  (`out_for_delivery`), and `EZ4000000004` (`delivered`). Creating a tracker
  with a test code emits the corresponding test-mode webhook. See the
  [EasyPost Tracker documentation](https://docs.easypost.com/docs/trackers).

EasyPost signs events in `X-Hmac-Signature` using the configured webhook
secret; see the [EasyPost webhook documentation](https://docs.easypost.com/docs/webhooks).

### T6 — email and queue

The local configuration uses SMTP plus the database queue, and the code queues
OTP, order-paid, order-shipped, cancellation-decision, and admin-alert work.
This checkout cannot confirm a worker on the production host. Before testing,
the deployment owner must show a healthy long-running worker consuming at
least `default` and `notifications`, send one OTP, and confirm no failed job.
The Laravel scheduler must also be running for checkout expiry and the
four-hour tracking fallback.

### T7 — test products

Use the admin CSV import so the frontend/admin flow is exercised and cleanup is
deterministic. A five-product import file is provided at
`docs/TEST_PRODUCTS_2026-09-27.csv`. Every SKU begins `TEST-`; it includes a
product over `$100`, a heavy product, and a `$0.25` item. Run `dry_run=true`
first, then commit the same file. Images can be added through the admin image
endpoints after import.

### T8 — personal admin

**Blocked on production admin authority.** Create a named, least-privilege
admin for the tester through the normal admin-user process; do not share or
copy the existing credential. Remove or demote it after acceptance testing.

### T9 — readiness command with test keys

`app:production-readiness` intentionally requires `sk_live_`; therefore the
`Stripe live secret` row remains red with `sk_test_` during test mode. The
local run also fails for the local environment, enabled debug, HTTP app and
frontend URLs, placeholder warehouse, enabled Telescope, and known demo
accounts/catalogue. Those local rows are not evidence about the server.

For the live test phase, the expected deliberate failure is only
`Stripe live secret`. Any other red row must be fixed or explicitly explained.
The command masks secrets and is non-destructive.

### T10 — rate limits

All client routes also share the general limit of **120/minute** per
authenticated user or IP. Additional endpoint limits are:

| Flow | Limit |
|---|---|
| Login / Google login | 10/minute per IP and 5/minute per normalized email |
| OTP send and verify | 5/minute per IP and 3/minute per normalized email |
| Forgot password | 5/minute per IP and 5/hour per normalized email |
| Reset password | 5/minute per IP and 5/hour per normalized email |
| Public order tracking | 5/minute per IP and 3/minute per order-number/email fingerprint |

The first exhausted applicable bucket returns `429`.

## Contract answers (R1–R7)

1. **R1 — free-shipping basis:** `subtotal - discount`, inclusive against the
   configured threshold. The stale raw-subtotal sentence in the remediation
   plan has been corrected.
2. **R2 — rates when shipping is free:** `/shipping/rates` returns the real
   carrier quote. `/orders` applies eligibility and stores customer
   `shipping_cost: 0`, while preserving the quote as admin-only
   `carrier_shipping_cost`.
3. **R3 — direct cancellation:** the window is measured from `created_at` and
   closes when elapsed time reaches three hours. Direct cancellation is only
   for `status=pending_payment` plus `payment_status=unpaid`; legacy `pending`
   is not directly cancellable.
4. **R4 — order detail:** `GET /orders/{id}` includes
   `free_shipping_reason`. It does not include `checkout_url` or
   `payment_required`; those are create-order response fields. To resume an
   unpaid order, call `POST /orders/{id}/checkout-session` for a fresh/current
   checkout URL.
5. **R5 — refund confirmation:** a `202` has no fixed completion time. The
   order remains unchanged until Stripe delivers `charge.refunded`; frontend
   polling should use bounded backoff and allow later refresh/manual follow-up.
6. **R6 — geo/me:** confirmed US-only for launch. Shipping normalization
   rejects destinations outside the configured `US` country list.
7. **R7 — minimum charge:** default exactly `$0.50`, configurable via
   `STRIPE_MINIMUM_CHARGE`, applied to final total
   `subtotal - discount + shipping + tax`. Zero is allowed; totals greater than
   zero and below the threshold return `422` with
   `errors.code: "minimum_charge"`.

## Test-to-live switch

The proposed sequence is sound, with these safeguards:

1. Stop test traffic and take a verified database backup.
2. Purge only records tied to `TEST-` SKUs and named test accounts in a reviewed
   transaction; retain provider-side test history and audit evidence rather
   than attempting to turn test objects into live objects.
3. Replace both API keys **and** mode-specific webhook endpoints/secrets.
4. Set the real warehouse and confirm live carrier accounts.
5. Clear configuration cache, restart queue workers, regenerate Swagger, and
   run `app:production-readiness`; every row must pass in live mode.
6. Perform one real low-value purchase, label/tracking check, and full refund,
   then verify webhook delivery, customer mail, worker health, stock, and the
   payment ledger.

## Local verification completed

- Regenerated `storage/api-docs/api-docs.json`.
- Added the missing OpenAPI fields/statuses and documented the `422`
  `minimum_charge` shape.
- Added `payment_required: true` to normal paid-order creation (zero-total
  creation already returns `false`).
- Broad release-focused suite before the contract patch: **100 tests passed,
  487 assertions**. Directly affected suite after the patch: **35 tests passed,
  141 assertions**. Targeted PHPStan analysis also passes with no errors.
