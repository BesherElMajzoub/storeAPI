# 08 Post-approval audit — results

## Executive summary

The application is **not shippable today**. A P0 path lets an admin move a normal
manual-capture order from `processing/authorized` to `shipped` through either
status endpoint without capturing the card; the scheduled capturer then ignores
the shipped order. Refund webhook ordering can also downgrade a completed refund
to `failed`, and the advertised retry can replay the same failed Stripe refund
because its idempotency key never changes. The mandatory full suite is red (3
failures), Pint is red, PHPStan level 5 is red even with the 205-block baseline
(27 errors), and without the baseline it reports 261 file errors. `composer
audit` reports one high and one medium advisory in `league/commonmark` 2.10.0.
The new manual-capture contract is not reflected in the frozen payment contract,
the Standard/Express shipping field is absent from both the frozen shipping
contract and OpenAPI, and 25 generated media files are committed. Migration,
seeding, configuration/route/event caching, and cleanup succeed. Stage A made no
application, test, configuration, database, route, dependency, or environment
changes.

## Baseline evidence (A0 outputs)

### Repository and test database

```text
> git status --short --branch
## main...origin/main
?? docs/AI/08-post-approval-audit.md
?? docs/AI/08-reviewer-prompt.md

> git log -1 --oneline --decorate
2952600 (HEAD -> main, origin/main, origin/HEAD) fix cancel

> git checkout -b ai/post-approval-audit
Switched to a new branch 'ai/post-approval-audit'

> Test-NetConnection 127.0.0.1 -Port 3308
Host             : 127.0.0.1
Port             : 3308
TcpTestSucceeded : True
```

The two untracked Phase 08 prompt files were supplied inputs. They were preserved;
only `docs/AI/08-post-approval-audit.md` is included in the audit commit.

### Full suite

```text
> php artisan test --compact
..................................................................................⨯⨯................................
....................................................................................................................
...............................................⨯............

FAILED  Tests\Feature\CreateStaffAccountCommandTest > refuses non staff or missing role
Failed asserting that 2 is identical to 0.
at tests\Feature\CreateStaffAccountCommandTest.php:63

FAILED  Tests\Feature\CreateStaffAccountCommandTest > refuses weak or mismatched password
Failed asserting that 2 is identical to 0.
at tests\Feature\CreateStaffAccountCommandTest.php:79

FAILED  Tests\Feature\TelegramNotificationTest > telegram notifier sends alert
An expected request was not recorded.
Failed asserting that false is true.
at vendor\laravel\framework\src\Illuminate\Http\Client\Factory.php:416

Tests:    3 failed, 289 passed (1638 assertions)
Duration: 374.21s
Exit code: 1
```

Isolation evidence, run sequentially (a discarded concurrent attempt collided on
the one shared MySQL schema and is not application evidence):

```text
> php artisan test --compact tests/Feature/CreateStaffAccountCommandTest.php
....
Tests:    4 passed (24 assertions)
Duration: 8.42s
Exit code: 0

> php artisan test --compact tests/Feature/TelegramNotificationTest.php
⨯.....
FAILED  Tests\Feature\TelegramNotificationTest > telegram notifier sends alert
An expected request was not recorded.
Tests:    1 failed, 5 passed (9 assertions)
Duration: 7.84s
Exit code: 1
```

### Pint

```text
> .\vendor\bin\pint --test
{"tool":"pint","result":"fail","files":[{"path":"app\\Http\\Controllers\\Api\\V1\\ContactMessageController.php","fixers":["fully_qualified_strict_types","ordered_imports"]}]}
Exit code: 1
```

### PHPStan level 5 with the baseline

The exact command first hit PHP's configured 128M worker limit:

```text
> .\vendor\bin\phpstan analyse --level=5 --no-progress
Child process error: PHPStan process crashed because it reached configured PHP memory limit: 128M
[ERROR] Found 1 error
Result is incomplete because of severe errors.
Exit code: 1
```

The required analysis was rerun with sufficient memory, without changing config:

```text
> .\vendor\bin\phpstan analyse --level=5 --no-progress --memory-limit=1G
[ERROR] Found 27 errors
Exit code: 1

By file:
app/Http/Resources/OrderResource.php                11
app/Http/Controllers/Api/V1/OrderController.php      9
app/Models/Order.php                                 5
app/Services/EasyPostService.php                     1
app/Http/Resources/AdminUserResource.php             1

By identifier:
property.notFound          9
ignore.count               6
argument.type              3
return.type                2
identical.alwaysFalse      2
method.notFound            2
ignore.unmatched           2
booleanAnd.alwaysFalse     1
```

### PHPStan level 5 without the baseline

A temporary audit-only config under `docs/AI/results/` included Larastan but not
`phpstan-baseline.neon`; it was removed before the commit.

```text
> .\vendor\bin\phpstan analyse --configuration=docs/AI/results/phpstan-audit-no-baseline.neon --level=5 --no-progress --memory-limit=1G --error-format=json
PHPStan exit code: 1
Total errors: 0
Total file errors: 261

BY ERROR IDENTIFIER
195 property.notFound
 24 method.notFound
 14 argument.type
  8 nullsafe.neverNull
  3 argument.unresolvableType
  3 method.nonObject
  3 return.type
  2 assign.propertyType
  2 identical.alwaysFalse
  2 staticMethod.notFound
  1 booleanAnd.alwaysFalse
  1 booleanAnd.leftAlwaysTrue
  1 constant.notFound
  1 instanceof.alwaysTrue
  1 nullCoalesce.offset
```

Full unsuppressed file grouping:

| File | Errors | File | Errors |
|---|---:|---|---:|
| `Http/Resources/OrderResource.php` | 36 | `Http/Resources/ProductDetailResource.php` | 25 |
| `Http/Controllers/Api/V1/OrderController.php` | 23 | `Http/Resources/CouponResource.php` | 19 |
| `Http/Resources/ProductResource.php` | 18 | `Http/Resources/ProductCardResource.php` | 14 |
| `Http/Controllers/Api/V1/Admin/TelescopeApiController.php` | 12 | `Services/OrderInventoryService.php` | 11 |
| `Http/Resources/CategoryDetailResource.php` | 10 | `Http/Resources/AdminOrderResource.php` | 6 |
| `Models/Coupon.php` | 6 | `Http/Resources/AuditLogResource.php` | 5 |
| `Models/Order.php` | 5 | `Services/StripeCheckoutService.php` | 5 |
| `Http/Controllers/Api/V1/Admin/AdminAnalyticsController.php` | 4 | `Http/Resources/AdminUserResource.php` | 4 |
| `Http/Resources/CategoryCardResource.php` | 4 | `Http/Resources/CategoryResource.php` | 4 |
| `Models/Product.php` | 4 | `Services/ShippingQuoteService.php` | 4 |
| `Http/Controllers/Api/V1/AddressController.php` | 3 | `Http/Controllers/Api/V1/Auth/AuthController.php` | 3 |
| `Http/Resources/CategoryBasicResource.php` | 3 | `Http/Resources/OrderItemResource.php` | 3 |
| `Http/Resources/ReviewResource.php` | 3 | `Mail/CancellationRequestDecidedMail.php` | 3 |
| `Models/Category.php` | 3 | `Services/OtpService.php` | 3 |
| `Console/Commands/MigrateImagesToSpatie.php` | 2 | `Http/Controllers/Api/V1/Admin/CancellationRequestController.php` | 2 |
| `Http/Resources/CouponUsageResource.php` | 2 | `Http/Resources/ProductGalleryImageResource.php` | 2 |
| `Http/Controllers/Api/V1/Admin/OrderController.php` | 1 | `Http/Controllers/Api/V1/Admin/ShippingController.php` | 1 |
| `Http/Controllers/Api/V1/ProductController.php` | 1 | `Http/Controllers/Controller.php` | 1 |
| `Http/Middleware/TrackVisitorSession.php` | 1 | `Http/Resources/AdminWishlistItemResource.php` | 1 |
| `Http/Resources/ShippingRateResource.php` | 1 | `Services/GoogleAuthService.php` | 1 |
| `Services/ReviewService.php` | 1 | `Services/WishlistAnalyticsService.php` | 1 |

