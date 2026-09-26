# Phase 01 — Baseline

**Output:** `results/01-baseline.md`
**Mode:** measure + add tooling. Do not fix business logic in this phase.

The purpose is to know exactly where the project stands before anyone changes
anything, and to put automatic checks in place so later phases are measured
by tools, not opinion.

## Steps

1. **Branch:** `git checkout -b ai/quality-pass`. Record the starting sha.
2. **Install check:** `composer install`, `composer validate`. Record PHP version
   (`php -v`).
3. **Test suite:** `php artisan test --compact`. Record pass/fail/skip counts
   and paste every failure. Run it **3 times** — note any test that is flaky.
4. **Coverage (if Xdebug or PCOV is available):**
   `php artisan test --coverage --min=0`. Record the per-file coverage for
   `app/Services/*` and `app/Http/Controllers/*`. If no coverage driver, say so.
5. **Code style:** `vendor/bin/pint --test`. Record the number of files that
   would change. Then run `vendor/bin/pint`, commit it alone as
   `style: apply pint`, and rerun the tests.
6. **Static analysis:** add Larastan as a dev dependency
   (`composer require --dev larastan/larastan`), create `phpstan.neon` with
   `level: 5` over `app/`. Run `vendor/bin/phpstan analyse --memory-limit=1G`.
   - Record the error count grouped by file.
   - Do **not** fix them here. Generate a baseline file
     (`--generate-baseline phpstan-baseline.neon`) so the check is green, and
     record the count. Phase 03 will reduce the baseline.
7. **Dependency audit:** `composer audit`. Record every advisory.
8. **Routes:** `php artisan route:list --path=api --json > docs/AI/results/routes-baseline.json`.
   Record the total, and list any route with **no** `auth:sanctum` that looks
   like it should have it (report only).
9. **Built-in readiness check:** there is `app/Console/Commands/ProductionReadinessCheck.php`.
   Find its signature, run it, paste the output.
10. **Fresh DB:** `php artisan migrate:fresh --seed --env=testing` against the
    test DB. Must succeed with no errors.
11. **Map what is untested:** for every file in `app/Services` and every
    controller, write one line: which test file covers it, or `NONE`.

## Result file structure

```markdown
# 01 Baseline — results
## Starting point (branch, sha, php version)
## Test suite (3 runs, counts, failures, flaky tests)
## Coverage
## Pint
## Larastan (error count by file, baseline created)
## Composer audit
## Routes (total, suspicious unauthenticated)
## Production readiness command output
## migrate:fresh --seed
## Coverage map (service/controller → test file | NONE)
## Top 10 risk areas (your ranking, one line each, for phase 02)
```

## Exit criteria

- Tests green (or every failure recorded as a finding for phase 02).
- Pint clean, Larastan configured with baseline, both committed.
- Coverage map complete.
