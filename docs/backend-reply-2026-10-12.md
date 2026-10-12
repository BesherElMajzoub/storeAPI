# Backend reply to "Backend requests after the live retest" (2026-10-12)

**From:** backend. Live is on commit `f05bbdc` (health 200). This covers every item in your document, in its order. Only what you need to integrate or retest is listed.

## 1. Status of your requests (section A)

| # | Request | Status |
|---|---|---|
| 1 | Hostinger browser check | The owner has handled it on the host. We are ready for real testing, so please re-run H1-1 and PERF-01b in a new private window and on mobile data. |
| 2 | Free-shipping coupon | Done. The type already existed in the code. `QA-FREESHIP` now exists on live. Details in answer 6. |
| 3 | "Express" was UPS Ground | Fixed, live now. See answer 7. |
| 4 | Old data | Done. Order 63 and the old addresses are cleaned. See section 3. |
| 5 | Refund failure to test B1-1 | Backend is ready. Stripe test setup is done by the owner. See section 4. |
| 6 | Plan items | Media done, test inbox set up, Stripe orders checked. Demo cleanup waits for the owner. See section 5. |

## 2. Answers to your questions (section B)

**1. `GET /products/{product}/my-review`**
The body is flat. Read the review from `data`, not `data.review`. A 404 is the only answer when the user has no review.
```json
{
  "success": true,
  "message": "Review fetched.",
  "data": {
    "id": 12, "rating": 5, "comment": "Great quality",
    "status": "pending", "is_approved": false, "is_verified_purchase": true,
    "is_own_review": true,
    "created_at": "2026-10-10T12:00:00+00:00", "updated_at": "2026-10-10T12:00:00+00:00",
    "user": { "id": 30, "name": "Jane", "avatar_url": null }
  },
  "errors": null
}
```
404: `{"success": false, "message": "You have not reviewed this product yet.", "data": null}`.
`status` is `pending`, `approved` or `rejected`. `admin_note` is only present for admins. `product` is not included here.

**2. Address check on `POST /orders`**
`POST /orders` does **not** run EasyPost address verification. `422 errors.address` comes only from `POST/PUT /profile/addresses`. At checkout the order is checked against the shipping quote you got from `/shipping/rates`:
- Address, cart or parcel changed after you got rates: `422` with `errors.shipping_rate_id` and `errors.code` = `shipping_address_changed`, `shipping_items_changed`, `shipping_parcel_changed` or `invalid_shipping_rate` (expired or already used). Request new rates and retry.
- `POST /shipping/rates` with an address the carrier cannot serve: `422`, `errors.shipping`, `errors.code: "unserviceable_address"`.
- To test: get rates, change the address, then submit the order.

**3. ZIP auto-correction**
`POST/PUT /profile/addresses` keeps returning the stored (verified) address, so your comparison of typed vs stored ZIP keeps working. A `corrections` field does not exist. We can add it if you need street or city corrections too; tell us.

**4. Shipped email for hand-shipped orders**
Yes, it is automatic. When the admin sets `status: "shipped"` with `tracking_number` (and `shipping_carrier`), the email goes to the customer. It shows carrier and tracking number, and no "Track" button because `tracking_url` is null; it shows a "View your order" link instead. Right now the mail queue is synchronous, so it is sent immediately. If sending fails, the order is still saved and the error is only logged. You can state this in the admin screen.

**5. Coupons on `/shipping/rates`**
Intended. Every coupon problem is `422` with `errors.coupon_code: ["<message>"]`, for example "Minimum order amount not met.", "Coupon has expired.", "Invalid coupon code.". Dropping the coupon and showing the message is the correct handling.