Paths in the table are relative to `app/`.

### Composer audit

```text
> composer audit
league/commonmark 2.10.0
- HIGH: PKSA-m4t9-vsgq-8khn / GHSA-3q6v-r5mr-hxv8
  Quadratic-time denial of service in the GitHub Flavored Markdown Table extension block-start scan
  affected: >=2.0.0,<=2.10.1
- MEDIUM: PKSA-m2dq-1fhr-29b1 / GHSA-97jj-33gv-5xf9
  DisallowedRawHtml bypass when a disallowed tag name ends the raw-HTML literal
  affected: >=1.3.0,<=2.10.1
Found 2 security vulnerability advisories affecting 1 package.
Exit code: 1

> composer why league/commonmark
laravel/framework v12.69.1 requires league/commonmark (^2.8.1)
```

No application code explicitly invokes CommonMark, but the vulnerable transitive
package remains installed and the Definition of Done requires a clean or justified
audit.

### Cache commands

```text
> php artisan config:cache
INFO  Configuration cached successfully.
> php artisan route:cache
INFO  Routes cached successfully.
> php artisan event:cache
INFO  Events cached successfully.
> php artisan optimize:clear
INFO  Clearing cached bootstrap files.
config ... DONE
cache ... DONE
compiled ... DONE
events ... DONE
routes ... DONE
views ... DONE
Exit code: 0
```

### Fresh test schema and seed

The process environment was populated from the `DB_*` entries in `phpunit.xml`
and `APP_ENV=testing`; no `.env` file was read or printed.

```text
> php artisan migrate:fresh --seed --no-interaction
Dropping all tables ... DONE
Creating migration table ... DONE
Running migrations:
0001_01_01_000000_create_users_table ... DONE
0001_01_01_000001_create_cache_table ... DONE
0001_01_01_000002_create_jobs_table ... DONE
2026_01_17_093523_create_personal_access_tokens_table ... DONE
2026_01_17_100000_create_store_tables ... DONE
2026_01_28_000000_create_otp_codes_table ... DONE
2026_01_28_000001_add_auth_fields_to_users_table ... DONE
2026_03_16_000001_upgrade_addresses_table ... DONE
2026_03_16_000002_create_wishlists_table ... DONE
2026_03_25_180000_update_product_images_table ... DONE
2026_04_05_000001_create_contact_messages_table ... DONE
2026_04_05_000002_create_inspired_leads_table ... DONE
2026_04_05_000003_rename_wishlists_to_wishlist_items_table ... DONE
2026_04_13_000001_create_social_accounts_table ... DONE
2026_04_13_000002_create_wishlist_events_table ... DONE
2026_04_13_000003_enhance_reviews_table ... DONE
2026_04_13_000004_add_avatar_to_users_table ... DONE
2026_04_13_192713_create_telescope_entries_table ... DONE
2026_05_02_000001_replace_media_table_for_spatie ... DONE
2026_05_02_000002_keep_product_images_for_migration ... DONE
2026_05_02_143849_create_media_table ... DONE
2026_05_19_000001_create_order_cancellation_requests_table ... DONE
2026_05_19_000002_add_stripe_fields_to_orders_table ... DONE
2026_05_20_000001_add_variant_attributes_to_order_items_table ... DONE
2026_05_28_000000_create_analytics_tables ... DONE
2026_05_31_170000_create_coupon_system_tables ... DONE
2026_06_01_000001_add_shipping_fields_to_orders_table ... DONE
2026_09_02_000001_add_inventory_and_refund_tracking_to_orders ... DONE
2026_09_02_000002_add_production_query_indexes ... DONE
2026_09_02_000003_add_description_to_categories ... DONE
2026_09_03_000001_add_shipping_quotes_and_physical_fields ... DONE
2026_09_26_000001_add_free_shipping_support ... DONE
2026_09_26_000002_expand_payment_statuses_for_refunds ... DONE
2026_10_05_000001_add_payment_authorization_and_refund_status_to_orders ... DONE

Database\Seeders\DemoAccessSeeder ... DONE
Demo access data: 5 roles and 24 users with varied auth/profile states.
Database\Seeders\DemoCatalogSeeder ... DONE
Demo catalogue: 36 categories, 96 products, 200+ variants, and varied galleries.
Database\Seeders\DemoCommerceSeeder ... DONE
Demo commerce: 10 coupons, 42 orders, payments, labels, tracking, refunds, and cancellation states.
Database\Seeders\DemoEngagementSeeder ... DONE
Demo engagement: reviews, wishlist history, inbox states, leads, notifications, and audit logs.
Database\Seeders\DemoAnalyticsSeeder ... DONE
Demo analytics: 50 visitors, 72 sessions, 431 page views, and 250 events.
Exit code: 0
```

### Routes

```text
> php artisan route:list --json
> compare with docs/AI/results/routes-current.json by method + URI and then full JSON
Approved route count: 176
Current route count: 184
Added: 8
GET|HEAD /
GET|HEAD docs
GET|HEAD docs/asset/{asset}
GET|HEAD sanctum/csrf-cookie
GET|HEAD storage/{path}
GET|HEAD telescope/{view?}
GET|HEAD up
PUT storage/{path}
Removed: 0
Changed: 0
```

All eight are non-`api/v1` framework/application infrastructure routes, but the
approved snapshot is authoritative; approval is still required before changing
the snapshot.

### Scheduler

```text
> php artisan schedule:list
0    0   * * *  php artisan telescope:prune --hours=48
0    */4 * * *  php artisan shipping:track
*/10 *   * * *  php artisan orders:expire-abandoned-checkouts
*/5  *   * * *  php artisan orders:capture-authorized-payments
0    0   * * *  shipping-quotes:prune
Exit code: 0
```

### Removed assertion audit

```text
> git diff 3cfcc6d..HEAD -- tests | select removed lines containing "assert"
Removed assertion lines: 9
```

The nine lines belong to rewrites rather than silent weakening: the pending
order assertion now uses the already loaded model; checkout response structure
adds `payment_required`; and the cancelled-payment webhook test moved its order,
payment, stock, and replay assertions into the new authorization suite. The new
suite adds substantially stronger state assertions, but findings below identify
the important paths it still omits.

## Post-approval diff review

Scope evidence:

```text
> git diff --stat 3cfcc6d..HEAD -- app config database routes
47 files changed, 1389 insertions(+), 172 deletions(-)

> git log -p --reverse 3cfcc6d..HEAD -- app config database routes
2782 patch lines; 7 commits; 52 per-commit file diffs reviewed
```

“Covered” below names the exact assertion that carries the verdict. “Uncovered”
points to a finding rather than claiming correctness.
For compactness in this table, `Controllers/`, `Requests/`, and `Resources/`
mean `app/Http/...`; other application entries are relative to `app/`, and
`migrations/` / `seeders/` are relative to `database/`.

