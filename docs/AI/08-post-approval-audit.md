# Phase 08 — Post-approval audit (executor prompt)

You are the **executor** for a Laravel 12 store API (`D:\store\PROJECT\storeAPI`,
Windows, PHP ^8.2, Sanctum, PHPUnit 11, Pint, Larastan). A second AI (the
**reviewer**) checks your work, and the **owner** gives the final go-ahead.

## Why this phase exists

A full AI quality pass (phases 01–06) was **APPROVED** at commit `3cfcc6d`.
After that, 7 more commits landed on `main` with **no review**
(`55004ba`, `fc1871c`, `b74040b`, `4ea189e`, `1c4d4ba`, `e611d04`, `2952600`).
They touched about 47 files in `app/`, `config/`, `database/` and `routes/`. The largest,
`2952600 "fix cancel"`, quietly added a whole new **manual-capture payment
flow**. The owner wants proof that the project is clean, correct, and
needs nothing more. Opinions don't count as proof.

Your job: **audit → stop for approval → fix → prove.** Keep a skeptical
stance. The previous approval says nothing about the code written after it,
and some things it approved may have been wrong. One example: Definition of Done #8
requires an empty PHPStan baseline, but `phpstan-baseline.neon` still has
~205 entries.

## Read first (in this order)

1. `docs/AI/00-README.md`: **Hard rules, pre-submit checklist, severity scale,
   environment facts all apply here unchanged**, except where this file says
   otherwise. (Branch name is different, see below.)
2. `docs/AI/templates/finding.md`: format for every finding.
3. `docs/AI/03-clean-code.md`: the clean-code checklist you audit against.
4. `docs/AI/07-definition-of-done.md`: the bar to meet at the end.
5. `docs/AI/PROGRESS.md`: especially the **Open decisions** table.
6. Frozen contracts: `docs/AUTHENTICATION_CONTRACT.md`,
   `docs/PAYMENT_CHECKOUT_CONTRACT.md`, `docs/SHIPPING_CONTRACT.md`,
   `docs/API_V1_ROUTE_MIDDLEWARE.md`, plus the newer
   `docs/FRONTEND_ORDER_CANCELLATION.md` and `docs/FRONTEND_ADDRESS_EASYPOST.md`.

## Owner decisions already made (do not re-ask)

| Topic | Decision |
|---|---|
| Task | Audit + fix + prove with real command output |
| Scope | **Deep** review of everything changed in `3cfcc6d..HEAD`, plus a **full re-run** of every quality tool and a quick clean-code sweep of the whole project |
| PHPStan bar | **Level 5 with an EMPTY baseline** (zero entries, baseline include removed or file empty) |
| API contracts | **Fully frozen.** Any change to a route, request field, response shape, status code, enum value or error format → `NEEDS-DECISION`, don't change it |
| Payment flow | The **new manual-capture flow is the approved business behaviour.** It supersedes BR-01, L-PAY-007 (and any other decision it contradicts) in `PROGRESS.md` |
| Production | **Not launched.** Migrations added after `3cfcc6d` may be edited if truly needed (prove with `migrate:fresh --seed`). Older migrations: add new ones only |
| Git | New branch **`ai/post-approval-audit`** from `main`. Never commit to `main`, never push |
| Gate | **No code changes until the audit report is approved by the reviewer AND the owner** |
| Language | All reports in English |

## Stage A: Audit (REPORT ONLY, no code changes)

In Stage A you may create/modify **only** files under `docs/AI/`. No edits
to `app/`, `config/`, `database/`, `routes/`, `tests/`, `composer.*`,
`phpstan*`, `.env*`. If you catch yourself "just quickly fixing" something,
stop and write it down as a finding instead.

### A0. Setup and baseline evidence
1. `git checkout -b ai/post-approval-audit` from a clean `main`.
2. Confirm the test MySQL (`127.0.0.1:3308`, `storeapi_testing`) is up. If
   it isn't, stop and tell the owner. **Do not** switch tests to SQLite.
