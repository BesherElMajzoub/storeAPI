# 02 D1 Auth - results

Status: IN-PROGRESS (B2).

## Entry points

- `Auth/AuthController`: register, login, me, updateProfile, changePassword,
  logout, refresh, forgotPassword, resetPassword, sendOtp, verifyOtp, googleLogin.
- `Middleware/EnsureActiveUser` (`active.user`), `Middleware/EnsureAdminRole`
  (`admin-access`), Sanctum `auth:sanctum`.
- `OtpService`, `GoogleAuthService`.

## Findings

### D1-AUTH-001 — P1: disabled users could keep using an existing Sanctum token

- **Severity:** P1
- **Status:** FIXED (already committed before this batch, `152dd5f`)
- **Location:** `app/Http/Middleware/EnsureActiveUser.php`, `bootstrap/app.php`, `routes/api.php`
- **Problem/Fix:** `login()` already rejected disabled accounts, but a token issued before deactivation kept working since nothing re-checked `is_active` per request. `active.user` middleware now runs on every authenticated route and rejects the token with 401 if the user is currently disabled.
- **Test:** `tests/Feature/ProductionReadinessTest.php::test_disabled_user_cannot_use_an_existing_sanctum_token`.
- **Evidence:** see commit `152dd5f`.

### D1-AUTH-002 — P1: `PUT /auth/me` let an attacker with a stolen token silently change the password, bypassing the dedicated `change-password` re-auth check

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/V1/Auth/AuthController.php:250` (`updateProfile`), `app/Http/Requests/Api/V1/Auth/UpdateProfileRequest.php`
- **Problem:** `POST /auth/change-password` correctly requires `current_password` before allowing a new one. But `UpdateProfileRequest` also validated a `password` field, and `updateProfile()` applied it with no re-auth check at all — completely bypassing that control. The endpoint's own OpenAPI doc had the `password`/`password_confirmation` properties commented out, showing this was not the intended surface for password changes.
- **Scenario:** A token is exfiltrated (XSS, log leak, shared device) but the account password is unknown to the attacker. `PUT /auth/me` with just `{"password": "...", "password_confirmation": "..."}` changes the password with no current-password proof, silently locking the real owner out and defeating the "prove you still know the password" control the dedicated endpoint enforces.
- **Test:** `tests/Feature/AuthTest.php::test_profile_update_cannot_change_password_without_current_password_check` — fails before the fix (`assertTrue(Hash::check('old_password_123', ...))` is false because the password did change), passes after.
- **Fix:** Removed the `password` validation rule from `UpdateProfileRequest` and the password-handling branch from `updateProfile()`. Password changes now go exclusively through `POST /auth/change-password`, which already enforces `current_password`. Not a contract change — `docs/AUTHENTICATION_CONTRACT.md` never documented `password` as an `updateProfile` field. Commit: (this batch).
- **Evidence:**
  ```
  # pre-fix
  FAILED  Tests\Feature\AuthTest > profile update cannot change password without current password check
  Failed asserting that false is true.
  Tests: 1 failed (2 assertions)

  # post-fix
  PASS  Tests\Feature\AuthTest
  ✓ profile update cannot change password without current password check
  Tests: 6 passed (17 assertions)
  ```

### D1-AUTH-003 — P1: changing the account email via `PUT /auth/me` did not reset `email_verified_at`

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Http/Controllers/Api/V1/Auth/AuthController.php:250` (`updateProfile`)
- **Problem:** `OrderController::store` (`app/Http/Controllers/Api/V1/OrderController.php:355`) requires `email_verified_at` to be set before checkout, per `test_unverified_customer_cannot_checkout`. `updateProfile` let a user change `email` to an address they don't necessarily own while leaving `email_verified_at` from the *old*, verified address untouched — so the verification gate was permanently satisfied by a one-time proof of a different mailbox, and all future receipts/shipping notifications/OTPs for that account silently go to the new, unverified address.
- **Scenario:** User verifies `alice@real.com`, then `PUT /auth/me {"email":"randomtypo@doesnotexist.com"}`. The account keeps `email_verified_at` set, can still check out, and every subsequent transactional email is sent to an address nobody can read — with no re-verification step ever required.
- **Test:** `tests/Feature/AuthTest.php::test_changing_email_via_profile_update_resets_verification` (fails before fix — `email_verified_at` stays non-null after the email change), `::test_updating_unrelated_profile_field_keeps_verification` (regression guard — changing `name` alone must not force reverification).
- **Fix:** `updateProfile()` now sets `email_verified_at = null` whenever the submitted `email` differs from the current one. Existing OTP `email_verification` flow (`AuthController::verifyOtp`) is the path back to a verified state; no contract or route changed.
- **Evidence:**
  ```
  # pre-fix
  FAILED  Tests\Feature\AuthTest > changing email via profile update resets verification
  Failed asserting that ... Carbon ... is null.
  Tests: 1 failed (4 assertions)

  # post-fix
  PASS  Tests\Feature\AuthTest
  ✓ changing email via profile update resets verification
  ✓ updating unrelated profile field keeps verification
  Tests: 6 passed (17 assertions)
  ```

