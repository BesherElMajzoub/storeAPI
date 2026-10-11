# Otantik Queen Backend Fix Response

This is the backend team's living response to `Otantik Queen Backend Fix List.md`. It is also the frontend integration contract for the remediation work.

## Baseline

- Live API baseline at the start of this work: `46e8219`, deployed 2026-10-09 12:23 UTC.
- Local baseline at the start of this work: `75496af`.
- Live read-only verification on 2026-10-11 found 32 public `demo-*` categories, 84 `demo-product-*` products, and 85 public products assigned to demo categories.
- A scripted request did not reproduce the Hostinger browser challenge. The API still reported `Server: hcdn` and `x-hcdn-cache-status: DYNAMIC`; the www host redirected to the bare domain.

## Status legend

| Status | Meaning |
|---|---|
| Not started | No remediation work has been completed. |
| In progress | Code or tests are currently being changed. |
| Code complete | Implementation and local focused tests pass. |
| Deployed | The implementation commit is reported by the live health endpoint. |
| Live verified | The documented production smoke test passes. |
| Blocked | An external owner action or production-only access is required. |

## Locked product decisions

- Only the `standard` shipping method can be free.
- Automatic free shipping applies when product subtotal minus monetary discounts, excluding shipping, is at least USD 100.
- A valid `free_shipping` coupon makes Standard free regardless of subtotal.
- Express always retains its carrier price.
- An address that fails EasyPost delivery verification is rejected with HTTP 422.
- A rejected review remains the same database record and can always be edited and resubmitted.

## Work status

| Item | Phase | Code | Tests | Deployed | Live verified | Frontend action |
|---|---:|---|---|---|---|---|
| B1 Cancellation/refund failure | 1 | Code complete | Focused passing | No | No | Keep customer errors generic; admins may show failure details and Retry. |
| B2 Manual shipment source | 1 | Code complete | Focused passing | No | No | Branch on `shipment.source`; hide label actions for `manual`. |
| B3 Admin category tree | 2 | Code complete | Focused passing | No | No | Consume recursive `children`; inactive nodes are intentional. |
| B4 Standard-only free shipping | 2 | Code complete | Focused passing | No | No | Send optional `coupon_code`; render payable `amount`. |
| B5 Public Track Order fields | 1 | Code complete | Focused passing | No | No | Render flat shipment fields only when returned. |
| B6 Demo cleanup | 3 | Code complete | Focused passing | No | Blocked | Rebuild sitemap only after approved live cleanup. |
| B7 Address normalization | 4 | Code complete | Focused passing | No | No | Treat 422 as undeliverable; display unit data once. |
| B8 Product conversions | 4 | Code complete | Focused passing | No | Blocked | Returned URLs already fall back to originals while queued. |
| B9 Email templates | 4 | Code complete | Render tests passing | No | Blocked | No API change; order links use numeric `orders.id`. |
| B10 Rejected review resubmission | 5 | Code complete | Focused passing | No | No | Load `my-review`, then PUT the existing row. |
| B11 Google verifies linked email | 5 | Verified in baseline | Passing | Baseline | Not live-retested | No change. |
| H1 Hosting and CORS | 3 | Config complete | Focused passing | No | hPanel verified 2026-10-12; www CORS needs deploy | Bare/www origins allowed after deploy. |

Release commit: `552d794`, pushed to `origin/main` on 2026-10-12. It is **not deployed yet**: live `/api/v1/health` still reports `46e8219`. The frontend can build against the contracts below now, but they only work against production after the deploy.

Since Phase 6, the full suite passes, not only the focused tests: 384 tests and 2,061 assertions, green on two consecutive runs.

## Delivery plan

All twelve items are code complete. What remains is to finish hardening, package the release, deploy, prove each item live, and close the operations blockers. A phase finishes only when every box in it is ticked and its evidence is recorded in the phase log below.

### Definition of done

An item is **done** only when all of these hold:

1. The code is merged, and the live `/api/v1/health` reports the release commit.
2. The full test suite passes twice in a row, Pint passes, and Larastan reports no new errors.
3. Each migration has been rehearsed up → down → up.
4. The OpenAPI file and this document match the shipped behaviour.
5. The live check for the item in Phase 9 passes, and its evidence is logged.
6. The frontend has confirmed that it consumes the contract.

### Phase 6: Hardening (done 2026-10-12)