3. Run and paste the **real output** of each (Windows: don't redirect with
   PowerShell `>`, which writes UTF-16):
   - `php artisan test --compact` (full suite)
   - `./vendor/bin/pint --test`
   - `./vendor/bin/phpstan analyse --level=5` **with** the baseline, and
     again **without** it (count real errors, group by file and by error type)
   - `composer audit`
   - `php artisan config:cache && php artisan route:cache && php artisan event:cache`, then `php artisan optimize:clear`
   - `php artisan migrate:fresh --seed` against the **test** DB
   - `php artisan route:list --json`, diffed against
     `docs/AI/results/routes-current.json` (the approved snapshot). List every
     added/removed/changed route.

### A1. Line-by-line review of the post-approval diff
Run `git diff 3cfcc6d..HEAD -- app config database routes` and `git log -p
3cfcc6d..HEAD`. For **every changed file**, record:
- What changed and why (infer from the code and the commit).
- Whether the logic is correct. Give a concrete failing scenario or mark it "Verified OK" with
  a one-line reason.
- Which test covers it (name the test and the exact assertion). Uncovered behaviour → finding.
- Clean-code violations per `03-clean-code.md`. Watch in particular for
  controllers that grew fat (`Api/V1/OrderController` +123 lines,
  `AddressController` +96, `Admin/ShippingController` +79, `Admin/OrderController`
  +55), business logic in models (`Order` +85), new magic status strings
  (`authorized`, `refund_status` values) where enums/constants should be used,
  `env()` outside `config/`.
- Contract impact: new/changed response fields or enum values (e.g.
  `OrderResource` +24 lines, `AddressResource`, new `payment_status =
  authorized`). Check whether the contract docs and `storage/api-docs` were
  updated to match. **Do not revert** anything already shipped to the
  frontend docs. Report mismatches.

### A2. Hotspots (the owner suspects these, so go deep)

**1. Payment, manual-capture flow (approved behaviour, verify it is
correct end to end):**
- Checkout with `STRIPE_CAPTURE_METHOD=manual` → webhook sets
  `payment_status=authorized` + `authorized_at`; stock/coupon/quote handled
  exactly as in the paid flow.
- `orders:capture-authorized-payments` (every 5 min) captures only
  authorized, non-cancelled orders past the 3-hour window. Check that it is
  idempotent (idempotency key, re-run safe), and that a Stripe intent already
  `canceled` triggers an admin alert and never ships unpaid.
- Capture triggered by label purchase (`fulfillment_started_at`): ordering,
  transaction boundaries, behaviour if the capture fails.
- Customer cancel inside the window → hold released → `refund_status=released`,
  stock restored. After capture → refund via `SettleCancelledOrderPayment`
  job → `succeeded` / `pending` (webhook finalises) / `failed` + admin alert.
- Races: capture vs. cancel (payment lock), cancel vs. label purchase
  (`fulfillment_started_at`), duplicate webhooks, job retries.
- `STRIPE_CAPTURE_METHOD=automatic` path still works as before.
- **Known suspect:** `app/Models/Order.php:83` computes the cancel window from
  `created_at`, but `:90` uses `authorized_at ?? paid_at`. Confirm whether
  this is intentional or a bug.
