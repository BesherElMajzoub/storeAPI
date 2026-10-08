# Frontend Integration: Order Cancellation, Refunds and Payment Holds (ORD-06)

**Audience:** Storefront and admin-panel frontend developers  
**API prefix:** `/api/v1`  
**Authentication:** `auth:sanctum` (Bearer token or SPA cookie)  
**Response envelope (unchanged):** `{ "success": bool, "message": string, "data": any, "errors": object | null }`

---

## 1. Summary

The backend is now the single source of truth for whether an order can be
cancelled, and it returns the customer's money automatically.

| Before | Now |
|---|---|
| Frontend computed the 3-hour window (`DIRECT_CANCEL_WINDOW_MS`). | Backend returns `cancellation.mode` / `reason` / `direct_until` on every order. |
| Only **unpaid** orders could be cancelled directly. | **Paid** orders can also be cancelled directly within 3 hours of checkout, until the shipping label is bought. |
| An approved cancellation did not refund the customer. | Every cancellation of a paid order returns the money automatically. |
| The card was charged at checkout. | The card is **held** at checkout and charged 3 hours later (or when the label is bought). Cancelling within the window releases the hold; the customer is never charged. |
| A refunded cancelled order changed to `status: "refunded"`. | A cancelled order **stays `cancelled`**. Money state lives in the new `refund` object. |

The rest of this document describes exactly what to change.

---

## 2. Action checklist

### Storefront
- [ ] Delete `DIRECT_CANCEL_WINDOW_MS` and any local 3-hour calculation in
      `src/infrastructure/api/mappers/order.mapper.ts`.
- [ ] Map the new order fields: `cancellation`, `refund`, `authorized_at`, and
      the new `payment_status` value `authorized` (section 3).
- [ ] Render the cancel UI from `cancellation.mode` (section 4).
- [ ] Show a countdown from `cancellation.direct_until`, and re-fetch the order
      when it reaches zero.
- [ ] Handle the new cancel responses: `200` returns the order, `400` returns a
      reason, `409` means a payment is being confirmed (section 5.1).
- [ ] Show refund messages from `refund.status` (section 6).

### Admin panel
- [ ] Show `refund.status` on cancelled orders and highlight `failed`.
- [ ] Add a "Retry refund" action that calls `POST /admin/orders/{id}/refund`.
- [ ] Handle the new `409` responses when buying a label or accepting a
      cancellation request (section 7).

---

## 3. Order model changes

These fields are present on every order returned by:

- `GET /orders`
- `GET /orders/{id}`
- `POST /orders/{id}/cancel`
- `GET /admin/orders`
- `GET /admin/orders/{id}`
- `POST /admin/orders/bulk-status`

```ts
type OrderStatus =
  | 'pending_payment' | 'processing' | 'shipped'
  | 'delivered' | 'cancelled' | 'refunded'
  | 'pending'; // legacy, rare

type PaymentStatus =
  | 'unpaid'
  | 'authorized' // NEW: card held, not charged yet
  | 'paid'
  | 'failed'     // payment failed, or cancelled before payment / hold released
  | 'refunded';

type CancellationMode = 'direct' | 'request' | 'none';

type CancellationReason =
  | 'within_window'            // mode: direct
  | 'window_expired'           // mode: request
  | 'fulfillment_in_progress'  // mode: request (label being bought right now)
  | 'label_purchased'          // mode: request
  | 'not_directly_cancellable' // mode: request (other cases, e.g. old orders)
  | 'request_pending'          // mode: none (a request is already waiting)
  | 'shipped' | 'delivered' | 'cancelled' | 'refunded'; // mode: none

interface CancellationRequest {
  id: number;
  status: 'pending' | 'accepted' | 'rejected';
  reason: string;
  admin_note: string | null;
  created_at: string;       // ISO 8601
  decided_at: string | null;
}

interface OrderCancellation {
  mode: CancellationMode;
  reason: CancellationReason;
  direct_until: string | null;               // ISO 8601, set only when mode === 'direct'
  pending_request: CancellationRequest | null;
}

type RefundStatus = 'none' | 'pending' | 'released' | 'succeeded' | 'failed';

interface OrderRefund {
  status: RefundStatus;
  amount: number;   // amount returned or being returned (0 when status is 'none')
  currency: string; // e.g. 'usd'
}

interface Order {
  // ...existing fields...
  status: OrderStatus;
  payment_status: PaymentStatus;
  cancellation: OrderCancellation; // NEW
  refund: OrderRefund;             // NEW
  authorized_at: string | null;    // NEW: when checkout completed
  paid_at: string | null;          // now set when the money is captured, not at checkout
  cancelled_at: string | null;
  refunded_at: string | null;
  refunded_amount: number;         // existing; prefer refund.amount for display
  cancellation_request?: CancellationRequest | null; // existing, latest request of any status
}
```

