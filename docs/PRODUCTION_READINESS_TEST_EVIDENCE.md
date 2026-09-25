# Production Readiness Test Evidence

**Evidence date:** September 26, 2026  
**Scope:** Local automated backend evidence  
**Database used:** MySQL test database `storeapi_testing`

This file records reproducible automated evidence. It does not claim that production configuration, credentials, data, backups, queue workers, or external-provider accounts were inspected.

## Latest result

```text
php artisan test
Tests: 159 passed (901 assertions)
Duration: 38.29s
```

Laravel Pint and `git diff --check` also passed. PHPUnit forces Telegram credentials to empty values, and external Stripe, EasyPost, mail, HTTP, storage, and notification paths are mocked or faked by their relevant tests.

## D-E1: admin authorization sweep

**Test:** `Tests\Feature\ProductionReadinessTest::test_every_admin_route_rejects_a_regular_customer`  
**Result:** Passed

The test discovers every registered route whose URI starts with `api/v1/admin/`, authenticates as a regular customer, calls each route using its first non-HEAD method, and requires every response to be `401` or `403`. This covers conditionally registered admin routes present in the test process as well as normal admin routes.

Additional evidence:

- Admin demotion takes effect without requiring a new session.
- Generic admin status updates cannot mark orders paid or refunded.
- The complete current route/middleware inventory is in `API_V1_ROUTE_MIDDLEWARE.md`.

## D-E2: IDOR and ownership isolation

**Test:** `Tests\Feature\ProductionReadinessTest::test_customer_cannot_read_or_mutate_another_customers_resources`  
**Result:** Passed

Covered resources and behaviors:

- another customer's order cannot be read or cancelled;
- another customer's address cannot be updated or deleted;
- wishlist count/check/delete remain scoped to the authenticated customer;
- profile reads and updates remain scoped to the authenticated customer.

Q24 adds dedicated coverage proving that `POST /orders/{id}/checkout-session` returns `404` for an order owned by another customer.

## D-E3: last-unit inventory race

**Test:** `Tests\Feature\ConcurrentInventoryTest::test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation`  
**Result:** Passed

The test launches two PHP worker processes against the real MySQL test database and synchronizes their reservation attempt for a product with one unit available. The verified outcome is exactly one `SUCCESS`, one `OUT_OF_STOCK`, final stock of zero, and exactly one order with `stock_reserved_at` set.

This is a real multi-process database concurrency test, not two sequential HTTP calls. It requires `proc_open`; the test explicitly skips if the function is unavailable.

## D-E4: upload hardening

**Test file:** `tests/Feature/SecureImageUploadTest.php`  
**Result:** Four tests passed

Verified behaviors:

- a PHP payload renamed with a `.jpg` suffix is rejected;
- an image/polyglot upload receives a random UUID filename with no executable extension;
- generated public conversions are WebP and do not retain the appended PHP payload;
- a product gallery is limited to eight images;
- the canonical reorder endpoint accepts stable media IDs.

Storage is faked for isolation, while real image decoding/conversion is exercised.

## Additional launch-relevant regression evidence

- Admin product bulk publishing rejects products with incomplete shipping data atomically.
- Public products in inactive categories are hidden and cannot be quoted or reserved from stale carts.
- API validation returns JSON without requiring an `Accept` header.
- Stripe-session creation failure reverses stock, coupon usage, and shipping-quote consumption.
- Checkout resume enforces ownership/state, reuses open sessions, replaces expired sessions, handles order-state races, and ignores stale expiration webhooks.
- Accepted cancellation requests and expired matching Stripe sessions release inventory through the registered order observer.
- Shipment label purchase queues the shipped email exactly once for an idempotent label flow.
- Signed Stripe webhooks verify current session, amount, and currency and are idempotent.
- EasyPost webhooks fail closed if their secret is missing.
- The staging fake shipping driver covers address, rate, label, tracking, and signed-webhook contracts and refuses to resolve in production.
- Admin user/address/wishlist and audit-log responses use explicit resources; audit changes recursively redact sensitive keys.
- Contact-message filters, batch product slugs, the sitemap feed, and guest-wishlist merge have feature contract coverage.
- Stock delta adjustment updates product/variant totals under locks and rejects negative or cross-product changes without writes.
- Bulk order status updates are all-or-nothing and reject duplicate or soft-deleted IDs.

## Reproduction

```bash
php vendor/bin/pint --dirty
php artisan test
git diff --check
php artisan route:list --path=api/v1
```

## Evidence not supplied by this file

The following remain deployment-owner evidence and must not be marked passed from local tests:

- production backup plus a successful restore drill;
- production credential rotation and Sanctum token revocation;
- live Stripe and EasyPost configuration or a verified provider transaction;
- production warehouse origin and package measurements;
- production product-dimension backfill and demo-data cleanup;
- supervised queue-worker health;
- production HTTPS, mail, Telescope, timezone, and cache/queue configuration;
- a clean `php artisan app:production-readiness` run in the production environment.
