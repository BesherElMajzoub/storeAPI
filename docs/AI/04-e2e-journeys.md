# Phase 04 — End-to-End User Journeys

**Output:** `results/04-e2e-journeys.md`
**Mode:** write journey tests; bugs found → fix with the phase 02 method.

Unit and feature tests check pieces. Journey tests check that a real user can
go from start to finish through the HTTP API only, with each step using the
output of the previous step — exactly like the frontend does.

## How to write them

- Location: `tests/Feature/Journeys/<Name>JourneyTest.php`, one class per journey.
- Only HTTP calls (`$this->postJson(...)`, etc.) after the initial seed. No
  direct model manipulation in the middle of a journey, except to simulate
  external systems (webhooks) — and those go through the real webhook route
  with a properly signed payload.
- Use the fakes that exist (`FakeEasyPostService`, Stripe mocks, `Mail::fake`,
  `Queue::fake` or sync queue, `Http::fake` for Geoapify/Google/Telegram).
- After every step assert: status code, response shape (contract fields),
  and the DB state that matters (order status, stock, coupon usage, payment).
- At the end assert the **invariants**: stock = initial − sold + restored,
  coupon usage count correct, exactly N mails sent, audit log entries present.

## Journeys (all required)

| ID | Journey |
|---|---|
| J01 | Guest browses: categories → category products → filters/sort/pagination → product detail → reviews. Hidden/inactive products never appear. |
| J02 | Register → verify (OTP if required) → login → me → logout → old token rejected. |
| J03 | Password reset: request → token from mail → reset → login with new password; old password fails. |
| J04 | **Happy checkout:** login → add address (autocomplete/details) → shipping rates → create order with rate → Stripe session → signed `checkout.session.completed` webhook → order paid → stock decremented → paid mail → order visible in "my orders". |
| J05 | Checkout with coupon + free shipping threshold: totals exactly as documented in D3; coupon usage recorded once. |
| J06 | Payment fails / session expires: order ends in the right status, stock and coupon usage restored. |
| J07 | Duplicate & out-of-order webhooks during J04: no double effects, no status regression. |
| J08 | Two customers race for the last unit: exactly one succeeds, the other gets the documented error, stock = 0 not negative. |
| J09 | Shipping: admin marks shipped / label → EasyPost tracking webhook → status updates → shipped mail → public tracking page shows it (with the right second factor only). |
| J10 | Cancellation: customer requests cancel → admin approves → stock + coupon restored, refund state per rule, mail sent. Second variant: admin rejects. |
| J11 | Admin catalog: login as admin → create category → create product + variants + images → generate SKU → publish → appears publicly → adjust stock → CSV import → audit log has every action. |
| J12 | Admin orders: list/filter → bulk status update with one invalid transition → documented partial-failure result. |
| J13 | Wishlist + review: add/remove wishlist → buy product (J04) → leave review → rating average updated → admin moderates. |
| J14 | Authorization sweep: user B tries every user-A resource (orders, addresses, reviews, wishlist, cancellation) by id → 403/404, never data. Normal user hits every admin route → 403. |
| J15 | Contact form + inspired lead: submit → admin sees it → status update; throttling kicks in. |

## Outside the test suite (smoke against a running app)

1. Run the app locally (`php artisan serve` + queue worker) against a fresh
   seeded DB.
2. Run `postman_collection.json` with Newman
   (`npx newman run postman_collection.json -e <env>`). Record pass/fail per
   request. Fix the collection if it's outdated (it's documentation the
   frontend uses) — log what changed.
3. Paste the Newman summary in the result file.

## Result file structure

```markdown
# 04 E2E journeys — results
## Journey table (ID — test file::method — PASS/FAIL — findings)
## Findings
## Newman smoke run (summary output)
## Full test suite output
```
