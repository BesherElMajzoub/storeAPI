# Backend production-readiness response

**Project:** Otantik Queen API  
**Audit date:** 2026-09-02  
**Scope:** repository, automated test database, route inventory, dependency lock, and generated OpenAPI document

## Decision

The backend code and API contract are ready for frontend integration. The production deployment is **conditionally ready**, not fully signed off: the code-side blockers found in the audit were fixed and all 124 automated tests pass, but the live infrastructure evidence listed under "Deployment blockers" must be completed by the deployment owner before client go-live.

This distinction is deliberate. A local repository cannot prove a live certificate, off-server backup restore, production account cleanup, supervised workers, live Stripe keys, real-carrier shipping, or monitoring.

## Automated evidence

- `php artisan test --compact`: **124 passed, 644 assertions**.
- `composer audit --locked`: **no security vulnerability advisories found** after updating the lock file.
- PHP syntax scan: all application, migration, route, and test PHP files passed.
- `vendor/bin/pint --dirty`: passed.
- `php artisan l5-swagger:generate`: passed; `storage/api-docs/api-docs.json` regenerated.
- Route inventory: **121 API v1 routes**, including **73 admin routes**. All 73 admin routes include `auth:sanctum`, `throttle:api`, `can:admin-access`, and `audit.admin`.
- Database checks: required indexes exist and `EXPLAIN` ran successfully for storefront listing, storefront search, and admin order listing queries.

## 1. Authentication and session security

- **Confirmed — token validation on protected routes.** Protected customer and admin routes use Sanctum. The complete admin route inventory is linked below.
- **Fixed now — token expiry and revocation.** Sanctum tokens default to 1,440 minutes; logout revokes the current token or all tokens. A real token replay after logout returns 401.
- **Confirmed — unauthenticated contract.** Protected JSON requests return 401, not a redirect or a successful empty body.
- **Confirmed — password hashing.** Laravel's hashed password cast and configured bcrypt driver are used; no MD5/SHA password storage was found.
- **Fixed now — password policy.** Register, reset, profile password update, and change-password require at least eight characters, a letter, and a number.
- **Fixed now — brute-force protection.** Login, forgot-password, OTP send/verify, reset-password, and the global API have limiters. Twenty rapid invalid logins produce 429.
- **Fixed now — OTP hardening.** OTPs are hashed, short-lived, locked after five failed attempts, verified atomically, and single-use. Sending is throttled.
- **Fixed now — reset tokens.** Reset tokens are hashed, email-bound, short-lived, consumed transactionally, single-use, and revoke existing API tokens.
- **Fixed now — Google Sign-In.** Issuer, audience, expiry, subject, email, and `email_verified` are checked server-side against Google's token-info response.
- **Fixed now — enumeration resistance.** Login uses a dummy hash for unknown users; login/forgot-password/OTP responses do not reveal account existence or inactive-account state.

## 2. Authorization

- **Confirmed — complete admin sweep.** A dynamic test calls every registered `/api/v1/admin/*` route with a regular customer identity; all 70 return 401/403. This includes products, categories, orders, coupons, reviews, cancellation requests, messages, users, analytics, shipping, and dashboard endpoints.
- **Confirmed — IDOR protection.** Cross-account order read/cancel and address update/delete tests are rejected. The same test proves wishlist count/check/delete and profile read/update remain scoped to the authenticated customer; public messages have no customer read-by-ID endpoint.
- **Fixed now — checkout verification gate.** An unverified account receives 403 from `POST /api/v1/orders`.
- **Confirmed — immediate role changes.** Removing an admin role blocks the same authenticated session on its next admin request.

## 3. Input validation and injection

- **Confirmed — query safety.** Eloquent/query-builder binding is used. Audited raw expressions contain fixed SQL fragments, not concatenated request input.
- **Fixed now — write validation.** Length, type, format, enum, phone, quantity, address, coupon, image, parcel, and collection bounds were tightened across public/admin requests.
- **Fixed now — filter/sort allowlists.** Public and admin product sorting/filtering use validated values and explicit column mappings. SQL-like sort payloads and array-type confusion return 422.
- **Fixed now — pagination caps.** User-controlled page sizes are capped/validated at 100; `per_page=100000` is rejected.
- **Confirmed — stored XSS handling.** Review/contact payloads containing HTML are stored as text and returned only as JSON; Blade mail templates use escaped `{{ ... }}` output. Tests include `<script>` payloads.
- **Confirmed — Unicode.** MySQL uses `utf8mb4`; review/contact tests persist and retrieve emoji successfully.
- **Fixed now — oversized input.** A global 45 MB request limit complements field limits; product uploads are at most 8 images and 5 MB per image. The reverse proxy must also enforce its own limit.

## 4. Business-logic integrity