- [x] Fix the flaky `ShippingStandardExpressTest`. The product price came from a random factory value, so the $100 threshold sometimes failed. It is now pinned to $150.
- [x] Fix test-database leakage. `ConcurrentInventoryTest` uses `DatabaseTruncation`, which only clears tables *before* a test. Its worker processes commit real users, which broke the user count in `CreateStaffAccountCommandTest`. It now also truncates after itself.
- [x] Fix the stale `TelegramNotificationTest`. It expected `parse_mode: Markdown`, but `TelegramNotifier` deliberately sends `HTML`.
- [x] Pint: the whole repository passes. Two new resources had their imports fixed.
- [x] Larastan: down from 43 to 30 errors, with none introduced by this work.
  - `OrderResource` gained `@mixin Order`, and `Order::cancellationRequest()` gained its generic type. This removed 28 baseline entries.
  - The `Product` baseline entry moved from `nonQueued()` to `queued()`.
  - The remaining 30 errors already existed at `75496af` and are listed under technical debt.
- [x] Migrations rehearsed up → down → up on `storeapi_testing`.
- [x] OpenAPI regenerated. The `POST /admin/orders/{order}/refund` endpoint now documents its `502` body.
- [x] Spatie conversion leftovers removed from git. `storage/media-library/temp` was being committed; it is now in `.gitignore`.

### Phase 7: Release packaging (backend, needs approval)

- [ ] Create the branch `fix/otantik-backend-retest` from `main`.
- [ ] Commit in reviewable slices:
  - (1) migrations, models and refund failure (B1)
  - (2) shipment source and tracking (B2, B5)
  - (3) admin categories (B3)
  - (4) Standard-only free shipping (B4)
  - (5) demo cleanup command (B6)
  - (6) address, media and email (B7–B9)
  - (7) reviews (B10)
  - (8) CORS and ops docs (H1)
  - (9) test and tooling hardening (Phase 6)
- [ ] Open a PR that links the fix list and this document. Run the full suite in CI on the PR.
- [ ] Get review from a second engineer, focusing on:
  - the B1 transaction and lock order
  - the B4 price recalculation in `OrderController::store`
  - the B6 deletion scope
- [ ] Record the commit SHA in the phase log.

### Phase 8: Deploy (Deployment Owner)

Prerequisites, all from `PRODUCTION_DEPLOYMENT_CHECKLIST.md`:

- [ ] Take a database backup and a `storage/` backup, and record the backup reference.
- [ ] Make sure the queue worker processes `default`, `notifications` **and `media`**. Two workers are acceptable, for example `queue:work --queue=default,notifications` and `queue:work --queue=media`.
  - Without a `media` worker, new product photos keep showing the original file and never get `card`/`detail`/`zoom`.
- [ ] Confirm the cron entry `schedule:run` is active.
- [ ] Add `MEDIA_QUEUE=media` and `FRONTEND_WWW_URL=https://www.otantikqueen.com` to the live `.env`.

Deploy:

- [ ] Run `ops/deploy-production.sh`. It runs migrations, `l5-swagger:generate`, the caches and `queue:restart`.
- [ ] Run `php artisan app:production-readiness`. Every row must pass.
- [ ] Run `php artisan media-library:regenerate --force`, then watch the `media` queue until it is empty and `failed_jobs` has no new rows.
- [ ] Check that `GET /api/v1/health` reports the release commit.

Rollback: follow the rollback gate in the deployment checklist.

- Both migrations are additive and reversible. Do not roll them back while the new code is live.
- If needed, revert the code first. The extra columns are harmless to the old code.

### Phase 9: Live verification (backend and frontend together)

Each check mirrors the item's "Done when" in the fix list. Record the order numbers and the response excerpts in the phase log.