### Example: a paid order inside the window

```json
{
  "id": 42,
  "order_number": "ORD-8F3K2L9QXA",
  "status": "processing",
  "payment_status": "authorized",
  "total": 100.0,
  "authorized_at": "2026-10-05T12:00:00+00:00",
  "paid_at": null,
  "cancellation": {
    "mode": "direct",
    "reason": "within_window",
    "direct_until": "2026-10-05T15:00:00+00:00",
    "pending_request": null
  },
  "refund": { "status": "none", "amount": 0, "currency": "usd" }
}
```

### Example: the same order after the customer cancelled

```json
{
  "id": 42,
  "status": "cancelled",
  "payment_status": "failed",
  "cancelled_at": "2026-10-05T12:40:00+00:00",
  "cancellation": { "mode": "none", "reason": "cancelled", "direct_until": null, "pending_request": null },
  "refund": { "status": "released", "amount": 100.0, "currency": "usd" }
}
```

---

## 4. Rendering the cancel UI (storefront)

Decide **only** from `order.cancellation`:

| `mode` | What to show | Action |
|---|---|---|
| `direct` | "Cancel order" button and a countdown to `direct_until` | `POST /orders/{id}/cancel` |
| `request` | "Request cancellation" button that opens a reason form | `POST /orders/{id}/cancellation-request` |
| `none` + `request_pending` | "Cancellation requested, awaiting review" | none |
| `none` (other reasons) | No cancel action | none |

Suggested helper text per `reason`:

| `reason` | Text |
|---|---|
| `within_window` | "You can cancel this order until {direct_until, local time}." |
| `window_expired` | "The free cancellation window has passed. You can still request a cancellation." |
| `fulfillment_in_progress` / `label_purchased` | "Your order is being prepared for shipping. You can request a cancellation." |
| `request_pending` | "We received your cancellation request and will reply by email." |

### Countdown rules
- Compute the remaining time as `Date.parse(direct_until) - Date.now()`, for
  display only.
- When the countdown reaches zero, **re-fetch the order** (`GET /orders/{id}`)
  and re-render from the new `cancellation`. Never switch modes from the client
  clock alone; the device clock can be wrong.
- If the customer clicks just after the window closed, the API answers `400`.
  Handle it as described in 5.1.

---

## 5. Customer endpoints

### 5.1 `POST /orders/{id}/cancel`: direct cancellation

No request body.

**200 OK**: the order was cancelled. `data.order` is the updated order.

```json
{
  "success": true,
  "message": "Order cancelled successfully. Your payment is being returned to your card.",
  "data": {
    "order": {
      "id": 42,
      "status": "cancelled",
      "refund": { "status": "pending", "amount": 100.0, "currency": "usd" },
      "cancellation": { "mode": "none", "reason": "cancelled", "direct_until": null, "pending_request": null }
    }
  },
  "errors": null
}
```

For an unpaid order, `message` is `"Order cancelled successfully."` and
`refund.status` is `"none"`.