### D1-AUTH-004 — P2: `PUT /auth/me` validated an `avatar` upload that was never persisted

- **Severity:** P2
- **Status:** FIXED
- **Location:** `app/Http/Requests/Api/V1/Auth/UpdateProfileRequest.php`
- **Problem:** `avatar` was validated as an image upload (`sometimes|nullable|image|...`), but `updateProfile()` never read it — no file storage, no `avatar_url` write. A client uploading an avatar gets a `200` with no error and no effect, and there is no other route that accepts an avatar upload (`grep` of `routes/api.php` confirms this is the only candidate). Silently accepting and discarding a file upload is a real functional bug, and adding actual file-storage handling would be a new feature outside this pass's scope, so the fix removes the dead, misleading validation rule rather than implementing upload handling.
- **Status:** WONT-EXTEND (removed dead field; implementing avatar upload storage is a new feature, flagged in `## Frontend impact` for the frontend team's awareness, not implemented here).
- **Fix:** Removed the `avatar` rule from `UpdateProfileRequest`. Commit: (this batch).

## Verified OK

- **Registration** assigns the default `User` role and returns a token; nothing here is client-influenced beyond the record's own fields (`RegisterRequest` — not re-read in this pass, unchanged).
- **Login** uses a constant dummy-hash `Hash::check` even when the user doesn't exist, so the response timing/shape for "no such user" and "wrong password" match, and the message is the same generic `Invalid credentials.` in both cases — no user enumeration.
- **Disabled-user login** is rejected with the same generic message as a bad password (`AuthController::login:160-167`) — no enumeration of account status either.
- **Password reset**: the token lookup and expiry/hash check run inside one `DB::transaction` with `lockForUpdate()` on both the token row and the user row, preventing a race between two concurrent reset attempts; on success it deletes the token and revokes **all** existing Sanctum tokens (`$user->tokens()->delete()`), so a leaked old token can't survive a reset. `test_password_reset_token_is_single_use` covers reuse.
- **OTP**: single-use (`consumed_at`), attempt-limited (`max_attempts`, checked inside the same locked transaction as the code comparison), resend cooldown and a daily send cap all present in `OtpService`; the raw code is never logged unless `otp.log_codes` is explicitly enabled (defaults false), and never included in the HTTP response. `test_otp_is_single_use_and_locks_after_five_wrong_attempts` covers reuse/lockout.
- **Google login**: requires `aud` to match our client id, `iss` to be a genuine Google issuer, the token to be unexpired, and — critically — `email_verified` to be `true` on Google's side before an existing local account can be linked by email match, so an attacker can't hijack an account by registering an *unverified* Google email that happens to match a victim's address. `test_google_token_requires_our_audience_and_a_verified_email` covers this.
- **Admin gate**: `EnsureAdminRole` calls `$user->hasRole('Admin')`, which queries the pivot table fresh on every request (no caching), so revoking/granting the Admin role takes effect on the user's very next request even with their existing token still valid. `test_admin_demotion_takes_effect_with_the_existing_session` covers this.
- **Logout**: deletes the current Sanctum token (or all tokens with `all: true`) and, only for stateful/cookie requests, also invalidates the session and rotates the CSRF token — a Bearer-only client with no session is left alone, matching `docs/AUTHENTICATION_CONTRACT.md`.

## Tests added/strengthened

- `tests/Feature/AuthTest.php::test_changing_email_via_profile_update_resets_verification` (new)
- `tests/Feature/AuthTest.php::test_updating_unrelated_profile_field_keeps_verification` (new)
- `tests/Feature/AuthTest.php::test_profile_update_cannot_change_password_without_current_password_check` (new)

## Frontend impact

- `PUT /api/v1/auth/me` no longer accepts `password`/`password_confirmation` or `avatar` fields (neither ever worked correctly — `password` bypassed the current-password check, `avatar` was silently discarded). Password changes must go through the existing `POST /api/v1/auth/change-password`. If the frontend's profile form currently submits `password` or `avatar` to `PUT /auth/me`, those fields will now be silently ignored by validation's `sometimes` rule removal (no error, just no-op) — same visible behavior as before for `avatar`, but `password` submissions will **no longer** change the password at all (previously it changed it insecurely). Flag to frontend team: use `/auth/change-password` for password changes; avatar upload is not implemented anywhere in this API and needs a NEEDS-DECISION product call if required.
- Changing `email` via `PUT /auth/me` now resets `email_verified_at` to null, so the account must re-verify (OTP `email_verification` purpose) before it can check out again. This is a behavior fix, not a new field/route/shape change, but is worth the frontend knowing: after a successful email change, route the user to the OTP-verify-email flow before letting them checkout.

## Test suite output

See end-of-batch full run in the B2 handover.