| Changed file | What changed / verdict | Covering test and exact assertion |
|---|---|---|
| `Console/Commands/CaptureAuthorizedPayments.php` | Added five-minute due-hold capture, pending-request delay, and alert path. Core selection is correct; alternate ship path is P0. | `OrderPaymentAuthorizationTest::test_scheduler_captures_only_holds_whose_cancel_window_has_closed` asserts due=`paid`, `paid_at` set, Payment=`completed`, fresh=`authorized`; expired-hold test asserts `failed` + one alert. |
| `Console/Commands/CreateStaffAccount.php` | Adds validated/audited interactive staff creation. Command behavior passes alone; suite assumptions are order-dependent. | `CreateStaffAccountCommandTest::test_creates_verified_active_admin_that_can_reach_admin_routes` asserts role, active/verified state, password hash, audit payload, no password leak, admin access. A08-QA-001. |
| `Console/Commands/UnpublishProductsMissingShippingData.php` | Adds dry-run/apply remediation. Verified OK. | `UnpublishProductsMissingShippingDataCommandTest::test_apply_moves_only_incomplete_published_products_to_draft` asserts only incomplete published products become draft and audit IDs/SKUs match. |
| `Contracts/EasyPostServiceInterface.php` | Adds carrier-account argument. Implementations match the signature. | `ShippingStandardExpressTest::test_rate_request_is_restricted_to_the_configured_usps_and_ups_carrier_accounts` has an exact mock expectation for both configured IDs. Missing-ID path: A08-SHIP-001. |
| `Exceptions/ReviewSubmissionException.php` | Adds typed HTTP status/code. Correct mechanism; changes frozen responses. | `ReviewTest` asserts 403/`REVIEW_PURCHASE_REQUIRED` and 409/`REVIEW_ALREADY_EXISTS`; 429 is uncovered. A08-CON-003. |
| `Controllers/Api/StripeWebhookController.php` | Introduces authorized state, late-payment auto-settlement, refund status. Happy/manual path works; refund ordering and timestamp defects remain. | Manual webhook test asserts processing/authorized, `authorized_at`, null `paid_at`, pending Payment and mail. Refund webhook test currently proves the wrong downgrade; A08-PAY-002/A08-PAY-004. |
| `Controllers/Api/V1/AddressController.php` | Verifies/normalizes saved addresses through EasyPost and exposes state. Correct for covered success/failure paths; controller is now 534 lines. | `AddressControllerTest::test_address_is_verified_and_normalized_before_it_is_saved` asserts normalized street/state/ZIP in JSON and DB; failed update asserts original city unchanged. |
| `Controllers/Api/V1/Admin/CancellationRequestController.php` | Locks order, blocks shipped/in-progress acceptance, queues settlement through observer. Normal path correct; cancellation row is not locked. | Authorization suite asserts captured cancellation becomes cancelled/refunded and shipped/in-progress requests stay pending. Concurrent double accept uncovered: A08-CAN-001. |
| `Controllers/Api/V1/Admin/MediaController.php` | Explicit missing category image 422. Verified OK (same schema as request validation). | Existing media upload validation suite asserts missing/invalid image 422; no response shape change beyond making this branch explicit. |
| `Controllers/Api/V1/Admin/OrderController.php` | Adds authorization-aware refund/cancel behavior and new resources. Refund paths mostly correct; status paths can ship uncaptured orders. | Existing bulk tests assert `shipped_at` only for paid orders; no authorized test. A08-PAY-001 and A08-PAY-003. |
| `Controllers/Api/V1/Admin/ProductController.php` | Commits DB changes before replacing media and adds new files before deleting old. Protects old gallery, but upload failure can leave a mixed gallery. | Successful replacement is covered by product media tests; injected mid-batch failure is uncovered. A08-MED-002. |
| `Controllers/Api/V1/Admin/ShippingController.php` | Claims fulfillment, captures before buying a label, blocks pending cancellation and double purchase. Verified for ordinary success/failure; raw status endpoints bypass it. | Authorization suite asserts shipped+paid after label, 502/no label on capture error, pending request 409, second claim 409. A08-PAY-001. |
| `Controllers/Api/V1/Admin/UserController.php` | Switches wishlist image eager load to Spatie media. Verified OK. | `ProductImageUrlsTest::test_admin_wishlist_images_come_from_the_product_gallery` asserts exact gallery URL. |
| `Controllers/Api/V1/Admin/WishlistAnalyticsController.php` | Uses `primaryImageUrl()` for three outputs. Verified OK. | Same image test asserts index and summary exact URLs; analytics tests cover conversion data. |
| `Controllers/Api/V1/ContactMessageController.php` | Prevents synchronous Telegram failure from turning a saved contact into HTTP 500. Logic is sound; formatting is red. | Contact notification test asserts 201 and queued notification; no sync-throw regression assertion. Pint failure: A08-QA-002. |
| `Controllers/Api/V1/EasyPostWebhookController.php` | Ignores stale snapshots and deduplicates failure alerts. Covered case is correct; equal/no-event snapshots remain ambiguous. | `EasyPostShippingTest::test_easypost_webhook_ignores_an_older_tracker_snapshot_arriving_late` asserts delivered/timeline unchanged and no alert. A08-SHIP-002. |
| `Controllers/Api/V1/OrderController.php` | Adds payment_required documentation and cancellation/refund flow. Ownership, direct cancel, stock/coupon/quote, late payment are covered. Controller is 917 lines and PHPStan has 23 unsuppressed errors. | `ProductionReadinessTest` asserts user A canceling user B is 404; cancellation tests assert 200/400/409, stock, coupon and quote outcomes. A08-CC-001/A08-STA-001. |
| `Controllers/Api/V1/ReviewController.php` | Maps typed review errors to 403/409/429 + code. Semantically clearer but contract-changing. | Review tests assert 403 and 409 shapes; 429 uncovered. A08-CON-003. |
| `Controllers/Api/V1/WishlistController.php` | Eager-loads Spatie media. Verified OK. | Wishlist and product image suites assert returned gallery URLs and normal CRUD. |
| `Requests/Api/V1/Admin/BaseAdminRequest.php` | Coerces empty strings to null recursively. Reasonable for multipart admin forms but broad. | Existing admin request suites exercise empty optional fields; no regression found in 289 passing tests. |
| `Requests/Api/V1/StoreAddressRequest.php` | Country becomes ISO length 2; state added. Covered/documented. | Address tests assert normalized US/CA and schema validation; frontend address contract states ISO-2. |
| `Requests/Api/V1/UpdateAddressRequest.php` | Same address contract for partial updates. Covered/documented. | Failed-verification test asserts 422 and no mutation; ordinary partial update asserts 200. |
| `Resources/AddressResource.php` | Adds `state`. Covered/documented. | Normalization test asserts `data.state=CA`; `FRONTEND_ADDRESS_EASYPOST.md` includes field. |
| `Resources/AdminWishlistItemResource.php` | Uses gallery URL. Verified OK. | Product image URL test asserts exact `data.wishlist.0.image`. |
| `Resources/Concerns/ResolvesMediaUrls.php` | Falls back to original while conversions are absent. Verified OK. | Product image test asserts card/thumb/zoom/detail equal original URL when conversions are missing. |
| `Resources/OrderResource.php` | Adds refund, cancellation, authorized_at. Covered values are correct; PHPStan reports 36 unsuppressed errors and payment contract is stale. | Authorization test asserts cancellation mode/reason/deadline and refund status; outage test asserts pending amount=100. A08-CON-001/A08-STA-001. |
| `Resources/ProductGalleryImageResource.php` | Uses shared fallback. Verified OK. | Product image test asserts gallery detail equals original before conversion. |
| `Jobs/SettleCancelledOrderPayment.php` | Six retries/backoff and final failed+alert. Correct covered lifecycle. | Outage test manually proves throw leaves pending, `failed()` sets failed, cancellation/stock remain, one alert queued. |
| `Models/Order.php` | Adds cancel window/refund fields and status helpers. `created_at` for unpaid versus `authorized_at ?? paid_at` for paid is intentional: the approved frontend contract says unpaid counts from order creation and paid counts from checkout. Magic strings and types remain. | Cancellation-options test asserts within-window, expired, in-progress, label, pending-request and shipped modes. A08-CC-001/A08-STA-001. |
| `Models/Product.php` | Adds conversion-aware primary URL. Verified OK. | Product image tests assert exact fallback and generated conversion files. |
| `Models/User.php` | Makes relative avatars absolute via URL generator. Verified for current demo values. | Product/user resource tests pass in the 289-test baseline; no dedicated host/`APP_URL` assertion exists. |
| `Observers/OrderObserver.php` | Releases coupons/quotes for all cancellations, restores unshipped stock, queues money settlement. Matches new approved cancellation behavior. | Authorization tests assert stock 8→10, coupon used_count 1→0, usage deletion; old shipped-refund tests assert no restock. |
| `OpenApi/Schemas/Order.php` | Adds statuses and refund/cancellation schema. Payment schema is updated, shipping `method` is not. | Generated JSON contains order fields; A08-CON-001/A08-CON-002. |
| `Services/EasyPostService.php` | Adds account restriction and SDK static signature validation. Signature path verified. | `EasyPostWebhookSignatureTest` asserts unsigned/bad=401, correct=200. Missing carrier config: A08-SHIP-001. |
| `Services/FakeEasyPostService.php` | Signature-compatible third argument; behavior unchanged. Verified OK. | Shipping contract/fake-driver tests pass; PHP interface enforcement also verifies signature compatibility. |
| `Services/OrderPaymentService.php` | Centralizes capture/release/refund with cache lock. Core decisions are sensible; alternate ship, failed-refund idempotency, and true concurrency gaps remain. | Authorization suite covers capture, release, synchronous refund, Stripe outage and simulated before/after race states. A08-PAY-001/003/005. |
| `Services/ReviewService.php` | Enforces delivered purchase, duplicate-specific conflict, daily limit. Delivered/duplicate paths correct; response contract and 429 uncovered. | Review tests assert no review=403+code, delivered=201+stored review, duplicate=409+code. A08-CON-003. |
| `Services/ShipmentTrackingService.php` | Prevents strictly older event snapshots from replacing newer state. Older case correct; equal/no-event regression possible. | Stale webhook test asserts delivered state/timeline unchanged. A08-SHIP-002. |
| `Services/ShippingQuoteService.php` | Selects Standard/Express and passes carrier IDs. Selection tests pass; missing IDs silently disable restriction and `method` is undocumented. | Standard/Express tests assert USPS cheapest and UPS fastest IDs and fallback. A08-SHIP-001/A08-CON-002. |
| `Services/StripeCheckoutService.php` | Adds manual capture/retrieve/capture/release and stable operation keys. Manual session is correct; retry refund key is not attempt-safe. | Authorization test asserts capture_method metadata; refund-key test asserts `refund-order-{id}`. No capture/release key assertion. A08-PAY-003/A08-PAY-005. |
| `Services/TelegramNotifier.php` | Switches to escaped HTML and plain-text fallback. Implementation is safer, but its test still expects Markdown and fails deterministically. | Isolated Telegram suite: 1 failed, 5 passed. A08-QA-001. |
| `Services/WishlistAnalyticsService.php` | Eager-loads Spatie media. Verified OK. | Product image and wishlist analytics tests assert exact image and analytics values. |
| `config/services.php` | Adds manual capture and USPS/UPS IDs. No `env()` outside config; `.env.example` lists all three new variables. Missing-ID semantics are unsafe. | Config cache succeeds; configured account IDs are asserted by shipping test. A08-SHIP-001. |
| `migrations/2026_10_05_000001_add_payment_authorization_and_refund_status_to_orders.php` | Adds authorized enum, timestamps, refund status and index. Production is not launched; editability exception applies. Verified fresh/up; down not separately run. | `migrate:fresh --seed` succeeds and payment tests persist every new field. |
| `seeders/Concerns/CreatesDemoMedia.php` | Uses Spatie pipeline and deletes stale files. Successful case works; temp cleanup on thrown conversion is absent. | Product image seeder test asserts two media, all conversions/files, and only original+conversions directory entries. A08-MED-003. |
| `seeders/DemoCatalogSeeder.php` | Aligns option values with generated variants. Verified OK. | `DemoDatabaseSeederTest` plus successful full seed; product image seeder test validates resulting media. |
| `routes/console.php` | Schedules capture every five minutes without overlap. Verified registration. | `schedule:list` output above; command selection assertions in authorization suite. |

