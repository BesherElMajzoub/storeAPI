# AI Quality Pass — Master Instructions

You are the **executor**. A second AI (the **reviewer**) checks your work after
every phase. Read this whole file before starting, then execute the phase files
**in order**. Do not start a phase until the previous one is `APPROVED` in
`reviews/`.

## Goal

Ship this Laravel 12 store API in a state where it needs no further work:
correct business logic, clean code, every user journey verified end to end,
no known security holes. "Done" is defined by the checklist in
[07-definition-of-done.md](07-definition-of-done.md) — not by opinion.

## Folder layout

```
docs/AI/
  00-README.md                 ← you are here (rules + workflow)
  01-baseline.md               ← phase plans (read-only for you)
  02-logic-review.md
  03-clean-code.md
  04-e2e-journeys.md
  05-security.md
  06-final-verification.md
  07-definition-of-done.md
  PROGRESS.md                  ← you update this after every step
  templates/finding.md         ← format for every finding
  results/                     ← YOU write here, one file per phase/domain
  reviews/                     ← REVIEWER writes here, one file per phase
```

Each result file is its own `.md`. Never merge two phases into one file.
Naming: `results/<phase-number>-<name>.md`, e.g. `results/02-D5-payments.md`.

## Workflow per phase

1. Read the phase file fully.
2. Do the work. For every problem found, write a finding using
   `templates/finding.md` in that phase's result file.
3. Fix it (unless the phase says "report only" or the fix needs a product
   decision — then mark it `NEEDS-DECISION` and move on).
4. Paste **real command output** as evidence. Never write "tests pass" without
   the output that proves it.
5. Update `PROGRESS.md`: set the phase to `READY-FOR-REVIEW`.
6. **Stop.** Wait for `reviews/<phase>-review.md`.
   - `APPROVED` → next phase.
   - `CHANGES-REQUESTED` → fix every listed item, append a
     `## Round N response` section to the same result file, set status back to
     `READY-FOR-REVIEW`, stop again.

## Hard rules

1. **Branch:** work on `ai/quality-pass`, never commit to `main`.
2. **One fix = one commit.** Message: `fix(<domain>): <what> [<finding-id>]`.
3. **Test first for every bug:** write a test that fails on the current code,
   commit-ready, then fix, then show it passing. No test → the finding is not
   fixed.
4. **Never weaken a test** to make it pass (no deleting assertions, no
   `markTestSkipped`, no loosening expected values) unless the test itself is
   proven wrong — then document why in the finding.
5. **Contracts are frozen.** The frontend depends on:
   `docs/AUTHENTICATION_CONTRACT.md`, `docs/PAYMENT_CHECKOUT_CONTRACT.md`,
   `docs/SHIPPING_CONTRACT.md`, `docs/API_V1_ROUTE_MIDDLEWARE.md`.
   Any change to a route, request field, response shape, status code or error
   format → `NEEDS-DECISION`, do not change it.
6. **No real external calls.** Stripe, EasyPost, Geoapify, Google, Telegram and
   mail must be faked/mocked in tests. Never read or print real secrets from
   `.env`. Never edit `.env`.
7. **No schema-destructive migrations.** Add new migrations only; never edit an
   existing migration that could have run in production.
8. **Stay in scope.** No new features, no framework/package upgrades, no
   rewrites "because it's nicer". Refactors belong to phase 03 only.
9. **When unsure, write it down** as `NEEDS-DECISION` with the options — do not
   guess on business rules (prices, stock, refunds, tax, statuses).
10. **Full test suite must be green at the end of every phase.**

## Environment facts

- PHP ^8.2, Laravel 12, Sanctum, PHPUnit 11, Pint.
- Tests use MySQL: `127.0.0.1:3308`, database `storeapi_testing`
  (see `phpunit.xml`). Make sure it is running before phase 01.
- Run tests: `php artisan test --compact` (or `composer test`).
- Existing docs worth reading first: `backend-open-items.md`,
  `docs/BACKEND_PRODUCTION_READINESS_RESPONSE.md`,
  `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`.

## Severity scale

| Level | Meaning | Must be fixed before done? |
|---|---|---|
| P0 | Money, stock, data loss, auth bypass, crash on a main path | Yes |
| P1 | Wrong behaviour a user will hit; security weakness | Yes |
| P2 | Edge case, missing validation, maintainability risk | Yes, or justified |
| P3 | Style / nice-to-have | Optional |
