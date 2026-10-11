# Backend *fix list*

For the backend developer · from the final retest of otantikqueen.com (9–11 October 2026) · live API `46e8219` (deployed 2026-10-09 12:23 UTC) · site build `2967e3e`

This list covers everything from the final retest that needs a backend change. Each item states what we saw, the fix we propose, and how we will check it. Where the frontend changes too, the note at the bottom of the item says exactly what it will read from the API, so both sides build to the same shape.

If you prefer a different fix, that's fine. Tell us the response shape you choose and we will follow it. Items marked P1 are needed before the client takes over the shop.

| # | Item | Priority | Retest |
| --- | --- | --- | --- |
| B1 | [Accepting a cancellation when the refund fails returns a 500 error](#b1) | P1 | CXL-5 |
| B2 | [Manual shipping looks like a bought label](#b2) | P1 | LBL-5, ORD-10 |
| B3 | [Admin categories return no subcategories](#b3) | P1 | ADM-CAT |
| B4 | [Free shipping for Standard only](#b4) | P1 | CHK-04 |
| B5 | [Track Order: carrier, tracking number and status are missing](#b5) | P1 | LBL-4, ORD-08 |
| B6 | [Remove the demo catalogue at the production switch](#b6) | P1 | GLB-09 |
| B7 | [Address verification: repeated apartment, mismatched city](#b7) | P2 | CHK-18, SMK-MOB |
| B8 | [Product images: square crops and a slow upload](#b8) | P2 | ADM-PROD-1 |
| B9 | [Email templates: drop-in replacements](#b9) | P2 | CHK-18, SMK-REG |
| B10 | [Reviews: a rejected review blocks a new one](#b10) | P3 | PDP-08 |
| B11 | [Google sign-in should verify an unverified account](#b11) | P3 | AUTH-05 |
| H1 | [Hosting: the CDN bot check and allowed origins](#h1) | P2 | PERF-01b, GLB-10 |

## Needed before the client takes over

B1

### Accepting a cancellation when the refund fails returns a 500 error

P1CXL-5

What we saw

- Admin pressed Accept on cancellation request #9 (order `ORD-ALI9OY6NUC`, paid $305.95). `POST /admin/cancellation-requests/9/accept` returned **500**.
- The order still became *Cancelled*, but payment stayed *Paid* with refund *failed – $305.95*.
- `POST /admin/orders/{order}/refund` (Retry refund) returned **502** "Stripe refund failed."
- Request #9 still shows **Pending**, so it can be accepted again.
- The customer sees "We are processing your refund manually".
- A fresh paid order refunds correctly through the same flow (CXL-9), so the problem is specific to this payment or to how the error is handled.

Proposed fix

1. Make the decision atomic. Mark the request `accepted`, cancel the order and restock in one transaction. Then run the refund as the spec describes ("in the background, see the order `refund_status`").
2. If Stripe refuses, keep the request `accepted`, set the order's `refund_status = failed`, store Stripe's error code and message, and still answer **200**. The admin page already shows "Refund failed" with a Retry button for that state. Never answer 500, and never leave the request Pending.
3. On Retry, return the Stripe reason in the 502 body (for example `{"message":"Stripe refund failed.","stripe_code":"charge_already_refunded"}`) and log it.
4. Please look up `ORD-ALI9OY6NUC` in Stripe and the Laravel log. Common causes are a charge that was already refunded, a PaymentIntent that was only authorized (it needs *cancel*, not *refund*), or a Stripe test/live key mismatch.

Done when

Accepting a request whose refund fails answers 200, the request shows *Accepted*, the order shows *Refund failed* with a reason, and Retry gives a readable reason. Please add a test that forces a Stripe exception.

B2

### Manual shipping looks like a bought label

P1LBL-5, ORD-10 · follows up Q7

What we saw

- Admin marked order `ORD-XGWENXEB5O` as shipped by hand (carrier "OnTrac", number `D20099887766554`, no label bought). The API now creates a **shipment record** for it, with:
  - `label_download_url` set;
  - the checkout rate's service ("Ground Advantage");
  - status `unknown`.
- Result in the admin: the panel shows "Awaiting carrier update" and a **Download / Print label** button. The link answers 404 "No label is available".
- Result for the customer: there is no Copy button and no link to the carrier's tracking page.
- The same happens on `DEMO-000002`.

Proposed fix

Keep the shipment record (it is useful), but say where it came from and leave out what doesn't exist. Add a `source` field to the shipment in the **customer** and **admin** order resources:

```
"shipment": {
  "source": "manual",            // "easypost" when the label was bought here
  "carrier": "OnTrac",
  "service": null,               // not the checkout rate's service
  "tracking_number": "D20099887766554",
  "tracking_url": null,          // or the carrier's page if you build one
  "status": "unknown",           // nobody tracks it; the frontend shows "Shipped" from source
  "label_url": null,
  "label_download_url": null,    // no label, so no link
  "easypost_shipment_id": null
}
```

Please also keep `tracking_number` and `shipping_carrier` filled on the order itself in both cases, because the shipped email reads them (email question 3 in B9).

Done when

For a hand-shipped order, admin and customer order responses carry `source: "manual"` and `label_download_url: null`, and a bought label still returns `source: "easypost"` with its links.

**Frontend side:** we will branch on `shipment.source`. A manual shipment shows "Shipped without a label bought here" in the admin, and the carrier link or a Copy button for the customer. Until `source` exists, we treat a shipment as manual only when its status is `unknown` and it has no `tracking_url`. In admin responses it must also have no `easypost_shipment_id` and no `label_url`. Customer responses never carry those two fields, so there the first rule is enough: every label bought here has an EasyPost tracking link. Please send `source` so we don't have to rely on this.

B3

### Admin categories return no subcategories

P1ADM-CAT

What we saw

- The admin Category Tree shows only the 8 top-level categories. Subcategories such as Dresses or Gift Sets never appear.
- When a category is given a parent, it disappears from the tree and from the Parent menu, so it can't be edited or moved back. We had to restore "Gifts" through the API.
- The product form's Category menu shows only top-level categories, so 96 of 98 products open with no category selected.
- Saving now works: no more 500 errors (D4 is fixed, thank you).

Why we think it's the API

The frontend calls `GET /admin/categories?depth=2` and walks `children` recursively, the same way the storefront reads the public `GET /categories`. The public endpoint returns the full tree (8 roots, 3 children each). The admin list appears to return roots only, with empty or missing `children`. Please confirm.

Proposed fix

Make `GET /admin/categories` return the same nested shape as the public endpoint, including **inactive** categories and each item's `parent_id`, `is_active` and `sort_order`. A flat list of every category with `parent_id` also works. Just tell us which one you choose.

Done when

The admin response includes every category: active and inactive, at every level. A category moved under a parent is still listed (under that parent).

**Frontend side:** as a safety net, we will also build the tree from `parent_id` when `children` is empty, so a flat list works too.

B4

### Free shipping for Standard only

P1CHK-04 · owner decision 2026-10-11

Change

- Today every method is free once `subtotal − discount ≥ $100`, so a $326 order got UPS Next Day Air Saver for free. The owner wants **only Standard** to be free. Express and any other method keep their normal rate.
- Keep the rest of the rule: the threshold is $100, and a 100% discount does not qualify.
- Please decide (or ask the owner) whether a `free_shipping` coupon makes only Standard free, or every method.

Where it applies

The rates from `POST /shipping/rates` and the order totals at order creation. `GET /shipping/free-shipping` can stay as it is: the cart already says "Free with Standard", which becomes exactly right with this change.

Done when

At $100 or more, Standard shows $0.00 and Express shows its normal price, both in the rates and in the order total. Please update your 10 tests for the rule.

B5

### Track Order: carrier, tracking number and status are missing

P1LBL-4, ORD-08

What we saw

After a label was bought, `POST /orders/track` returned the right list of events, up to Delivered. But the page never showed the carrier or tracking number, and its heading stayed "Preparing your order". The customer's own order page shows all of it correctly from the order's `shipment`. Our original request (`backend-open-items.md`) only asked for `status` and `events`, so the missing fields were our omission.

Proposed fix

Return the shipment's fields flat in the track response. These are the same values the customer order resource already has:

```
{ "data": {
  "order_number": "ORD-X4DKEORMWR",
  "status": "delivered",          // a shipment status (in_transit, delivered, ...); null before any label or manual shipping
  "carrier": "USPS",
  "service": "Ground Advantage",
  "tracking_number": "9400100208192110060039",
  "tracking_url": "https://track.easypost.com/…",
  "estimated_delivery": "2026-10-12",
  "events": [ … ]
} }
```

Include `carrier` and `tracking_number` for manual shipments too (B2). Keep the generic 404 for a wrong email.

Done when

Tracking a delivered order shows status `delivered` with its carrier and number. An order with no label yet returns `status: null` with no tracking fields.

**Frontend side:** we will stop hiding the status when the tracking number is missing, so the heading follows `status` (and the newest event) even before this change.

B6

### Remove the demo catalogue at the production switch

P1GLB-09 · follows up Q14

What we saw

The public API still serves the demo catalogue: 8 `demo-*` categories with 24 subcategories, and demo products and orders such as `DEMO-000002`. The sitemap therefore lists 16 demo category addresses. We will filter `demo-*` categories from the sitemap on our side as well.

Proposed fix

Please send a one-off cleanup command or seeder flag, and run it when the owner says the real products are in. It should remove:

- the demo categories;
- the demo products and their media;
- the `DEMO-*` orders;
- the retest orders the owner lists.

The owner keeps draft product #102 "Retest ADM-PROD-1" until then.

Done when

`GET /categories` and `GET /products` return no `demo-*` slugs.

## Soon after launch

B7

### Address verification: repeated apartment, mismatched city

P2CHK-18, SMK-MOB

What we saw

- The order email showed **"179 N HARBOR DR APT 2, Apt 2"**. The verified line 1 already holds the apartment (EasyPost moves it there), and the original line 2 is added again.
- At checkout the stored address read "20 W 34TH ST FL 34 APT 3400 / Empire State Building, Floor 34th, Apt 3400" with **SAND SPRINGS, OK 74063**. That is a New York street with an Oklahoma city and ZIP, and it was accepted.

Proposed fix

1. When verification moves the unit into line 1, clear line 2, or keep line 2 only when it isn't already contained in line 1.
2. Check EasyPost's `verifications.delivery.success`. When it is false, return a warning (for example `address_warning: "not_deliverable"`) so checkout can ask the shopper to confirm, or refuse the address.

Done when

The apartment appears once in the stored address and in emails, and an undeliverable address gets a warning.

B8

### Product images: square crops and a slow upload

P2ADM-PROD-1 · follows up Q11

What we saw

- The server's `detail` (1000×1000) and `zoom` (1600×1600) sizes are **square crops**, but the product page frame is 3:4 (`card` 600×800 is fine). A portrait photo is cropped twice, and on desktop only about 56% of it shows: the hem and shoulders are cut.
- Creating a product with 5 phone photos (already converted to WebP and at most 1600 px by the browser) took **96 seconds** before the 201 came back.
- iPhone HEIC files are refused. The frontend stops them first with a clear message.

Proposed fix

1. Make `detail` and `zoom` keep the photo's shape (fit inside, no crop), or crop to 3:4, for example detail 900×1200 and zoom 1500×2000. Then regenerate the existing images.
2. Queue the media conversions (Spatie `->queued()` with a worker or cron), so the upload answers quickly and the sizes appear shortly after.
3. Optional: accept HEIC if the server's Imagick has libheif. Otherwise the owner sets the iPhone to "Most Compatible" (JPEG).

Done when

A 3:4 portrait photo shows uncropped on the product page, and a 5-photo create answers in a few seconds.

B9

### Email templates: drop-in replacements

P2CHK-18, SMK-REG

What's ready

The improved Blade templates come in a separate folder, `improved/`, with a `CHANGES.md`. They fix:

- the off-centre subtitle in the code email;
- the code shown as one box, so it can be copied in one go;
- text colours that are easier to read;
- the cancel deadline, shown in the shop's time zone;
- "Ground Advantage" spelled with a space;
- one shared header, button and footer.

Every variable the mailables pass today is used the same way.

Please also

- Answer the four questions in `CHANGES.md`:
  - the cancellation link should use `/orders/{id}`;
  - is `$order->id` the primary key?
  - are the tracking fields filled for manual shipping (B2)?
  - is `reset.blade.php` still used?
- Drop the `#` before the order number in subjects ("Order confirmed: #ORD-…"), to match the site.
- Send each email once to a test inbox after the swap.

## When convenient

B10

### Reviews: a rejected review blocks a new one

P3PDP-08

A customer whose review of a product was *rejected* gets 409 "You have already reviewed this product" on every new attempt. We suggest that a rejected review doesn't count: allow a new one, or let `PUT /products/{product}/reviews/{review}` resubmit it as pending. The owner has the final say. Please tell us which rule you implement.

B11

### Google sign-in should verify an unverified account

P3AUTH-05

Suppose someone registers with email and password, never enters the code, and later uses "Continue with Google" with the same Gmail address. Google has confirmed the address, so the account should get `email_verified_at`. We couldn't test this live, because it needs an unused Gmail address. A backend feature test is enough: please confirm it exists or add one.

H1

### Hosting: the CDN bot check and allowed origins

P2PERF-01b, GLB-10

- Hostinger's CDN shows **"Checking your browser before accessing"** for 5–6 s on a first visit, and returns 403 to scripts during it. Please turn off bot protection / "Under attack" mode in hPanel. At the very least turn it off for `apis.otantikqueen.com`: its responses are never cached (`x-hcdn-cache-status: DYNAMIC`), so the CDN adds nothing there.
- **GLB-10, already fixed:** `www.otantikqueen.com` loaded the site, but CORS allows only `https://otantikqueen.com`, so every API call failed with "Network Error". The site now redirects www to the bare domain. As a safety net you could also add `https://www.otantikqueen.com` to the allowed origins; it isn't required.

## Short questions

1. Cancellation reason: the frontend requires at least 10 characters. Does the API enforce the same minimum, and with which 422 message?
2. In Stripe, `ORD-OWKMMCPFJG` (cancelled inside the window) should show the PaymentIntent as *Canceled*, not refunded. Can you confirm from the log that the hold was released?
3. Shipment `status` values: the frontend accepts `unknown, pre_transit, in_transit, out_for_delivery, available_for_pickup, delivered, return_to_sender, failure, cancelled, error`. Please tell us if the API can send any other value.