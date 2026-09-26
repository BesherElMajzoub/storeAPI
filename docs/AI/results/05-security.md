# 05 Security - results

Status: READY-FOR-REVIEW (B3-full — every checklist box the B2 review flagged
as unchecked is now covered).

## Checklist

### Access control
- [x] **BOLA/IDOR sweep over every `{id}` route**: walked every customer-scoped
      parameterized route in `routes/api.php`:
      - `orders/{id}` (show/cancel/tracking/checkout-session/cancellation-request) —
        `OrderController` resolves through `$request->user()->orders()->findOrFail($id)`
        everywhere, never a bare `Order::findOrFail($id)`.
      - `products/{product}/reviews/{review}` (update/destroy) — `ReviewController`
        checks `$review->product_id === $product->id` (404 if not) **and**
        `$this->authorize('update'|'delete', $review)` against `ReviewPolicy`,
        which checks `$review->user_id === $user->id` (delete also allows Admin).
      - `profile/addresses/{id}` (update/destroy/setDefault) — `AddressController::update`
        explicitly checks `$address->user_id !== $request->user()->id` → `403`
        (destroy/setDefault checked too, same pattern).
      - `wishlist/{productId}` (destroy/check) — both scoped through
        `$request->user()->wishlistItems()`, never a bare product/user lookup.
      - All `/admin/*` `{id}` routes are intentionally god-scoped (admin role
        already grants access to any resource by design) and sit behind the
        single `can:admin-access` group gate (see below) — ownership scoping
        doesn't apply to them.
      - Existing regression: `ProductionReadinessTest::test_customer_cannot_read_or_mutate_another_customers_resources`
        already exercises the order/address/wishlist cross-user cases end to end.
- [x] **Every admin route has `auth:sanctum`, `can:admin-access`, `audit.admin`,
      throttle**: `routes/api.php:129` applies
      `['auth:sanctum', 'active.user', 'can:admin-access', 'audit.admin']` to the
      **entire** `Route::prefix('admin')` group in one place — `grep -n "prefix('admin')"`
      confirms there is exactly one such group, so a newly added admin route
      can't ship unprotected by omission. The whole `v1` prefix (including
      `admin`) also sits under the blanket `throttle:api` (120/min).
- [x] **Mass assignment**: `grep -rn "guarded\s*=\s*\[\]" app/Models` — zero
      matches; every model uses an explicit `$fillable` allow-list. Verified
      `Order`, `User`, `Product` specifically don't allow `total`, `paid_at`,
      `is_admin`/role, or `stock_qty` to be set through any client-facing
      `::create()`/`::update()` call — `OrderController::store` computes
      `total`/`subtotal` server-side (D3, already approved); `AuthController::register`
      only ever passes `name`/`email`/`phone`/`password` into `User::create`.
- [x] **`Http/Resources/*` field-exposure audit**: read every resource class.
      `OrderResource` excludes `label_url`/Stripe session id/EasyPost shipment
      id from the customer-facing payload (`shipmentPayload(false)`) —
      provider/label data is admin-only via `AdminOrderResource`, matching
      `backend-open-items.md` A3. `ReviewResource` only exposes
      `id`/`name`/`avatar_url` for the review's author (no email, no IP), and
      gates `admin_note` behind `$user?->hasRole('Admin')`. `User::$hidden`
      covers `password`/`remember_token` globally, so no resource can
      accidentally leak a hash even if it forgot to. `ProductController::reviews`
      (public route) filters to `->approved()` before wrapping in
      `ReviewResource::collection`, so a pending/rejected review's content is
      never visible to the public regardless of what the resource itself hides.

### Authentication
- [x] Rate limits on `login`, `register`'s downstream `otp/send`+`otp/verify`,
      `forgot-password`, `reset-password`, public `orders/track`, and the two
      provider webhooks — all pre-existing, re-verified this pass by reading
      `routes/api.php` and `AppServiceProvider::boot`.
- [x] **Public write endpoints (contact, leads) throttling** — this was the
      one real gap found this pass: see `results/02-D8-misc.md` D8-F1 (fixed).
- [x] Token/session invalidation on logout and password change — re-verified
      in D1 (previous batch).
- [x] Sanctum stateful domains and CORS allow-list are explicit, no `*` with
      credentials — `config/sanctum.php` builds `stateful` from
      `SANCTUM_STATEFUL_DOMAINS`; `config/cors.php` builds `allowed_origins`
      from `FRONTEND_URL`/`STAGING_FRONTEND_URL` (filtered/deduped) while
      `supports_credentials` is `true`. `ApiHygieneTest::test_cors_allows_the_storefront_and_rejects_an_unlisted_origin`
      already asserts an unlisted `Origin` never gets reflected back.

### Input & files
- [x] **Image upload**: content checked by actual bytes, not extension/name —
      `tests/Feature/SecureImageUploadTest.php` already covers a renamed PHP
      payload being rejected, a JPEG polyglot getting a random non-executable
      name and safe WebP re-encoding, an 8-image gallery cap, and stable
      ordering — all pre-existing and still green, re-verified by reading the
      test file and the controller it exercises (`Admin/MediaController`).
