# Phase 06 — Final Verification

**Output:** `results/06-final-verification.md`
**Mode:** verify only. If anything fails, fix it with the normal method and
rerun this whole phase from step 1.

Run everything from a clean state and paste the **real output** of each step.

1. `git status` clean, branch `ai/quality-pass` rebased on latest `main`.
2. `composer install --no-interaction` from scratch (delete `vendor/` first).
3. `php artisan migrate:fresh --seed --env=testing` — no errors.
4. `php artisan test --compact` — **3 consecutive runs**, all green, zero
   skipped (or each skip justified), zero flaky.
5. `vendor/bin/pint --test` — clean.
6. `vendor/bin/phpstan analyse` — zero errors, empty baseline.
7. `composer audit` — clean or justified.
8. `php artisan config:cache && php artisan route:cache && php artisan event:cache`
   — all succeed (proves no `env()` outside config, no closure routes); then
   `php artisan optimize:clear`.
9. Production readiness command (from phase 01) — all checks pass.
10. `php artisan route:list --path=api --json` — diff against
    `results/routes-baseline.json`. Every difference must be explained (should
    be none — contracts are frozen).
11. Newman run of `postman_collection.json` against a locally served app —
    all green.
12. Check `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`: mark each item done /
    not applicable / needs ops (things only the server owner can do, e.g.
    real Stripe keys, DNS, cron for `TrackShipments`, queue worker supervisor).
13. Every finding across all result files is `FIXED`, `WONT-FIX` (P3 only,
    with reason) or `NEEDS-DECISION` answered by the owner. List any leftovers.
14. Update `PROGRESS.md` and fill in [07-definition-of-done.md](07-definition-of-done.md).

## Result file structure

```markdown
# 06 Final verification — results
## Step 1..13 (command + output + PASS/FAIL)
## Leftovers (should be empty)
## Ops handover (things the owner must do on the server)
```