- Money exactness: cents rounding, `refunded_amount`, partial refunds. Each
  rule needs an exact-value test (DoD #7).
- List every row of the `PROGRESS.md` decisions table that the new flow
  supersedes or contradicts (BR-01, L-PAY-007, D7-OBS-001, D4-OBS-002, L-PAY-011
  …). Write the **current actual behaviour** for each, so the owner can
  confirm it at the gate. Behaviour the code leaves ambiguous → `NEEDS-DECISION`.

**2. Cancellation (last commit `2952600 "fix cancel"`):** customer
self-cancel window (3h), cancellation requests + admin decision
(`Admin/CancellationRequestController`), stock restore, coupon handling,
mails (`CancellationRequestDecidedMail`), `OrderObserver` side effects,
authorization (can user A cancel user B's order?).

**3. EasyPost shipping:** USPS/UPS carrier-account restriction and
behaviour when `EASYPOST_*_CARRIER_ACCOUNT_ID` is missing; Standard/Express
mapping; package dimensions/weights config; quote TTL; label purchase
flow; webhook signature; `shipping:track` command; does
`FakeEasyPostService` still match `EasyPostServiceInterface` exactly?

**4. Images and media:** test/demo upload artifacts are **committed to git**
under `storage/media-library/temp/…` (must be removed from git + gitignored).
Check `ResolvesMediaUrls` and `ProductGalleryImageResource` changes (absolute
URLs, correct disk, `APP_URL`); conversion sizes; `CreatesDemoMedia` seeder;
upload security (`SecureImageUploadTest`); temp-file cleanup.

### A3. Whole-project clean-code sweep (quick)
Apply the `03-clean-code.md` checklist to the whole of `app/`, but report
only real findings, not style nitpicks (P3 at most). Then **categorise all
~205 PHPStan baseline entries** (by error type and file) and estimate the fix
effort for reaching zero.

### A4. Deliverable (end of Stage A)
Write `docs/AI/results/08-post-approval-audit.md`:

```markdown
# 08 Post-approval audit — results
## Executive summary (5–10 lines: is it shippable today? top risks)
## Baseline evidence (A0 outputs)
## Post-approval diff review (per file: verdict + covering test)
## Findings (templates/finding.md format, sorted P0 → P3)
## Verified OK (one line each)
## Decisions table: proposed updates (superseded rows + current behaviour)
## NEEDS-DECISION (options A/B + your recommendation)
## Fix plan (ordered list of planned commits: id, finding, test to write, files)
## Frontend impact (anything visible to the frontend)
```

Add a `08 Post-approval audit` row to `PROGRESS.md` with status
`READY-FOR-REVIEW (audit)`. Commit only `docs/AI/**` with
`docs(audit): post-approval audit report`. **STOP.**

## Gate (do not pass without both)

Continue to Stage B only when **both** of these hold:
1. `docs/AI/reviews/08-audit-review.md` has verdict `APPROVED`, and
2. `PROGRESS.md` row 08 says `OWNER-APPROVED` (the owner writes this, never you).

If the review says `CHANGES-REQUESTED`, append `## Round N response` to the
results file, fix the report, and stop again.

## Stage B: Fix (follow the approved plan only)

- Follow the approved fix plan in order. Anything outside the plan needs a new
  finding and approval, or goes into the report as carried forward.
- `00-README.md` hard rules apply: **test first** (fails before, passes
  after, with both outputs pasted), **one fix = one commit**
  (`fix(<domain>): <what> [<finding-id>]`), never weaken a test, no real
  external calls, never read/print/edit `.env`.
- Refactors (clean code, PHPStan baseline → 0) go in **separate** commits
  (`refactor(<area>): <what>`). Behaviour and responses stay byte-identical,
  and the full suite is green before every commit.
- Update the `PROGRESS.md` decisions table with the owner-confirmed rows.
- Remove the committed media artifacts from git and add the right
  `.gitignore` rule.

## Stage C: Final proof

Paste real output for:
1. Full suite **3 times in a row**, green, no unjustified skips.
2. `pint --test` clean.
3. `phpstan analyse --level=5` clean with an **empty baseline**.
4. `composer audit` clean or justified.
5. config/route/event cache succeed.
6. Route list identical to the approved snapshot, or every difference listed
   under Frontend impact and confirmed.
7. `migrate:fresh --seed` succeeds.
8. Newman run of `postman_collection.json` against a freshly seeded
   `php artisan serve`, if you can run it. If you can't, say so; don't fake it.
9. `git diff 3cfcc6d..HEAD -- tests | grep '^-.*assert'` empty or justified.
10. Re-tick every row of `07-definition-of-done.md` with a link to the evidence.

Append `## Stage B/C results` to the results file, set PROGRESS row 08 to
`READY-FOR-REVIEW (final)`, and **STOP**.

## Non-negotiables

- Never write "tests pass" / "verified" without the pasted output that proves it.
- Never claim something is checked if you skipped it. List it as not done.
- If you hit a wall (DB down, tool missing, a business rule you're unsure of),
  stop and write down what's blocked. Don't guess and don't work around it.
- No new features, no package upgrades, no rewrites for taste.