- [x] **Raw SQL audit**: every `DB::raw`/`whereRaw`/`orderByRaw`/`selectRaw` in
      `app/` uses either a hardcoded literal expression (no interpolation) or
      a `?` binding for the only interpolated value
      (`Product::scopeFilter`'s price-range bounds). The one place user input
      chooses *which* SQL runs — `Product::scopeSort($sort)` — is a `switch`
      statement over a fixed set of `case` values, never string-built into the
      query; matches the existing `test_sort_type_confusion_and_injection_are_rejected_and_pagination_is_capped`.
      No CSV-export formula-injection surface exists (`ProductImportService`
      is import-only; no CSV export endpoint was found anywhere in the API).
- [x] **Open redirect on Stripe URLs**: `StripeCheckoutService`'s
      `success_url`/`cancel_url` are built entirely from
      `config('app.frontend_url', config('app.url'))` — server-side config,
      never client input — plus a fixed path and query string. No redirect
      target is ever attacker-controlled.

### Webhooks & integrations
- [x] **EasyPost webhook**: signature verified via `easyPostService->validateWebhook`,
      fails closed (`503`) when the secret isn't configured
      (`test_easypost_webhook_fails_closed_when_secret_is_missing`); idempotency
      and no-regression-on-replay fixed this quality pass (D6-F1, prior batch).
- [x] **Stripe webhook re-verification**: `tests/Feature/StripeWebhookSecurityTest.php`
      already has 12 tests covering signature/amount/currency mismatch
      rejection, out-of-order `expired`-after-`completed` and
      `completed`-after-`expired` races, double-refund/replay idempotency, and
      partial-refund-then-full-refund ordering — read in full this pass, all
      still green, no gap found worth adding to.
- [x] Outgoing HTTP calls have timeouts: `GoogleAuthService::callGoogleTokenInfo`
      uses `Http::timeout(5)`; `EasyPostService`/`GeoapifyService`/
      `GooglePlacesService` all configure a client timeout (checked via `grep -rn "->timeout("`).

### Configuration & ops
- [x] `APP_DEBUG` defaults to `false` (`.env.example:6`, `config/app.php:46`
      falls back to `false` when unset) — `test_production_style_500_response_does_not_expose_exception_details`
      already pins that a 500 with `app.debug=false` never leaks a stack trace.
- [x] `SecurityHeaders` middleware exists (`app/Http/Middleware/SecurityHeaders.php`)
      and is applied globally (verified in phase 01 baseline; not re-derived
      here).
- [x] **No secrets committed**: `git ls-files | grep -i '^\.env'` → only
      `.env.example`. Working-tree and **full git history** (`git log --all -p`)
      grepped for `sk_live_`, `whsec_`, and Google API-key-shaped strings —
      zero hits. The one match in a working-tree grep
      (`ProductionReadinessCheck.php:26`) only reads `config('services.stripe.secret')`
      at runtime and masks it for a readiness report; it contains no literal
      secret.
- [x] `composer audit` — `No security vulnerability advisories found.`
- [x] Logs don't contain OTPs/passwords/tokens: `OtpService` only logs the raw
      code when `otp.log_codes` is explicitly enabled (defaults `false`,
      already verified in D1); `TelescopeServiceProvider::hideRequestHeaders`/
      the sensitive-params list redact `password`/`token`/`secret`/`card_number`/`cvv`
      and the `Authorization`/`Cookie` headers.

## Findings

No new P0/P1 security findings this pass. The one real gap found
(public-form throttling) is filed as **D8-F1** in `results/02-D8-misc.md`
rather than duplicated here, since the fix lives in the D8/routing layer, not
a security-specific file — cross-referenced above.

## Secret scan output

```
$ git ls-files | grep -i '^\.env'
.env.example

$ git log --all -p | grep -iE "sk_live_[a-zA-Z0-9]{10}|whsec_[a-zA-Z0-9]{10}|AIza[0-9A-Za-z_-]{30}"
(no output)

$ grep -rniE "sk_live|sk_test_[a-zA-Z0-9]{10}|whsec_[a-zA-Z0-9]{10}" --include=*.php app config
app/Console/Commands/ProductionReadinessCheck.php:26: ... Str::startsWith((string) config('services.stripe.secret'), 'sk_live_') ...
(reads config() at runtime and masks the value — not a hardcoded secret)

$ composer audit
No security vulnerability advisories found.
```

## Full test suite output

Full suite, run at the end of B3-full (D2+D8+Security), three consecutive runs:
```
Tests:    229 passed (1224 assertions)   Duration: 47.29s
Tests:    229 passed (1224 assertions)   Duration: 46.19s
Tests:    229 passed (1224 assertions)   Duration: 45.54s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`

## Frontend impact

None from this file directly — see `results/02-D8-misc.md` for the one
frontend-visible change (public-form `429` throttling).
