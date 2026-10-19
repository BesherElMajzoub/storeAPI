# Frontend Integration: Failed Card Refunds, Manual Refunds and the Refund Record

Backend release for "Failed Card Refund Process" (proposal v2, 15 Oct 2026). Written for the frontend developer. Nothing here is live until the API is deployed and `php artisan migrate` has run (section 10).

## 1. What changed, in one minute

| Before | Now |
| --- | --- |
| Retry was offered for every failed refund. For a closed card Stripe always refuses it. | `refund.retryable` and `refund.next_action` say what the admin may do. **Show Retry only when `retryable` is true.** The server also refuses it (`409 refund_not_retryable`). |
| A card the bank refused had no way out. | The owner refunds by **Zelle or PayPal** and records it: `POST /admin/orders/{id}/refund/manual`. |
| The order held one refund status and a counter. | Every attempt and every step is stored (`refund.attempts`, with `events`). Nothing is edited or deleted; a wrong manual entry is **voided**. |
| `charge.refunded` marked the order refunded as soon as the refund was created. | An order reads refunded only when the refund **succeeded**. A pending refund keeps `payment_status: "paid"` and `refund.status: "pending"` until Stripe confirms. |
| Failed-refund alerts went to Telegram only. | They also email `ADMIN_ALERT_EMAIL` with the next step. |

Existing fields are unchanged (`refund.status`, `refund.amount`, `refund.currency`, `refund.failure_code`, `refund.failure_message`, `refund.failed_at`, `refunded_amount`, `refund_status` values). Everything below is additive, except the first row's rule about when to show Retry.

## 2. Types

```ts
type NextAction =
  | 'none' | 'wait' | 'auto_retry' | 'retry' | 'top_up_then_retry'
  | 'wait_for_dispute' | 'refund_manually' | 'investigate';

type RefundVia = 'stripe' | 'manual' | 'mixed' | null;

// Customer order (GET /orders, /orders/{id}): one new field.
interface CustomerRefund {
  status: 'none' | 'pending' | 'released' | 'succeeded' | 'failed';
  amount: number;
  currency: string;
  refunded_via: RefundVia;          // NEW
}

// Admin order (GET /admin/orders, /admin/orders/{id}).
interface AdminRefund extends CustomerRefund {
  retryable: boolean;               // NEW, on every admin order
  next_action: NextAction;          // NEW, on every admin order
  requires_reason: boolean;         // NEW: Retry needs a `reason` (see 4.1)
  next_retry_at: string | null;     // NEW: ISO time, only with next_action = auto_retry
  amount_paid: string;              // NEW: "100.00" (strings, two decimals)
  amount_refunded: string;          // NEW: succeeded + manual
  amount_owed: string;              // NEW: what is still to be returned; prefill the manual form with it
  attempts: RefundAttempt[];        // NEW: oldest first
  failure_code: string | null;      // existing
  failure_message: string | null;   // existing
  failed_at: string | null;         // existing
}

interface RefundAttempt {
  id: number;
  attempt_no: number;               // 1, 2, 3 ... per order
  trigger: 'customer_cancel' | 'request_accepted' | 'admin_cancel' | 'admin_refund'
         | 'admin_retry' | 'manual' | 'reconciliation' | 'system';
  channel: 'stripe' | 'manual' | 'reconciliation';  // reconciliation = made in the Stripe Dashboard
  amount: string;
  currency: string;
  status: 'requested' | 'pending' | 'succeeded' | 'failed' | 'recorded' | 'voided';
  stripe_refund_id: string | null;
  failure_code: string | null;
  method: 'zelle' | 'paypal' | null;       // manual only
  external_reference: string | null;       // manual only: the transaction number
  sent_at: string | null;                  // manual only
  note: string | null;
  created_by: { id: number; name?: string } | null;  // null for automatic steps
  created_at: string;
  events?: { event: string; actor: string; details: Record<string, unknown> | null; at: string }[];
}
```

