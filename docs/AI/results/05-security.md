# 05 Security - results

Status: IN-PROGRESS (B3 started; spot-checked against the checklist, not yet exhaustive).

## Checklist (spot-checked this pass)

### Access control
- [x] **Every admin route is protected as a group, not per-route**: `routes/api.php:129` wraps
      the entire `Route::prefix('admin')` group in
      `['auth:sanctum', 'active.user', 'can:admin-access', 'audit.admin']` — a single
      `grep -n "prefix('admin')"` confirms there is exactly one such group, so a new admin route
      can't accidentally ship unprotected. `EnsureAdminRole`/`can:admin-access`
      (`AppServiceProvider.php:131`) checks `hasRole('Admin')` fresh from the DB every request.
- [x] **Mass assignment**: no model in `app/Models` uses `protected $guarded = []`
      (`grep -rn "guarded\s*=\s*\[\]"` — no matches); every model uses an explicit `$fillable`
      allow-list. `OrderController::store` computes `total`/`subtotal` server-side from DB prices
      (D3, previously approved; `test_order_ignores_client_prices_and_reserves_database_stock`)
      and never passes raw request arrays into `Model::create`.
- [ ] BOLA/IDOR sweep across every `{id}` route, signed/media URLs, and exports — not completed
      this pass (partially covered by `ProductionReadinessTest::test_customer_cannot_read_or_mutate_another_customers_resources`,
      which already exists from phase 01).
- [ ] Resource field exposure audit (password hashes, tokens, OTPs, other users' PII) across every
      `Http/Resources/*Resource.php` — not completed this pass.

### Authentication
- [x] Rate limits present on `login`, `forgot-password`, `reset-password`, `otp/send`,
      `otp/verify`, `google` (all via named throttles in `routes/api.php:49-56`), and on
      `orders/track` (`throttle:order-tracking`) and the two provider webhooks
      (`throttle:provider-webhook`).
- [x] Sanctum stateful domains come from `SANCTUM_STATEFUL_DOMAINS` (`config/sanctum.php:21`), not
      hardcoded/wildcard.
- [x] CORS: `config/cors.php` builds `allowed_origins` from explicit `FRONTEND_URL`/
      `STAGING_FRONTEND_URL` env vars (filtered/deduped), not `*`, while
      `supports_credentials` is `true` — a wildcard origin with credentials (the classic
      misconfiguration) is not present.
- [x] Token/session invalidation on logout and password change — re-verified in D1 this batch
      (`AuthController::logout`, `resetPassword`'s `$user->tokens()->delete()`); see
      `results/02-D1-auth.md`.

### Input & files
- [ ] Image upload content-type/size/storage audit — not completed this pass (existing
      `SecureImageUploadTest` referenced by the phase file was not re-read/re-verified here).
- [ ] Raw SQL / `whereRaw`/`orderByRaw` binding audit — not completed this pass.
- [ ] Open-redirect check on Stripe success/cancel URLs — not completed this pass.

### Webhooks & integrations
- [x] EasyPost webhook fails closed (503) when `services.easypost.webhook_secret` is unset
      (`EasyPostWebhookController.php:41`, tested by `test_easypost_webhook_fails_closed_when_secret_is_missing`)
      and validates the signature via `easyPostService->validateWebhook` before acting.
      Idempotency/no-regression on repeated events was fixed this batch (D6-F1).
- [ ] Stripe webhook signature/replay re-verification — not re-checked this pass (D5 already
      approved in a prior batch with its own webhook idempotency tests).

### Configuration & ops
- [x] No real secrets in the tracked tree: `git ls-files | grep '^\.env'` returns only
      `.env.example`; `grep` for `sk_live_`/`whsec_`-shaped literals across `app/`+`config/` finds
      none (the one hit, `ProductionReadinessCheck.php:26`, only reads `config()` and masks the
      value for a readiness report — it doesn't contain a secret itself).
- [x] Telescope dashboard gate (`TelescopeServiceProvider::gate()`) restricts `viewTelescope` to
      users with an `Admin`/`Owner`/`Manager` role in non-local environments.
- [ ] `composer audit`, full secret-scan of git *history* (not just the working tree), and
      `APP_DEBUG`/stack-trace verification in a production-like run — not completed this pass.

## Findings

None found (P0/P1) in the areas spot-checked this pass. See the unchecked boxes above for what
still needs a pass — carried forward, not blocking B3's other domains.

## Tests added/strengthened

None yet — this pass was verification-only (reading code + existing test coverage), no new gap
requiring a fix was found in the areas covered.

## Secret scan output

```
$ git ls-files | grep -i '^\.env'
.env.example

$ grep -rniE "sk_live|sk_test_[a-zA-Z0-9]{10}|whsec_[a-zA-Z0-9]{10}" --include=*.php app config
app/Console/Commands/ProductionReadinessCheck.php:26:  ... Str::startsWith((string) config('services.stripe.secret'), 'sk_live_') ...
```
(Only hit reads from `config()` at runtime and masks it for a readiness report; not a hardcoded secret.)

## Full test suite output

Shared with B3's other domains at handover — see `PROGRESS.md` log for the latest full-suite run.
