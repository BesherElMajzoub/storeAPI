# 02 D6 Shipping - results

Status: IN-PROGRESS (B2).

## Entry points

- `ShippingQuoteService`, `Api/V1/AddressController` (rate quotes, address verify)
- `Api/V1/EasyPostWebhookController::handle` (EasyPost `tracker.updated` webhook)
- `Console/Commands/TrackShipments` (`shipping:track` scheduled poll)
- `ShipmentTrackingService::sync` (shared by both of the above)
- `Admin/ShippingController` (label purchase)
- `PublicOrderTrackingController` (customer tracking lookup)

## Findings

### D6-F1 — P1: a late/duplicate EasyPost status update can resurrect a cancelled or refunded order

- **Severity:** P1
- **Status:** FIXED
- **Location:** `app/Services/ShipmentTrackingService.php:48`, `app/Http/Controllers/Api/V1/EasyPostWebhookController.php:74-84`, `app/Console/Commands/TrackShipments.php:63`
- **Problem:** `ShipmentTrackingService::sync()` set `$order->status = 'delivered'` (or `'shipped'`) whenever the carrier status matched, with no check on the order's *current* status. The webhook looks the order up by `tracking_number` alone, with no status filter, so a webhook event that arrives after the order was already cancelled/refunded (return processed, chargeback, manual admin cancel) flips it back to `delivered`. Because the old code also decided whether to send the admin alert by comparing the *tracker's* status to `'delivered'` rather than whether the order's status actually changed, a second `delivered` webhook for an order already marked delivered re-sent the "🎉 DELIVERED" alert every time.
- **Scenario:** Order is refunded/cancelled after a return. EasyPost later redelivers a `tracker.updated` event with status `delivered` (carrier retried the webhook, or the tracker object still updates after the label existed). The webhook handler finds the order by `tracking_number`, calls `sync()`, and the order flips from `cancelled`/`refunded` back to `delivered` — masking the refund from admin dashboards and reports.
- **Test:** `tests/Feature/EasyPostShippingTest.php::test_easypost_webhook_does_not_resurrect_a_cancelled_order`, `::test_easypost_webhook_does_not_realert_an_already_delivered_order` — both fail on pre-fix code (first: order flips to `delivered`; second passed already but is kept as a regression guard for the dedup logic).
- **Fix:** `sync()` only transitions to `delivered` when the order is currently `pending`/`processing`/`shipped`, and only transitions to `shipped` when currently `pending`/`processing` — a `cancelled`/`refunded`/already-`delivered` order is left untouched. The webhook controller and `TrackShipments` command now capture the order's status *before* calling `sync()` and only fire the admin alert when the *synced* order's status actually changed to `delivered`/`shipped`, not merely when the tracker payload says so. Commit: (this batch).
- **Evidence:**
  ```
  # pre-fix (git stash of the three files), targeted test:
  FAILED  Tests\Feature\EasyPostShippingTest > easypost webhook does not resurrect a cancelled order
  Failed asserting that a row in the table [orders] matches the attributes {"id": 1,"status": "cancelled"}.
  Found similar results: [{"id": 1,"status": "delivered"}].
  Tests: 1 failed (2 assertions)

  # post-fix, full file:
  PASS  Tests\Feature\EasyPostShippingTest
  ✓ customer can verify address
  ✓ customer can get shipping rates
  ✓ admin can purchase label
  ✓ easypost webhook updates order
  ✓ easypost webhook does not resurrect a cancelled order
  ✓ easypost webhook does not realert an already delivered order
  ✓ easypost webhook fails closed when secret is missing
  ✓ customer can retrieve tracking
  Tests: 8 passed (32 assertions)
  ```

## Tests added/strengthened

- `tests/Feature/EasyPostShippingTest.php::test_easypost_webhook_does_not_resurrect_a_cancelled_order` (new)
- `tests/Feature/EasyPostShippingTest.php::test_easypost_webhook_does_not_realert_an_already_delivered_order` (new)

## Frontend impact

None — this only prevents an incorrect status regression; no route, field, or response shape changed.
