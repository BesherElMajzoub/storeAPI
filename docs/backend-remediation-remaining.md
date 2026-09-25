# Backend Remediation — Outstanding Items

**Date:** September 26, 2026 (updated — batch 2: free shipping, guest-checkout confirmation, httpOnly-cookie SPA auth)
**Companion to:** `docs/backend-remediation-plan.md`
**Status at time of writing:** all code-actionable items from the remediation plan are implemented in the working tree and pass the full test suite (**183/183, 1032 assertions**, verified). Nothing below can be closed by writing more code alone — each item needs production access or a business decision the code itself can't make. One exception already has a default: free shipping ships enabled at **$100** and only needs a decision if that number is wrong.

---

## 1. Blocked on Production Access (Ops)

These require credentials, database access, or infrastructure this session does not have.

| Item | What's needed | Source |
|---|---|---|
| Credential rotation | Rotate or delete any admin/demo account matching `ProductionReadinessCheck::demoAccountCount()`'s list in the **production** database; revoke their Sanctum tokens. | BLK-3 |
| EasyPost live configuration | Production `EASYPOST_API_KEY` / `EASYPOST_WEBHOOK_SECRET` and real warehouse `store_origin` (replace the placeholder). Package presets are now filled in from your supplier quote (see §4 below) — still need your sign-off on the assumed weight capacities before they go live. Then run the sandbox/live lifecycle once (quote → label → tracking). | BLK-2 |
| Product shipping-dimension backfill | Real per-product `weight_oz`/`length_in`/`width_in`/`height_in` for the 44 published products currently missing them. The code guard now blocks bulk-publishing without dimensions, but existing published rows still need real numbers from the catalog owner — the plan deliberately does not fabricate these. | BLK-1 |
| Demo catalog / demo account cleanup | Run `demoContentCount()`/`demoAccountCount()` (via `php artisan app:production-readiness`) against production and remove matches following the procedure in the plan's §7. | BE-4, BLK-3 |
| Backup/restore evidence | A verified restore-drill log for automated off-server encrypted backups. | D7 / D-E5 |
| Secret rotation, HTTPS, queue workers | Confirm production secrets are rotated, HTTPS/SSL is enforced, and queue workers are supervised (needed for `OrderShippedMail`/`SendAdminAlert` to actually fire). | D8 |
| Live Stripe/EasyPost credentials + test purchase | Configure live keys and record one verified live purchase end-to-end. | D9 |
| Production readiness run | Execute `php artisan app:production-readiness` against production (read-only, safe) and attach the output. | §11 of the plan |

---

## 2. Blocked on Business/Product Decisions

Two of these are now implemented (Q3, Q22) and one is now confirmed as a final answer rather than an open question (Q21) — kept here because each still needs a human input the code can't supply.

| Decision | Options | Current code state |
|---|---|---|
| Tax calculation | Flat rate / destination-based / provider (e.g. Stripe Tax) / explicitly none for this launch | `orders.tax` still hardcoded to 0 — no code changed pending this decision. |
| Public `geo/me` | Open `GET /api/v1/geo/me` to unauthenticated traffic, or tell the frontend to use a different signal | Route still admin-only; not changed. |
| Variant `null` semantics (ADM-EP3 / Q28) | The code now treats an **omitted** field as "leave unchanged" and an **explicit `null`** as "clear the value." This needs a formal sign-off that this is the intended contract (it matches what the frontend said they'd rather have) so it can be documented and relied on. | Implemented, needs confirmation + a reply to frontend closing Q28. |
| **Free shipping (Q3) — implemented, ships with a default** | Both mechanisms (automatic threshold + `free_shipping` coupon) are built and **enabled by default at a $100 threshold**. The only remaining business call is whether $100 is the right number, or whether to disable it at launch. | `GET/PUT /api/v1/admin/settings/shipping`; the $100/enabled default lives in `FreeShippingService` and applies automatically until an admin changes it. |
| **Guest checkout (Q21) — confirmed, not open** | Per the explicit requirement: checkout requires login, no guest path. | `POST /orders` still requires `auth:sanctum`; now has an explicit regression test rather than relying on routing as an implicit side effect. |
| **httpOnly cookies (Q22) — implemented** | Web SPA can now authenticate via a first-party httpOnly session cookie (`Sanctum::statefulApi()`); Bearer-token clients are completely unaffected. | `bootstrap/app.php`, `AuthController`. Needs `SANCTUM_STATEFUL_DOMAINS`/`SESSION_SECURE_COOKIE` set for the production domain before the frontend can rely on it (see `.env.example`). |

---

## 3. Ready but Not Yet Deployed

Every item below is implemented, tested, and currently sitting **uncommitted in the working tree**. None of it is live until it's committed, deployed, and (where applicable) the frontend flags are flipped.

- BE-1, BE-2, BE-5 (public API defects)
- NEW-1 (bulk-publish dimension guard), NEW-3 (test isolation from Telegram)
- Q12 (`expires_at` on rate quotes), Q31 (Stripe-failure rollback of coupon/quote), Q30 (order-shipped email)
- Q24 (resume-payment / `POST /orders/{id}/checkout-session`)
- Q5, Q6b, Q7, Q8, Q15, Q17, Q20, Q29 (sitemap, audit-log read, stock-delta, fake shipping driver, batch slugs + wishlist merge, contact-message filters, admin-user resources/search, bulk order-status)
- **Q3** — free shipping: automatic threshold (`GET/PUT /admin/settings/shipping`) + `free_shipping` coupon type, with the real carrier cost preserved in `orders.carrier_shipping_cost`
- **Q21** — guest checkout confirmed unsupported (regression test only, no behavior change)
- **Q22** — httpOnly-cookie SPA authentication alongside unchanged Bearer-token support
- DEP-1 health endpoint (`GET /api/v1/health`)
- All six requested Section 5 documents + the filename-mismatch fix

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

**Still worth doing before launch:** have whoever packs orders sanity-check these five weight limits against what actually gets shipped (a heavy multi-item abaya order that's fine on volume could still be over on weight, which would now correctly fail closed with "does not fit an available shipping package" rather than silently underquoting the rate — but it's worth confirming that failure is rare, not routine).

---

## 5. Suggested Order of Operations

1. Sanity-check the assumed package weight capacities in §4 against real fulfillment (or accept them as-is).
2. Confirm the $100 free-shipping default (or change/disable it) via `PUT /admin/settings/shipping`.
3. Set `SANCTUM_STATEFUL_DOMAINS` (and `SESSION_SECURE_COOKIE=true`) for the production domain so the frontend can adopt cookie-based auth.
4. Commit and deploy the code in §3 (includes the package config update, free shipping, and cookie auth).
5. Get real per-product shipping dimensions from the catalog owner → run the BLK-1 backfill.
6. Run `php artisan app:production-readiness` in production → resolves credential rotation, demo cleanup, and EasyPost/warehouse verification in one pass (it tells you exactly what's still failing).
7. Resolve the remaining open decisions in §2 (tax, `geo/me`, Q28 sign-off — can happen in parallel with 1–6).
8. Notify frontend (DEP-1), let them verify, flip the two feature flags.
