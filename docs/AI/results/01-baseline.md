# 01 Baseline — results

## Starting point (branch, sha, php version)

- Base branch / SHA: `main` at `a8ca1a809ced887a9a3e1ab8b92ddc69ea86cec1` (`fix`).
- Working branch: `ai/quality-pass`.
- Workflow documentation commit: `ba5534ef02559973513bf288e761e0ffbc8717fb`.
- PHP: `8.5.2`.

```text
./composer.json is valid
PHP 8.5.2 (cli) (built: Jan 13 2026 21:54:57) (ZTS Visual C++ 2022 x64)
```

`composer install --no-interaction` completed from the lock file with no package changes.

## Test suite (3 runs, counts, failures, flaky tests)

```text
Run 1
Tests:    184 passed (1038 assertions)
Duration: 76.20s

Run 2
Tests:    184 passed (1038 assertions)
Duration: 58.80s

Run 3
Tests:    184 passed (1038 assertions)
Duration: 52.09s
```

No failures, skips, or flaky tests observed.

After Pint:

```text
Tests:    184 passed (1038 assertions)
Duration: 61.11s
```

## Coverage

```text
ERROR  Code coverage driver not available. Did you install Xdebug or PCOV?
```

Neither Xdebug nor PCOV is installed. No coverage driver was added in this phase.

## Pint

`vendor/bin/pint --test` initially failed. Pint changed 105 files and the changes were committed separately as `9a4dcdc` (`style: apply pint`).

```text
[ai/quality-pass 9a4dcdc] style: apply pint
 105 files changed, 1314 insertions(+), 1307 deletions(-)

{"tool":"pint","result":"passed"}
```

## Larastan (error count by file, baseline created)

Larastan `^3.12` / PHPStan `2.2.16` were added. `phpstan.neon` analyzes `app/` at level 5. The initial analysis had 357 errors in 55 files; the generated `phpstan-baseline.neon` is committed in `835c9ae` (`build: configure larastan baseline`).

```text
[OK] Baseline generated with 357 errors.
[OK] No errors
```

```text
app/Console/Commands/MigrateImagesToSpatie.php: 2
app/Http/Controllers/Api/StripeWebhookController.php: 4
app/Http/Controllers/Api/V1/Admin/AdminAnalyticsController.php: 4
app/Http/Controllers/Api/V1/Admin/AuditLogController.php: 1
app/Http/Controllers/Api/V1/Admin/CancellationRequestController.php: 9
app/Http/Controllers/Api/V1/Admin/DashboardController.php: 1
app/Http/Controllers/Api/V1/Admin/OrderController.php: 12
app/Http/Controllers/Api/V1/Admin/ProductController.php: 10
app/Http/Controllers/Api/V1/Admin/ReviewController.php: 6
app/Http/Controllers/Api/V1/Admin/ShippingController.php: 1
app/Http/Controllers/Api/V1/Admin/TelescopeApiController.php: 12
app/Http/Controllers/Api/V1/Admin/UserController.php: 4
app/Http/Controllers/Api/V1/Auth/AuthController.php: 6
app/Http/Controllers/Api/V1/CategoryController.php: 1
app/Http/Controllers/Api/V1/OrderController.php: 1
app/Http/Controllers/Api/V1/ProductController.php: 4
app/Http/Controllers/Api/V1/PublicOrderTrackingController.php: 1
app/Http/Controllers/Api/V1/ReviewController.php: 5
app/Http/Controllers/Controller.php: 1
app/Http/Middleware/TrackVisitorSession.php: 1
app/Http/Resources/AddressResource.php: 15
app/Http/Resources/AdminOrderResource.php: 6
app/Http/Resources/AdminUserResource.php: 11
app/Http/Resources/AdminWishlistItemResource.php: 1
app/Http/Resources/AuditLogResource.php: 5
app/Http/Resources/CancellationRequestResource.php: 6
app/Http/Resources/CategoryBasicResource.php: 3
app/Http/Resources/CategoryCardResource.php: 4
app/Http/Resources/CategoryDetailResource.php: 10
app/Http/Resources/CategoryResource.php: 4
app/Http/Resources/CouponResource.php: 19
app/Http/Resources/CouponUsageResource.php: 7
app/Http/Resources/OrderItemResource.php: 14
app/Http/Resources/OrderResource.php: 29
app/Http/Resources/ProductCardResource.php: 14
app/Http/Resources/ProductDetailResource.php: 25
app/Http/Resources/ProductGalleryImageResource.php: 10
app/Http/Resources/ProductResource.php: 18
app/Http/Resources/ProductVariantResource.php: 10
app/Http/Resources/ReviewResource.php: 13
app/Http/Resources/ShippingRateResource.php: 5
app/Http/Resources/WishlistItemResource.php: 2
app/Mail/CancellationRequestDecidedMail.php: 1
app/Mail/OrderPaidMail.php: 1
app/Mail/OrderShippedMail.php: 1
app/Models/Category.php: 3
app/Models/Coupon.php: 6
app/Models/Product.php: 4
app/Services/EasyPostService.php: 1
app/Services/GoogleAuthService.php: 2
app/Services/OrderInventoryService.php: 5
app/Services/OtpService.php: 3
app/Services/ShippingQuoteService.php: 6
app/Services/StripeCheckoutService.php: 1
app/Services/WishlistAnalyticsService.php: 6
```