**400 Bad Request**: not directly cancellable any more. `errors.reason[0]`
uses the same values as `cancellation.reason`.

```json
{
  "success": false,
  "message": "The 3-hour direct cancellation window has passed. Please submit a cancellation request instead.",
  "data": null,
  "errors": { "reason": ["window_expired"] }
}
```

Frontend: re-fetch the order and show the UI for the new `cancellation.mode`
(usually the request form).

**409 Conflict**: the customer just completed payment for this order (e.g. in
another tab) and Stripe is still confirming it.

```json
{ "success": false, "message": "Your payment is being confirmed. Please refresh the order in a moment.", "data": null, "errors": null }
```

Frontend: show the message, wait a few seconds, then re-fetch. The order will
then be `processing` and `mode: "direct"`, and the customer can cancel again.

**404 Not Found**: not the customer's order.

> **Payment tabs:** cancelling an unpaid order now closes its Stripe Checkout
> page. A second tab that still shows Checkout can no longer pay. Nothing is
> needed from the frontend for this.

### 5.2 `POST /orders/{id}/cancellation-request`: request approval (unchanged)

```json
{ "reason": "I ordered the wrong size, please cancel." }
```

`reason` is required, 10–2000 characters.

| Status | Meaning |
|---|---|
| `201` | Request submitted. Re-fetch the order: `cancellation.mode` becomes `none`, `reason` becomes `request_pending`. |
| `422` | Validation error, the order is already shipped/delivered/cancelled, or a request is already pending. |

When the admin accepts, the order becomes `cancelled`, the money is returned
automatically (section 6), and the customer gets an email.

### 5.3 `GET /orders/{id}` and `GET /orders`

Unchanged endpoints, with the new fields from section 3. Use them to poll
`refund.status` after a cancellation (see section 6).

---

## 6. Showing the refund state

Show this only when `order.status === 'cancelled'` (or `refunded`):

| `refund.status` | Meaning | Suggested customer text |
|---|---|---|
| `none` | Nothing was paid. | No message needed. |
| `pending` | The backend is returning the money. | "Your order was cancelled. We are returning your payment." |
| `released` | The card hold was cancelled; the customer was never charged. | "Your order was cancelled and the hold on your card was released. Depending on your bank, the pending amount can take a few days to disappear." |
| `succeeded` | The charge was refunded. | "Your order was cancelled and {amount} {currency} was refunded. It usually appears within 5–10 business days." |
| `failed` | Automatic return failed; staff were alerted. | "Your order was cancelled. We are processing your refund manually and will contact you." |

`pending` usually changes within seconds to minutes. There are no push
updates. If the order page is open with `pending`, re-fetch every 10–15
seconds for up to about 2 minutes, then stop and keep showing the `pending`
text.

### Displaying `payment_status`

| Value | Customer label |
|---|---|
| `unpaid` | Awaiting payment |
| `authorized` | Payment confirmed |
| `paid` | Paid |
| `failed` | Not charged (when cancelled) / Payment failed |
| `refunded` | Refunded |

`authorized` must look like a successful payment to the customer. The money
is reserved and will be charged automatically.

---

## 7. Admin panel

### 7.1 Order list and detail
`GET /admin/orders` and `GET /admin/orders/{id}` include `cancellation`,
`refund` and `authorized_at`, plus the existing admin fields. Suggested
additions:

- A badge for `payment_status: "authorized"`, e.g. "Card held, captured after 3h".
- On cancelled orders, a `refund.status` badge. Highlight `failed` in red.
- A "Cancellation requested" marker when `cancellation.pending_request` is not null.

`POST /admin/orders/{id}/status` still returns the raw order model. There, the
money state is the flat field `refund_status` (same values), and the
`cancellation` / `refund` objects are absent.

### 7.2 Retry a refund: `POST /admin/orders/{id}/refund`