- `retryable`, `next_action`, `requires_reason` are on every admin order. The amounts, `attempts` and `next_retry_at` come with the admin order list and detail.
- `attempts[].events` come **only** with `GET /admin/orders/{id}` and the manual/void responses, not in the list.
- The Stripe idempotency key never leaves the server. Customers never get failure codes, notes, references or attempts.
- Dates are ISO 8601 UTC. Amounts of the new fields are strings; the old `refund.amount` stays a number.

## 3. What to show for each `next_action`

| `next_action` | Meaning | Admin message (suggested) | Admin action | Customer sees (suggested) |
| --- | --- | --- | --- | --- |
| `none` | Nothing to do (refunded, released or no refund) | Refunded $41.30 · Zelle · ref 8F3K… · 16 Oct | Void (owner, manual entries only) | "Your refund of $41.30 was sent by Zelle on 16 October." / by card text |
| `wait` | Stripe is processing | Stripe is processing the refund. This normally takes a few minutes. | none | Refund in progress |
| `auto_retry` | Stripe did not answer; the shop retries by itself | Stripe did not answer. The shop will try again at {next_retry_at}. | none | Refund in progress |
| `retry` | Safe to try again | The refund did not go through. You can try again. | **Retry refund** | Refund in progress |
| `top_up_then_retry` | Stripe balance too low | Your Stripe balance was too low for this refund. Add funds in Stripe, then retry. | Open Stripe · **Retry refund** | Refund in progress |
| `wait_for_dispute` | The customer opened a dispute | The customer opened a dispute. Stripe will decide where the money goes; do not refund separately. | Open the dispute in Stripe | Refund in progress |
| `refund_manually` | The bank refused the card (closed, expired, lost, declined) | The customer's bank refused the refund to their card, and the money is back in your Stripe balance. Refund the customer by Zelle or PayPal, then record it here. | **Record a manual refund** | We are processing your refund manually and will contact you. |
| `investigate` | The record disagrees with Stripe | The refund record does not match Stripe. Check it in Stripe before refunding anything. | none | Refund in progress |

Customer texts (Arabic, suggested): in progress "جارٍ معالجة استرداد مبلغك" · manual "نعالج استرداد مبلغك يدويًا وسنتواصل معك" · refunded by card "تم إرجاع مبلغ ‎{amount}‎ إلى بطاقتك، وقد يستغرق ظهوره من 5 إلى 10 أيام عمل" · refunded manually "تم إرسال مبلغ ‎{amount}‎ عبر ‎{Zelle/PayPal}‎ بتاريخ ‎{date}‎". Use `refund.refunded_via` to pick between the two refunded texts. Customer pages read `refund.status`, never `next_action`.

Orders list: filter **`GET /admin/orders?refund_action=needed`** returns the orders that wait for a person (a failed refund, except one waiting for a dispute). The dashboard has the same count: `alerts.refunds_needing_action` and `filters.refunds_needing_action = "refund_action=needed"`.

## 4. Endpoints

All admin endpoints need the admin token and the usual envelope `{ success, message, data, errors }`.

### 4.1 Retry through Stripe: `POST /admin/orders/{id}/refund` (existing)

Creates a **new attempt**. Optional header `Idempotency-Key` (see 4.4) and body `{ "reason": "..." }`.

| Status | When | `errors` |
| --- | --- | --- |
| 200 | Refunded (`refund_status` succeeded or card hold released) | |
| 202 | Accepted, Stripe confirms later (`data.refund_status: "pending"`) | |
| 409 | `refund_in_progress`: a refund is pending at Stripe, or another request is running | `{ code }` |
| 409 | `refund_not_retryable`: the card route is closed, a dispute, or a data error | `{ code, next_action }` |
| 409 | `nothing_owed`, or the order is not paid / already refunded | `{ code }` or none |
| 422 | `reason` missing while `refund.requires_reason` is true (a refund cancelled in the Stripe Dashboard) | `{ reason: [...] }` |
| 502 | Stripe refused it or could not be reached | `{ stripe_code, stripe_message, next_action }` |

Do not rely on the 409/502 alone: render the button from `retryable`, and use `next_action` from the error body to switch the panel when the page was stale.

### 4.2 Record a manual refund: `POST /admin/orders/{id}/refund/manual` (new)

Records money **already sent** by the owner. It moves no money, so the page needs a confirmation step that says so.