## Composer audit

```text
No security vulnerability advisories found.
```

## Routes (total, suspicious unauthenticated)

Baseline saved to `docs/AI/results/routes-baseline.json`.

```text
TOTAL_API_ROUTES=133
SUSPICIOUS_UNAUTHENTICATED=0
```

The scan covers admin, order (except deliberately public `POST api/v1/orders/track`), profile, and wishlist routes.

## Production readiness command output

Signature: `app:production-readiness`.

The command returned non-zero in the local environment. Its secret-bearing details are redacted; no secret values are recorded here.

```text
Production environment: FAIL (local)
Debug disabled: FAIL (enabled)
HTTPS application URL: PASS
HTTPS frontend URL: FAIL
Application key configured: PASS
Sanctum token expiry: PASS
Stripe live secret: FAIL ([REDACTED])
Stripe webhook secret: PASS ([REDACTED])
Real shipping driver: PASS
EasyPost configured: PASS
Warehouse origin configured: FAIL
Shipping packages configured: PASS
Durable queue enabled: PASS
Production mailer enabled: PASS
Telescope disabled: FAIL (enabled)
UTC timestamps: PASS
MySQL utf8mb4: PASS
No known demo accounts: FAIL (24 found)
No known demo catalogue: FAIL (132 found)
Published products have shipping data: PASS
No test/debug routes: PASS
Production readiness checks failed. No data was changed.
```

## migrate:fresh --seed

```text
Dropping all tables ... DONE
Running migrations ... DONE
Seeding database ... DONE
DemoAccessSeeder ... DONE
DemoCatalogSeeder ... DONE
DemoCommerceSeeder ... DONE
DemoEngagementSeeder ... DONE
DemoAnalyticsSeeder ... DONE
```

`php artisan migrate:fresh --seed --env=testing` completed successfully against the test database.

## Coverage map (service/controller → test file | NONE)

This is a conservative static map: a named feature test is listed only where its route/domain directly exercises the component; `NONE` means no dedicated/direct test was identified and must be closed in later phases.