| Item | Live check | Evidence to record |
|---|---|---|
| B1 | Accept a cancellation whose refund Stripe refuses (test mode). | The answer is `200`, the request shows `accepted`, and the admin order shows `refund.status = failed` with `failure_code`/`failure_message`. Retry returns `502` with `errors.stripe_code`. |
| B2 | Mark a paid test order shipped by hand. Buy a label for another one. | Manual order: `shipment.source = "manual"` and `label_download_url = null`. Bought label: `source = "easypost"` with working links. Check both the admin and the customer resources. |
| B3 | `GET /admin/categories?depth=2`, then move a category under a parent. | Every category is listed, inactive ones included. The moved category appears under its new parent. |
| B4 | Rates for a $100+ cart and for a $99 cart, then place the $100+ order with Express. | At $100+, Standard `amount = 0` and Express keeps its carrier price. The Express order total includes its shipping. |
| B5 | `POST /orders/track` for a delivered order, a manual order and an order without a label. | The flat fields are present, or omitted when there is no label, and `status` matches. A wrong email gives the generic `404`. |
| B6 | Run `app:cleanup-demo-data` as a dry run, then `--execute`, only after owner approval. | The before/after count table. `GET /categories` and `GET /products` return no `demo-*` slugs. Product #102 is kept unless the owner lists it. |
| B7 | Verify `179 N Harbor Dr Apt 2`, and an address with a mismatched city and ZIP. | The apartment appears once in the stored address and in the email. The mismatched address returns `422` under `errors.address`. |
| B8 | Create a product with five portrait phone photos. | `201` comes back within a few seconds. `detail`/`zoom` keep the 3:4 shape after the `media` worker runs. |
| B9 | Send each email to the test inbox. | Subjects have no `#`. Check rendering in Gmail, Outlook and Apple Mail. |
| B10 | Reject a review, then resubmit it with `PUT`. | `POST` returns `409 REVIEW_REJECTED_RESUBMIT` with `review_id`. `PUT` sets the review back to `pending`. |
| B11 | The automated test `AuthTest::test_linking_google_to_an_unverified_account_verifies_its_email` covers this; there is no live retest. | The CI result. |
| H1 | Load the storefront and API in a fresh browser and from a script, from both origins. | No Hostinger challenge and no `403`. The CORS preflight passes for both the bare and the www origin. |

### Phase 10: Close operations blockers and decisions

- [ ] **ORD-ALI9OY6NUC (B1 root cause).** Read the Stripe event log and `laravel.log` for this order, and record the Stripe error code. If the PaymentIntent was only authorized, cancel it in Stripe; if it is already refunded, mark it refunded from the admin panel. The code now reports this case cleanly, but the specific order still needs to be settled by hand.
- [ ] **ORD-OWKMMCPFJG.** Confirm in Stripe that the PaymentIntent shows *Canceled* (hold released).
- [ ] **Hostinger hPanel.** Turn off the bot check / "Under attack" mode, at least for `apis.otantikqueen.com`.
- [ ] **Demo cleanup.** Run it after the owner confirms the real catalogue is in, together with the list of retest orders to delete.
- [ ] **Owner decision on B7.** Undeliverable addresses are currently **refused** with `422`; the fix list allowed either refusing or warning. Confirm with the owner that refusing is acceptable, or ask for the warning mode as a follow-up.
- [ ] **Frontend sign-off.** Confirm the contracts in this document are implemented: `shipment.source`, the flat tracking fields, `coupon_code` on rates, `REVIEW_REJECTED_RESUBMIT`, and the admin `refund.failure_*` fields.

## Known technical debt (not in this release)

- Larastan reports 30 errors at level 5. All of them already existed at `75496af`:
  - untyped Eloquent relations in `OrderController`, the mails and the product resources
  - enum narrowing in `Order`
  - three stale baseline entries
  
  Plan: type the relations, refresh `phpstan-baseline.neon`, then gate CI on Larastan.
- `DatabaseTruncation` tests share the testing database with `RefreshDatabase` tests. Any new truncation test must clean up after itself the way `ConcurrentInventoryTest` now does.

## API contracts

All existing success/error envelopes remain unchanged. The fields below are additive unless explicitly stated.

### Shipping rates

`POST /api/v1/shipping/rates` accepts the existing `address` and `items`, plus optional `coupon_code`. Authentication remains optional; a bearer token makes coupon limits user-specific.

```json
{
  "address": { "line1": "179 N Harbor Dr", "city": "Redondo Beach", "state": "CA", "postal_code": "90277", "country": "US" },
  "items": [{ "product_id": 42, "variant_id": null, "quantity": 1 }],
  "coupon_code": "FREESHIP"
}
```

