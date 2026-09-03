# API v1 route and middleware inventory

Generated from `php artisan route:list --json` on 2026-09-02 after the readiness changes.

- API v1 routes: 117
- Admin routes: 70
- Every admin route has `auth:sanctum`, `throttle:api`, `can:admin-access`, and `audit.admin`.
- Every API v1 route has the global `throttle:api` inherited from the v1 group. Rows with an additional limiter show both.

| Method | URI | Middleware |
|---|---|---|
| GET | `api/v1/address/autocomplete` | api, throttle:api, throttle:60,1 |
| GET | `api/v1/address/details` | api, throttle:api, throttle:60,1 |
| GET | `api/v1/admin/analytics/dashboard` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/cancellation-requests` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/cancellation-requests/{id}/accept` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/cancellation-requests/{id}/reject` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/categories` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/categories` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/categories/reorder` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/categories/{category}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PUT|PATCH | `api/v1/admin/categories/{category}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/categories/{category}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/categories/{category}/media` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/contact-messages` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/contact-messages/{contact_message}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PUT|PATCH | `api/v1/admin/contact-messages/{contact_message}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/contact-messages/{contact_message}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PATCH | `api/v1/admin/contact-messages/{id}/status` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/coupons` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/coupons` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/coupons/{coupon}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PUT|PATCH | `api/v1/admin/coupons/{coupon}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/coupons/{coupon}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PATCH | `api/v1/admin/coupons/{coupon}/toggle-status` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/coupons/{coupon}/usages` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/dashboard` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/geo/me` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/inspired-leads` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/inspired-leads/{inspired_lead}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PUT|PATCH | `api/v1/admin/inspired-leads/{inspired_lead}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/inspired-leads/{inspired_lead}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/media/{media}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/orders` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/orders/{id}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/orders/{id}/status` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/orders/{order}/refund` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/orders/{order}/ship` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/orders/{order}/tracking` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/products` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products/bulk` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/products/{product}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PUT|PATCH | `api/v1/admin/products/{product}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/products/{product}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products/{product}/images` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products/{product}/images/reorder` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/products/{product}/images/{media}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products/{product}/media` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/products/{product}/media/reorder` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/reviews` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/reviews/{review}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| DELETE | `api/v1/admin/reviews/{review}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| PATCH | `api/v1/admin/reviews/{review}/moderate` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/admin/skus/generate` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/cache` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/events` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/exceptions` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/jobs` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/logs` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/mail` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/notifications` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/queries` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/requests` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/telescope/summary` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/users` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/users/{id}` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/users/{id}/addresses` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/users/{id}/wishlist` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/wishlist-analytics` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/wishlist-analytics/conversions` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/wishlist-analytics/summary` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| GET | `api/v1/admin/wishlist-analytics/trending` | api, auth:sanctum, throttle:api, can:admin-access, audit.admin |
| POST | `api/v1/analytics/event` | api, throttle:api |
| POST | `api/v1/auth/change-password` | api, auth:sanctum, throttle:api |
| POST | `api/v1/auth/forgot-password` | api, throttle:api, throttle:forgot-password |
| POST | `api/v1/auth/google` | api, throttle:api, throttle:login |
| POST | `api/v1/auth/login` | api, throttle:api, throttle:login |
| POST | `api/v1/auth/logout` | api, auth:sanctum, throttle:api |
| GET | `api/v1/auth/me` | api, auth:sanctum, throttle:api |
| PUT | `api/v1/auth/me` | api, auth:sanctum, throttle:api |
| POST | `api/v1/auth/otp/send` | api, throttle:api, throttle:otp |
| POST | `api/v1/auth/otp/verify` | api, throttle:api, throttle:otp |
| POST | `api/v1/auth/refresh` | api, auth:sanctum, throttle:api |
| POST | `api/v1/auth/register` | api, throttle:api |
| POST | `api/v1/auth/reset-password` | api, throttle:api, throttle:password-reset |
| GET | `api/v1/categories` | api, throttle:api |
| GET | `api/v1/categories/{slug}` | api, throttle:api |
| POST | `api/v1/contact-messages` | api, throttle:api |
| POST | `api/v1/coupons/validate` | api, throttle:api |
| POST | `api/v1/inspired-leads` | api, throttle:api |
| POST | `api/v1/orders` | api, auth:sanctum, throttle:api |
| GET | `api/v1/orders` | api, auth:sanctum, throttle:api |
| GET | `api/v1/orders/{id}` | api, auth:sanctum, throttle:api |
| POST | `api/v1/orders/{id}/cancel` | api, auth:sanctum, throttle:api |
| POST | `api/v1/orders/{id}/cancellation-request` | api, auth:sanctum, throttle:api |
| GET | `api/v1/orders/{id}/tracking` | api, auth:sanctum, throttle:api |
| GET | `api/v1/products` | api, throttle:api |
| GET | `api/v1/products/{id}/reviews` | api, throttle:api |
| GET | `api/v1/products/{product}/my-review` | api, auth:sanctum, throttle:api |
| POST | `api/v1/products/{product}/reviews` | api, auth:sanctum, throttle:api |
| PUT | `api/v1/products/{product}/reviews/{review}` | api, auth:sanctum, throttle:api |
| DELETE | `api/v1/products/{product}/reviews/{review}` | api, auth:sanctum, throttle:api |
| GET | `api/v1/products/{slug}` | api, throttle:api |
| GET | `api/v1/profile/addresses` | api, auth:sanctum, throttle:api |
| POST | `api/v1/profile/addresses` | api, auth:sanctum, throttle:api |
| PUT | `api/v1/profile/addresses/{id}` | api, auth:sanctum, throttle:api |
| DELETE | `api/v1/profile/addresses/{id}` | api, auth:sanctum, throttle:api |
| POST | `api/v1/profile/addresses/{id}/default` | api, auth:sanctum, throttle:api |
| POST | `api/v1/shipping/rates` | api, throttle:api |
| POST | `api/v1/shipping/verify-address` | api, throttle:api |
| POST | `api/v1/webhooks/easypost` | api, throttle:api |
| POST | `api/v1/webhooks/stripe` | api, throttle:api |
| GET | `api/v1/wishlist` | api, auth:sanctum, throttle:api |
| POST | `api/v1/wishlist` | api, auth:sanctum, throttle:api |
| GET | `api/v1/wishlist/check/{productId}` | api, auth:sanctum, throttle:api |
| GET | `api/v1/wishlist/count` | api, auth:sanctum, throttle:api |
| DELETE | `api/v1/wishlist/{productId}` | api, auth:sanctum, throttle:api |