```text
Services
CategoryService → NONE
CouponService → tests/Feature/CouponTest.php, tests/Feature/AdminCouponTest.php
EasyPostService → tests/Feature/EasyPostShippingTest.php
FakeEasyPostService → tests/Feature/FakeShippingDriverTest.php
FreeShippingService → tests/Feature/FreeShippingTest.php
GeoapifyService → tests/Feature/GeoapifyAddressControllerTest.php
GeoLocationService → tests/Feature/GeoapifyAddressControllerTest.php
GoogleAuthService → tests/Feature/ProductionReadinessTest.php (Google token audience and verified-email cases)
GooglePlacesService → tests/Feature/AddressControllerTest.php
OrderInventoryService → tests/Feature/OrderStockTest.php, tests/Feature/ConcurrentInventoryTest.php, tests/Feature/CancellationRequestInventoryTest.php
OtpService → tests/Feature/AuthTest.php, tests/Feature/SpaAuthTest.php
ProductImportService → tests/Feature/ProductImportTest.php
ProductService → tests/Feature/PublicProductContractTest.php, tests/Feature/AdminProductContractTest.php
ReviewService → tests/Feature/ReviewTest.php
ShipmentTrackingService → tests/Feature/EasyPostShippingTest.php
ShippingQuoteService → tests/Feature/ShippingContractTest.php, tests/Feature/FakeShippingDriverTest.php
SkuGeneratorService → NONE
StripeCheckoutService → tests/Feature/StripeCheckoutTest.php
TelegramNotifier → tests/Feature/TelegramNotificationTest.php
WishlistAnalyticsService → tests/Feature/WishlistAnalyticsTest.php

Controllers
Api/StripeWebhookController → tests/Feature/StripeWebhookSecurityTest.php
Api/V1/AddressController → tests/Feature/AddressControllerTest.php, tests/Feature/GeoapifyAddressControllerTest.php
Api/V1/AnalyticsEventController → tests/Feature/AnalyticsTest.php
Api/V1/CategoryController → tests/Feature/PublicProductContractTest.php
Api/V1/ContactMessageController → tests/Feature/ApiHygieneTest.php, tests/Feature/TelegramNotificationTest.php
Api/V1/CouponController → tests/Feature/CouponTest.php
Api/V1/EasyPostWebhookController → tests/Feature/EasyPostShippingTest.php
Api/V1/HealthController → tests/Feature/HealthEndpointTest.php
Api/V1/InspiredLeadController → NONE
Api/V1/OrderController → tests/Feature/OrderStockTest.php, tests/Feature/StripeCheckoutTest.php, tests/Feature/CouponTest.php
Api/V1/ProductController → tests/Feature/PublicProductContractTest.php
Api/V1/PublicOrderTrackingController → tests/Feature/PublicOrderTrackingTest.php
Api/V1/ReviewController → tests/Feature/ReviewTest.php
Api/V1/ShippingController → tests/Feature/ShippingContractTest.php
Api/V1/WishlistController → tests/Feature/WishlistAnalyticsTest.php
Api/V1/Admin/AdminAnalyticsController → tests/Feature/AnalyticsTest.php
Api/V1/Admin/AuditLogController → tests/Feature/AdminAuditLogTest.php
Api/V1/Admin/CancellationRequestController → tests/Feature/CancellationRequestInventoryTest.php
Api/V1/Admin/CategoryController → NONE
Api/V1/Admin/ContactMessageController → tests/Feature/AdminContactMessageContractTest.php
Api/V1/Admin/CouponController → tests/Feature/AdminCouponTest.php
Api/V1/Admin/DashboardController → tests/Feature/ProductionReadinessTest.php (authorization only; dashboard metrics remain untested)
Api/V1/Admin/GeoController → NONE
Api/V1/Admin/InspiredLeadController → NONE
Api/V1/Admin/MediaController → tests/Feature/SecureImageUploadTest.php
Api/V1/Admin/OrderController → tests/Feature/AdminOrderBulkStatusTest.php
Api/V1/Admin/ProductController → tests/Feature/AdminProductContractTest.php, tests/Feature/ProductImportTest.php
Api/V1/Admin/ReviewController → tests/Feature/ReviewTest.php
Api/V1/Admin/SettingController → tests/Feature/ShippingContractTest.php
Api/V1/Admin/ShippingController → tests/Feature/EasyPostShippingTest.php
Api/V1/Admin/SkuController → NONE
Api/V1/Admin/TelescopeApiController → NONE
Api/V1/Admin/UserController → tests/Feature/AdminUserContractTest.php
Api/V1/Admin/WishlistAnalyticsController → tests/Feature/WishlistAnalyticsTest.php
Api/V1/Auth/AuthController → tests/Feature/AuthTest.php, tests/Feature/SpaAuthTest.php
```

`app/Http/Controllers/Controller.php` is the framework base controller and has no dedicated test target.

## Top 10 risk areas (for phase 02)

1. Stripe webhook payment confirmation, amount validation, idempotency, and state regression (D5).
2. Inventory reservation/release under concurrent checkout, cancellation, and refund (D4).
3. Server-side pricing, rounding, coupon limits, and free-shipping threshold (D3).
4. Authentication: OTP throttling/expiry, password reset, Google account linking, and disabled users (D1).
5. Shipping quote fingerprints/expiry and EasyPost webhook authenticity/idempotency (D6).
6. Order transition table, bulk update partial failures, and exactly-once side effects (D7).
7. Missing direct tests for public contact and inspired-lead writes plus several admin endpoints (D8).
8. Static-analysis concentration in API resources (231 of 357 baseline errors), risking type/interface drift (Phase 03).
9. Catalog variants, CSV import idempotency, SKU concurrency, and review recalculation (D2).
10. Admin authorization boundaries, especially dashboard, geo, SKU, and Telescope endpoints (D1/D8/Security).

## Final phase check

```text
Tests:    184 passed (1038 assertions)
Duration: 66.43s

{"tool":"pint","result":"passed"}
[OK] No errors
```

## Round 2 response

### R1 — UTF-8 route baseline and count definition

Regenerated `routes-baseline.json` as UTF-8 without BOM using the Laravel command output and verified it with PHP.

```text
Command: php artisan route:list --path=api --json
ROUTES=176
First three bytes: 5B-7B-22
```

`176` is the canonical baseline count for Phase 06: every route returned by `--path=api`, including 133 application routes whose URI starts with `api/` and 43 `telescope/telescope-api/*` routes. The saved JSON is valid UTF-8 and `json_decode(..., JSON_THROW_ON_ERROR)` succeeds.

### R2 — all routes without `auth:sanctum`

