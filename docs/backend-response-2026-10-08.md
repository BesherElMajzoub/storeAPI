# Backend response to `backend-reply-2026-10-08.md`

**From:** backend. **Branch:** `ai/backend-reply-2026-10-08` (not merged or deployed yet).
**Tests:** full suite 331 pass, 3 fail. Those 3 already failed before this work and are unrelated (`CreateStaffAccountCommandTest` x2, `TelegramNotificationTest`; audit finding A08-QA-001). The suite needs `-d memory_limit=1G` (`php artisan test -d memory_limit=1G`); at the default 128 MB it runs out of memory in the image tests. Pint passes. PHPStan level 5 was already red with its baseline; I added no deliberate new ignore entries and fixed the typing errors in the new code, but resource-magic-property errors of the existing kind remain.

Legend: **done** = code written and tested here. **ops** = needs someone with server, Stripe, DNS or data access. **changed** = done differently from the request. **not doing** = with the reason.

## Part 1: gaps in the handoffs

| # | Status | Answer |
|---|---|---|
| G1 | done + ops | `/api/v1/health` now falls back to the git SHA when `APP_VERSION` is unset; set `APP_VERSION` and `APP_DEPLOYED_AT` in the deploy script. The deploy itself is ops. |
| G2 | done | `/admin/orders/{id}/label` is final. `/ship` stays as a deprecated alias until the next major API version. |
| G3 | changed | Option A: buying a label sets `shipped`; `label_purchased` is never returned for a shipped order. By then the money is already captured, so Option B would need a refund plus a label void, not just a hold release. Can be added as P1 if the owner wants it. |
| G4 | done | Shipments are created with `label_format=PDF`, `label_size=4x6`. The PDF is stored at `storage/app/private/labels/{order_number}.pdf` and served by `GET /api/v1/admin/shipments/{order}/label` (`application/pdf`, `inline`, `Cache-Control: private, no-store`). `shipment.label_download_url` is a 15-minute signed link in `GET /admin/orders/{id}` and in the label response. Old non-PDF labels are converted through EasyPost. The link works after the EasyPost URL expires; a bad or expired signature is `403`. The phone/Labelife check is yours to run after deploy. |
| G5 | done | `server_time` on `GET /orders`, `GET /orders/{id}` and the cancel response; `Date` is also CORS-exposed. |
| G6 | done | Terminal failure (hold expired or cancelled, card declined): `payment_status: failed`, order stays `processing`, `fulfillment_hold: true`, admin alert by Telegram **and** email (`ADMIN_ALERT_EMAIL`), label purchase refused (`409`), the customer sees `cancellation.reason: payment_failed`. Admin "Cancel order" restocks it. Transient errors are retried every 5 minutes and alert once after 3 failures. New webhook `payment_intent.canceled` uses the same path. |
| G7 | done + ops | `orders:capture-authorized-payments` every 5 min without overlap; new hourly `orders:alert-stale-authorizations` (6 h, once a day per order). Window is `ORDER_DIRECT_CANCEL_WINDOW_MINUTES` (default 180). Confirming the cron entry and queue worker on the host is ops. |
| G8 | done | Admin status returns the full order resource. Bulk refuses `cancelled` for authorized/paid orders. Cancellation requests carry `order_id` and `order_number`. |
| G9 | done | `payment_status: voided` for anything cancelled before a charge. Existing rows were migrated. |
| G10 | done + ops | Checkout is `payment_method_types: ['card']` with manual capture. "Authorized" comes from the session's manual-capture marker set at creation, and every capture re-reads the PaymentIntent (`requires_capture` / `succeeded` / `canceled`). Webhook events we handle: `checkout.session.completed`, `checkout.session.expired`, `charge.refunded`, `refund.failed`, `payment_intent.canceled` (that is the list for the checklist; the other events you proposed are not needed). Checking `ORD-WDWF8KLSUW` and `ORD-YNCIANMFTI` in the Stripe dashboard is ops. |
| G11 | done + ops | One branded layout for every email (brand name, optional logo, footer, no "Laravel"). New "Order confirmed" email (items, totals, address, "cancel free until ..." in `STORE_TIMEZONE`, order link) replaces the plain payment email. Cancellation email: "No charge was made" when the money was never captured, "We are returning your payment ... 5 to 10 business days" otherwise. OTP wording is "Use this code to verify your email / reset your password". Ops: set `APP_NAME`, fix the sender typo `conact-us@` in the live `.env`, check SPF/DKIM. |
| Address | ops | Strict verification (`verifications.delivery.success`) is already in the code; D7/D8 mean the live server is on older code. Deploy, then retest. Deleting addresses 31 and 33 is a data task. |

## Part 2: other defects

| # | Status | Answer |
|---|---|---|
| B1 | done | `POST /admin/contact-messages/{id}/replies` `{body}` returns `{id, admin_name, body, created_at}` (201), emails the customer, sets status `replied`. The admin `show` also returns `replies`. |
| B2 | done | `GET /me/contact-messages` returns the signed-in customer's messages (matched by email) with replies. |
| B3 | needs the log | I could not reproduce the 500: multipart `_method=PATCH` with name only, `is_active="false"`, `parent_id=""`, `meta_description=""`, unknown fields and an image all return 200 here (regression tests added). A live-only 500 usually means a migration or the image library is missing on that server. Please send the `laravel.log` stack trace, and run `php artisan migrate --force` first. |
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
| B14 | done + ops | (a) `product_card` is 3:4 at 600x800; `product_detail` stays square until you confirm a portrait frame. Run `php artisan media-library:regenerate --force` after deploy. (b) Originals are re-encoded on upload (product and category), which removes EXIF/GPS; existing originals are not touched. (c) PHP limits are ops (`upload_max_filesize >= 6M`, `post_max_size >= 45M`). (d) Conversions are `nonQueued()`, so they run inside the upload request and need no worker. |
| B15 | done | The V2 image endpoints exist and have passing tests; variant diff on update is covered by `AdminProductContractTest`. You can turn the flag on once the deploy is live. |
| B16 | ops | Disable the host's bot challenge for both domains. |
| B17 | not doing now | P2: atomic `POST /admin/bazaar/sales`. |
| B18 | not doing now | P2: free-shipping coupons, only if the owner asks. |
| B19 | ops | The limit is already 5/min per IP; the 9-10 seen live means older code or a shared proxy IP. Retest after deploy. |

## Part 3: production switch

Added to `docs/PRODUCTION_DEPLOYMENT_CHECKLIST.md`: window back to 180, cron, queue worker, mail values and sender typo, SPF/DKIM, Stripe event list, label storage and backups, PHP limits, bot challenge, `media-library:regenerate`. Still to do at switch time (ops): delete demo data and test accounts, live Stripe/EasyPost/mail keys, re-check the EasyPost webhook URL (it returned 404/500 earlier), rebuild the frontend sitemap, and the live smoke test.

## For your mocks
`docs/FRONTEND_ORDER_CANCELLATION.md` section 11 lists the contract changes (`voided`, `payment_failed`, `server_time`, admin responses, `label_download_url`, bulk rules).
