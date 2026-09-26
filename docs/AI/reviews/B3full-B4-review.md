# Batch B3-full (D2+D8+Security) + B4-partial (Phase 03+04) — Review

**Verdict: B3-full APPROVED (D2, D8, Security all close out). B4 ACKNOWLEDGED
AS PARTIAL** — exactly as reported, nothing overclaimed. This is the second
batch in a row with no fabricated coverage; the honesty about what's
unfinished is itself worth noting in the final report.

Reviewed: commits 86b4483..7689692. Full suite independently rerun:
**231 passed (1251 assertions)**, Pint passed, PHPStan level 5 `[OK]`.
Matches.

## B3-full — APPROVED

### D8-F1 (public form flood) — verified
Dedicated `public-form` limiter (5/min/IP) applied to contact-messages and
inspired-leads only; `analytics/event` correctly left alone (legitimate high
frequency). Test proves the 6th submission gets 429. Good scope judgment —
didn't over-throttle traffic that's supposed to be frequent.

### D2-F2 (dashboard stuck at 0) — verified, and closes a D8 checklist item
Confirmed `current_orders_count`/`alerts.pending_orders` used the same dead
`'pending'` value as D7-F1. Same root cause, correctly cross-referenced
instead of treated as a coincidence. The new test also pins
`month_sales_total` to an exact value with mixed statuses — this satisfies
the "3 known metrics, exact values" requirement from the original D8
checklist in one commit. Good.

### Checklist closures — spot-verified myself, not just taken on your word
- Admin route grouping, `$guarded=[]`, CORS: re-confirmed (already checked in
  B2 review, unchanged).
- Scratch file removal: reran the reference grep myself
  (`map_routes|mapping_result|pasted-text|routes.json` across
  app/config/routes/composer.json/bootstrap) — zero hits. Safe.
- J14/D2-F4 also functions as your BOLA/IDOR sweep answer for reviews and
  cancellation requests — noted below under B4.

No disputes. D2, D8, and Phase 05 Security all move to **APPROVED**.

## B4 — Phase 03 (Clean code): ACKNOWLEDGED PARTIAL, small item to fix

### Verified good
- Scratch file removal, `.env.example` gap (Stripe/Google/OTP — genuinely
  the highest-risk config that was undocumented, good catch),
  `preventLazyLoading` (confirmed it forces non-production, i.e. `testing`
  too, and the suite is green under it), README/INSTRUCTIONS consolidation
  (confirmed `INSTRUCTIONS.md` is gone and `README.md` now has real content,
  not the Laravel skeleton) — all correct, all low-risk, all reversible via
  git history as claimed.
- Explicitly declining the 281-entry baseline sweep and the duplication/
  method-length/naming sweeps rather than rushing them is the right call —
  those need dedicated, unhurried passes, not a rider on this handover.

### D3-C-doc — P3, fix in your next commit
`results/03-clean-code.md`'s summary table says the baseline is
"281 -> 281 (unchanged)". It's actually **280** now — `84c2a3e` (the D2-F4
fix, committed after this table was written) removed the stale
`ReviewController::authorize()` suppression. Update the table and the two
sentences that repeat "281 entries" to 280. Small, but the whole point of
this process is that the paper trail is trustworthy; don't let a report go
stale after a later commit in the same handover changes the number it
reports.

### Carried forward (not blocking, already an honest list — just formalizing it)
- `phpstan-baseline.neon` full reduction (280 -> 0 at level 5, then attempt
  level 6).
- Duplicated-logic sweep, method-length/nesting sweep, magic-string-to-enum,
  naming audit.
- Decide and act on the dead `'pending'` order-status value (remove from
  enum/checks, or document why it's kept for historical data).
- `rollbackFailedCheckout` vs `OrderObserver` duplication (flagged since B2).

## B4 — Phase 04 (E2E journeys): ACKNOWLEDGED PARTIAL, one real finding

### J04 — verified as a genuine HTTP-only journey
Read the full test. Every step after login (address, real `POST
shipping/rates`, real `POST orders`, a properly signed Stripe webhook via
raw HMAC — not a shortcut helper — and the final `GET orders`) is a real HTTP
call; only Stripe/EasyPost themselves are mocked, exactly per the phase spec.
Asserts stock reserved at creation (not double-decremented at payment),
exactly one queued mail, and final visibility. This is what a journey test
is supposed to look like — no complaints.

### J08 — reasoning accepted
Agreed: `ConcurrentInventoryTest` already exercises real multi-process
concurrency at the layer where the race would actually happen. Rewriting it
as two racing HTTP calls would test the same guarantee through more
scaffolding for no added confidence. Correctly *not* claimed as a new HTTP
journey — logged as "already covered," which is accurate.

### J14 / D2-F4 — the standout finding of this batch
Read the fix and the policy. Confirmed: `ReviewPolicy` exists and is correct,
`ReviewController` is the only caller of `$this->authorize()` in the entire
codebase, and the base `Controller` is the conventional and correct place for
`AuthorizesRequests`. This was a **complete, unconditional 500 on two
customer-facing routes for every caller including the legitimate owner** —
not a narrow authz bug, a totally broken feature — sitting silently behind
a phpstan-baseline suppression of the exact error that would have caught it.
That the baseline was hiding a real, user-facing crash rather than a false
positive is exactly the risk flagged back in Phase 01 when the baseline was
first generated ("do not fix them here... phase 03 will reduce the
baseline") — this is a concrete example of why that reduction matters, worth
keeping as the lead example when you eventually write up phase 03's full
sweep.

Good instinct to stop and root-cause this properly instead of plowing through
more journeys once it surfaced — a shallow J14 that only checked "does it
403" without ever calling the routes for real would never have found it.

### Newman — correctly not run, and the caveat about `postman_collection.json`
staleness (dead `'pending'` cancel status, dashboard field drift) is a good
thing to have flagged now rather than at phase 06.

## Owner decisions
None needed this round — D7-OBS-001 was the only open item and is already
closed.

## Summary
| Domain | Verdict |
|---|---|
| D2 Catalog | APPROVED |
| D8 Misc | APPROVED |
| Phase 05 Security | APPROVED |
| Phase 03 Clean code | IN-PROGRESS (partial, correctly labeled) |
| Phase 04 E2E journeys | IN-PROGRESS (partial, correctly labeled; 1 P1 found+fixed) |

## Next step
Fix the one doc inconsistency (D3-C-doc) as your first commit, then continue
straight through the rest of B4: finish the `phpstan-baseline.neon` reduction
and the duplication/naming sweeps for phase 03, and J01-J03/J05-J07/J09-J13/
J15 plus the Newman run for phase 04 (refresh `postman_collection.json`
against the current routes first, per your own note). Then move into B5
(Phase 06 final verification) per batch mode.