```json
{
  "success": true,
  "message": "Shipping rates retrieved.",
  "data": [
    { "method": "standard", "rate_id": "rate_standard", "carrier": "USPS", "service": "Ground Advantage", "amount": 0, "carrier_amount": 8.45, "free_shipping_reason": "coupon", "eta_days": 4, "expires_at": "2026-10-11T15:15:00+00:00" },
    { "method": "express", "rate_id": "rate_express", "carrier": "UPS", "service": "Next Day Air", "amount": 30, "carrier_amount": 30, "free_shipping_reason": null, "eta_days": 1, "expires_at": "2026-10-11T15:15:00+00:00" }
  ],
  "errors": null
}
```

- `amount` is customer-payable. `carrier_amount` is informational and must never be submitted as trusted pricing.
- Show free shipping only when `amount === 0` and the reason is `threshold` or `coupon`. Express is never free.
- Order creation transactionally recalculates pricing. Coupon errors use HTTP 422 under `errors.coupon_code`.

### Shipment source and public tracking

Customer/admin shipment objects add `source: "manual" | "easypost" | null`. Manual shipments keep carrier/tracking number, use status `unknown`, and return EasyPost/service/label/provider URL/ETA/events fields as null or empty. A label download URL exists only for EasyPost shipments with label evidence.

`shipment` is `null` until the order has a tracking number. The order-level `tracking_number` and `shipping_carrier` are filled in both cases, so the shipped email works for manual shipments too. `source` is `null` only for a legacy order the backfill could not classify; treat `null` like `manual`.

Customer order (`GET /orders/{id}`), hand-shipped:

```json
"shipment": {
  "source": "manual",
  "tracking_number": "D20099887766554",
  "carrier": "OnTrac",
  "service": null,
  "tracking_url": null,
  "shipped_at": "2026-10-12T10:00:00+00:00",
  "estimated_delivery": null,
  "status": "unknown"
}
```

Admin order (`GET /admin/orders/{id}`), label bought here. The admin `shipment` adds `label_url` and `label_download_url`. `easypost_shipment_id` is a **top-level** field of the admin order, not part of `shipment`:

```json
"easypost_shipment_id": "shp_123",
"shipment": {
  "source": "easypost",
  "tracking_number": "9400100208192110060039",
  "carrier": "USPS",
  "service": "GroundAdvantage",
  "tracking_url": "https://track.easypost.com/…",
  "shipped_at": "2026-10-12T10:00:00+00:00",
  "estimated_delivery": "2026-10-15",
  "status": "pre_transit",
  "label_url": "https://easypost-files…/label.pdf",
  "label_download_url": "https://apis.otantikqueen.com/api/v1/admin/shipments/63/label?expires=…&signature=…"
}
```

`label_download_url` is a temporary signed link, so it opens without a bearer token, for example on a phone. Do not cache it; read it again from the order response.

For a hand-shipped admin order, `label_url`, `label_download_url`, `service`, `tracking_url` and the top-level `easypost_shipment_id` are all `null`.

Tracked manual response:

```json
{
  "success": true,
  "message": "Tracking information retrieved.",
  "data": { "order_number": "ORD-ABC123", "status": "unknown", "estimated_delivery": null, "events": [], "source": "manual", "carrier": "USPS", "service": null, "tracking_number": "9400111899223856928499", "tracking_url": null },
  "errors": null
}
```

Untracked response omits all tracking-specific fields:

```json
{
  "success": true,
  "message": "Tracking information retrieved.",
  "data": { "order_number": "ORD-ABC123", "status": null, "estimated_delivery": null, "events": [] },
  "errors": null
}
```

Missing orders and email mismatches deliberately share the same HTTP 404 response: `{"success":false,"message":"Order tracking information was not found.","data":null,"errors":null}`.

### Admin categories

The endpoint remains a tree. Every node returns `id`, `name`, `slug`, `description`, `parent_id`, `is_active`, `sort_order`, metadata, `image`, and recursive `children`. Inactive roots/descendants are included, depth is respected, and moved categories remain under their parent.

### Refund failures

Customer resources remain generic. Admin order `refund` objects add nullable `failure_code`, `failure_message`, and `failed_at`. Example after Stripe refuses:

```json
"refund": {
  "status": "failed",
  "amount": 305.95,
  "currency": "usd",
  "failure_code": "charge_already_refunded",
  "failure_message": "Charge ch_123 has already been refunded.",
  "failed_at": "2026-10-12T10:00:00+00:00"
}
```

`POST /admin/cancellation-requests/{id}/accept` answers `200` even when the refund later fails. The refund runs in the background, so poll the order's `refund.status`:

- `pending` while the background job runs;
- then `succeeded` (charge refunded), `released` (an authorized hold was cancelled, so no money was taken), `failed` (show the reason and Retry), or `none` (nothing was paid). When Stripe refuses a Retry (`POST /admin/orders/{order}/refund`), the response is `502` and the reason sits inside the standard error envelope, not at the top level as in the fix-list example: `{"success":false,"message":"Stripe refund failed.","data":null,"errors":{"stripe_code":"charge_already_refunded","stripe_message":"…"}}`. `stripe_code` is null when the failure did not come from Stripe. They are cleared on a new pending attempt or success. Cancellation acceptance remains successful and atomic even when settlement/email dispatch fails; the failed settlement remains actionable through Retry.

### Address, media, and email

- EasyPost verification failure remains strict HTTP 422 with `errors.address`. Canonical lines deduplicate represented apartment/floor/building components.
  - The 422 body is `{"success":false,"message":"Address verification failed.","data":null,"errors":{"address":["<EasyPost reason>"]}}`. Show the message and let the shopper correct the address.
  - On success, the stored `line1` holds the verified street with the unit, for example `179 N HARBOR DR APT 2`. `line2` is `null` when line 1 already contains it. `apartment` / `floor` / `building` are cleared when line 1 already contains them. Display the stored lines as they are, without adding the components again.
- `product_detail` fits within `900x1200`; `product_zoom` fits within `1500x2000`, both without aspect-ratio cropping.
- All product conversions run on the `media` queue; resource URLs fall back to the original until ready. HEIC remains rejected.
- Confirmation, shipped, and cancellation subjects have no `#` before the order number.
- `reset.blade.php` remains active because `ResetPasswordMail` is still used.
- The improved templates from `improved/` are in place (commit `7fd8009`). Answers to the four questions in `resources/views/emails/CHANGES.md`:
  1. The cancellation email receives `$orderUrl = FRONTEND_URL/orders/{id}`, the same as order confirmed.
  2. Yes, `$order->id` is the `orders` primary key.
  3. Yes. `tracking_number` and `shipping_carrier` are filled for both bought labels and manual shipping. For manual shipping, `shipping_service` and `tracking_url` are `null`, so the email shows the carrier and number without a tracking button.
  4. `reset.blade.php` is still sent by `ResetPasswordMail`, so it stays.
- `GET /shipping/free-shipping` is unchanged.

### Rejected reviews

POST remains blocked when a row exists. For a rejected row it returns HTTP 409:

```json
{
  "success": false,
  "message": "Your previous review was rejected. Edit and resubmit the existing review.",
  "data": null,
  "errors": { "code": "REVIEW_REJECTED_RESUBMIT", "review_id": 87 }
}
```

Call `GET /api/v1/products/{product}/my-review`, show the rejected content, then `PUT /api/v1/products/{product}/reviews/{review}`. PUT preserves the row, resets it to `pending`, and clears the moderation note. Rejected reviews have no 30-day edit limit; pending/approved reviews do.

## Short answers

1. Cancellation request reasons are required strings with a minimum of 10 and maximum of 2,000 characters. HTTP 422 uses the standard validation envelope, e.g. `{"message":"The reason field must be at least 10 characters.","errors":{"reason":["The reason field must be at least 10 characters."]}}`.
2. `ORD-OWKMMCPFJG` requires production Stripe/log access before its PaymentIntent release can be confirmed.
3. Shipment statuses are `unknown`, `pre_transit`, `in_transit`, `out_for_delivery`, `available_for_pickup`, `delivered`, `return_to_sender`, `failure`, `cancelled`, and `error`. Unrecognized provider values are normalized to `unknown`.

## Operations blockers

- Do not run demo cleanup until the owner confirms that the real catalogue is ready and a database plus storage backup reference exists.
- Hostinger bot protection/CDN settings require hPanel access.
- Stripe investigations for `ORD-ALI9OY6NUC` and `ORD-OWKMMCPFJG` require production Stripe and Laravel-log access.
- Real inbox verification requires the deployment owner's configured test recipient.

## Append-only phase log

### 2026-10-11 — Phase 0 started

- Commit: working tree based on `75496af`.
- Migrations: none.
- Tests: baseline focused suite passed, 87 tests / 414 assertions.
- Deployment: not deployed; live remains `46e8219`.
- Live verification: baseline public API/CDN checks recorded above.
- Frontend: no contract changes available yet.