There are 71 routes without `auth:sanctum`: 28 non-Telescope routes below and 43 Telescope API routes. This table records the intended public reason, not an approval of its security posture; items flagged for D5, D6, or Phase 05 remain in scope there.

| Route | Reason it is currently public |
|---|---|
| `GET api/documentation` | Swagger documentation endpoint; Phase 05 must decide production exposure. |
| `GET api/oauth2-callback` | Swagger OAuth callback endpoint; Phase 05 must decide production exposure. |
| `GET api/v1/address/autocomplete` | Guest checkout address lookup; validation plus `60,1` throttle. |
| `GET api/v1/address/details` | Guest checkout address details; validation plus `60,1` throttle. |
| `POST api/v1/analytics/event` | Anonymous client telemetry ingestion; Phase 05 validates abuse controls. |
| `POST api/v1/auth/forgot-password` | Password-recovery entry point; `forgot-password` throttle. |
| `POST api/v1/auth/google` | Google sign-in entry point; `login` throttle. |
| `POST api/v1/auth/login` | Sign-in entry point; `login` throttle. |
| `POST api/v1/auth/otp/send` | OTP delivery entry point; `otp` throttle. |
| `POST api/v1/auth/otp/verify` | OTP verification entry point; `otp` throttle. |
| `POST api/v1/auth/register` | Account-registration entry point; generic API throttle. |
| `POST api/v1/auth/reset-password` | Password-reset completion entry point; `password-reset` throttle. |
| `GET api/v1/categories` | Public catalog browsing. |
| `GET api/v1/categories/{slug}` | Public category browsing. |
| `POST api/v1/contact-messages` | Public contact form; Phase 05 validates throttling and abuse protection. |
| `POST api/v1/coupons/validate` | Guest checkout coupon preview; no usage is recorded here. |
| `GET api/v1/health` | Public deployment health probe. |
| `POST api/v1/inspired-leads` | Public lead form; Phase 05 validates throttling and abuse protection. |
| `POST api/v1/orders/track` | Public tracking with its dedicated `order-tracking` throttle and request second factor. |
| `GET api/v1/products` | Public catalog browsing. |
| `GET api/v1/products/sitemap` | Public SEO sitemap. |
| `GET api/v1/products/{id}/reviews` | Public product-review browsing. |
| `GET api/v1/products/{slug}` | Public product detail. |
| `GET api/v1/shipping/free-shipping` | Public checkout configuration read. |
| `POST api/v1/shipping/rates` | Guest checkout quote; D6 and Phase 05 assess paid-provider cost abuse. |
| `POST api/v1/shipping/verify-address` | Guest checkout validation; D6 and Phase 05 assess paid-provider cost abuse. |
| `POST api/v1/webhooks/easypost` | Provider callback authenticated by webhook verification; D6 checks retries versus throttle. |
| `POST api/v1/webhooks/stripe` | Provider callback authenticated by signature verification; D5 checks retries versus throttle. |
| Each `telescope/telescope-api/*` route (43 routes, fully enumerated in `routes-baseline.json`) | Uses Telescope middleware and the `viewTelescope` gate; Phase 05 must verify this with the app's Sanctum/web-session setup and `TELESCOPE_ENABLED`. |

### R3 — coverage-map recheck

Rechecked every prior `NONE` against endpoint URIs in `tests/`. The map now records direct coverage for `GoogleAuthService`, `GooglePlacesService`, the public contact controller, and the admin dashboard authorization path. The remaining `NONE` items have no matching route invocation in `tests/`: `CategoryService`, `SkuGeneratorService`, `Api/V1/InspiredLeadController`, `Api/V1/Admin/CategoryController`, `Api/V1/Admin/GeoController`, `Api/V1/Admin/InspiredLeadController`, `Api/V1/Admin/SkuController`, and `Api/V1/Admin/TelescopeApiController`.

### R4 — coverage driver attempt

```text
PECL=NOT_FOUND
Loaded Configuration File: C:\Program Files\php-8.5.2\php.ini
```

PCOV/Xdebug are not loaded and PECL is not installed in this Windows PHP 8.5.2 environment, so an extension cannot be installed through the available PHP toolchain. Per-file runtime coverage remains unavailable and will be tracked statically until the server owner provides a coverage-enabled PHP runtime.

### R5 — readiness classification and handover items

Expected local/test failures: production environment, debug disabled, Telescope disabled, demo accounts, and demo catalogue.

Server-owner handover items: configure the production HTTPS frontend URL, a live Stripe secret, and warehouse origin; verify the production runtime's PHP version (the local runtime is PHP 8.5.2 and the project requires PHP `^8.2`). These are carried forward to Phase 06.
