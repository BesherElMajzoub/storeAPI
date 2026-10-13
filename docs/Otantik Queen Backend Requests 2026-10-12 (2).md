# Otantik Queen: backend requests after the live retest (2026-10-12)

The frontend tested release `d7d06aa` on the live site (frontend `d146d05`). Results are in `Otantik_Queen_Retest_2026-10_rev5.xlsx`: 46 Pass, 3 Fail, 2 Blocked.

Thank you. The main fixes work live: B2, B3, B4, B5, B7, B10, the CSV import and the CXL-5 refund. This document lists what is still needed, in priority order, and the questions we need answered.

## A. Please do (priority order)

### 1. Remove the Hostinger browser check for new visitors (rows H1-1, PERF-01b: Fail)

- **What we saw:** three fresh browser profiles each showed "Checking your browser before accessing…" for several seconds before `otantikqueen.com` loaded. Scripted requests (curl) get no challenge.
- **Please:** turn off the bot protection / "Under attack" mode in hPanel for `otantikqueen.com` and `apis.otantikqueen.com`, or allow-list normal browsers.
- **Done when:** a new private window, and a phone on mobile data, open the site with no check page.

### 2. Add a free-shipping coupon type (row B4-3: Blocked)

- **What we saw:** the contract says "a valid `free_shipping` coupon makes Standard free", but `POST /admin/coupons` accepts only `type: percentage | fixed`. The owner cannot create one, so the case cannot be tested.
- **Please:**
  - Add a free-shipping type to `POST/PUT /admin/coupons`.
  - Return it in `GET /admin/coupons`.
  - Tell us the exact field names (see question 6). The frontend will add the option to the admin coupon form.
- **For testing now:** please create one coupon `QA-FREESHIP` (free Standard shipping, no minimum).

### 3. "Express" is UPS Ground

- **What we saw:** live quotes return `method: "express"` as `carrier: UPSDAP, service: Ground`, 1-day ETA, $6.80–$7.93. Customers who pay for Express probably expect an express service.
- **Please:** map `express` to the intended service and confirm which one (see question 7).

### 4. Clean up old data

- **Order 63 (`ORD-XGWENXEB5O`), shipped by hand:** the shipment still returns `status: pre_transit` and `service: GroundAdvantage`. The contract says manual shipments use `status: unknown` and `service: null`. Please re-run the backfill for `source = manual` shipments.
- **Addresses saved before B7:** these keep the unit both in the street and in `apartment` / `floor` / `building`, so it shows twice.
  - Example: address #34 (user 30) has street `179 N HARBOR DR APT 2` and apartment `2`. It shows as "…APT 2, Apt 2" in the address book, at checkout, on orders and in the admin.
  - Please run a one-off update that clears a component the street already contains. New addresses are already correct.
  - The frontend is also hiding such duplicates on its side.

### 5. Set up a refund failure so we can test B1-1 (row B1-1: Blocked)

- **Please:** set up one paid test order whose refund Stripe refuses, so we can check the admin "refund failed" reason and the 502 Retry.
- **Suggestion:** Stripe's test card `4000 0000 0000 5126` succeeds on payment, but a refund of it fails asynchronously.
  - If your webhook handles that failure (`charge.refund.updated`), we can place the order ourselves; just confirm.
  - Otherwise, please tell us how to trigger it.

### 6. Open items from your own plan (Phases 8 and 10), if not done yet

- **Media queue:** the `media` worker running, plus `media-library:regenerate` (needed for B8: portrait photos keep their 3:4 shape).
- **Emails:** a test inbox and recipient for checking them (B9).
- **Stripe orders:** settle `ORD-ALI9OY6NUC` and confirm the hold release on `ORD-OWKMMCPFJG`.
- **Demo cleanup (B6):** waits for the owner's approval and the real catalogue. 32 `demo-*` categories and their products are still public.

## B. Questions we need answered

1. **`GET /products/{product}/my-review`:** please send an example 200 body. The spec only says "Review fetched". We currently read the review from `data` or from `data.review` (fields `id`, `rating`, `comment`). Is a 404 the only answer when there is no review?
2. **Address check on `POST /orders`:** does placing an order also run EasyPost verification and return 422 `errors.address`, or does that happen only when saving an address in the account? We handle both cases; we just need to know what to test.
3. **ZIP auto-correction:** saving "179 N Harbor Dr, Redondo Beach, CA **10001**" was accepted, and the ZIP was silently changed to `90277-2506`.
   - **Owner decision:** the customer must see a "We corrected your ZIP code" note.
   - **Frontend:** we now compare the typed ZIP with the stored one in the save response, so this already works with no backend change. Please keep returning the stored address in the `POST/PUT /profile/addresses` response.
   - **Nice to have:** a field listing what verification changed, for example `corrections: { postal_code: ["10001", "90277-2506"] }`, so we can name street or city corrections too. Is that possible?
4. **Shipped email for hand-shipped orders:** your document says the shipped email now also goes out for manual shipping, with carrier and number but no tracking button. Is it sent automatically when the admin marks an order shipped by hand? The admin screen will say so once you confirm.
5. **Coupons on `/shipping/rates`:** the endpoint answers 422 `errors.coupon_code` for every coupon problem, including "Minimum order amount not met". Is that intended? The frontend now drops the coupon and shows that reason, so it works either way; we want to be sure it is deliberate.
6. **Free-shipping coupon (item 2):** what will the type be called, and which fields go with it? For example `type: "free_shipping"` with `value: 0`, plus `free_shipping: true` in `POST /coupons/validate`.
7. **Express service (item 3):** which carrier and service should "express" use (for example UPS 2nd Day Air or Next Day Air, or USPS Priority Express)? Will Standard stay USPS Ground Advantage?

## C. Already verified live (no action needed)

- **B4:** at $100+, Standard reads `amount 0` / `free_shipping_reason: threshold`, and Express is charged. The Stripe total matches the checkout total.
- **B2:** a manual shipment returns `source: manual` with every label field null. A bought label returns `source: easypost`. The signed `label_download_url` opens without a token. EasyPost is in test mode.
- **B5:** the flat tracking fields are right for a delivered order, a manual order and an untracked order (`status: null`). A wrong email gives the generic 404.
- **B3:** all 32 categories are returned, inactive ones included. A moved category appears under its new parent.
- **B7:** a new address stores the unit once. A non-existent street is refused with `errors.address` ("Address not found").
- **B10:** a POST for a rejected review gives 409 `REVIEW_REJECTED_RESUBMIT`. The PUT returns the same row to pending and clears the note.
- **CXL-5:** accepting a cancellation refunds automatically (`refund.status: succeeded`). The rev. 3 refund 500 is fixed.
- **CSV import:** warnings, `changes`, the row-level 422 with `data` + `errors.rows`, and the UTF-8 message all work.
  - Note: the two-tab race (CSV-6) produced an update rather than a 409, because the second commit is re-validated. That's fine; no duplicate was created.
- **H1, CORS:** preflight passes for both the bare and the `www` origin, and `www` redirects with a 301.