## Findings

### A08-PAY-001 — Authorized orders can be marked shipped without capture

- **Severity:** P0
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/V1/Admin/OrderController.php:251` and `:300`
- **Problem:** The single and bulk status endpoints allow `processing -> shipped` without requiring `payment_status=paid` or calling the capture service. The scheduler selects only `status=processing`, so it never captures the resulting shipped authorization.
- **Scenario:** A normal manual-capture order is `processing/authorized`; an admin calls `/admin/orders/{id}/status` or `/admin/orders/bulk-status` with `shipped` → the API returns 200 and sets `shipped_at`, the capture scheduler skips it, cancellation is refused, and admin refund returns 409 because the payment is only authorized. Fulfillment can complete while no money is captured.
- **Test:** Planned `OrderPaymentAuthorizationTest::test_single_and_bulk_status_endpoints_cannot_ship_an_authorized_order_without_capture` — both requests must return 409 and preserve `processing/authorized` with null `shipped_at`.
- **Fix:** Stage B: gate every non-label shipping transition on a captured payment (recommended: reject authorized status transitions and require the label/capture path). Commit: N/A (Stage A report only).
- **Evidence:**
  ```text
  statusTransitions(): processing => ['shipped', 'cancelled']
  bulkUpdateStatus(): updates status/shipped_at without inspecting payment_status
  updateStatus(): updates status/shipped_at without inspecting payment_status
  CaptureAuthorizedPayments query: where status='processing' and payment_status='authorized'
  Existing shipping-status tests create only payment_status='paid'; no authorized assertion exists.
  ```

### A08-PAY-002 — Late refund.failed webhook downgrades a completed refund

- **Severity:** P1
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/StripeWebhookController.php:303`
- **Problem:** `handleRefundFailed()` locates only by PaymentIntent and unconditionally writes `refund_status=failed`, even when a full `charge.refunded` event already made the order `refunded/succeeded`. It does not correlate a refund ID or protect terminal success.
- **Scenario:** Stripe delivers `charge.refunded` and then a delayed `refund.failed` event for an earlier/other refund attempt on the same PaymentIntent → the customer-facing order changes from “refunded” to “manual follow-up required” and staff receive a false urgent alert even though the full amount was returned.
- **Test:** Replace the currently inverted assertion in `OrderPaymentAuthorizationTest::test_refund_webhooks_update_refund_status_without_reopening_a_cancelled_order`; success followed by failure must remain `succeeded`, `refunded_amount=total`, with no new alert.
- **Fix:** Stage B: make terminal full-refund success monotonic and correlate failure to an active refund attempt where possible. Commit: N/A.
- **Evidence:**
  ```text
  Current test sends charge.refunded, asserts succeeded, then sends refund.failed and asserts failed.
  handleRefundFailed(): $order->update(['refund_status' => 'failed']);
  ```

