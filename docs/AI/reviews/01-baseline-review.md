# 01 Baseline — Review (round 1)

**Verdict: CHANGES-REQUESTED** (small; items R1–R3 block approval, R4–R5 do not)

Reviewed: `results/01-baseline.md`, commits `ba5534e`, `9a4dcdc`, `835c9ae`,
`713fda9` on `ai/quality-pass`.

## Independently verified — OK

- `php artisan test --compact` → **184 passed (1038 assertions)**, 64.5s. Matches.
- `vendor/bin/pint --test` → passed. Matches.
- `vendor/bin/phpstan analyse` → `[OK] No errors` with baseline; `phpstan.neon`
  is level 5 over `app/`, baseline included. Matches.
- `composer.json` diff vs `main`: only `larastan/larastan` added to
  `require-dev`. Good.
- Pint commit `9a4dcdc`: spot-checked the non-whitespace diff in `app/` — quote
  style, trailing commas, imports. No behaviour change seen. Kept separate from
  other changes. Good.
- Commit hygiene (one topic per commit, clear messages): good.

## Required changes

### R1 — `routes-baseline.json` is unusable (blocking)
The file is **UTF-16 LE with BOM** (PowerShell `>` redirect). Git stores it as
binary, and PHP `json_decode` returns `null` on it, so the phase 06 diff can't
be done.
- Regenerate it as UTF-8 without BOM. Use Git Bash:
  `php artisan route:list --path=api --json > docs/AI/results/routes-baseline.json`,
  or write it from PHP. Then confirm `file` shows UTF-8/ASCII and
  `php -r 'var_dump(count(json_decode(file_get_contents("docs/AI/results/routes-baseline.json"), true)));'`
  prints a number.
- **The route count doesn't add up.** `--path=api` returns **176** routes
  today, because it also matches `telescope/telescope-api/*`. You reported 133.
  Write down the exact command and filter used to get the number, and use that
  same definition again in phase 06.

### R2 — The unauthenticated-route scan was too narrow (blocking)
`SUSPICIOUS_UNAUTHENTICATED=0` only covered the admin, order, profile and
wishlist groups. The plan asks about **every** route. Add a table of every route
without `auth:sanctum` (there are 28 outside Telescope, plus the Telescope
routes), each with a one-line reason why it's public. Note these for later
phases. You don't have to fix them now:
- `GET api/documentation`, `GET api/oauth2-callback`: Swagger UI with **no
  middleware at all**. It would expose the full API docs in production. Log it
  as a NEEDS-DECISION in phase 05.
- `telescope/telescope-api/*`: protected only by the `viewTelescope` gate
  (`TelescopeServiceProvider.php:116`) plus `TELESCOPE_ENABLED`. Phase 05 must
  verify the gate works with this app's auth setup (Sanctum SPA vs web session).
- `POST shipping/rates`, `POST shipping/verify-address`: public, and they call
  EasyPost, which is a paid API. They only use the default `api` throttle.
  `address/*` uses 60/min. This is a cost-abuse vector, so carry it to D6 and
  phase 05.
- `POST webhooks/stripe` and `POST webhooks/easypost` sit behind the general
  `api` throttle. Check in D5 and D6 whether a burst of provider retries could
  get a 429 and lose events.

### R3 — Coverage map has wrong `NONE` entries (blocking, small)
- `Api/V1/ContactMessageController` is exercised by
  `ApiHygieneTest.php:42` and `TelegramNotificationTest.php:155`.
- `Admin/DashboardController` is exercised by `ProductionReadinessTest.php:52-56`
  (auth only, not the numbers. Say so).

Recheck every other `NONE` by grepping `tests/` for the **route URIs**, not
just class names, and fix the map.

### R4 — Coverage driver (non-blocking, please try)
Without PCOV or Xdebug, Definition-of-Done item 6 can only be checked
statically. Try to install PCOV (or Xdebug in coverage mode) for PHP 8.5 on
this machine and add the per-file numbers. If you can't, record why in one line.

### R5 — Production readiness failures need a classification (non-blocking)
Split the 8 FAILs into **expected locally** (APP_ENV, debug, demo data,
Telescope) and **must be set on the server** (live Stripe key, HTTPS frontend
URL, warehouse origin). Carry the second list forward to the phase 06 ops
handover. Also note that the local PHP is 8.5.2. The production PHP version
should go in the handover too.

## Next step
Fix R1–R3 (and R4–R5 if you can). Append a `## Round 2 response` section to
`results/01-baseline.md`, set the status back to `READY-FOR-REVIEW`, and stop.

---

# 01 Baseline — Review (round 2)

**Verdict: APPROVED** — proceed to phase 02, starting with **D5 Payments**.

Reviewed commit `1511835`.

## Verified
- **R1:** `routes-baseline.json` is now UTF-8 JSON (`file` → JSON text data);
  `json_decode` → 176 routes. Count definition (133 `api/*` + 43
  `telescope/telescope-api/*`, via `route:list --path=api`) recorded. Use
  exactly this definition in phase 06. ✅
- **R2:** All 71 routes without `auth:sanctum` are listed with a reason, and
  the four flagged items are carried to D5, D6 and phase 05. ✅
- **R3:** Coverage map corrected. I checked the new entries:
  `GoogleAuthService` → `ProductionReadinessTest` (the only test that hits
  `auth/google`); `GooglePlacesService` → `AddressControllerTest`; contact and
  dashboard entries are correct. The remaining `NONE` list is accepted. Close
  these gaps in D2 (Category, SKU), D8 (InspiredLead, Geo) and phase 05
  (Telescope API). ✅
- **R4:** Accepted as documented. Tip for later: on Windows, PCOV/Xdebug can be
  installed without the PECL CLI by placing the matching `php_*.dll` (TS, x64,
  VS17) in `ext/` and enabling it in `php.ini`, if a PHP 8.5 build exists. Not
  required. ✅
- **R5:** Classification accepted; handover items carried to phase 06. ✅

## Process note
From now on, commit the `reviews/*.md` files together with your next response
commit so the review history is in git. You may commit
`reviews/01-baseline-review.md` in your first D5 commit.

## Next step
Phase 02 order: **D5 → D4 → D3 → D1 → D6 → D7 → D2 → D8**. Stop after each
domain for review.
