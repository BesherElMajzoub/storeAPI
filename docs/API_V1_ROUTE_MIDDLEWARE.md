# API v1 Route and Middleware Inventory

**Generated from:** `php artisan route:list --json`  
**Last verified:** September 26, 2026  
**Route definitions:** 128 under `/api/v1`

This inventory groups routes that have identical middleware. `GET|HEAD` is shown as `GET` for readability, and Laravel resource routes that accept both update verbs are shown as `PUT|PATCH`.

## Middleware legend

| Label | Resolved middleware | Purpose |
|---|---|---|
| API | `api`, `throttle:api` | API state, bindings, and default API rate limit |
| Customer auth | `auth:sanctum` | Requires an authenticated Sanctum user |
| Admin authorization | `can:admin-access` | Requires the admin authorization gate |
| Admin audit | `audit.admin` / `App\Http\Middleware\AuditAdminActions` | Records admin mutations/access as configured by the middleware |
| Named throttle | `throttle:<name>` | Adds the endpoint-specific limiter on top of `throttle:api` |

All `/api/v1/*` routes include the API group and `throttle:api`. API exceptions and validation failures are rendered as JSON even when the client omits `Accept: application/json`.

## Public routes: API middleware only

| Method | Path |
|---|---|
| `POST` | `/api/v1/analytics/event` |
| `POST` | `/api/v1/auth/register` |
| `GET` | `/api/v1/categories` |
| `GET` | `/api/v1/categories/{slug}` |
| `POST` | `/api/v1/contact-messages` |
| `POST` | `/api/v1/coupons/validate` |
| `GET` | `/api/v1/health` |
| `POST` | `/api/v1/inspired-leads` |
| `GET` | `/api/v1/products` |
| `GET` | `/api/v1/products/{id}/reviews` |
| `GET` | `/api/v1/products/sitemap` |
| `GET` | `/api/v1/products/{slug}` |
| `POST` | `/api/v1/shipping/rates` |
| `POST` | `/api/v1/shipping/verify-address` |
| `POST` | `/api/v1/webhooks/easypost` |
| `POST` | `/api/v1/webhooks/stripe` |

The webhook routes intentionally have no user authentication. Each controller fails closed unless the provider signature is valid and its webhook secret is configured.

## Public routes with additional throttles

| Method | Path | Additional limiter |
|---|---|---|
| `GET` | `/api/v1/address/autocomplete` | `throttle:60,1` |
| `GET` | `/api/v1/address/details` | `throttle:60,1` |
| `POST` | `/api/v1/auth/forgot-password` | `throttle:forgot-password` |
| `POST` | `/api/v1/auth/google` | `throttle:login` |
| `POST` | `/api/v1/auth/login` | `throttle:login` |
| `POST` | `/api/v1/auth/otp/send` | `throttle:otp` |
| `POST` | `/api/v1/auth/otp/verify` | `throttle:otp` |
| `POST` | `/api/v1/auth/reset-password` | `throttle:password-reset` |
| `POST` | `/api/v1/orders/track` | `throttle:order-tracking` |

The public tracking limiter applies both an IP limit and an order-number/email-pair limit through its named limiter definition.

## Authenticated customer routes

These routes have API middleware plus `auth:sanctum`.

| Method | Path |
|---|---|
| `POST` | `/api/v1/auth/change-password` |
| `POST` | `/api/v1/auth/logout` |
| `GET` | `/api/v1/auth/me` |
| `PUT` | `/api/v1/auth/me` |
| `POST` | `/api/v1/auth/refresh` |
| `POST` | `/api/v1/orders` |
| `GET` | `/api/v1/orders` |
| `GET` | `/api/v1/orders/{id}` |
| `POST` | `/api/v1/orders/{id}/cancel` |
| `POST` | `/api/v1/orders/{id}/cancellation-request` |
| `POST` | `/api/v1/orders/{id}/checkout-session` |
| `GET` | `/api/v1/orders/{id}/tracking` |
| `GET` | `/api/v1/products/{product}/my-review` |
| `POST` | `/api/v1/products/{product}/reviews` |
| `PUT` | `/api/v1/products/{product}/reviews/{review}` |
| `DELETE` | `/api/v1/products/{product}/reviews/{review}` |
| `GET` | `/api/v1/profile/addresses` |
| `POST` | `/api/v1/profile/addresses` |
| `PUT` | `/api/v1/profile/addresses/{id}` |
| `DELETE` | `/api/v1/profile/addresses/{id}` |
| `POST` | `/api/v1/profile/addresses/{id}/default` |
| `GET` | `/api/v1/wishlist` |
| `POST` | `/api/v1/wishlist` |
| `POST` | `/api/v1/wishlist/merge` |
| `GET` | `/api/v1/wishlist/check/{productId}` |
| `GET` | `/api/v1/wishlist/count` |
| `DELETE` | `/api/v1/wishlist/{productId}` |

