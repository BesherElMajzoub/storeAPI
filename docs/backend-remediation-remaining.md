# Backend Remediation — Outstanding Items

**Date:** September 26, 2026 (updated — batch 3: tax, variant-`null` semantics, free-shipping default, and EasyPost weight capacities all confirmed by the business; production cookie-domain values added)
**Companion to:** `docs/backend-remediation-plan.md`
**Status at time of writing:** all code-actionable items from the remediation plan are implemented in the working tree and pass the full test suite (verified — see §6 for the current run). Every item that was a business decision is now resolved except `geo/me` (§2a). What remains is entirely production access, deployment, and data (§1, §3) — nothing left is blocked on a business call except `geo/me`, which is explicitly deferred, not blocking launch.

---

## 1. Blocked on Production Access (Ops)

These require credentials, database access, or infrastructure this session does not have.

| Item | What's needed | Source |
|---|---|---|
| Credential rotation | Rotate or delete any admin/demo account matching `ProductionReadinessCheck::demoAccountCount()`'s list in the **production** database; revoke their Sanctum tokens. | BLK-3 |
| EasyPost live configuration | Production `EASYPOST_API_KEY` / `EASYPOST_WEBHOOK_SECRET` and real warehouse `store_origin` (replace the placeholder). Package presets are filled in from your supplier quote (see §4 below) — weight capacities are now accepted for launch (§2). Then run the sandbox/live lifecycle once (quote → label → tracking). | BLK-2 |
| Product shipping-dimension backfill | Real per-product `weight_oz`/`length_in`/`width_in`/`height_in` for the 44 published products currently missing them. The code guard now blocks bulk-publishing without dimensions, but existing published rows still need real numbers from the catalog owner — the plan deliberately does not fabricate these. | BLK-1 |
| Demo catalog / demo account cleanup | Run `demoContentCount()`/`demoAccountCount()` (via `php artisan app:production-readiness`) against production and remove matches following the procedure in the plan's §7. | BE-4, BLK-3 |
| Backup/restore evidence | A verified restore-drill log for automated off-server encrypted backups. | D7 / D-E5 |
| Secret rotation, HTTPS, queue workers | Confirm production secrets are rotated, HTTPS/SSL is enforced, and queue workers are supervised (needed for `OrderShippedMail`/`SendAdminAlert` to actually fire). | D8 |
| Live Stripe/EasyPost credentials + test purchase | Configure live keys and record one verified live purchase end-to-end. | D9 |
| Production readiness run | Execute `php artisan app:production-readiness` against production (read-only, safe) and attach the output. | §11 of the plan |

---

## 2. Resolved Business/Product Decisions (September 26, 2026 — batch 3)

All of the following are now closed with a confirmed business answer. Nothing further is needed from the business on these; only deployment remains.

| Decision | Confirmed answer | Code state |
|---|---|---|
| Tax calculation | **Explicit launch decision: no tax system for this launch.** Not a rounding gap and not an unresolved blocker — tax is intentionally `0` at launch; a real tax system may be built later if needed. | `orders.tax` stays hardcoded to `0`. No code change required. |
| Variant `null` semantics (ADM-EP3 / Q28) — **RESOLVED** | **Approved as the official, permanent API contract:** an **omitted** field means "leave unchanged"; an **explicit `null`** means "clear the value." | Already implemented; this is now documented as the official contract (see `docs/PRODUCT_IMPORT_CSV.md`/`BACKEND_PRODUCTION_READINESS_RESPONSE.md` and reply to frontend closing Q28). No code change required. |
| Free shipping (Q3) — **RESOLVED** | **$100 automatic threshold confirmed correct, ships enabled by default.** Both mechanisms (automatic threshold + `free_shipping` coupon) stay exactly as implemented. | `GET/PUT /api/v1/admin/settings/shipping`; the $100/enabled default lives in `FreeShippingService`. No code change required. |
| Guest checkout (Q21) — confirmed, not open | Per the explicit requirement: checkout requires login, no guest path. | `POST /orders` still requires `auth:sanctum`; has an explicit regression test rather than relying on routing as an implicit side effect. |
| httpOnly cookies (Q22) — implemented | Web SPA can now authenticate via a first-party httpOnly session cookie (`Sanctum::statefulApi()`); Bearer-token clients are completely unaffected. | `bootstrap/app.php`, `AuthController`. Production values for `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN`/`SESSION_SECURE_COOKIE`/`SESSION_SAME_SITE` are given in §4a below. |
| EasyPost package weight capacities (§4) — **RESOLVED** | **Accepted for launch as-is:** `bag_small` 24 oz, `box_small` 32 oz, `bag_large` 48 oz, `box_medium` 64 oz, `box_large` 112 oz. | `config/services.php` already has these values. Kept documented as operational assumptions, not supplier-certified limits — worth a sanity check against real fulfillment post-launch, but not a launch blocker. |