### A08-PAY-003 — “Retry refund” reuses the terminal failed Stripe request

- **Severity:** P1
- **Status:** OPEN
- **Location:** `app/Services/StripeCheckoutService.php:143`
- **Problem:** Every refund attempt for an order uses the permanent key `refund-order-{id}`. That is correct for transport retries of one request, but the advertised admin retry after an asynchronous terminal failure needs a new logical attempt; within Stripe's idempotency retention period the same key replays the original refund response.
- **Scenario:** Refund creation returns pending, a later webhook marks it failed, and an admin immediately clicks “Retry refund” → the API sends the same idempotency key and can receive/replay the failed original instead of creating a new refund, leaving the customer's money outstanding.
- **Test:** Planned service/controller test with two logical attempts: transport retry within attempt 1 uses the same key; retry after terminal failure uses a distinct attempt key and succeeds once.
- **Fix:** Stage B: persist/derive a refund-attempt identifier while keeping each attempt idempotent. Commit: N/A.
- **Evidence:**
  ```text
  createStripeRefund(..., ['idempotency_key' => "refund-order-{$order->id}"])
  Existing test asserts only that permanent key; admin retry test mocks Stripe and cannot expose provider replay.
  ```

### A08-SHIP-001 — Missing carrier account IDs silently remove carrier restriction

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `app/Services/ShippingQuoteService.php:79`
- **Problem:** Empty `EASYPOST_USPS_CARRIER_ACCOUNT_ID` / `EASYPOST_UPS_CARRIER_ACCOUNT_ID` values are filtered out; `EasyPostService` then omits `carrier_accounts`, asking every account carrier for rates. This contradicts the code comment that checkout is restricted to USPS/UPS and has no missing-config test.
- **Scenario:** Production deploys with either new variable omitted (both placeholders are blank by default) → checkout can expose FedEx/other-carrier rates under “Standard/Express”, contrary to the intended carrier policy.
- **Test:** Planned `ShippingStandardExpressTest::test_missing_required_carrier_account_configuration_fails_closed` — assert a configuration error and no provider call.
- **Fix:** Recommendation A: fail readiness and rate requests closed until both IDs exist. Commit: N/A.
- **Evidence:**
  ```text
  domesticCarrierAccounts(): array_values(array_filter([USPS_ID, UPS_ID]))
  EasyPostService: carrier_accounts is set only when !empty($carrierAccounts)
  .env.example: both IDs are blank
  No test names or assertions cover missing IDs.
  ```
- **Decision needed:** A) require both IDs and return a configuration error (recommended); B) intentionally allow all configured carriers and document the fallback.

### A08-CON-001 — Frozen payment contract still describes automatic capture

- **Severity:** P1
- **Status:** OPEN
- **Location:** `docs/PAYMENT_CHECKOUT_CONTRACT.md:76`
- **Problem:** The frozen contract says `checkout.session.completed` marks the order `processing/paid`; approved manual capture actually returns `processing/authorized`, `authorized_at`, a new cancellation/refund object, and a later capture.
- **Scenario:** A frontend built only from the frozen payment contract treats `authorized` as unknown or waits for `paid`, even though checkout succeeded, blocking confirmation/cancellation UI.
- **Test:** Planned contract test asserting the documented manual webhook shape and automatic-mode compatibility.
- **Fix:** Stage B documentation/OpenAPI-only alignment to the owner-approved manual behavior; no route or runtime response change. Commit: N/A.
- **Evidence:**
  ```text
  PAYMENT_CHECKOUT_CONTRACT: "marks the order processing / paid"
  OrderPaymentAuthorizationTest: asserts processing / authorized, authorized_at non-null, paid_at null
  FRONTEND_ORDER_CANCELLATION documents authorized correctly.
  ```

### A08-CON-002 — Standard/Express response field is absent from frozen docs and OpenAPI

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `app/Services/ShippingQuoteService.php:61`
- **Problem:** Item-based rates now include `method: standard|express` and are collapsed to at most two records, but `SHIPPING_CONTRACT.md`, `ShippingController` OpenAPI attributes, and generated `storage/api-docs/api-docs.json` omit `method` and still describe generic provider rates.
- **Scenario:** A frontend generated from OpenAPI has no `method` member and labels USPS/UPS service strings directly; runtime sends a different, intentionally simplified contract.
- **Test:** Planned frozen-contract test asserting the approved rate keys and number/selection semantics.
- **Fix:** If approved, update frozen shipping contract + OpenAPI + frontend impact docs without changing runtime. Commit: N/A.
- **Evidence:**
  ```text
  Runtime result[] includes 'method' => $method.
  ShippingStandardExpressTest asserts method=standard/express.
  rg found no OpenAPI property named method; SHIPPING_CONTRACT response has no method key.
  ```
- **Decision needed:** A) approve/document `method` and two-tier selection (recommended, matches committed tests); B) remove it and restore the frozen generic-rate response.

### A08-CON-003 — Review endpoint status/error contract changed without approval

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `app/Http/Controllers/Api/V1/ReviewController.php:48`
- **Problem:** “No delivered purchase” changed from 409 to 403, daily-limit failures changed to 429, and machine codes were added to `errors`. These are frozen status/response changes and no frontend handoff documents them; the 429 branch has no test.
- **Scenario:** A client that handles all review submission failures as 409 now receives 403/429 and can route 403 to a global authorization screen rather than showing the review-specific message.
- **Test:** Existing 403/409 tests plus planned exact 429 envelope assertion.
- **Fix:** Implement only the owner-approved option and update contract/OpenAPI. Commit: N/A.
- **Evidence:**
  ```text
  git diff: old catch(Exception) always returned 409; new exception returns 403/409/429 + errors.code.
  ReviewTest asserts 403 and 409; no daily-limit/429 test exists.
  ```
- **Decision needed:** A) approve semantic 403/409/429 plus codes and document them (recommended); B) restore the frozen 409 envelope for every domain rejection.

### A08-SEC-001 — Composer audit reports high and medium advisories

- **Severity:** P1
- **Status:** NEEDS-DECISION
- **Location:** `composer.lock` (`league/commonmark` 2.10.0)
- **Problem:** The installed Laravel transitive dependency is in both affected ranges. No explicit application Markdown entry point was found, which reduces present exploitability, but the required dependency gate is red.
- **Scenario:** Any current/future framework Markdown rendering path accepts attacker-controlled GFM table/raw HTML input → it can trigger quadratic CPU use or bypass disallowed raw-HTML filtering.
- **Test:** `composer audit` must exit 0 after the approved resolution; add a focused rendering regression only if an application input path is identified.
- **Fix:** A minimal transitive patch update is the normal remedy, but Phase 08 forbids package upgrades without an explicit exception. Commit: N/A.
- **Evidence:** See exact `composer audit`, version, and `composer why` output in A0.
- **Decision needed:** A) authorize the smallest compatible `league/commonmark` security update (recommended); B) formally accept/justify the risk because no app Markdown path exists.

### A08-QA-001 — Mandatory full suite is red and order-dependent

