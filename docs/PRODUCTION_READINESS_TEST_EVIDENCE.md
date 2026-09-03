# Production-readiness test evidence

**Run date:** 2026-09-02  
**Database:** isolated MySQL test database (`storeapi_testing`)  
**Final command:** `php artisan test --compact`

```text
...........................................................................................................

Tests:    107 passed (542 assertions)
Duration: 21.60s
```

The suite was run again after the Composer security update, EasyPost hardening, payment-email addition, Pint formatting, and final webhook tests.

## Four explicitly requested tests

### 1. Complete admin endpoint sweep with a customer token

Test: `ProductionReadinessTest::test_every_admin_route_rejects_a_regular_customer`

The test discovers routes from Laravel's registered route collection rather than maintaining a hand-picked list. It selects every URI beginning with `api/v1/admin/`, replaces route parameters with non-existent IDs, invokes the declared HTTP method while authenticated as a regular customer, and asserts every response is 401 or 403.

Result: **PASS**. The current inventory contains 70 admin routes; all 70 also show `auth:sanctum` and `can:admin-access` in `API_V1_ROUTE_MIDDLEWARE.md`.

### 2. IDOR order/resource test

Test: `ProductionReadinessTest::test_customer_cannot_read_or_mutate_another_customers_resources`

Two customers are created. Customer B attempts to use Customer A's identifiers:

- `GET /api/v1/orders/{A-order}` → 404
- `POST /api/v1/orders/{A-order}/cancel` → 404
- `PUT /api/v1/profile/addresses/{A-address}` → 403
- `DELETE /api/v1/profile/addresses/{A-address}` → 403

Result: **PASS**. The test verifies both read and mutation paths without relying on frontend route guards.

### 3. Concurrent last-unit inventory test

Test: `ConcurrentInventoryTest::test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation`

This is not two sequential HTTP calls. The test starts two independent PHP processes, synchronizes them at a start barrier, and makes both reserve the same product's final unit through `OrderInventoryService`. The service opens a database transaction and uses `SELECT ... FOR UPDATE` row locks.

Assertions verify:

- exactly one worker returns success;
- exactly one worker returns insufficient stock;
- final product stock is zero, never negative;
- only one order owns a reservation.

Result: **PASS**.

### 4. Disguised PHP upload test

Tests:

- `SecureImageUploadTest::test_php_payload_renamed_to_jpg_without_an_image_is_rejected`
- `SecureImageUploadTest::test_jpeg_polyglot_gets_a_random_non_executable_name_and_safe_webp_conversions`

The first submits PHP content with a `.jpg` name and image content type; server-side MIME/image validation rejects it. The second appends PHP to a valid JPEG, verifies that storage uses a UUID image filename, and verifies that the generated WebP conversion has no PHP payload.

Result: **PASS**. The same class also confirms the eight-image gallery limit.

## Other notable passing evidence

- Revoked-token replay returns 401.
- Twenty invalid logins trigger 429.
- Password-reset and OTP tokens are single-use.
- Admin demotion takes effect with an existing session.
- Invalid Google audience/unverified Google email are rejected.
- Unverified checkout is rejected.
- Submitted order price/total are ignored in favor of locked database prices.
- Product sort injection, array confusion, and excessive pagination are rejected.
- Signed Stripe event accepts only matching session/amount/currency; replay queues one customer email only.
- Signed expired-session and partial-refund events leave correct order/stock state.
- EasyPost webhook fails closed without a configured secret.
- PHP dependency audit reports zero advisories.
- HTML payloads and emoji persist as inert JSON data.
- Production-style 500 response contains no exception/path/SQL/trace.
- Required database indexes exist and the three requested `EXPLAIN` statements run.

## Reproduction commands

```bash
php artisan test --compact
composer audit --locked
php artisan route:list --path=api/v1 --except-vendor -v
php artisan l5-swagger:generate
php artisan app:production-readiness
```

The last command is expected to fail on a developer machine with local/test settings. It must pass on the deployed production host before sign-off.