## 2a. Still Open — Business/Product Decisions

| Decision | Options | Current code state |
|---|---|---|
| Public `geo/me` | Open `GET /api/v1/geo/me` to unauthenticated traffic, or tell the frontend to use a different signal | **No decision made yet.** Route stays admin-only/unchanged for now, per explicit instruction. |

---

## 3. Ready but Not Yet Deployed

Every item below is implemented, tested, and currently sitting **uncommitted in the working tree**. None of it is live until it's committed, deployed, and (where applicable) the frontend flags are flipped.

- BE-1, BE-2, BE-5 (public API defects)
- NEW-1 (bulk-publish dimension guard), NEW-3 (test isolation from Telegram)
- Q12 (`expires_at` on rate quotes), Q31 (Stripe-failure rollback of coupon/quote), Q30 (order-shipped email)
- Q24 (resume-payment / `POST /orders/{id}/checkout-session`)
- Q5, Q6b, Q7, Q8, Q15, Q17, Q20, Q29 (sitemap, audit-log read, stock-delta, fake shipping driver, batch slugs + wishlist merge, contact-message filters, admin-user resources/search, bulk order-status)
- **Q3** — free shipping: automatic threshold (`GET/PUT /admin/settings/shipping`, public read via `GET /shipping/free-shipping`) + `free_shipping` coupon type, with the real carrier cost preserved in `orders.carrier_shipping_cost`
- **Q21** — guest checkout confirmed unsupported (regression test only, no behavior change)
- **Q22** — httpOnly-cookie SPA authentication alongside unchanged Bearer-token support
- DEP-1 health endpoint (`GET /api/v1/health`)
- All six requested Section 5 documents + the filename-mismatch fix
- `docs/SHIPPING_CONTRACT.md` (Free shipping section added) and `docs/AUTHENTICATION_CONTRACT.md` (new) — frontend-facing integration contracts for everything in this batch

**Next step for all of the above:** commit, deploy, run `php artisan app:production-readiness` and the smoke tests from the plan's §8–9, then notify the frontend team (DEP-1) so they can re-run their own verification before flipping `VITE_CHECKOUT_SERVER_RATES` and `VITE_ADMIN_PRODUCT_ENDPOINTS_V2`.

---

## 4. New Input Received: Packaging Supplier Quote

You shared a supplier price list (photo transcription) for two package types, relevant to **BLK-2**'s "Standard Package Definitions" ask:

**Shipping bags** (140G material, black with gold logo):
| Size (cm) | Price/unit (RMB) |
|---|---:|
| 25 × 35 + 5 | 1.1 |
| 35 × 50 + 5 | 1.7 |

**Packaging boxes** (black box, gold logo, 1000-unit quantity, ~10–12 day lead time):
| Size (cm) | Price/unit (RMB) |
|---|---:|
| 25 × 24 × 8 | 3.38 |
| 34 × 36 × 9 | 5.8 |
| 50 × 35 × 12 | 9.3 |

(A `40 × 30 × 14 cm` size also appears on the box artwork itself — that looks like a mockup/design dimension, not a priced catalog entry.)

**Update — done:** `config/services.php`'s `easypost.packages` array has been replaced with these 5 real presets (converted cm → in), since you asked me to pick reasonable weight capacities myself rather than wait on exact numbers:

| Name | Size (in, L×W×H) | From | Assumed max weight |
|---|---|---|---|
| `bag_small` | 13.78 × 9.84 × 1.97 | bag 25×35+5 cm | 24 oz (1.5 lb) |
| `box_small` | 9.84 × 9.45 × 3.15 | box 25×24×8 cm | 32 oz (2 lb) |
| `bag_large` | 19.69 × 13.78 × 1.97 | bag 35×50+5 cm | 48 oz (3 lb) |
| `box_medium` | 14.17 × 13.39 × 3.54 | box 34×36×9 cm | 64 oz (4 lb) |
| `box_large` | 19.69 × 13.78 × 4.72 | box 50×35×12 cm | 112 oz (7 lb) |

The **dimensions are your real supplier data** (exact cm→in conversion). The **weight capacities are my judgment**, based on typical folded-garment load for boxes/mailers this size for a clothing/abaya store — not a spec from your supplier. This is called out directly in a comment above the array in `config/services.php` so it isn't mistaken for verified data later. The one test that hardcoded the old placeholder dimensions (`ShippingContractTest.php`) was updated to match, and the full suite passes.

**Confirmed for launch (§2):** these five weight limits are accepted as operational assumptions, not supplier-certified limits. Whoever packs orders should still sanity-check them against what actually gets shipped post-launch (a heavy multi-item abaya order that's fine on volume could still be over on weight, which correctly fails closed with "does not fit an available shipping package" rather than silently underquoting the rate) — but this is no longer a launch blocker.

---

## 4a. Production Environment Values — SPA / HttpOnly Cookie Domains

Frontend: `https://otantikqueen.com`. API: `https://apis.otantikqueen.com`. These are two subdomains of the same registrable domain (`otantikqueen.com`), which is the easy case for Sanctum's stateful-SPA cookie auth — no cross-site (`SameSite=None`) workaround is needed. Verified against `config/sanctum.php` and `config/session.php`, both of which already read these exact env vars with no code changes required:

```
SANCTUM_STATEFUL_DOMAINS=otantikqueen.com,apis.otantikqueen.com
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Why these values:
- `SANCTUM_STATEFUL_DOMAINS` must list every host that should receive the stateful cookie treatment — the frontend origin (`otantikqueen.com`, the one that appears in `Origin`/`Referer`) and, for consistency, the API host itself. No scheme, no wildcard needed since there are only two hosts.
- `SESSION_DOMAIN=null` (i.e. leave it unset) is correct here, not `.otantikqueen.com`. The session cookie only ever needs to be sent back to the host that issued it — `apis.otantikqueen.com` — because that's the only host the SPA calls. Leaving it unset makes Laravel scope the cookie to the exact issuing host, which is the tighter, more conventional setting; broadening it to `.otantikqueen.com` would also work but needlessly exposes the cookie to every other subdomain.
- `SESSION_SECURE_COOKIE=true` is required in production since both hosts are served over HTTPS — without it, browsers will still accept `SameSite=Lax` cookies over HTTP, but it's a hard requirement the moment `SameSite=None` is ever needed, and there's no reason to run a production auth cookie without `Secure` regardless.
- `SESSION_SAME_SITE=lax` is correct (not `none`) because per browser same-site rules, `otantikqueen.com` and `apis.otantikqueen.com` share the same registrable domain and are treated as **same-site** even though they're different origins — cross-subdomain XHR/fetch calls with `Lax` still carry the cookie. `SameSite=None` (which would additionally force `Secure`) is only needed if the frontend were ever moved to a genuinely different registrable domain (e.g. a Vercel preview URL not on `otantikqueen.com`).

This matches what `.env.example` and `docs/AUTHENTICATION_CONTRACT.md` already document generically — this section just fills in the real production values now that both domains are known.

---

## 5. Suggested Order of Operations

1. Set the production `.env` values in §4a so the frontend can adopt cookie-based auth.
2. Commit and deploy the code in §3 (includes the package config update, free shipping, and cookie auth).
3. Get real per-product shipping dimensions from the catalog owner → run the BLK-1 backfill.
4. Run `php artisan app:production-readiness` in production → resolves credential rotation, demo cleanup, and EasyPost/warehouse verification in one pass (it tells you exactly what's still failing).
5. Resolve the one remaining open decision, `geo/me` (§2a), whenever convenient — it does not block launch.
6. Notify frontend (DEP-1), let them verify, flip the two feature flags.

---

## 6. Test Suite Status

Full suite run after this batch's documentation updates (no application code changed in this pass): **184 tests / 1038 assertions, 0 failures.**
