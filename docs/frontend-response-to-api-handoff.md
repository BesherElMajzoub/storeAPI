# Frontend Response to `FRONTEND_API_INTEGRATION_HANDOFF.md`

**Project:** Otantik Queen E-commerce
**From:** Frontend Team
**To:** Backend Team
**Date:** September 25, 2026
**Responds to:** `FRONTEND_API_INTEGRATION_HANDOFF.md`. The copy received on September 25 is identical to the September 3 version.
**Companion document:** `backend-requests-and-clarifications (2).md` (Revision 2, September 24). It holds every open item addressed to you. This response does not repeat those items.

---

## 1. Summary

We checked the frontend against every instruction in your handoff.

- **All 12 items in your §2 action list are implemented.** Section 2 lists where each one is in the code.
- **Four gaps were found and fixed on September 25** (Section 3). The most important: checkout now sends `billing_address: null` as your §4.2 shows, and it now handles every HTTP status in your §3 table, including the `403` for an unverified email.
- **Two features stay switched off** until your side is confirmed deployed: the checkout contract (`VITE_CHECKOUT_SERVER_RATES`) and the Phase 4 admin endpoints (`VITE_ADMIN_PRODUCT_ENDPOINTS_V2`). Section 4 explains what turns each one on.
- **We cannot verify the flow end to end against production yet.** All 44 products still have no shipping measurements, so every rates call returns `422 shipping_configuration` (**BLK-1** in the companion document). Everything below is verified by automated tests against your documented contract, not against live data.

Automated gate after the fixes: `eslint` 0 errors · `tsc` clean · **481/481 tests passing** (up from 461) · production build clean.

---

## 2. Your §2 Action List — Status

| # | Your instruction | Status | Where |
| --- | --- | --- | --- |
| 1 | Request rates with `address + items`; never send a parcel or a price | ✅ Done | `src/infrastructure/services/shippingRates.ts` (`buildShippingRatesRequest`) |
| 2 | Keep `rate_id`, send it as `shipping_rate_id`; 15-minute quote | ✅ Done. If the quote is within 60 s of expiry, it is refreshed automatically before submitting | `CheckoutPage.tsx`, `useShippingQuote.ts` |
| 3 | Re-quote on any cart, quantity, variant or address change, and on expiry | ✅ Done. A new quote is requested whenever address, items, variants or quantities change | `src/modules/checkout/hooks/useShippingQuote.ts` |
| 4 | Treat `503` as a provider outage; never continue with zero shipping | ✅ Done on rates: submit stays blocked and a retry is offered. **Fixed on order create** (Section 3.2) | `src/shared/utils/checkoutErrors.ts` |
| 5 | Add the nullable `shipment` object to customer and admin DTOs | ✅ Done. The deprecated top-level `tracking_number` is deliberately **not read** | `src/infrastructure/api/mappers/shipment.mapper.ts` |
| 6 | Never expect `label_url` / Stripe / EasyPost IDs on customer orders | ✅ Done. They are only read on admin DTOs | `order.contracts.ts`, `admin.contracts.ts` |
| 7 | Use `POST /orders/track` | ✅ Done. The same message is shown for both 404 cases, and `429` shows "wait and retry" | `src/modules/static/pages/TrackOrderPage.tsx` |
| 8 | Show the label action only for `paid` + `processing` | ✅ Done. **409/422 split fixed** (Section 3.3) | `src/modules/admin/pages/AdminOrderDetailPage.tsx` |
| 9 | Product shipping inputs in oz/in; publishing requires all four | ✅ Done. The form refuses to publish without category, price and the four measurements | `ProductMeasurementsFields.tsx`, `productFormSchema.ts` |
| 10 | `POST …/images/order` with `image_ids` | ✅ Built, **switched off** until DEP-1 is confirmed (Section 4) | `useAdminQuery.ts` |
| 11 | Transactional variant diff | ✅ Built, **switched off** until DEP-1 is confirmed. The one open question is Q28 (`null` semantics) | `productFormPayload.ts` |
| 12 | CSV import: `dry_run=true` first, show every row, commit only after confirmation | ✅ Done. The identical file is re-sent with `dry_run=false` only on explicit confirmation | `AdminProductImportDialog.tsx` |

**Other handoff sections**

| Section | Status |
| --- | --- |
| §4.1 six rates error codes | ✅ All six mapped to specific messages. The three "cart is stale" codes link back to the cart |
| §4.2 four quote-mismatch codes | ✅ The frontend discards the rate, re-quotes and returns the customer to rate selection |
| §4.3 legacy `address + parcel`, `street1`/`zip`, `easypost_shipment_id` | ✅ Not used by the new checkout. The legacy code path exists only while `VITE_CHECKOUT_SERVER_RATES` is off, and is deleted after launch |
| §5.1 shipment status enum + `unknown` fallback | ✅ All ten values have labels, and anything unrecognised shows as `unknown` |
| §5.3 / §6.2 `GET …/tracking` refresh endpoints | Not used. As you recommend, the UI reads the stored snapshot from the order resource |
| §6.1 empty body, `/label` not `/ship`, idempotent repeat | ✅ Sends `{}`, calls `/label` only, and displays your message (including "Shipping label already exists.") |
| §7.2 per-variant measurement overrides | Not implemented. Variants inherit the product's measurements, which your handoff says is the default. We will add overrides if the catalogue needs them |
| §7.4 8 images / 5 MB | ✅ Enforced client-side before upload |
| §7.5 bulk cap of 100 IDs | ✅ Bulk selection is limited to one list page (at most 100 rows) |
| §7.7 120 requests/minute | ✅ Noted. It only matters for the per-product fan-out used while `VITE_ADMIN_PRODUCT_ENDPOINTS_V2` is off |

