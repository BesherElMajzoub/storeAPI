# Finding template

Copy this block for every finding. IDs: `<PHASE>-<DOMAIN>-<NNN>`,
e.g. `L-PAY-003`, `C-ORD-001`, `E-J04-001`, `S-AUTH-002`.

```markdown
### L-PAY-003 — <one-line title>

- **Severity:** P0 | P1 | P2 | P3
- **Status:** OPEN | FIXED | NEEDS-DECISION | WONT-FIX (reason)
- **Location:** `app/Services/StripeCheckoutService.php:142`
- **Problem:** What is wrong, in one or two sentences.
- **Scenario:** Concrete input/state → wrong result. (e.g. "Webhook
  `checkout.session.completed` delivered twice → order marked paid twice,
  stock decremented twice.")
- **Test:** `tests/Feature/...Test.php::test_...` — fails before, passes after.
- **Fix:** What changed and why this is the right fix. Commit: `<sha>`.
- **Evidence:**
  ```
  <paste real output: failing run, then passing run>
  ```
- **Decision needed (only if NEEDS-DECISION):** options A / B, with trade-offs.
```

Rules:
- No finding without a concrete scenario. "Might be a problem" is not a finding.
- If you checked something and it is correct, list it under a
  `## Verified OK` section with one line of reasoning — the reviewer needs to
  know it was checked, not skipped.
