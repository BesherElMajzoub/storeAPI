# Backend response to `backend-reply-2026-10-08.md`

**From:** backend. **Deployment:** merged into `main` and deployed to `https://apis.otantikqueen.com` at commit `a866e6d`.
**Tests:** full suite 331 pass, 3 fail. Those 3 already failed before this work and are unrelated (`CreateStaffAccountCommandTest` x2, `TelegramNotificationTest`; audit finding A08-QA-001). The suite needs `-d memory_limit=1G` (`php artisan test -d memory_limit=1G`); at the default 128 MB it runs out of memory in the image tests. Pint passes. PHPStan level 5 was already red with its baseline; I added no deliberate new ignore entries and fixed the typing errors in the new code, but resource-magic-property errors of the existing kind remain.

Legend: **done** = code written and tested here. **ops** = needs someone with server, Stripe, DNS or data access. **changed** = done differently from the request. **not doing** = with the reason.

## Part 1: gaps in the handoffs

| # | Status | Answer |
|---|---|---|
| G1 | done | `/api/v1/health` falls back to the deployed git SHA when `APP_VERSION` is unset. The implementation is deployed on `main`. |
| G2 | done | `/admin/orders/{id}/label` is final. `/ship` stays as a deprecated alias until the next major API version. |
| G3 | changed | Option A: buying a label sets `shipped`; `label_purchased` is never returned for a shipped order. By then the money is already captured, so Option B would need a refund plus a label void, not just a hold release. Can be added as P1 if the owner wants it. |
| G4 | done | Shipments are created with `label_format=PDF`, `label_size=4x6`. The PDF is stored at `storage/app/private/labels/{order_number}.pdf` and served by `GET /api/v1/admin/shipments/{order}/label` (`application/pdf`, `inline`, `Cache-Control: private, no-store`). `shipment.label_download_url` is a 15-minute signed link in `GET /admin/orders/{id}` and in the label response. Old non-PDF labels are converted through EasyPost. The link works after the EasyPost URL expires; a bad or expired signature is `403`. The phone/Labelife check is yours to run after deploy. |
| G5 | done | `server_time` on `GET /orders`, `GET /orders/{id}` and the cancel response; `Date` is also CORS-exposed. |
| G6 | done | Terminal failure (hold expired or cancelled, card declined): `payment_status: failed`, order stays `processing`, `fulfillment_hold: true`, admin alert by Telegram **and** email (`ADMIN_ALERT_EMAIL`), label purchase refused (`409`), the customer sees `cancellation.reason: payment_failed`. Admin "Cancel order" restocks it. Transient errors are retried every 5 minutes and alert once after 3 failures. New webhook `payment_intent.canceled` uses the same path. |
| G7 | done | `orders:capture-authorized-payments` is registered every 5 minutes without overlap; `orders:alert-stale-authorizations` is registered hourly (6 h, once a day per order). The production window is `ORDER_DIRECT_CANCEL_WINDOW_MINUTES=180`, and the host scheduler has been observed running. Production intentionally uses `QUEUE_CONNECTION=sync`, so no queue worker is required. The 148 dormant rows from the previous database-queue setup were inspected: 144 old page-view jobs and 4 old OTP emails; they must not be processed. |
| G8 | done | Admin status returns the full order resource. Bulk refuses `cancelled` for authorized/paid orders. Cancellation requests carry `order_id` and `order_number`. |
| G9 | done | `payment_status: voided` for anything cancelled before a charge. Existing rows were migrated. |
| G10 | done | Checkout is `payment_method_types: ['card']` with manual capture. "Authorized" comes from the session's manual-capture marker set at creation, and every capture re-reads the PaymentIntent (`requires_capture` / `succeeded` / `canceled`). Webhook events we handle: `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`, `refund.failed`, `payment_intent.canceled`. The two reported orders were checked directly in Stripe: both are test-mode, succeeded $200 payments with no refund or dispute. They may be retained as test fixtures; they have no live financial impact. |
| G11 | done | One branded layout for every email (brand name, optional logo, footer, no "Laravel"). New "Order confirmed" email (items, totals, address, "cancel free until ..." in `STORE_TIMEZONE`, order link) replaces the plain payment email. Cancellation email: "No charge was made" when the money was never captured, "We are returning your payment ... 5 to 10 business days" otherwise. OTP wording is "Use this code to verify your email / reset your password". Production now uses `APP_NAME`/`MAIL_BRAND_NAME="Otantik Queen"`, `contact-us@otantikqueen.com` for SMTP/from/admin alerts, and `America/Los_Angeles`; SMTP authentication, SPF, DKIM and DMARC were verified. |
| Address | done | Strict delivery verification (`verifications.delivery.success`) is deployed. Test addresses 31 and 33 were deleted from production for test user 30. |

