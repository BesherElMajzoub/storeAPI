# Payment Checkout Contract

**API version:** v1  
**Last verified:** September 26, 2026

This document describes the customer-facing Stripe Checkout flow implemented by the backend. It does not expose Stripe secrets and does not replace webhook verification.

## Authentication and ownership

- Creating an order and resuming payment both require `auth:sanctum`.
- A customer can only resume payment for an order owned by that customer.
- An order owned by another customer is returned as `404`, not `403`, so the endpoint does not disclose whether the order exists.
- Checkout is only available while `status=pending_payment` and `payment_status=unpaid`.

## Create order and initial Checkout Session

`POST /api/v1/orders`

On success, the backend reserves inventory, consumes the selected shipping quote, creates a Stripe Checkout Session, and returns `201`:

```json
{
  "success": true,
  "message": "Order created. Redirect to Stripe checkout.",
  "data": {
    "order": {},
    "checkout_url": "https://checkout.stripe.com/...",
    "payment": {
      "session_id": "cs_..."
    }
  },
  "errors": null
}
```

If Stripe session creation fails, the backend returns `502` and compensates the committed order work: reserved stock is released, coupon usage is reversed, the shipping quote is made available again, and the failed order is soft-deleted.

## Resume payment

`POST /api/v1/orders/{id}/checkout-session`

The request has no body. The frontend should call it when an owned order is still shown as `pending_payment` and `unpaid`.

The backend serializes concurrent resume requests for the same order and then checks Stripe:

- An `open` session is reused. No duplicate session is created.
- An `expired` session is replaced and the order's `stripe_session_id` is updated.
- A `complete` session is not replaced while its completion webhook is pending.
- A missing provider response, unknown provider state, or Stripe failure does not change the stored session ID.
- If the order changes state while a replacement is being created, the replacement is immediately expired and the request returns `409`.

Successful response (`200`):

```json
{
  "success": true,
  "message": "Existing checkout session retrieved.",
  "data": {
    "checkout_url": "https://checkout.stripe.com/...",
    "payment": {
      "session_id": "cs_...",
      "reused": true
    }
  },
  "errors": null
}
```

`payment.reused` is `false` when a new or replacement session was created.

## Resume response codes

| Status | Meaning | Frontend action |
|---|---|---|
| `200` | Checkout URL is ready | Redirect the customer to `data.checkout_url` |
| `401` | Authentication is missing or invalid | Start the login flow |
| `404` | Order not found for this customer | Return to the customer's order list |
| `409` | Order is no longer payable, payment is awaiting webhook confirmation, or another resume request holds the lock | Refresh the order; retry only if it remains `pending_payment` and `unpaid` |
| `429` | API rate limit exceeded | Retry with backoff |
| `502` | Stripe could not be queried or did not return a supported state | Keep the order unchanged and offer a retry |

## Webhook rules

`POST /api/v1/webhooks/stripe` is public but requires a valid Stripe signature.

- `checkout.session.completed` is accepted only when the session ID, order amount, and currency match the order. It marks the order `processing` / `paid`.
- `checkout.session.expired` cancels an unpaid order only when the event's session ID is still the order's current `stripe_session_id`.
- An expiration event from a session that was replaced is intentionally ignored, so it cannot cancel the resumed order.
- Webhook replay is idempotent.

## Frontend integration sequence

1. Use the `checkout_url` returned by `POST /orders` for the first payment attempt.
2. If the customer later returns to an unpaid order, call `POST /orders/{id}/checkout-session`.
3. Redirect only after a `200` response; never construct a Stripe URL client-side.
4. After Stripe redirects to the order page, refresh `GET /orders/{id}` until the webhook changes the payment state or the UI retry window ends.
5. Treat the backend order state as authoritative; a Stripe redirect alone is not proof of payment.