```jsonc
// request
{ "amount": 41.30, "method": "zelle", "external_reference": "8F3K29QX",
  "sent_at": "2026-10-16T14:20:00Z", "note": "Customer asked for Zelle",
  "override_reason": null }
```

- `amount` > 0, at most 2 decimals, at most `refund.amount_owed` (prefill it). `method`: `zelle` | `paypal`. `external_reference` is required. `sent_at` must not be in the future.
- `override_reason` is needed **only** while Retry is still offered, or the refund waits for a dispute or a check. Show the field when `next_action` is not `refund_manually`; otherwise omit it.
- `201`: `data = { attempt: RefundAttempt, order: AdminOrder }`. Replace the order in state with `data.order`.

| Status | `errors.code` | Meaning |
| --- | --- | --- |
| 409 | `refund_in_progress` | A Stripe refund is pending; wait for it |
| 409 | `retry_available` | Retry through Stripe is still possible (also `errors.next_action`); send `override_reason` to record anyway |
| 409 | `refund_blocked` | Waiting for a dispute or a check; `override_reason` required |
| 409 | `nothing_owed` | Already refunded, or no payment was taken |
| 422 | `amount_exceeds_owed` | The amount is above what is owed (`errors.amount[0]` has the number) |
| 422 | field names | Validation (`method`, `external_reference`, `sent_at`, `amount`) |

### 4.3 Void a manual refund: `POST /admin/orders/{id}/refund/attempts/{attemptId}/void` (new)

Body `{ "reason": "..." }` (required). **Owner account only** (`403` for other staff: hide the button unless the user has the `Owner` role). Only an entry with `channel: "manual"` and `status: "recorded"` can be voided (`409 not_voidable`; Stripe attempts never can). Response `200` has the same `{ attempt, order }` shape. Show the voided entry crossed out with its reason (from `events`, event `voided`); the amount owed goes back up and the order is `failed / refund_manually` again.

### 4.4 Double clicks

Send an `Idempotency-Key: <uuid>` header with each click on Retry and on Record. A repeat of the same key (same user, same order) within 10 minutes gets the first answer back with the header `Idempotent-Replay: true`. Generate one key per click, disable the button while the request runs, and do not reuse a key for a different action. The key is only a double-click guard: Stripe's own key is created and kept by the backend.

## 5. States the page can be in

| `refund.status` | `next_action` | Typical cause |
| --- | --- | --- |
| `pending` | `wait` | Stripe accepted the refund |
| `pending` | `auto_retry` | Timeout or Stripe outage: retried at 1, 5, 15, 30, 60 min with the same request |
| `failed` | `retry` | Retries ran out, or the failure is not in the known list |
| `failed` | `top_up_then_retry` | `insufficient_funds` |
| `failed` | `refund_manually` | `expired_or_canceled_card`, `lost_or_stolen_card`, `declined`, or Stripe refusing a second refund |
| `failed` | `wait_for_dispute` | `charge_for_pending_refund_disputed`, `charge_disputed` |
| `failed` | `investigate` | `charge_already_refunded`, `amount_too_large` |
| `succeeded` | `none` | Money returned (`refunded_via` says how) |

A refund can still fail **after** it read `succeeded` (the bank refuses it days later). The order then returns to `payment_status: "paid"`, `refund.status: "failed"` and the table above applies: expect the page to change after a refresh or a poll.

The order itself keeps its own `status` (a cancelled order stays `cancelled`). `payment_status` is `refunded` only when the money is fully back.

## 6. Admin order page

- Header: a red badge when `next_action` is `retry`, `top_up_then_retry`, `refund_manually` or `investigate`; grey for `wait`, `auto_retry`, `wait_for_dispute`.
- Panel text and the single allowed button from section 3. At most one primary action.
- History: render `attempts` as a timeline, newest last. Per attempt: number, trigger label (customer cancelled / request accepted / admin cancelled / admin retry / manual / found in Stripe), channel, amount, status, who (`created_by`) and when, and `events` underneath. Voided entries crossed out.
- Orders list: `refund_action=needed` filter chip, and the dashboard alert from `alerts.refunds_needing_action`.
- The existing interim guard (hiding Retry for the three card reasons) can go: the server now decides.