---

## 3. Gaps Found and Fixed (September 25)

### 3.1 `billing_address` is now `null`
Checkout used to send the shipping-address object as `billing_address`. Your swagger schema for `billing_address` uses different keys (`first_name`, `last_name`, `address_line_1`). That risked a `422` on every order. Checkout now sends `billing_address: null`, exactly as your §4.2 example shows.
**This closes Q23** in the companion document.

### 3.2 Every documented order-create status now has specific handling
Before, any non-422 failure on `POST /orders` showed your raw English `message`, so Arabic customers saw English text. Each status now has specific handling, with translated messages in English and Arabic. The cart and address are kept in every case, because the cart is only cleared after a successful create.

| Status (your §3) | Frontend behaviour |
| --- | --- |
| `403` — email verification required | Checkout reads `email_verified_at` from `/auth/me`, so an unverified customer is **stopped before filling the form**. They see a **"Verify my email now"** link and are **returned to checkout** after the OTP. If a `403` still arrives, we re-read the profile: if it now says unverified, the same link is shown; if it says verified, your `message` is shown instead, to avoid a verification loop |
| `409` — state conflict | Shows your `message` and any field errors, since a 409 is not always stock. Also refreshes the cart against the API so quantities are corrected if it was stock |
| `503` — carrier repricing unavailable | "Couldn't confirm shipping — your cart and address are saved, try again" |
| `502` — Stripe session failed | Tells the customer to check **My orders** before retrying, and refreshes that list so a created order appears. **See Q31 in Section 5** |
| `429` — rate limited | "Wait a minute and try again" |

**This closes Q26** in the companion document: the §3 table answers it with `403`.

### 3.3 Label purchase: `409` and `422` are now shown differently
Before, the admin order screen treated both as "this order is not ready for a label". Per your §6.1:
- **`409`** → "This order is not ready for a label": the order is not both paid and processing.
- **`422`** → "The label could not be bought", followed by your `message` and field errors (address, carrier wallet balance, expired rate).

**This closes Q11** in the companion document.

### 3.4 The OTP page can return the customer to where they came from
`/verify-otp` accepts a `redirect` parameter so that verification started from checkout ends back at checkout. That includes the case where the session expired during verification: the redirect survives the sign-in. The value is parsed as a URL and only same-origin paths are accepted, so it cannot be used as an open redirect.

---

## 4. What Switches Each Feature On

| Flag | Today | Turned on when |
| --- | --- | --- |
| `VITE_CHECKOUT_SERVER_RATES` | Off in the deploy workflow | **The next frontend deploy.** Your checkout contract is already live on production. This is a frontend task and no action is needed from you, but checkout cannot succeed until **BLK-1** (measurements) is resolved |
| `VITE_ADMIN_PRODUCT_ENDPOINTS_V2` | Off | You confirm **DEP-1**, that the September 3 revision is fully deployed. The bulk and image routes started answering `401` instead of `404` on September 24. After that we run a short live check on a draft product, then switch it on |

---

## 5. Still Open for the Backend

Your handoff fully answers several questions from the companion document, which are therefore **withdrawn**:

| Question | Answered by | Withdrawn |
| --- | --- | --- |
| Q9 — is `errors.code` on all six rates codes | §4.1 table | ✅ |
| Q10 — where the quote-mismatch codes appear | §3 + §4.2 (`errors.code` on 422) | ✅ |
| Q11 — label response shape and 409 vs 422 | §6.1 | ✅ (implemented, Section 3.3) |
| Q14 — tracking response shape and rate limits | §5.2 | ✅ |
| Q23 — `billing_address` | §4.2 (`null`) | ✅ (implemented, Section 3.1) |
| Q26 — verified email required at checkout | §3 (`403`) | ✅ (implemented, Section 3.2) |

**One new question from this review:**

- **Q31 — What does a `502` on `POST /orders` leave behind?** If Stripe session creation fails, is the order already created (in `pending_payment`, with stock reserved), or is it rolled back? If it is left behind, the customer can end up with an unpaid order they cannot pay (see Q24), and a retry creates a duplicate. Please confirm which happens. We would prefer a rollback on `502`.

Everything else still open is in `backend-requests-and-clarifications (2).md` (Revision 2). Items that block launch:
- **BLK-1** — measurements.
- **BLK-2** — EasyPost.
- **BLK-3** — the shared `admin@store.com` test account.
- **BE-1** — products in disabled categories are publicly visible.
- **BE-4** — the demo catalogue.
- **BE-5** — validation errors redirect instead of returning JSON.
- **DEP-1** — the deployed revision.
- **Section 5** — the six backend documents referenced in your handoff but not received.

---

## 6. Verification

- **Automated:** `eslint` 0 errors · `tsc --noEmit` clean · `vitest run` **481/481** · `npm run build` clean with the live catalogue prerendered. The new tests cover:
  - `billing_address: null` in the order payload;
  - the 403 → verify-email path, including the exact link;
  - the 503 order-create path;
  - the status classification for 403/409/429/502/503;
  - the separate 409 and 422 label outcomes.
- **Not yet possible:** an end-to-end live run (rates → order → Stripe → webhook → label → tracking). It is blocked by **BLK-1** and **BLK-2**, and we will run it as soon as both are resolved.