**6. Free-shipping coupon: names and fields**
- Create or update: `POST/PUT /admin/coupons` with `type: "free_shipping"`. `value` is optional and defaults to `0`. `minimum_order_amount`, usage limits and dates work as for other types.
- Read: `GET /admin/coupons` returns `type: "free_shipping"` and `free_shipping: true`. Other types return `free_shipping: false`.
- `POST /coupons/validate` returns `discount: 0` and `free_shipping: true`.
- `POST /shipping/rates` returns `free_shipping_reason: "coupon"` on **Standard only**, with `amount: 0` and the real price in `carrier_amount`. Express is never waived.
- If the order is already over the $100 threshold, the reason is `"threshold"` instead.
- The coupon does not discount the products (`discount: 0`).
- For testing: coupon `QA-FREESHIP` is on live, active, no minimum.

**7. Express service**
- Standard: the cheapest USPS rate, normally Ground Advantage. This is unchanged.
- Express: the fastest **non-Ground** UPS service (price breaks ties). Ground-class services are never offered as Express, even if the carrier reports a 1-day ETA. Carrier names such as `UPSDAP` are accepted.
- Expect Express prices to go up (UPS air services instead of about $7).
- If UPS returns no air service for an address, `/shipping/rates` returns **only Standard** (an array of one). Your UI must accept one or two options. This was already allowed by the contract.
- Open: which exact service the owner wants (for example 2nd Day Air or Next Day Air). If the owner wants one fixed service, tell us and we will pin it.

## 3. Old data cleaned (you can retest B2 and B7)

- **Order 63 (`ORD-XGWENXEB5O`)**: now `shipment.status: "unknown"` and `service: null`, as the contract says for manual shipments. The tracking number is unchanged.
- **Saved addresses**: the unit is no longer stored twice. Address #34 (user 30) now has street `179 N HARBOR DR APT 2` and `apartment: null`. Address #35 (user 31) and several demo addresses were fixed the same way.
- **Keep your duplicate-hiding code.** The cleaning changed the address book only. Addresses already copied into **old orders** (`shipping_address` on the order) were not changed, and the admin order screen still shows them as saved.

## 4. Refund failure (B1-1)

- The backend now records Stripe's failure for refunds that fail after they were created. In the admin order, `refund.status` is `failed` and `refund.failure_code`, `refund.failure_message` and `refund.failed_at` are filled. Example: `failure_code: "lost_or_stolen_card"`.
- To test, either path works:
  - **Immediate failure (502 + Retry):** pay an order, refund it from the Stripe Dashboard, then click Refund in the admin. Stripe refuses and the admin gets the reason and the 502.
  - **Delayed failure:** pay with test card `4000 0000 0000 5126`, then refund from the admin. Stripe accepts it, then reports the failure by webhook. The owner has enabled `refund.failed` and `charge.refund.updated`.
- Order `ORD-ALI9OY6NUC` already has `refund.status: failed`, but with `failure_code: null`. It was recorded before this change, and its payment no longer exists in the current Stripe account, so it can never be refunded. Do not use it as the reference for the failure reason. Use a new order.
- Order `ORD-OWKMMCPFJG` is fine: cancelled, `payment_status: voided`, `refund.status: released` (the card hold was released).

## 5. Plan items

- **Media (B8):** all 118 media files were regenerated. Portrait photos should keep their 3:4 shape. Please retest.
- **Emails (B9):** the test inbox is ready; the owner has sent you the address. Emails are sent immediately (no queue delay).
- **Demo cleanup (B6):** not done yet. It waits for the owner's approval and the real catalogue. The 35 `demo-*` categories and their products are still public. Six QA products that sit inside demo categories (for example "تجريب 3" with 19 orders) also block an automatic cleanup. Do not count on the demo data disappearing yet.

## 6. Contract changes in short

- `POST/PUT /admin/coupons`: accepts `type: "free_shipping"`. Reads return `free_shipping`.
- `POST /shipping/rates`: Express can no longer be a Ground service and can be missing. A coupon can set `free_shipping_reason: "coupon"` on Standard.
- Admin order `refund`: `failure_code`, `failure_message` and `failed_at` are now filled after a delayed failure from Stripe.
- Nothing else changed in the API shapes you already integrated.