- **Fixed now — trusted pricing.** Order totals and checkout line items are recalculated from locked product/variant rows; submitted client prices/totals are ignored.
- **Confirmed — coupons.** Active window, minimum order, global limit, per-user limit, and discount bounds are server-side. Coupon rows are locked during order creation and usage is recorded in the same transaction.
- **Fixed now — atomic inventory.** Product and variant rows are locked in deterministic order; reservation/decrement and order/items/coupon usage are transactional and idempotently released on failure/cancel/full refund.
- **Confirmed — last-unit race.** Two synchronized PHP processes compete for one unit; exactly one reservation succeeds.
- **Confirmed — quantity bounds.** Quantity must be an integer from 1 to 100 and an order is limited to 50 lines.
- **Fixed now — order state machine.** Customer cancellation states and admin transitions are constrained. The generic admin status endpoint cannot mark an order paid/refunded; those states belong to verified payment/refund flows.
- **Fixed now — Stripe webhooks.** Signatures are mandatory; completion checks order metadata, exact session ID, amount, and currency. Replay is idempotent. Expiry checks the matching session. Partial refunds record the amount without full restock; full refunds close the order and release stock once.
- **Confirmed — server-created checkout amounts.** Stripe Checkout data is built from persisted order items and configured USD currency.

## 5. File-upload security

- **Fixed now — content MIME and extension controls.** Product/category uploads allow actual JPEG/PNG/WebP content only; PHP/PHTML/PHAR/SVG/GIF and misleading content are rejected.
- **Fixed now — size/count bounds.** Files are limited to 5 MB and product galleries to 8 images.
- **Fixed now — safe filenames.** Server-generated UUID filenames and detected extensions are used; client path/name is not trusted.
- **Fixed now — execution defense.** The public storage directory includes an Apache deny rule for script extensions. Equivalent Nginx/PHP-FPM configuration remains a deployment check.
- **Confirmed — re-encoding.** Media conversions re-encode WebP variants, stripping executable polyglot tails and metadata from served conversions.
- **Confirmed — disguised-PHP test.** A renamed PHP file is rejected; a JPEG/PHP polyglot is stored under a random image name and its served conversion is re-encoded without PHP content.

## 6. API hygiene

- **Fixed now — CORS.** Production allowlist defaults to `https://otantikqueen.com`, with an optional explicit staging origin. Local origins are available only in local/testing. No wildcard origin is used with credentials.
- **Fixed now — production error posture.** `.env.example` defaults to `APP_DEBUG=false`; a production-style 500 test contains no exception message, path, SQL text, or trace.
- **Confirmed — error contract.** Validation uses 422 with `message` and field `errors`; authentication/authorization use 401/403.
- **Confirmed — response privacy.** User hidden fields exclude password/remember token, OTPs are never serialized, and customer order queries are user-scoped.
- **Fixed now — headers.** API responses include `nosniff`, no-referrer, frame denial, and HSTS for secure/production requests.
- **Fixed now — legacy endpoints.** Direct Stripe test PHP, test checkout, Google test, and order-debug web routes were removed.
- **Fixed now — global throttling.** Every API v1 route has `throttle:api` in addition to stricter endpoint-specific limits.
- **Confirmed — versioning.** The consumer contract remains under `/api/v1`; additive fields/endpoints were documented and Swagger regenerated.

## 7. Database

- **Fixed now — indexes.** Added composite product/order indexes and unique variant SKU; existing unique/foreign-key indexes cover slugs, emails, coupon codes, order items, and reviews. Automated `SHOW INDEX` and `EXPLAIN` checks pass.
- **Confirmed — foreign keys/history.** Order items preserve product name/price snapshots and product/variant deletion uses explicit nullable references instead of deleting order history.
- **Fixed now — transactions.** Order, inventory, coupon usage, variants, bulk product updates, reset-token consumption, and OTP verification use transactions/locks where required.
- **Fixed now — N+1 hot paths.** Product/media/variant, order/item/product, review/user, and admin-order relations are eager-loaded. Staging query-count observation is still recommended after realistic data import.
- **Pending production evidence — backups.** No live backup system or restore result is accessible from this repository. Risk: go-live without a successful off-server restore drill can cause unrecoverable data loss.

## 8. Operations and deployment

- **Fixed now — repository secret posture.** `.env` is ignored, sample secrets were removed, the PHPUnit database password was removed from the tracked XML, and dependency/config defaults are production-safe.
- **Pending production action — credential rotation.** Git history indicates Telegram and mail credentials were committed previously. They must be revoked/rotated outside the repository. Removing them from the current tree does not revoke them.
- **Pending production evidence — HTTPS.** Validate the live certificate, proxy trust, and HTTP-to-HTTPS redirect on `apis.otantikqueen.com`.
- **Fixed now — logging.** Auth failures, admin mutations, order, payment, refund, and shipping events are logged without request bodies, passwords, tokens, or card data; daily log rotation is the default.
- **Pending production evidence — monitoring.** Uptime and exception/error-rate alerts must be enabled and an alert delivery test recorded.
- **Pending production evidence — queue/cron.** A durable queue is required by the preflight command, but worker supervision and scheduler heartbeat must be verified on the host.
- **Pending production action — demo accounts.** Production data is not accessible. The local database currently contains 14 known demo accounts; production seeders now refuse to create users. The production owner must remove/rename/reset all listed demo identities and rerun preflight.
- **Confirmed — timezone/currency code.** Application time is UTC; Stripe currency is configured as USD and money columns use decimal values.