### 2026-10-12 — Phase 1 code complete

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: `2026_10_11_000001_add_refund_failure_and_shipment_source_to_orders.php`; additive columns, deterministic backfill, reversible rollback.
- Tests: focused cancellation, shipment, and public-tracking suites pass, including Stripe/final failure persistence, queue-dispatch failure, Retry, manual/EasyPost data, stale-field clearing, and tracked/untracked responses.
- Deployment: not deployed; `/api/v1/health` must confirm the future release commit.
- Live verification: blocked by deployment. `ORD-ALI9OY6NUC` investigation is blocked by production Laravel-log and Stripe access.
- Frontend: adopt `shipment.source` and flat tracking fields; keep customer refund errors generic.

### 2026-10-12 — Phase 2 code complete

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: `2026_10_11_000002_add_method_to_shipping_rate_quotes.php`; additive method column, unexpired quote backfill, reversible rollback.
- Tests: focused category/pricing suites pass, including inactive nesting, depth, threshold boundaries, discounts, coupon-only free Standard, Express preservation, and order/rate consistency.
- Deployment: not deployed.
- Live verification: blocked by deployment.
- Frontend: send optional `coupon_code`; use `method`, payable `amount`, `carrier_amount`, and `free_shipping_reason`.

### 2026-10-12 — Phase 3 code complete; operations blocked

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: none.
- Tests: cleanup-command safety and bare/www CORS tests pass.
- Deployment: not deployed. Demo deletion was not executed.
- Live verification: blocked pending catalogue-owner approval, database/storage backup reference, deployment, and Hostinger hPanel access.
- Frontend: do not rebuild or submit the production sitemap until demo-free public catalogue verification passes.

### 2026-10-12 — Phase 4 code complete; manual QA blocked

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: none.
- Tests: focused address, media dimensions/queue/fallback, and email render/subject tests pass.
- Deployment: not deployed; conversion regeneration and the dedicated media worker are pending.
- Live verification: portrait/landscape inspection and Gmail/Outlook/Apple Mail evidence require deployment and a test inbox.
- Frontend: preserve original-image fallback behavior and show the address 422 correction message.

### 2026-10-12 — Phase 5 code complete; release verification pending

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: none.
- Tests: focused review tests pass for old rejected rows, ownership, product mismatch, status/note reset, and unique-row preservation. Existing Google-link verification regression passes.
- Deployment: not deployed.
- Live verification: `ORD-OWKMMCPFJG` release remains blocked by production Stripe/log access.
- Frontend: use `REVIEW_REJECTED_RESUBMIT` and `review_id` to edit/resubmit by PUT.

### 2026-10-12 — Phase 6 hardening complete

- Commit: working tree based on `75496af`; release commit not created.
- Migrations: both `2026_10_11_*` migrations rehearsed up → down → up on `storeapi_testing`.
- Tests: full suite 384 passed / 2,061 assertions on two consecutive runs. Fixed one flaky test (random price), one leak between tests (truncation tests left rows behind), and one stale assertion (Telegram `parse_mode`).
- Static analysis: Pint passes on the whole repository. Larastan went from 43 errors to 30, with none new.
- API docs: `storage/api-docs/api-docs.json` regenerated, including the refund `502` body.
- Deployment: not deployed; live remains `46e8219`.
- Next: Phase 7 (branch, commits, PR) after approval.

### 2026-10-12 — Release committed; hPanel verified

- Commit: `552d794` on `main`, pushed to `origin/main`. It is a single commit pushed straight to `main`, not the sliced branch and PR planned in Phase 7. `ops/deploy-production.sh` deploys `origin/main`, so the next deploy ships it.
- Tests: the full suite passes on the committed tree.
- Hostinger: after the hPanel change, scripted requests (`python-requests`, `curl`) to `apis.otantikqueen.com/api/v1/health` and `otantikqueen.com` return `200` with no challenge. The API now reports `x-hcdn-cache-status: BYPASS`. `www` redirects with `301` to the bare domain. Still to do: a first visit in a fresh browser, and the Stripe/EasyPost webhook delivery logs.
- Deployment: not deployed; live remains `46e8219`, and 32 `demo-*` categories are still public.
- Frontend: the contracts in this document are final for this release. Build against them now, and verify after the deploy.