- **Severity:** P2
- **Status:** OPEN
- **Location:** `tests/Feature/CreateStaffAccountCommandTest.php:63`, `:79`; `tests/Feature/TelegramNotificationTest.php:55`
- **Problem:** The full suite has three failures. Two staff-command assertions assume `User::count()===0` and fail only in the full run (two users remain); the class passes alone. Telegram changed to escaped HTML but its deterministic test still requires Markdown.
- **Scenario:** CI/full verification fails, while a developer running only the staff class sees green and can miss the isolation leak; Telegram's intended safe formatting has no passing regression proof.
- **Test:** Fix/isolate the leaking setup and assert rejected email addresses do not exist rather than global zero; update Telegram assertions to exact escaped HTML plus 400 fallback/plain text.
- **Fix:** Stage B, test-only unless isolation investigation finds application boot leakage. Commit: N/A.
- **Evidence:** Full and sequential isolated outputs in A0.

### A08-STA-001 — PHPStan level 5 is red with and without a stale baseline

- **Severity:** P2
- **Status:** OPEN
- **Location:** `phpstan.neon`, `phpstan-baseline.neon`, 42 app files
- **Problem:** The 205 baseline blocks suppress 245 occurrences, are stale against current code, and still leave 27 errors. Removing the baseline exposes 261 current file errors, so DoD #8 is unmet.
- **Scenario:** A real invalid inferred order type in `OrderController` (including calls to `canBeCancelledByCustomer()` on `Model|Collection`) is hidden among suppressions and new failures; future regressions cannot rely on the static gate.
- **Test:** PHPStan level 5 with baseline include removed must exit 0; full suite must remain green after each refactor group.
- **Fix:** Stage B refactor commits grouped by resources/model annotations, controller/service generics, then residual real errors; do not add ignores/casts solely to silence analysis. Commit: N/A.
- **Evidence:** Exact 27/261 outputs and full categorization below.

### A08-QA-002 — Pint gate is red

