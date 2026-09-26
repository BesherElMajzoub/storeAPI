# 02 D8 Misc - results

Status: READY-FOR-REVIEW (B3-full — checklist items the B2 review flagged as
unchecked are now covered).

## Entry points

- `AnalyticsEventController`, `ContactMessageController`, `InspiredLeadController`,
  `SettingController` (admin), `Admin/UserController`, `DashboardController`,
  `AdminAnalyticsController`.

## Verified OK

- **Admin user management "can't demote/delete the last admin or yourself"**: not applicable —
  `Api/V1/Admin/UserController` only exposes `index`, `show`, `wishlist`, `addresses`
  (read-only views of a customer). There is no route or controller method anywhere that updates a
  user's role, disables/deletes a user, or otherwise mutates an account
  (`grep` of `routes/api.php` for `AdminUserController` shows only 4 `GET` routes). There is
  nothing to protect because the feature doesn't exist; building admin role/user management is a
  new feature and out of scope for this pass.
- **Settings caching**: `Setting::getValue()`/`setValue()` (`app/Models/Setting.php`) read/write
  the DB directly with no `Cache::remember` layer at all, so "cache invalidated on update" doesn't
  apply — every read is already fresh, there is no staleness window to introduce a bug into.

## Findings

### D8-F1 — P2: public one-shot form endpoints shared the generic 120/min API ceiling instead of a dedicated throttle

- **Severity:** P2
- **Status:** FIXED
- **Location:** `routes/api.php` (`contact-messages`, `inspired-leads`), `app/Providers/AppServiceProvider.php`
- **Problem:** `POST /contact-messages` and `POST /inspired-leads` are unauthenticated, one-shot form submissions — a real visitor never submits either more than once or twice. They had no dedicated throttle, only the blanket `throttle:api` (120 requests/minute per IP) shared with normal authenticated shopping traffic. That ceiling allows up to 172,800 submissions/day from a single IP, each one inserting a DB row **and** dispatching a `SendAdminAlert` job (which forwards to Telegram) — a bot can cheaply flood both the `contact_messages`/`inspired_leads` tables and the admin's alert channel.
- **Scenario:** An automated bot posts garbage to `/api/v1/contact-messages` at close to 120/min, sustained; the admin's Telegram channel receives a new "📩 New message" alert every ~0.5s, burying real customer messages, while the database accumulates unbounded spam rows.
- **Test:** `tests/Feature/ApiHygieneTest.php::test_contact_message_submissions_are_rate_limited_per_ip`, `::test_inspired_lead_submissions_are_rate_limited_per_ip` — both submit 6 requests from the same test client (same IP) and assert the 6th gets `429`; fails on pre-fix code (`Expected response status code [429] but received 201`), passes after.
- **Fix:** New dedicated `RateLimiter::for('public-form', ...)` — 5/minute per IP, matching the style of the existing `forgot-password`/`otp` limiters (per-IP only; there's no authenticated account to also key on for an anonymous form). Applied via `throttle:public-form` to both routes. `analytics/event` was deliberately left on the generic `api` limiter — unlike a one-shot form, legitimate page-view/interaction tracking can fire many times per minute per visitor, so a tight per-IP cap there would drop real analytics data, not just spam. Commit: (this batch).
- **Evidence:**
  ```
  # pre-fix
  FAILED Tests\Feature\ApiHygieneTest > contact message submissions are rate limited per ip
  Expected response status code [429] but received 201.
  Tests: 1 failed (6 assertions)

  # post-fix
  PASS  Tests\Feature\ApiHygieneTest
  ✓ contact message submissions are rate limited per ip
  ✓ inspired lead submissions are rate limited per ip
  Tests: 5 passed (...) — full file, see 02-D2-catalog.md for the shared full-suite run
  ```

## Verified OK

- **Admin user management "can't demote/delete the last admin or yourself"**: still not applicable — confirmed again this pass that `Admin/UserController` remains read-only (`index`/`show`/`wishlist`/`addresses`); no mutation endpoint exists for roles or account status.
- **Settings caching**: unchanged from the spot-check — no cache layer exists to go stale.
- **Dead-code report**: see `results/02-D2-catalog.md` D2-F3 (`Campaign`, `Post` — unused, flagged for phase 03).

## Tests added/strengthened

- `tests/Feature/ApiHygieneTest.php::test_contact_message_submissions_are_rate_limited_per_ip` (new)
- `tests/Feature/ApiHygieneTest.php::test_inspired_lead_submissions_are_rate_limited_per_ip` (new)

## Test suite output

Full suite, run at the end of B3-full (D2+D8+Security), three consecutive runs:
```
Tests:    229 passed (1224 assertions)   Duration: 47.29s
Tests:    229 passed (1224 assertions)   Duration: 46.19s
Tests:    229 passed (1224 assertions)   Duration: 45.54s
```
Pint: `{"tool":"pint","result":"passed"}`
PHPStan level 5: `[OK] No errors`

## Frontend impact

- `POST /api/v1/contact-messages` and `POST /api/v1/inspired-leads` now return `429 Too Many Requests` after 5 submissions/minute from the same IP (matching the existing `429` shape used by `login`/`otp`/`forgot-password`). A real visitor submitting a contact form or phone-lead form normally does this once; only automated/burst traffic will ever see the `429`.
