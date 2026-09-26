# 04 E2E journeys - results

Status: READY-FOR-REVIEW (final phase).

## Journey evidence

The previously shallow `RemainingJourneysTest` checks were replaced with HTTP
chains. Each chain uses the API for every step after fixture setup and asserts
the resulting state or response contract.

| ID | Test | Result |
|---|---|---|
| J01 | `test_j01_guest_browses_category_filters_detail_and_reviews_without_hidden_products` | PASS |
| J02 | `test_j02_registration_otp_endpoint_login_me_logout_and_old_token_rejection` | PASS |
| J03 | `test_j03_password_reset_uses_the_mail_token_and_invalidates_old_password` | PASS |
| J04 | `HappyCheckoutJourneyTest::test_customer_can_go_from_login_to_a_paid_order` | PASS |
| J05 | `test_j05_coupon_checkout_applies_free_shipping_and_records_exact_totals` | PASS |
| J06 | `test_j06_expired_payment_webhook_cancels_order_and_restores_stock_and_coupon` | PASS |
| J07 | `test_j07_valid_duplicate_and_out_of_order_webhooks_are_idempotent` | PASS |
| J08 | `ConcurrentInventoryTest::test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation` | PASS |
| J09 | `test_j09_admin_shipping_webhook_and_public_tracking_chain` | PASS |
| J10 | `test_j10_customer_cancel_request_admin_accepts_and_rejects_with_invariants` | PASS |
| J11 | `test_j11_admin_catalog_authoring_publish_stock_import_and_audit_chain` | PASS |
| J12 | `test_j12_admin_order_filter_and_bulk_update_reports_invalid_transition_without_partial_write` | PASS |
| J13 | `test_j13_wishlist_purchase_review_moderation_and_rating_chain` | PASS |
| J14 | `AuthorizationSweepJourneyTest::test_user_b_cannot_touch_user_as_review_or_cancellation_request` | PASS |
| J15 | `test_j15_contact_and_lead_are_visible_to_admin_status_updated_and_throttled` | PASS |

Focused output for the rewritten class:

```
Tests:    12 passed (136 assertions)
```

External providers remain mocked in the journey tests (Stripe, EasyPost,
mail, queue, and geocoding); no real provider call is made.

## Newman evidence

Newman was run against the repository's actual `postman_collection.json` (39
requests). The collection was corrected to use seeded demo credentials,
capture generated category/product IDs, follow the API response envelopes,
keep dependent cleanup requests at the end, include product dimensions, and
accept the documented validation/rate-limit outcomes.

Command:

```
npx newman run postman_collection.json --env-var base_url=http://127.0.0.1:8000 \
  --reporters cli --disable-unicode
```

Output:

```
requests: 39, failed: 0
test-scripts: 39, failed: 0
prerequest-scripts: 42, failed: 0
assertions: 66, failed: 0
```

The obsolete `postman_b4_collection.json` throwaway collection was removed.
