# Reply to the backend: cancellation and address handoffs + remaining tasks (2026-10-08)

**From:** frontend
**About:** `FRONTEND_ORDER_CANCELLATION.md`, `FRONTEND_ADDRESS_EASYPOST (2).md`, and the open defects from the live E2E run (`docs/e2e-test-findings-2026-10-05.md`, 120 cases, 30 failed).
**Frontend side:** the matching frontend checklist is `docs/frontend-tasks-from-backend-2026-10-08.md`. We are building it now against your documented contract (mocked), so both sides can work in parallel.

Thank you, the cancellation design is right and matches the owner's rules: manual capture, a 3-hour window from authorization, capture at 3 h or at label purchase, release the hold inside the window, order status kept separate from the refund state, a fulfilment hold while a request is pending, coupon released only on cancel, and expiring the Checkout page when an unpaid order is cancelled. Below are the gaps we need closed, then the defects no handoff covers yet.

Priority: **P0** = needed before the client's acceptance test (UAT). **P1** = before going live. **P2** = after launch.

---

## Part 1: Gaps in the two handoffs

### G1 (P0) Deploy, and expose the release version
**Evidence:** on 2026-10-08 the live OpenAPI (`https://apis.otantikqueen.com/docs`) has no `cancellation`, `refund`, `direct_until` or `released`, no `/admin/orders/{id}/ship`, and no contact-reply routes. `GET /api/v1/health` returns `version: "unknown", deployed_at: null`.
**Needed:** deploy both handoffs to the current server. Make `/api/v1/health` return the release (git SHA or tag).
**Done when:** `/api/v1/health` shows the new version and `/docs` lists the new fields.

### G2 (P0) Label endpoint path
**Evidence:** the handoff (§7.3) uses `POST /admin/orders/{id}/ship`. The live spec and the frontend use `POST /admin/orders/{order}/label`.
**Needed:** confirm which path is final. If `/ship` replaces `/label`, keep `/label` as an alias until the frontend release is live, or tell us the cut-over date.

### G3 (P0) `label_purchased` vs `shipped`
**Evidence:** QA #7 says buying a label sets the order to `shipped`, so `cancellation.mode` becomes `none`. But §3 lists `label_purchased` as `mode: request`, and §7.4 says accepting a request for such an order returns `409 already shipped`. These conflict.
**Needed:** define the state "label bought, parcel not yet handed to the carrier":
- Option A (simplest): buying the label sets `shipped`, `label_purchased` is never returned, and the reason list drops it.
- Option B: keep the order `processing` (or a `ready_to_ship` state) until the first carrier scan. Requests stay possible, and **accepting one voids the unused label** through EasyPost's refund call (`POST /shipments/{id}/refund`), so the owner doesn't pay for postage that is never used.

We recommend B, because customers often ask to cancel right after the label is printed. Either way, document the final rule.

### G4 (P0) Owner rule: 4x6 PDF label, served from our server
**Evidence:** the owner prints with a small 4x6 thermal printer (Phomemo/Munbyn) through the **Labelife** phone app over Bluetooth. Labelife needs a PDF. Today `label_url` points to EasyPost's storage (often PNG, sometimes ZPL, and the link can expire or be blocked by CORS). The handoff doesn't cover this.
**Needed:**
1. Create shipments with `options: { label_format: "PDF", label_size: "4x6" }`. For a shipment that already has a non-PDF label, use EasyPost's label convert call (`GET /shipments/{id}/label?file_format=PDF`).
2. When the label is bought, download the PDF once and store it on our server (private disk, e.g. `storage/app/labels/{order_number}.pdf`).
3. Serve it from our API: `GET /api/v1/admin/shipments/{id}/label`. Response: `Content-Type: application/pdf`, `Content-Disposition: inline; filename="label-ORD-XXXX.pdf"`, `Cache-Control: private, no-store`.
4. Admin auth uses a bearer token, which a plain link on a phone cannot send. So return a **temporary signed URL** to that route (Laravel `URL::temporarySignedRoute`, valid 15 minutes) as `shipment.label_download_url` in `GET /admin/orders/{id}` and in the label-purchase response. The signed route needs no bearer token.

**Done when:**
- the admin taps one link on a phone, the PDF opens at 4x6 (288×432 pt), and Share → Labelife prints it;
- the link still works after the EasyPost URL expires;
- an expired signature returns 403.

