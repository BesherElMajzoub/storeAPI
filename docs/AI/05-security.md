# Phase 05 — Security

**Output:** `results/05-security.md`
**Mode:** find → prove with a test (or exact reproduction) → fix.

Reference: OWASP API Security Top 10. Every item below gets either a finding or
a `Verified OK` line with the reason (file:line of the protection).

## Checklist

### Access control
- [ ] BOLA/IDOR: every route with an `{id}` checks ownership (J14 covers the
      API; here also check jobs, exports, media URLs, signed URLs).
- [ ] Every admin route has `auth:sanctum`, `can:admin-access`,
      `audit.admin`, throttle (compare with `docs/API_V1_ROUTE_MIDDLEWARE.md`).
- [ ] Mass assignment: every model's `$fillable`/`$guarded` — can a user set
      `role`, `is_admin`, `status`, `total`, `paid_at`, `user_id`, `stock`?
- [ ] Resources never expose: password hashes, tokens, OTPs, internal notes,
      other users' emails/addresses, Stripe secrets, full payment data.

### Authentication
- [ ] Rate limits on login, register, OTP, password reset, public tracking,
      contact, leads, analytics.
- [ ] Token/session invalidation on logout and password change.
- [ ] Sanctum stateful domains and CORS allow-list are explicit (no `*` with
      credentials).

### Input & files
- [ ] Image upload: type checked by content (not extension), size limit,
      stored outside executable paths, random names (see
      `SecureImageUploadTest`, `RejectOversizedRequests`).
- [ ] CSV import: formula injection on export, size/row limits.
- [ ] Raw SQL (`DB::raw`, `whereRaw`, `orderByRaw`, `selectRaw`) — every one
      uses bindings; sort/filter fields are allow-listed.
- [ ] No user input in `redirect()`/URLs without validation (open redirect in
      Stripe success/cancel URLs).

### Webhooks & integrations
- [ ] Stripe and EasyPost webhooks: signature/secret verified, timestamp
      tolerance, replay-safe, CSRF-exempt only for those routes.
- [ ] Outgoing HTTP calls have timeouts and don't leak secrets in logs.

### Configuration & ops
- [ ] Production: `APP_DEBUG=false`, Telescope disabled or admin-only
      (`TelescopeServiceProvider`, `TelescopeApiController`), no stack traces
      in API errors.
- [ ] `SecurityHeaders` middleware applied globally; values correct for an API.
- [ ] No secrets committed: scan git history
      (`git log -p | grep -iE "sk_live|sk_test|whsec_|api[_-]?key|password="`)
      and the working tree. `.env` must not be tracked.
- [ ] Logs don't contain OTPs, passwords, tokens, full card/Stripe payloads.
- [ ] `composer audit` clean (or each advisory justified).

## Result file structure

```markdown
# 05 Security — results
## Checklist (each item → finding ID or Verified OK + file:line)
## Findings
## Secret scan output
## Full test suite output
```