## 9. Payments and shipping

- **Pending production evidence — live credentials.** The local environment intentionally uses a Stripe test key. Production must pass the preflight live-key checks.
- **Fixed now — automated happy path.** Checkout creation, a genuinely signed webhook, matching payment, idempotent customer confirmation email, and admin alert are covered.
- **Fixed now — failure paths.** Invalid signature, amount/currency mismatch, expired session, replay, unpaid refund, partial refund, and full admin refund are covered.
- **Fixed now — refund policy.** Only an admin can call the dedicated full-refund route; a full refund restocks once, while a partial refund records `refunded_amount` and does not restock/close the order.
- **Fixed now — EasyPost quote and checkout contract.** Rates use server-side product dimensions and configured package sizes, persist 15-minute quotes, and are re-priced at checkout. Invalid, expired, consumed, changed, or provider-unverifiable rates fail closed.
- **Fixed now — shipment privacy and tracking.** Customer resources expose a nested shipment without label/provider IDs; admin resources include the label. Signed webhooks and scheduled polling persist a normalized tracking snapshot.
- **Fixed now — public tracking.** `POST /api/v1/orders/track` requires order number plus email, returns generic not-found responses, and has dedicated rate limits.
- **Pending production evidence — carrier validation.** Automated tests mock EasyPost. A real US address/rate/label/tracking flow must be performed against the deployment account. International shipping is intentionally rejected in v1 until customs data is implemented.

## 10. Admin product-management answers and changes

- **Q1 — Confirmed.** `discount_price`, `in_stock`, `meta_title`, and `meta_description` are persisted and returned in product detail responses.
- **Q2/R3 — Fixed now.** Variant update is a transactional diff: a valid `id` updates, no `id` creates, `_delete: true` deletes, and omitted variants are kept. Cross-product IDs are rejected.
- **Q4 — Confirmed.** Category `description`, `meta_title`, and `meta_description` are accepted, persisted, and returned. The frontend may keep `meta_title` hidden if desired; it is no longer silently discarded.
- **Q5/R5 — Confirmed.** Category children/siblings are ordered by `sort_order` in model relations and public nested responses.
- **Q6-b — Confirmed.** `PATCH /api/v1/admin/reviews/{review}/moderate` is a literal PATCH route and is admin-guarded.
- **R1 — Fixed now.** Admin products support `search`, `category_id`, `status`, `is_featured`, the requested sort values, and bounded `per_page`.
- **R2 — Fixed now.** Added append, delete, and reorder image endpoints. Stable media IDs are already returned. Existing `/media` routes remain temporarily as compatible aliases.
- **R4 — Confirmed.** Product create/update enforce `draft | published | archived`.
- **R6 — Fixed now.** `POST /api/v1/admin/products/bulk` atomically allowlists `status`, `is_featured`, `in_stock`, and `category_id`, for at most 100 IDs.
- **R7 — Fixed now.** `POST /api/v1/admin/products/import` previews or atomically imports UTF-8 CSV product/variant rows, upserts by SKU, reports errors by row, and performs no writes when any row is invalid.

### Product image endpoint contract

- `POST /api/v1/admin/products/{product}/images` with multipart `images[]` appends images.
- `POST /api/v1/admin/products/{product}/images/reorder` with `image_ids: [id, ...]` sets order; first is primary.
- `DELETE /api/v1/admin/products/{product}/images/{media}` deletes only media owned by that product.

## Deployment blockers before the words "production approved"

1. Rotate historically committed mail and Telegram credentials; configure production-only Stripe/EasyPost secrets.
2. Run database backup, restore it into an isolated instance, and record the successful restore.
3. Remove/reset every production demo/test account and verify no shared credentials remain.
4. Verify the live certificate and HTTP-to-HTTPS redirect.
5. Enable supervised queue workers and scheduler heartbeat checks.
6. Enable uptime/error monitoring and prove an alert reaches the owner.
7. Execute Stripe test-mode end-to-end from the deployed host, then validate live-key configuration without making a real charge.
8. Validate EasyPost with a real US address and confirm rate, label, webhook, and tracking responses; verify non-US destinations are rejected with `unsupported_destination`.
9. Run `php artisan app:production-readiness` on the deployment; it must exit successfully.

See `PRODUCTION_DEPLOYMENT_CHECKLIST.md` for commands and sign-off fields and `API_V1_ROUTE_MIDDLEWARE.md` for the complete route/middleware inventory.