**Out of scope (owner's decision):** silent printing (kiosk mode or PrintNode).

### G5 (P0) `server_time` for the countdown
**Evidence:** the owner's rule is that the cancel countdown follows the server's clock, not the device's. The handoff tells us to use `Date.now()`. The `Date` response header is not readable from the browser across origins (it is not CORS-safelisted).
**Needed:** add `server_time` (ISO 8601 UTC) to `GET /orders`, `GET /orders/{id}` and the cancel response. Alternatively, add `Access-Control-Expose-Headers: Date`. The frontend will correct the countdown by the difference, and still re-fetch at zero.

### G6 (P0) Failed scheduled capture
**Evidence:** the handoff covers a failed capture during label purchase (`409`/`502`), but not the 3-hour job. A capture can fail if the hold was released by the bank or the card was blocked.
**Needed:** document and implement:
- the order state after a failed capture;
- the admin alert (email + a flag on the order);
- what the customer sees;
- whether the order is cancelled automatically, and what happens to the stock.

Suggested: `payment_status: failed`, order kept `processing` with `fulfillment_hold: true`, admin email, and a "Cancel order" path that restocks.

### G7 (P0) Scheduler, queue, monitoring, and a test window
**Needed:**
- Confirm the cron entry `* * * * * php artisan schedule:run` exists on the current host (and later on the new server). State how often `orders:capture-authorized-payments` runs. We suggest every 5 minutes, without overlapping runs.
- Confirm a queue worker runs (refund jobs, emails, image conversions) under Supervisor or the host's equivalent.
- Send an alert when any order stays `authorized` for more than 6 hours.
- Make the window configurable, e.g. `ORDER_DIRECT_CANCEL_WINDOW_MINUTES` (default 180). During UAT we will ask for 10 minutes so the scenarios don't take 3 hours each. It must go back to 180 before launch (it is on the production checklist).

### G8 (P1) Admin cancel response and bulk safety
**Evidence:** handoff §7.1: `POST /admin/orders/{id}/status` returns the raw model with a flat `refund_status` and no `cancellation`/`refund` objects.
**Needed:**
- Return the same order resource as `GET /admin/orders/{id}`.
- Confirm whether `POST /admin/orders/bulk-status` and the per-row status change can cancel **paid or authorized** orders. If they can, a bulk action can trigger many refunds at once; we suggest rejecting `cancelled` in bulk for paid/authorized orders. On our side, the frontend will only allow cancelling a paid order from the order page, behind a confirmation.
- Add `order_id` and `order_number` to each cancellation request returned by `GET /admin/cancellation-requests`, so the admin list can link to the order.

### G9 (P2, optional) `payment_status: failed` is also used for "hold released"
**Evidence:** §3: `failed` means both "payment failed" and "cancelled before payment / hold released".
**Needed (optional):** a distinct value such as `voided`, so reports don't count customer cancellations as failed payments. If you keep `failed`, the frontend shows "Not charged" whenever the order is `cancelled`.

### G10 (P0) Stripe setup
**Needed:** confirm that:
- Checkout is limited to methods that support manual capture. Card covers Apple Pay, Google Pay and Link. Methods such as ACH must stay off.
- The webhook endpoint subscribes to `checkout.session.completed`, `checkout.session.expired`, `payment_intent.amount_capturable_updated`, `payment_intent.succeeded`, `payment_intent.canceled`, `payment_intent.payment_failed`, `charge.refunded`, `refund.updated`. Please send the exact list you rely on; it goes on the production-switch checklist.
- The "authorized" decision reads the PaymentIntent status (`requires_capture`), not `checkout.session.payment_status`.
- The orders cancelled in the E2E run are checked in the Stripe dashboard: `ORD-WDWF8KLSUW` and `ORD-YNCIANMFTI` were cancelled while paid, with `refunded_amount 0.00`. Were they refunded? If not, refund them, or tell us they are test data to ignore.

### G11 (P0) Emails
**Evidence (owner's inbox):**
- E1: sender `Otantik Queen <conact-us@otantikqueen.com>` has a typo ("conact"), on all emails.
- E2/D38: the verification and cancellation emails show the default **LARAVEL** header and "© 2026 Laravel. All rights reserved." (set `APP_NAME`, and publish/edit the mail theme or logo).
- E3: the cancellation email says "a refund has been initiated" even when no refund happened.
- E4: the payment email is plain (order number + total only).
- E5: there is no order confirmation email. The verification email also reads "Verification Code for Email verification".

**Needed:**
- Fix the sender, and use brand name/logo/footer on every email.
- Send one styled **"Order confirmed"** email at authorization: items, address, total, a link to the order, and "you can cancel free until {direct_until in store time}".
- In the cancellation email, the money sentence depends on `refund.status`:
  - `released`: "no charge was made; your bank may show a pending hold for a few days";
  - `succeeded`: "refunded, usually 5–10 business days";
  - `pending`: "we are returning your payment".
- Fix the wording of the verification email.
- Check SPF/DKIM for the sending domain.

### Address handoff (P0)
- The contract is clear and the frontend already follows it: `state` is sent, `422 errors.address` is shown, and the form stays open.
- **But on 2026-10-04 the live API did not behave like this:**
  - D7: `state: "CA"` was sent and came back empty.
  - D8: `99999 Fake Imaginary Blvd, Redondo Beach CA 90277` was saved with `201`.
- **Needed:** deploy and confirm.
  - If it is already deployed, make verification strict: reject when EasyPost's `verifications.delivery.success` is false (or use `verify_strict`), so the fake address above returns 422.
- **Data to fix:** the test customer's saved addresses 31 and 33 hold a full autocomplete string in `street` and an empty `state` (D29). Delete or correct them.

---

## Part 2: Defects not covered by any handoff

| # | Pri | Problem (evidence) | Needed / done when |
|---|---|---|---|
| B1 | P0 | **D2** `POST /admin/contact-messages/{id}/replies` → 404 (route missing). | Route exists. Body `{ "body": string }`. Returns the reply `{id, admin_name, body, created_at}`. Emails the customer. |
| B2 | P0 | **D3** `GET /me/contact-messages` → 404, so `/messages` shows an error on every visit. | Route returns the signed-in customer's messages: `{id, subject, message, status, replies[{id, admin_name, body, created_at}], created_at, updated_at}`. |
| B3 | P0 | **D4** `POST /admin/categories/{id}` (multipart, `_method=PATCH`) → 500 for every change (name only, active flag only). Create and delete work. The frontend sends `name, slug, parent_id ("" when none), is_active ("true"/"false"), meta_description ("" when empty)` and `image` when changed. The swagger update schema has no `meta_description`. | Check `laravel.log`. Updating name / `is_active` / parent / image returns 200. Empty strings are treated as null. Unknown fields are ignored. (The frontend will also stop sending empty `parent_id`/`meta_description`.) |
| B4 | P0 | **D6** Admin product responses have no `category_id`, only `category {id,name,slug}`. Also confirm the category is saved on create: products 99/100/101 created with "Women" showed no category, and category pages list almost nothing (CAT-07: 85 products, 1 shown in `demo-women`). | Admin product responses include `category_id`. A product created with `category_id` appears on its category page. |
| B5 | P0 | **D11** Variant prices ignore the product discount. `tgryb-3`: `price 200`, `discount_price 20`, `final_price 20`, variant `price 200`. Checkout charged $200, the wishlist showed $20, and the Bazaar sold it at $20. | Each variant returns `final_price` (with the product's discount applied, or its own sale price). Order pricing uses exactly that value. The frontend will display `variant.final_price ?? variant.price`. |
| B6 | P0 | **D19** An order can be set to `shipped` and then `delivered` with no label or tracking. The customer sees "Shipped" with nothing to track. | `processing → shipped` requires a shipment with a tracking number (a purchased label, or a manually entered carrier + tracking number). Otherwise 422 with a clear message. |
| B7 | P1 | **D20** Admin shipment shows status `UNKNOWN` and the carrier code `UPSDAP` after purchase. The track page shows "Label created" for an order with no label. | After purchase, `shipment.status` is a real EasyPost status (`pre_transit`). Tracking for an order without a shipment returns no shipment status. (The frontend will prettify carrier names.) |
| B8 | P1 | **D12** Dashboard counts don't match their lists. "Current orders" = 4 but the processing+paid list shows 3. "Pending orders" alert = 4 and 22, but the `status=pending` list shows 0. | Document what `current_orders_count`, `alerts.pending_orders` and `alerts.low_stock` count, as order-list filters. Each tile then links to a filter that returns the same number. Count `authorized` orders as needing fulfilment. |
| B9 | P1 | **D46** A duplicate-email registration returns only "Validation failed." | 422 with `errors.email: ["…"]` (wording may stay generic for privacy, e.g. "Try signing in or resetting your password"). |
| B10 | P1 | **D36** The reset flow puts the token in the URL (frontend fix is on our side). | Confirm the reset token/code is single-use and expires: a second submit of the same code returns "invalid or expired". |
| B11 | P1 | **D40** A Google sign-in that links to an existing account leaves it "Unverified" in admin. | Set `email_verified_at` when Google confirms the email. |
| B12 | P1 | **D22** `GET /orders/999` 404 body leaks `No query results for model [App\Models\Order]`. | Generic "Order not found." for all model 404s. Confirm `APP_DEBUG=false`. |
| B13 | P1 | **PDP-07** Reviewing a product the customer hasn't bought showed "You have already reviewed this product". | Separate error codes, checked in this order: `purchase_required` (403/422) before `already_reviewed` (409). |
| B14 | P1 | **Images** (review of the upload pipeline): product conversions are center-cropped **squares** (card 420×420), but storefront cards use a **3:4** frame, so fashion photos are cropped twice. Originals are public and may keep GPS EXIF (uploads under 300 KB skip the frontend's re-encoding). | (a) `card` conversion 3:4 at **600×800**, and `detail` 3:4 if the product page frame is portrait. Then run `php artisan media-library:regenerate`. (b) Strip metadata from stored originals, or stop exposing original URLs. (c) Confirm PHP `upload_max_filesize` / `post_max_size` allow 8 × 5 MB in one request (create sends all images in one multipart). (d) Confirm conversions run on the queue worker (otherwise originals are served). |
| B15 | P1 | **Admin product V2 endpoints** (`/admin/products/{id}/images`, `/images/order`, `/images/{media}`, variant diff on update) are in the swagger. The frontend keeps them off (`VITE_ADMIN_PRODUCT_ENDPOINTS_V2`), so variants can't be edited after create. | Confirm they work live, then we turn the flag on. |
| B16 | P0 (ops) | **D31/D50** Every new visitor first gets a "Checking your browser…" page from the host's bot protection. From some networks the API call then fails and the shop shows "We could not load the category collection". It also blocks Lighthouse and may hurt SEO crawlers. | Disable the bot challenge for `otantikqueen.com` and `apis.otantikqueen.com` (or allow-list the API), on the current host and on the new server. |
| B17 | P2 | **D26** The Bazaar records an in-person sale by sending a product update with a stock number the browser computed. Two sales at once can overwrite each other. | `POST /admin/bazaar/sales` that decrements stock atomically and records the sale. |
| B18 | P2 | **D23** The coupon form can't create a free-shipping coupon (the create enum is `percentage`/`fixed` only). | Only if the owner wants free-shipping coupons later. |
| B19 | P2 | **D25** The contact form limit triggers at about 9–10 messages/minute (the plan expected 5). | Set the limit the owner wants (suggest 5/min per IP). |

Owner decisions that need no backend work: a 100% coupon still charges shipping (D24, intended). An expired Stripe session keeps auto-cancelling the unpaid order (D18, intended; it releases stock).

---

## Part 3: Production switch (after the client approves)

1. Delete demo products/categories, test orders, coupon `E2E-FREE-100`, the throwaway account `user.indian2003+e2e1@gmail.com`, and test contact messages (full list in the findings doc, §3).
2. New server: cron `schedule:run`, queue worker, `APP_DEBUG=false`, `APP_URL`/CORS for the final domains, storage link, PHP upload limits, the bot challenge off.
3. `ORDER_DIRECT_CANCEL_WINDOW_MINUTES=180`.
4. Live keys: Stripe (new webhook secret + the event list from G10), EasyPost (production key + webhook URL; earlier the EasyPost webhook returned 404/500, please re-check), mail.
5. Regenerate image conversions if B14 changed them.
6. The frontend rebuilds after the demo data is deleted (the sitemap is generated from the live catalogue).
7. Live smoke test:
   - one real card order cancelled inside the window (no fee, because it was never captured);
   - one captured order with a label printed through Labelife, then refunded;
   - all emails;
   - first page load on mobile data.

## How we will verify

After your deploy we run your QA scenarios 1–8 plus our narrow retest (only the failed cases and the flows your changes touch): ADM-CXL, ADM-ORD-4, ORD-07, ADM-MSG, SUP-03, ADM-CAT, ADM-PROD-1/2, PDP-01, ACC-01…04, CHK-18 (emails), ADM-DASH, the label print on the client's phone, and a smoke run of checkout and payment. Please reply per item with **done / changed (how) / not doing (why)**.
