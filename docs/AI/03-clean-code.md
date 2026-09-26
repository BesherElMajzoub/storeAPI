# Phase 03 — Clean Code

**Output:** `results/03-clean-code.md`
**Mode:** refactor with a green test suite before and after **every** commit.
Behaviour must not change. API responses must stay byte-identical (contracts
are frozen).

Start only after all phase 02 domains are APPROVED — the tests written there
are the safety net for refactoring.

## Checklist

### Architecture (Laravel conventions for this repo)
- Controllers are thin: validate (FormRequest) → call service → return
  Resource. Business logic in controllers → move to the matching service.
- All input validation in `app/Http/Requests`; no `$request->validate()` or
  `$request->all()` passed straight into `create()/update()`.
- All responses through `ApiResponseTrait` + Resources; no ad-hoc arrays with
  different error shapes.
- External providers behind their interface (`app/Contracts`) and bound in
  `AppServiceProvider` — no `new EasyPostService()` in code.
- Side effects (mail, telegram, analytics) through jobs/events, not inline.

### Code
- Duplicated logic (same query/calculation in 2+ places) → one method.
- Methods > ~40 lines or > 3 nesting levels → split with clear names.
- Magic strings for statuses/roles/event types → PHP enums or class constants
  (keep DB values identical).
- N+1 queries: enable `Model::preventLazyLoading()` in the test environment,
  run the suite, fix every violation with eager loading.
- Dead code: unused classes, methods, routes, models, commands, config keys,
  root-level scratch files (`map_routes.php`, `mapping_result.txt`,
  `pasted-text.txt`, `routes.json`). Prove unused with grep before removing;
  list each removal.
- Commented-out code blocks → remove.
- Consistent naming (English, camelCase methods, snake_case DB columns).

### Static analysis
- Reduce `phpstan-baseline.neon` to **zero** at level 5. Then try level 6; fix
  or document what remains.
- Pint clean.

### Config & repo hygiene
- Every `env()` call is only in `config/*` (never in app code — breaks
  `config:cache`).
- `.env.example` lists every env var the code reads, with safe placeholders.
- `README.md` / `INSTRUCTIONS.md`: accurate setup and test instructions
  (one document, not two conflicting ones).

## Rules
- One refactor topic per commit: `refactor(<area>): <what>`.
- Run the full suite before each commit; paste the final run in the result file.
- If a refactor would change a response or needs a decision → skip it and log
  as NEEDS-DECISION.

## Result file structure

```markdown
# 03 Clean code — results
## Summary (before/after: phpstan errors, baseline size, N+1 count, files removed)
## Changes (commit sha — what — why)
## Findings not fixed (with reason)
## Final test + pint + phpstan output
```