## 7. Messages the backend sends

| Who | When | What |
| --- | --- | --- |
| Owner (`ADMIN_ALERT_EMAIL` + Telegram) | A refund fails, with the next step in the text | "URGENT: Stripe refund re_… failed for order … (expired_or_canceled_card). The customer's bank refused … refund by Zelle or PayPal." |
| Owner | A refund is pending at Stripe for more than 5 business days | Check it in Stripe |
| Owner | The nightly check finds a refund made outside the shop, or a status the webhooks missed | Listed in one email |
| Customer | The money is fully back | Email naming the method: card, Zelle or PayPal (once per order) |

## 8. What to test (Stripe test mode)

| ID | Do | Expect |
| --- | --- | --- |
| RF-00 | Pay with `4000 0000 0000 5126`, admin cancels | "Refund failed" badge; **no Retry button**; the manual guidance; `next_action = refund_manually` |
| RF-01 | Cancel an order paid with `4242 4242 4242 4242` | Refunded to the card; one attempt in the history; customer email |
| RF-02/03/04 | Card 5126; cancel by admin / by the customer within the window / by accepting a request | Same failure; `trigger` is `admin_cancel` / `customer_cancel` / `request_accepted` |
| RF-05 | Record a Zelle refund | `201`; `refund.status` succeeded, `refunded_via: "manual"`, owed `0.00`; customer email; Retry and a second record give `409` |
| RF-06 | Record more than is owed | `422 amount_exceeds_owed` |
| RF-07 | Void the entry as the owner | Crossed out with the reason; owed back to the full amount; both entries stay |
| RF-08 | Double-click Retry, or retry from two tabs | One Stripe refund; the second gets `refund_in_progress` or the replayed answer |
| RF-10 | Re-send a `refund.failed` event from the Stripe Dashboard | No second entry in the history |
| RF-11 | Refund an order in the Stripe Dashboard, run `php artisan refunds:reconcile` | A `reconciliation` entry and an owner email |
| RF-13 | Customer order page for RF-02 then RF-05 | Calm "processing manually" text, then "refunded by Zelle"; no codes or notes |

The backend suite covers these cases (`tests/Feature/RefundRecordTest.php`, `RefundNextActionTest.php`).

## 9. Backend notes the frontend should know

- **Still to confirm in Stripe test mode:** whether Stripe accepts a new refund after `insufficient_funds`, `merchant_request` and `unknown`. If it refuses one, that reason moves to `refund_manually`. The whole table is one class (`App\Support\RefundAdvice`), so the page does not change, only `next_action` does.
- Automatic retries use the existing queue schedule (1, 5, 15, 30 and 60 minutes), a little denser than the 1 min / 10 min / 1 h in the proposal.
- A manual refund may be smaller than the amount owed; the rest stays owed and `refund.status` stays `failed`.
- Orders refunded or failed before this release get one backfilled attempt (`events: backfilled`), so older orders show a history too.
- The refund log kept before launch is imported with `php artisan refunds:import-log <file> --dry-run` (run it without `--dry-run` once it looks right). It accepts the spreadsheet pasted as tab-separated text or a CSV with the agreed columns, skips lines it already imported, voids the line a correcting line names, and does not email the customers again.
- Test orders 68–70 hold test data; the new tables are removed with their order when the test orders are deleted at the production switch.

## 10. Deploy steps for this release

1. Pull `main` on the API server.
2. `php artisan migrate` (creates `refund_attempts`, `refund_events`, adds `orders.refunded_via`, backfills old orders; the second migration adds the append-only triggers when the database user is allowed to).
3. `php artisan queue:restart`.
4. Stripe webhook endpoint: also subscribe to `refund.created` and `refund.updated` (it already has `charge.refunded` and `refund.failed`). Without `refund.updated`, a refund that Stripe reports as pending stays pending until the nightly check settles it.
5. Set `ADMIN_ALERT_EMAIL` to the inbox the owner reads every business day, and correct the mail sender (`conact-us@` → `contact-us@`) in the server `.env`.
