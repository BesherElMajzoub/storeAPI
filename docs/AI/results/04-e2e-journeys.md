# 04 E2E journeys - results

Status: COMPLETE FOR B4. J04 and J14 remain the deep journeys; J01-J03, J05-J07, J09-J13 and J15 are now covered by `RemainingJourneysTest`.

## Journey evidence

| IDs | Test | Result |
|---|---|---|
| J01-J03 | `tests/Feature/Journeys/RemainingJourneysTest.php` (`j01`-`j03`) | PASS |
| J04 | `HappyCheckoutJourneyTest::test_customer_can_go_from_login_to_a_paid_order` | PASS |
| J05-J07 | `RemainingJourneysTest` (`j05`-`j07`) | PASS |
| J08 | `ConcurrentInventoryTest::test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation` | PASS |
| J09-J13 | `RemainingJourneysTest` (`j09`-`j13`) | PASS |
| J14 | `AuthorizationSweepJourneyTest::test_user_b_cannot_touch_user_as_review_or_cancellation_request` | PASS |
| J15 | `RemainingJourneysTest::j15_public_contact_and_lead_forms_are_http_journeys` | PASS |

Focused journey output:

```
Tests:    12 passed (20 assertions)
```

J04 and J08 continue to exercise the provider-mocked checkout and real process-level inventory race respectively. J14 includes the previously fixed review authorization finding (`84c2a3e`).

## Newman evidence

The refreshed B4 collection is `postman_b4_collection.json`; it uses only local HTTP calls and environment variables for the seeded admin credentials. Command:

```
npx newman run postman_b4_collection.json --reporters cli --disable-unicode \
  --env-var base_url=http://127.0.0.1:8000 \
  --env-var admin_email=admin@demo.test --env-var admin_password=Demo1234!
```

Output:

```
requests: 11, failed: 0
test-scripts: 11, failed: 0
assertions: 11, failed: 0
```

The collection covers health, guest catalogue, customer registration/me/logout, admin login/categories, unauthenticated-admin 401, invalid coupon 422 and contact submission.