- **Severity:** P3
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/V1/ContactMessageController.php`
- **Problem:** Import ordering / fully-qualified strict-types formatting violates the mandatory formatter gate.
- **Scenario:** CI executes `pint --test` → exits 1.
- **Test:** `pint --test` exits 0 after formatting; no behavior change.
- **Fix:** Stage B formatting-only commit. Commit: N/A.
- **Evidence:** Exact Pint JSON in A0.

### A08-MED-001 — Generated upload artifacts are committed and not ignored

- **Severity:** P2
- **Status:** OPEN
- **Location:** `storage/media-library/temp/**`, `.gitignore`
- **Problem:** 25 originals/conversions (41,556 bytes) from five random temp directories are tracked; `.gitignore` has no rule for this tree.
- **Scenario:** Test/demo uploads continually create random binary diffs and repository growth; generated files can be mistaken for deployable assets.
- **Test:** `git ls-files 'storage/media-library/temp/**'` returns nothing; a generated temp file remains untracked/ignored; image tests regenerate their own fixtures.
- **Fix:** Stage B removal from git plus precise ignore rule. Commit: N/A.
- **Evidence:**
  ```text
  Committed temp media files: 25
  Sum: 41556 bytes
  .gitignore matches only /public/storage, /storage/*.key, /storage/pail
  ```

### A08-PAY-004 — Authorization time is webhook receipt time, not Stripe event time

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/StripeWebhookController.php:143`
- **Problem:** `authorized_at` is always `now()` when the webhook is processed. The contract defines it as checkout completion and both the cancel deadline and scheduled capture derive from it.
- **Scenario:** Stripe completes at 12:00 but the webhook/queue is delayed until 14:30 → the API exposes a direct-cancel deadline of 17:30 and delays capture 2.5 hours beyond the approved three-hour post-checkout window.
- **Test:** Deliver a signed completed event whose `created` time is two hours old; assert `authorized_at` and `direct_until` derive from the event completion time.
- **Fix:** Stage B: record the trusted signed event timestamp (with an explicit fallback) rather than handler wall-clock time. Commit: N/A.
- **Evidence:** Current update writes `'authorized_at' => now()`; existing test uses current delivery and cannot distinguish the clocks.

### A08-PAY-005 — Critical money/race rules lack exact tests

- **Severity:** P2
- **Status:** OPEN
- **Location:** `tests/Feature/OrderPaymentAuthorizationTest.php`
- **Problem:** The large new suite uses whole-dollar 100.00 examples and simulated before/after states. It does not assert fractional-cent conversion/refund values, capture/release idempotency keys, a true simultaneous capture-vs-cancel interleaving, or the alternate authorized status endpoints.
- **Scenario:** A 10.005/discounted total rounds inconsistently or a retry sends a different capture/release key → tests remain green while Stripe and DB money diverge or duplicate a provider operation.
- **Test:** Exact cent-boundary cases, exact refunded amount/partial ordering, capture/release key capture, and a real two-connection race test.
- **Fix:** Stage B tests first, then only fixes exposed by them. Commit: N/A.
- **Evidence:** Search found manual-flow money assertions at 100/10000 only; no tests contain `capture-order-` or `release-order-`; existing race tests set states sequentially.

### A08-SHIP-002 — Equal/no-event tracker snapshots can regress delivery state

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Services/ShipmentTrackingService.php:62`
- **Problem:** A snapshot is stale only when its latest event timestamp is strictly less. An incoming snapshot with the same timestamp but an older top-level status, or no tracking details at all, is accepted and can replace `delivered` with an earlier shipment status/timeline.
- **Scenario:** A duplicate/out-of-order EasyPost snapshot has `status=in_transit` and the same latest event time as stored delivered data → `shipment_status` regresses and stored events are replaced even though no newer carrier fact exists.
- **Test:** Add equal-timestamp/lower-status and empty-history snapshots against a delivered order; assert status/timeline remain monotonic.
- **Fix:** Stage B monotonic comparison using snapshot/event identity and terminal-state ranking. Commit: N/A.
- **Evidence:** Existing test covers only `incoming->lt(stored)` and passes; `isStale()` returns false when either side is null or times are equal.

### A08-SHIP-003 — Polling command re-alerts the same shipping failure every run

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Console/Commands/TrackShipments.php:54`
- **Problem:** The webhook path deduplicates failure alerts using prior `shipment_status`; the scheduled `shipping:track` command alerts on every `failure`/`return_to_sender` response and has no command test.
- **Scenario:** A shipment stays in `failure` for 24 hours → the four-hour scheduler sends the same urgent alert six times.
- **Test:** Run `shipping:track` twice with the same failure tracker; assert one alert and stable status.
- **Fix:** Reuse the webhook's transition check/shared service and add command coverage. Commit: N/A.
- **Evidence:** No test references `shipping:track`; command branch checks only current tracker status, not previous shipment status.

### A08-CAN-001 — Concurrent admin accepts can send duplicate decision email

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/V1/Admin/CancellationRequestController.php:72`
- **Problem:** Request status is checked before the transaction and only the order row is locked. The cancellation-request row/object is not locked or refreshed before update/email.
- **Scenario:** Two admins accept the same pending request concurrently → both read `pending`; the second waits on the order lock, then still updates its stale request object and both queue an accepted email.
- **Test:** Two-connection concurrent accept test; exactly one 200/email, the loser gets 409, money/stock settle once.
- **Fix:** Lock and re-check the cancellation request inside the transaction; dispatch mail only for the winning transition. Commit: N/A.
- **Evidence:** Lines 72–78 check stale model before transaction; transaction locks only `orders`; email is unconditional after it returns.

### A08-MED-002 — Failed multi-image replacement can leave a mixed gallery

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/V1/Admin/ProductController.php:374`
- **Problem:** The safer add-before-delete sequence preserves old media, but if upload/conversion N fails after earlier new images succeed, no cleanup removes the partial new media and the endpoint aborts with a mixed old/new gallery.
- **Scenario:** Replacing three images succeeds for the first and fails converting the second → old gallery remains plus the first new image, although the update request failed.
- **Test:** Inject failure on the second `addMedia`; assert response failure and byte/DB-identical old gallery with no new media/temp files.
- **Fix:** Compensate newly added media on failure before rethrowing, without deleting old media until the complete new set exists. Commit: N/A.
- **Evidence:** New media is added in a loop; old deletion occurs only after the loop; there is no catch/cleanup. Existing tests cover success only.

### A08-MED-003 — Demo media temp file is not cleaned when attachment throws

- **Severity:** P3
- **Status:** OPEN
- **Location:** `database/seeders/Concerns/CreatesDemoMedia.php:22`
- **Problem:** `makeDemoPng()` creates a file under `storage/app/demo-seed-temp`; `addMedia()` moves it only on success and there is no `finally` cleanup.
- **Scenario:** Image conversion/disk write throws during seed → the source PNG remains in the temp directory on every retry.
- **Test:** Force `addMedia` failure and assert the temp directory is empty.
- **Fix:** Guaranteed cleanup in `finally`, preserving the successful move behavior. Commit: N/A.
- **Evidence:** Successful seeder test passes; no failure cleanup test exists and the method contains no catch/finally.

### A08-CC-001 — Payment/order logic remains concentrated in oversized controllers and magic strings

- **Severity:** P2
- **Status:** OPEN
- **Location:** `app/Http/Controllers/Api/V1/OrderController.php` (917 lines), `Admin/OrderController.php` (502), `AddressController.php` (534), `Admin/ShippingController.php` (394), `app/Models/Order.php`
- **Problem:** The post-approval work added orchestration/business rules to already-large controllers and dozens of raw order/payment/refund status literals across controllers, services, command, observer, model and schema. Eight controllers also still call inline `$request->validate()`, contrary to the clean-code checklist.
- **Scenario:** Adding `authorized` missed the raw admin ship transitions (A08-PAY-001), demonstrating that duplicated string transition rules are already producing correctness drift.
- **Test:** Behavior-preserving full suite plus focused transition matrix tests; response bytes unchanged.
- **Fix:** Separate refactor commits: status enums/constants with identical DB strings; thin cancellation/payment/shipping orchestration services; FormRequests for the eight inline validators. Commit: N/A.
- **Evidence:**
  ```text
  Largest files: OrderController 917, AuthController 736, Admin/ProductController 618,
  AddressController 534, Admin/OrderController 502, Admin/ShippingController 394.
  Top status-literal files: Admin/OrderController 31, OrderPaymentService 18,
  StripeWebhookController 17, OrderController 13.
  Inline validation remains in 8 controllers.
  No env() calls were found outside config/.
  ```

### A08-ROUTE-001 — Current route list has eight entries outside the approved snapshot

- **Severity:** P2
- **Status:** NEEDS-DECISION
- **Location:** `docs/AI/results/routes-current.json`
- **Problem:** The current generated list has 184 routes versus the approved 176, with eight added infrastructure routes. No scoped post-approval route file explains them, so silently replacing the frozen snapshot would hide the delta.
- **Scenario:** Deployment exposes docs/storage/Telescope UI routes that reviewers believe are absent because the approved inventory is used as proof.
- **Test:** Regenerate in the same environment/config as the approved snapshot and assert the owner-approved set exactly.
- **Fix:** Approve each intentional route and update snapshot, or disable/exclude it; do not blanket-refresh. Commit: N/A.
- **Evidence:** Exact added/removed/changed list in A0.
- **Decision needed:** A) approve the seven normal app/framework routes but require Telescope UI absent when production disables it (recommended); B) require exact removal of all eight from this environment/snapshot.

## Verified OK

- Test MySQL at `127.0.0.1:3308` is reachable; the schema/seed proof used `storeapi_testing` settings from `phpunit.xml`.
- `migrate:fresh --seed` succeeds, including the editable post-approval migration and conversion-generating catalog seeder.
- Config, route and event caches build successfully and `optimize:clear` removes them.
- Manual Checkout sessions set both PaymentIntent capture method and signed session metadata to `manual`.
- Manual completion moves matching unpaid orders to `processing/authorized`, records the PaymentIntent and sends side effects once; replay is ignored.
- Automatic mode remains the legacy branch: absence of manual metadata moves the order to `processing/paid`; signed webhook tests cover amount/currency/session matching and replay.
- Unpaid direct cancel uses creation time; paid/authorized direct cancel uses authorization/payment time. This matches the newer approved frontend contract and resolves the prompt's known suspect as intentional.
- Customer ownership is enforced by the user's order relation; cross-customer show/cancel/request tests return 404.
- Customer cancel restores stock, coupon usage and quote; a held card is released; a captured payment is refunded while order status stays cancelled.
- Label purchase claims the order under a row lock, captures before EasyPost, blocks pending cancellation and duplicate purchase, and clears the claim on provider/capture failure.
- The five-minute capture schedule is registered with `withoutOverlapping`; its command skips fresh holds and pending cancellation requests until the documented five-day safety cutoff.
- Stripe capture/release/refund provider calls have stable operation keys; capture/release key behavior still needs direct assertions (A08-PAY-005).
- EasyPost real and fake implementations have matching interface signatures; signed webhook validation accepts good HMAC and rejects missing/bad HMAC.
- Address create/update verifies physical-field changes, returns normalized state/ZIP/street, and leaves an existing address unchanged on provider rejection.
- Product conversions are generated at the configured 120/420/1000/1600 square sizes; resources fall back to the original absolute media URL while conversion is unavailable.
- `SecureImageUploadTest` was among the 289 baseline passes and covers MIME/extension normalization and SVG/polyglot rejection; no upload-security regression was found.
- No `env()` call exists under `app/`, `routes/`, `database/`, or `bootstrap/`; all application reads are in config.
- No unrelated tracked file was modified during Stage A.

## PHPStan baseline categorization and effort

The file contains **205 ignore blocks suppressing 245 occurrences**. This is
separate from the current no-baseline total of 261 because six blocks are stale
and post-approval code added new failures.

| Identifier | Blocks | Suppressed occurrences |
|---|---:|---:|
| `property.notFound` | 166 | 188 |
| `method.notFound` | 17 | 23 |
| `argument.type` | 3 | 11 |
| `nullsafe.neverNull` | 8 | 8 |
| `argument.unresolvableType` | 3 | 3 |
| `method.nonObject` | 1 | 3 |
| `assign.propertyType` | 1 | 2 |
| `staticMethod.notFound` | 1 | 2 |
| `booleanAnd.leftAlwaysTrue` | 1 | 1 |
| `constant.notFound` | 1 | 1 |
| `instanceof.alwaysTrue` | 1 | 1 |
| `nullCoalesce.offset` | 1 | 1 |
| `return.type` | 1 | 1 |

Baseline file totals (all 42 files, suppressed occurrences):

```text
OrderResource 29; ProductDetailResource 25; OrderController 19; CouponResource 19;
ProductResource 18; ProductCardResource 14; TelescopeApiController 12;
OrderInventoryService 11; CategoryDetailResource 10; AdminOrderResource 6; Coupon 6;
AuditLogResource 5; StripeCheckoutService 5; AdminAnalyticsController 4;
CategoryCardResource 4; CategoryResource 4; Product 4; ShippingQuoteService 4;
AddressController 3; AuthController 3; AdminUserResource 3; CategoryBasicResource 3;
OrderItemResource 3; ReviewResource 3; CancellationRequestDecidedMail 3; Category 3;
OtpService 3; MigrateImagesToSpatie 2; CancellationRequestController 2;
CouponUsageResource 2; ProductGalleryImageResource 2; Admin/OrderController 1;
Admin/ShippingController 1; ProductController 1; base Controller 1;
TrackVisitorSession 1; AdminWishlistItemResource 1; ShippingRateResource 1;
EasyPostService 1; GoogleAuthService 1; ReviewService 1; WishlistAnalyticsService 1.
```

Estimated effort to reach level 5 with no baseline: **3–5 focused engineering
days**, split into (1) resource/model mixins and generated model properties,
(2) relation/query generics and controller return types, (3) genuine logic/type
errors exposed after annotations, and (4) full-suite/static regression proof.
This estimate excludes behavioral fixes above and must be separate refactor
commits.

## Decisions table: proposed updates

| Existing row | Relationship to new flow | Current actual behavior to confirm |
|---|---|---|
| `L-PAY-005` card-only | Unchanged | Checkout remains `payment_method_types=['card']`; manual capture changes timing, not method support. |
| `L-PAY-006` webhook limiter | Unchanged | Stripe/EasyPost signed webhooks remain public provider endpoints; no post-approval route middleware change. |
| `L-PAY-007` payment after cancellation | **Superseded** | A signed late payment is recorded as requiring settlement, automatically released/refunded after commit, and alerts admin once; it is no longer manual-refund-only. |
| `L-PAY-010` zero/minimum charge | Unchanged | Total 0 is paid without Stripe; `0 < total < minimum` is 422 `minimum_charge`. |
| `L-PAY-011` pending admin refund | Clarified/unchanged | Pending returns 202 and webhook finalizes. Cancelled-order retry also returns 202 when Stripe is pending. |
| `D4-OBS-001` abandoned checkout | Unchanged | 30-minute Checkout session and ten-minute expiry safety job remain; cancellation tries to expire an open session, with late signed payment automatically settled. |
| `D4-OBS-002` shipped/delivered refund stock | Unchanged | Refund after shipment/delivery does not restock. Cancelled pre-shipment orders do restock. |
| `D3-FS-01` free-shipping basis | Unchanged | Threshold remains after coupon discount. |
| `D7-OBS-001` paid cancellation coupon | **Superseded** | Every cancellation releases coupon usage and the shipping quote, paid/authorized or unpaid. A post-delivery refund (`status=refunded`) does not release them. |
| `BR-01` paid/shipped cancellation | **Superseded** | Within three hours after checkout and before fulfillment claim: direct cancel + automatic hold release/refund. After the window: admin request + automatic settlement on acceptance. Shipped/delivered/refunded orders cannot be cancelled. |

The P0 raw-status shipping bypass is a defect, not a proposed business behavior.

## NEEDS-DECISION

1. **A08-SHIP-001 carrier IDs:** A fail closed/readiness error (recommended); B intentionally query all carriers.
2. **A08-CON-002 Standard/Express:** A approve and freeze `method` + two-tier selection (recommended); B restore generic rate list.
3. **A08-CON-003 review errors:** A approve semantic 403/409/429 + codes (recommended); B restore universal 409.
4. **A08-SEC-001 dependency advisory:** A authorize minimal compatible security update (recommended); B accept and document risk.
5. **A08-ROUTE-001 route delta:** A approve normal infrastructure routes but require production Telescope UI absent (recommended); B remove all eight to match snapshot.

Manual capture itself, automatic settlement, and paid-cancellation coupon release
are not re-asked; the executor prompt already approves/supersedes those rules.

## Fix plan

Each item is a planned commit after both gates approve this report. Tests are
written failing first; frozen contract decisions are applied only after owner
selection.

1. `fix(payments): block uncaptured admin shipping transitions [A08-PAY-001]` — authorized single + bulk regression tests; `Admin/OrderController`, requests if needed.
2. `fix(payments): keep completed refunds terminal [A08-PAY-002]` — out-of-order refund webhook test; Stripe webhook controller.
3. `fix(payments): make failed-refund retries attempt-idempotent [A08-PAY-003]` — provider-key attempt test; payment/Stripe service and minimal schema only if required.
4. `fix(payments): record signed authorization event time [A08-PAY-004]` — delayed-event deadline test; Stripe webhook controller.
5. `test(payments): prove cents keys and real races [A08-PAY-005]` — exact cents, capture/release keys, capture/cancel and label/cancel concurrency tests; fix only exposed defects in separate commits.
6. `fix(shipping): enforce approved carrier account policy [A08-SHIP-001]` — missing-config test; service/config/readiness files, per decision.
7. `fix(shipping): keep tracking state and alerts monotonic [A08-SHIP-002/A08-SHIP-003]` — equal/no-event and double-poll tests; tracking service/command.
8. `fix(orders): serialize cancellation request decisions [A08-CAN-001]` — concurrent accept/email test; cancellation controller.
9. `fix(media): rollback partial gallery replacement [A08-MED-002]` — injected failure test; product controller/service extraction if approved.
10. `fix(media): clean failed demo temp files [A08-MED-003]` — failure cleanup test; seeder concern.
11. `chore(media): remove generated repository artifacts [A08-MED-001]` — remove 25 tracked files and add precise ignore rule.
12. `fix(tests): restore deterministic full-suite gates [A08-QA-001]` — isolate leaked records; exact HTML/fallback Telegram tests.
13. `style(api): apply Pint formatting [A08-QA-002]` — formatter-only.
14. Decision commits for `A08-CON-001/002/003`, `A08-SEC-001`, and `A08-ROUTE-001` — docs/OpenAPI or minimal code/dependency change exactly as approved.
15. Separate `refactor(...)` commits for A08-CC-001 and A08-STA-001: resources/models, query generics, payment/order status constants/enums, thin controller extraction, FormRequests, residual PHPStan; behavior/response byte-identical and full suite green before every commit.
16. Final proof: full suite 3×, Pint, PHPStan level 5 with baseline include removed, composer audit, caches, routes, fresh seed, Newman if available, removed-assertion audit, and DoD links.

## Frontend impact

- Manual checkout now yields `payment_status=authorized`, `authorized_at`,
  `cancellation`, and `refund`; `paid_at` is set only on capture. This is in
  `FRONTEND_ORDER_CANCELLATION.md` but missing from the frozen payment contract.
- Direct paid cancellation is now available for three hours after authorization
  until fulfillment starts; success returns `data.order` instead of null and
  may show refund `pending/released/succeeded/failed`.
- Accepted cancellations automatically return money and cancelled orders remain
  `status=cancelled`; coupons are released on any cancellation.
- Saved address create/update now requires two-letter country codes, may call
  EasyPost, returns normalized fields, and exposes `state`. This is documented in
  `FRONTEND_ADDRESS_EASYPOST.md`.
- Shipping item-rate responses now add `method=standard|express` and return at
  most two selected rates; approval/documentation is pending A08-CON-002.
- Review submission now returns 403 purchase-required, 409 duplicate, or 429
  daily limit with `errors.code`; approval/documentation is pending A08-CON-003.
- Product/wishlist/admin image URLs now use Spatie gallery URLs and fall back to
  the original absolute URL until conversions exist.
- No route under `/api/v1` was added, removed, or changed by the route snapshot
  comparison; eight non-API infrastructure routes require A08-ROUTE-001 approval.