On a **cancelled** order this returns the customer's money (release the hold
or refund the charge) and keeps the order `cancelled`. Use it as the
"Retry refund" button when `refund.status` is `failed`.

| Status | Body / meaning |
|---|---|
| `200` | `data: { order_id, refund_status: "released" \| "succeeded" }` |
| `202` | `data: { order_id, refund_status: "pending" }`: Stripe is still confirming. |
| `409` | The money was already returned, or a refund is already running. |
| `502` | Stripe failed. `refund.status` is `failed`; try again later. |

On a **non-cancelled** order the endpoint keeps its old behaviour: full refund,
and the order becomes `refunded` (for returns after delivery). It returns
`409` for an `authorized` order. A hold is not refunded; cancel the order
instead (`POST /admin/orders/{id}/status` with `{"status":"cancelled"}`), which
releases it automatically.

### 7.3 Buy a label: `POST /admin/orders/{id}/ship`

New behaviour and errors:

| Status | `message` | What to show |
|---|---|---|
| `409` | `This order has a pending cancellation request. Accept or reject it before shipping.` | Fulfilment hold: link to the request. |
| `409` | `A label purchase for this order is already in progress.` | Disable the button and refresh. Prevents double clicks and double labels. |
| `409` | `Only paid processing orders can be shipped.` | Unchanged. |
| `409` | `The payment could not be captured, so no label was purchased.` | The card hold is no longer valid. Contact the customer. |
| `502` | `The payment could not be captured, so no label was purchased.` | Stripe error. Retry later. |

For an `authorized` order the backend captures the payment first, then buys the
label. No label is ever bought without captured money.

### 7.4 Accept a cancellation request: `POST /admin/cancellation-requests/{id}/accept`

- Cancels the order and returns the money automatically. Follow it via the
  order's `refund.status`.
- New `409` responses:
  - `The order is already shipped and can no longer be cancelled.` (also `delivered` / `refunded`)
  - `The order is already being shipped and can no longer be cancelled.` (a label purchase is in progress)

Reject (`/reject`) is unchanged.

---

## 8. Coupons

When an order is cancelled (paid or not), its coupon use is released and the
customer can use the coupon again. A refund after delivery (order `refunded`)
does **not** release the coupon. No frontend change is needed; just don't
show "coupon already used" from cached data after a cancellation.

---

## 9. Shipment tracking (no change required)

Tracking updates from EasyPost that arrive late or out of order are now
ignored. `shipment.status` and the tracking timeline no longer jump back
(e.g. from `delivered` to `in_transit`). The response shape is unchanged.

---

## 10. QA scenarios (Stripe test mode)

Use test card `4242 4242 4242 4242`.

| # | Steps | Expected |
|---|---|---|
| 1 | Place an order, pay, open the order. | `payment_status: authorized`, `cancellation.mode: direct`, countdown visible. |
| 2 | Cancel within the window. | `200`, `status: cancelled`, `refund.status` becomes `released`. Stripe shows the payment as *Canceled* (never captured). |
| 3 | Open Checkout, cancel the order from another tab, then try to pay in the first tab. | Checkout is expired and the payment cannot complete. |
| 4 | Order older than 3 hours (ask backend to run `php artisan orders:capture-authorized-payments` or adjust `authorized_at` in a test DB). | `payment_status: paid`, `cancellation.mode: request`, `reason: window_expired`. |
| 5 | Submit a cancellation request, admin accepts. | Order `cancelled`, `refund.status` becomes `succeeded`, email sent. |
| 6 | Submit a request, then admin tries to buy the label. | `409` fulfilment hold. |
| 7 | Admin buys the label on an authorized order. | Payment captured (`paid`), order `shipped`, customer `mode: none`. |
| 8 | Cancel right as the countdown hits zero. | Either `200`, or `400` with `window_expired`; the UI then shows the request form. |

Questions: contact the backend team. The OpenAPI spec (`/api/documentation`) has
been regenerated with these fields.