## Part 2: other defects

| # | Status | Answer |
|---|---|---|
| B1 | done | `POST /admin/contact-messages/{id}/replies` `{body}` returns `{id, admin_name, body, created_at}` (201), emails the customer, sets status `replied`. The admin `show` also returns `replies`. |
| B2 | done | `GET /me/contact-messages` returns the signed-in customer's messages (matched by email) with replies. |
| B3 | done | The production log confirmed the cause: the multipart form sent `parent_id=""`, and MySQL rejected the empty string for the integer `categories.parent_id` column. `BaseAdminRequest` now normalizes `""`/`"null"`/`"undefined"` to `null` and string booleans to real booleans before validation. The exact `_method=PATCH` request has a passing regression test, and the fix is deployed. |
| B4 | done | Admin product responses and the public product resources now include `category_id`. A product created with `category_id` is listed under its category. Note: published products only show publicly when their **category is active**, and `GET /products?category=` takes the slug or the id; that explains the near-empty category pages. |
| B5 | done | Variants return `final_price`. Variants have no sale price of their own, so a discounted product gives its variants the same percentage off (`price x final/price`); a variant without a price costs the product's final price. Orders, the cart total and coupon validation use the same value. Use `variant.final_price ?? variant.price`. |
| B6 | done | `processing -> shipped` needs a purchased label or `tracking_number` + `shipping_carrier` in the request (`422` otherwise), and captures a held payment first. Bulk applies the same rule. |
| B7 | done | After purchase the shipment status is `pre_transit` (the carrier's `unknown` is mapped, and never overwrites a known status). Public tracking returns `status: null` for an order with no label. |
| B8 | done | `current_orders_count` = processing + authorized/paid; `alerts.pending_orders` = `pending_payment`; new `alerts.payment_holds`; `alerts.low_stock` = `stock_qty < 3`. The response has a `filters` object with the matching list query, and `GET /admin/products?low_stock=1` exists. A test checks each tile equals its list total. |
| B9 | done | Duplicate email is `422` with `errors.email` ("This email can't be used to register. Try signing in or resetting your password."). Auth validation errors now put the first field message in `message`. |
| B10 | done | Already true and covered by `test_password_reset_token_is_single_use`: the token is deleted on use and expires. |
| B11 | done | Linking Google to an existing account now sets `email_verified_at`. |
| B12 | done | Model 404s on `api/*` return `{"message":"Order not found."}` style (or "Resource not found."). Set `APP_DEBUG=false` on the live server (ops). |
| B13 | done | `REVIEW_PURCHASE_REQUIRED` (403) is checked before `REVIEW_ALREADY_EXISTS` (409). |
| B14 | done | (a) `product_card` is 3:4 at 600x800; `product_detail` stays square until you confirm a portrait frame. (b) Originals are re-encoded on upload (product and category), which removes EXIF/GPS; existing originals are not touched. (c) Production PHP 8.5 has `upload_max_filesize=256M`, `post_max_size=256M`, and `memory_limit=512M`. (d) Conversions are `nonQueued()`, so they run inside the upload request and need no worker. |
| B15 | done | The V2 image endpoints exist and have passing tests; variant diff on update is covered by `AdminProductContractTest`. You can turn the flag on once the deploy is live. |
| B16 | verified | Direct checks returned `200` from the storefront and API without a browser challenge or challenge cookie. The host currently allows non-browser requests to both domains. |
| B17 | not doing now | P2: atomic `POST /admin/bazaar/sales`. |
| B18 | not doing now | P2: free-shipping coupons, only if the owner asks. |
| B19 | ops | The limit is already 5/min per IP; the 9-10 seen live means older code or a shared proxy IP. Retest after deploy. |

## Part 3: production switch

Added to `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`: window 180, scheduler, queue mode, mail values, SPF/DKIM, Stripe event list, label storage and backups, PHP limits, bot challenge, and `media-library:regenerate`. The code is deployed and the API smoke endpoints return `200`. Production configuration, SMTP/DNS, PHP limits, Stripe test-order inspection, and removal of test addresses 31/33 are complete. Remaining launch-only work is replacing any test Stripe/EasyPost credentials and demo accounts with production data, confirming the EasyPost webhook, rebuilding the frontend sitemap, and running the final end-to-end live smoke test.

## For your mocks
`docs/FRONTEND_ORDER_CANCELLATION.md` section 11 lists the contract changes (`voided`, `payment_failed`, `server_time`, admin responses, `label_download_url`, bulk rules).