Order, address, review, and wishlist controllers apply ownership checks in addition to authentication. Cross-customer regression coverage is recorded in `PRODUCTION_READINESS_TEST_EVIDENCE.md`.

## Admin routes

Every route below has all five resolved layers: `api`, `auth:sanctum`, `throttle:api`, `can:admin-access`, and `audit.admin`.

| Area | Methods and paths |
|---|---|
| Dashboard and audit | `GET /admin/dashboard`; `GET /admin/analytics/dashboard`; `GET /admin/audit-logs` |
| Cancellation requests | `GET /admin/cancellation-requests`; `POST /admin/cancellation-requests/{id}/accept`; `POST /admin/cancellation-requests/{id}/reject` |
| Categories | `GET|POST /admin/categories`; `POST /admin/categories/reorder`; `GET|PUT|PATCH|DELETE /admin/categories/{category}`; `POST /admin/categories/{category}/media` |
| Contact messages | `GET /admin/contact-messages`; `GET|PUT|PATCH|DELETE /admin/contact-messages/{contact_message}`; `PATCH /admin/contact-messages/{id}/status` |
| Coupons | `GET|POST /admin/coupons`; `GET|PUT|PATCH|DELETE /admin/coupons/{coupon}`; `PATCH /admin/coupons/{coupon}/toggle-status`; `GET /admin/coupons/{coupon}/usages` |
| Geo | `GET /admin/geo/me` |
| Inspired leads | `GET /admin/inspired-leads`; `GET|PUT|PATCH|DELETE /admin/inspired-leads/{inspired_lead}` |
| Media | `DELETE /admin/media/{media}` |
| Orders | `GET /admin/orders`; `POST /admin/orders/bulk-status`; `GET /admin/orders/{id}`; `POST /admin/orders/{id}/status`; `POST /admin/orders/{order}/label`; `POST /admin/orders/{order}/ship`; `POST /admin/orders/{order}/refund`; `GET /admin/orders/{order}/tracking` |
| Products | `GET|POST /admin/products`; `POST /admin/products/bulk`; `POST /admin/products/import`; `GET|PUT|PATCH|DELETE /admin/products/{product}`; `POST /admin/products/{product}/stock/adjust` |
| Product media aliases | `POST /admin/products/{product}/images`; `POST /admin/products/{product}/images/order`; `POST /admin/products/{product}/images/reorder`; `DELETE /admin/products/{product}/images/{media}`; `POST /admin/products/{product}/media`; `POST /admin/products/{product}/media/reorder` |
| Reviews | `GET /admin/reviews`; `GET|DELETE /admin/reviews/{review}`; `PATCH /admin/reviews/{review}/moderate` |
| SKUs | `POST /admin/skus/generate` |
| Users | `GET /admin/users`; `GET /admin/users/{id}`; `GET /admin/users/{id}/addresses`; `GET /admin/users/{id}/wishlist` |
| Wishlist analytics | `GET /admin/wishlist-analytics`; `GET /admin/wishlist-analytics/summary`; `GET /admin/wishlist-analytics/trending`; `GET /admin/wishlist-analytics/conversions` |
| Telescope API | Conditional routes listed below |

Telescope admin API routes exist only when `TELESCOPE_ENABLED=true`: `GET /admin/telescope/{summary,requests,queries,exceptions,jobs,logs,events,mail,notifications,cache}`. Production readiness requires Telescope to be disabled, so these routes must be absent in production.

## Verification commands

```bash
php artisan route:list --path=api/v1
php artisan test --filter=test_every_admin_route_rejects_a_regular_customer
```

The route inventory must be regenerated whenever `routes/api.php`, middleware aliases, or conditional route configuration changes.
