# Production Deployment Checklist

**Prepared:** September 25, 2026  
**Last updated:** September 26, 2026  
**Release state:** Not approved until every blocking item below has evidence

Never paste secret values into this file, terminal captures, tickets, or chat. Readiness output should remain masked.

## Owners

| Owner | Responsibility |
|---|---|
| Backend Team | Code, migrations, automated tests, API smoke tests, readiness command support |
| Deployment Owner / DevOps | Secrets, infrastructure, deployment, queue workers, HTTPS, mail, backup and rollback |
| Catalog Owner | Real product weight and dimensions |
| Frontend Team | Feature flags and post-deploy integration smoke |

## Blocking pre-deploy gates

- [ ] Deployment Owner: create a production database backup and complete or reference a successful restore drill.
- [ ] Deployment Owner: rotate/delete any shared or demo admin accounts; revoke their Sanctum tokens.
- [ ] Deployment Owner: rotate any exposed external-service credentials, including the previously exposed Telegram credential.
- [ ] Catalog Owner: provide approved `weight_oz`, `length_in`, `width_in`, and `height_in` for every published product.
- [ ] Backend/Catalog: backfill those values without fabricated universal defaults.
- [ ] Deployment Owner: configure production Stripe secret and webhook secret.
- [ ] Deployment Owner: configure EasyPost key, webhook secret, real warehouse origin, and approved package presets.
- [ ] Deployment Owner: confirm `EASYPOST_DRIVER=easypost`; the staging-only `fake` driver is rejected by production and by the readiness gate.
- [ ] Deployment Owner: confirm durable queue workers and a production mail transport.
- [ ] Product Owner: record decisions for tax and free-shipping behavior, or explicitly approve no tax/free-shipping feature for this release.

## Code verification before deployment

```bash
composer install --prefer-dist
php vendor/bin/pint --test
php artisan test
git diff --check
php artisan route:list --path=api/v1
```

Build the production artifact with `composer install --no-dev --prefer-dist --optimize-autoloader` only after the development/CI verification above has passed.

- [x] Local suite passed: 159 tests, 901 assertions.
- [x] Q24 checkout resume route is registered and covered.
- [x] Shipping/product import/payment contracts are documented.
- [ ] CI passes from a clean checkout of the exact release commit.
- [ ] Release commit/tag and `APP_VERSION` are recorded.
- [ ] `APP_DEPLOYED_AT` is set by the deployment process.

## Deployment sequence

1. Put the application into the approved maintenance/deployment mode if required by the platform.
2. Deploy the exact reviewed release artifact.
3. Apply production environment variables through the secret manager; do not edit or echo secrets in shell history.
4. Run database migrations with the normal production migration mechanism.
5. Clear and rebuild framework caches:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

6. Restart/reload PHP workers and supervised queue workers using the platform's normal zero-downtime procedure.
7. Run the non-destructive readiness gate:

```bash
php artisan app:production-readiness
```

8. If any readiness row fails, stop the release and follow the rollback section. Do not enable frontend feature flags.

## Production data verification

- [ ] `No known demo accounts` passes.
- [ ] `No known demo catalogue` passes.
- [ ] `Published products have shipping data` passes with zero missing.
- [ ] Warehouse origin is real and US country/config matches the shipping policy.
- [ ] Package presets match operational packaging and weight limits.
- [ ] Any demo-data deletion has before/after counts and backup reference.

## Post-deploy smoke tests

Use approved test accounts/data and avoid destructive tests against customer records.

- [ ] `GET /api/v1/health` returns the expected `version` and `deployed_at`.
- [ ] An intentionally invalid `/api/v1/*` request without `Accept` returns JSON `422`, never `302`.
- [ ] Public product list excludes products in inactive categories.
- [ ] `GET /products?in_stock=true` and `false` both return `200` with correct filtering.
- [ ] Regular customer receives `403` from a representative admin route.
- [ ] Admin product bulk-publish rejects a draft product missing any shipping measurement.
- [ ] Admin stock delta adjusts a disposable product/variant without overwriting concurrent stock.
- [ ] Admin bulk order status rejects a mixed valid/invalid batch with zero writes.
- [ ] Admin audit-log endpoint is inaccessible to a customer and redacts sensitive change keys.
- [ ] Contact-message `status=new` and search filters return the expected work queue.
- [ ] EasyPost address verification and item-based rate quote succeed with approved test data.
- [ ] Returned shipping rate includes a future `expires_at`.
- [ ] Approved sandbox lifecycle succeeds: quote, order, Stripe payment, webhook, label, shipped email, and tracking.
- [ ] Returning to an unpaid order reuses its open Checkout Session through `POST /orders/{id}/checkout-session`.
- [ ] Product sitemap and comma-separated slug feed expose only published products in active categories.
- [ ] Guest wishlist merge is idempotent and rejects unavailable products.
- [ ] Public tracking returns only the documented safe snapshot.
- [ ] Queue dashboard/supervisor shows notification jobs processing without repeated failures.
- [ ] Application logs contain no secret values, stack traces in API responses, or unexpected provider errors.

## Frontend feature flags

- [ ] Set `VITE_CHECKOUT_SERVER_RATES=true` only after dimension, EasyPost, lifecycle, and readiness gates pass.
- [ ] Set `VITE_ADMIN_PRODUCT_ENDPOINTS_V2=true` only after admin product smoke tests pass on a disposable draft product.
- [ ] Frontend integrates Q24 according to `PAYMENT_CHECKOUT_CONTRACT.md`.

## Monitoring after release

- [ ] Watch 4xx/5xx rates for order, shipping, webhook, and admin-product endpoints.
- [ ] Watch queue failures and mail delivery.
- [ ] Confirm Stripe and EasyPost webhook delivery dashboards show successful responses.
- [ ] Confirm inventory, coupon usage, and quote consumption remain consistent on a controlled failed-checkout test.
- [ ] Keep the pre-deploy backup until the agreed stability window has elapsed.

## Rollback gate

Rollback the code release if readiness or smoke tests fail, webhook handling regresses, or checkout/shipping creates inconsistent state.

1. Disable frontend flags first.
2. Restore the previous application artifact and environment configuration through the deployment platform.
3. Rebuild caches and restart workers.
4. Re-run health and safe read-only smoke tests.
5. Do not reverse migrations or restore the database blindly. Review migration/data changes and use the pre-approved database rollback procedure.
6. Restore the database only when the incident commander confirms data corruption and the restore point/scope.
7. Record the failed gate, timestamps, affected transactions, and recovery result without secrets.

## Release approval

| Approval | Name/reference | Date | Status |
|---|---|---|---|
| Backend |  |  | Pending |
| Deployment / DevOps |  |  | Pending |
| Catalog |  |  | Pending |
| Frontend |  |  | Pending |
| Product |  |  | Pending |
