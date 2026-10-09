# Backend answers to `backend-questions-2026-10-09.md` (2026-10-09)

**From:** backend. Answers come from the code at `a866e6d`. Items marked **[OPS]** depend on the live server, which the code cannot show; they are filled in by whoever has server access.

---

### Q1. Is the reply branch what is live now?
- `a866e6d` is the head of `ai/backend-reply-2026-10-08`, and it is also `main` and `origin/main`. So the branch **is merged** now. The old note "not merged" is out of date. It is final for UAT unless a later answer below changes code.
- **[OPS]** Whether `php artisan migrate --force` ran (migration `2026_10_08_000001_add_voided_payment_status_and_fulfillment_hold_to_orders`, which backfills `failed` → `voided`). Because the live API already serves `voided` and `fulfillment_hold`, the code is deployed; the migration must be confirmed on the server.
- **[OPS]** Set `APP_VERSION` and `APP_DEPLOYED_AT` in the live `.env`. Without them `/health` shows the git SHA and `deployed_at: null`, as you saw.
- **[OPS]** Cron (`php artisan schedule:run` every minute) and a queue worker must run on the host that serves the API. Capture, expiry, refunds and emails all depend on them (emails and refund settlement are queued).

### Q2. Dashboard `filters`
Exact keys (strings, no leading `?`):
```json
{
  "current_orders_count": "status=processing&payment_status=authorized,paid",
  "low_stock": "low_stock=1",
  "pending_orders": "status=pending_payment",
  "payment_holds": "status=processing&payment_status=failed"
}
```
The keys follow the count names (`current_orders_count` at the top level, the others under `alerts`). `GET /admin/orders` **does accept a comma list** in `status` and `payment_status`. Your default is fine.

### Q3. Failed capture: way back?
- There is **no recovery path** other than Cancel today. No new payment link, no capture retry after the customer changes the card. A held PaymentIntent that is `canceled` or `requires_payment_method` can never be captured.
- `fulfillment_hold` is set only by a failed capture and is **never cleared**. Cancel is the only exit. (Cancelling restocks and sets `payment_status: voided`.)
- The customer is **not emailed**. Only the admin gets an urgent alert email (`ADMIN_ALERT_EMAIL`). The shop must contact the customer. Your default text ("We will contact you by email") is therefore only true if the shop does it.
- A "new payment link / retry" feature would be new work. Say if the owner wants it (P1).

### Q4. Manual `payment_status`
- Manual `failed` **does not** set `fulfillment_hold`. It only changes `payment_status` (allowed from `unpaid`). That is inconsistent with the new meaning of `failed`.
- Manual `unpaid` from `failed` is rejected with `409`; the transition map has no `failed → unpaid`.
- Your default is right: offer nothing by hand. We will drop `failed` from the accepted values in `PATCH /status` so only real capture failures produce it. **Backend change, small; will ship with the next push.** Tell us if you need `unpaid`.

### Q5. Label link
- A **new** 15-minute signed URL is made on every response that includes `shipment` for admin. Refetching every 10 minutes works.
- `label_download_url` is in **both** `GET /admin/orders/{id}` and the **list** (both use the admin resource), and in the label purchase response.
- It is `null` when the order has no tracking number, or has neither a stored PDF nor an EasyPost shipment id.
- There is no "conversion pending" state and no job. On the first download the PDF is fetched from EasyPost (`label_pdf_url`, or a PDF re-request) and stored. If that fetch fails, `GET /admin/shipments/{order}/label` returns `404 "No label is available for this order yet."` and retries on the next call. The field is **not** null in that case.
- The response sends `inline` with file name `label-<order_number>.pdf` (e.g. `label-ORD-xxxx.pdf`), `Content-Type: application/pdf`, `Cache-Control: private, no-store`.
- Your default (fall back to EasyPost `label_url` only when the field is null) is fine.

### Q6. Bulk `409` body
```json
{ "success": false, "message": "Status transition not allowed; no orders were changed.",
  "data": null,
  "errors": { "orders": { "<order_id>": ["message"] } } }
```
Two other `409`/`502` bodies have no `errors.orders`: Stripe session already completed (`409`) and payment provider error (`502`). Bulk **refuses** to cancel an `authorized`/`paid` order and to ship an `authorized` order, so keep one call per order for those. Your default is fine; use the atomic `/bulk-status` only for simple moves.

### Q7. Shipped without a label
- `shipping_carrier` is **free text** (max 64), no fixed list. Your select with "Other" works. `tracking_number` is max 64 and must be sent together with `shipping_carrier`.
- It stores the two fields on the order. It does **not** build a `tracking_url`, and it does **not** send the "shipped" email (that email is sent only when a label is bought). `shipment.tracking_url` stays `null`, and `status` shows `unknown`.
- If the owner wants the customer email and a tracking link for manual shipping, that is new work (P1).

### Q8. `refund.status` pending → succeeded
- The only event that does it is **`charge.refunded`** (full refund → `succeeded`, `payment_status: refunded`). `refund.failed` sets `failed`.
- **There is no fallback job.** If `charge.refunded` is missed, the refund stays `pending`. The queue job that settles a cancellation retries only while Stripe has not created the refund.
- Gap, agreed. We will add a small scheduled check that re-reads pending refunds from Stripe. **Backend change; P1.** Until then the admin can press the refund retry on the order.

### Q9. `label_purchased`
Cannot appear for a shipped order. It can still appear in the code for an order with a tracking number whose status is not `shipped` (an unusual state, e.g. a label stored while the status was changed back by hand). In practice: never in normal flow. Keep your generic text. We will keep it in the enum until Option B is decided, then remove it if the answer is "never".

### Q10. UAT cancellation window
- **[OPS]** Set `ORDER_DIRECT_CANCEL_WINDOW_MINUTES=10` in the live `.env`, then `php artisan config:clear` (or re-cache). Reply here when active.
- Capture runs by itself every 5 minutes. On demand, the server runs: `php artisan orders:capture-authorized-payments`.
- It must return to `180` before launch (already on the checklist).

### Q11. Image regeneration (B14)
**[OPS]** `php artisan media-library:regenerate --force` has not been run on the live server. Needs someone with server access; reply here when done. Keep the 420 `srcset` until then.

### Q12. EasyPost webhook
**[OPS]** The route is `POST /api/v1/webhooks/easypost`; it needs `EASYPOST_WEBHOOK_SECRET` in the live `.env` (without it the endpoint returns an error and logs critical). Needs the last delivery result from the EasyPost dashboard; to be filled in by the server owner.

### Q13. D4 category update 500
**[OPS]** After Q1 is confirmed, retry. If it still fails, the stack trace is in `storage/logs/laravel.log` on the live server; to be pasted here by the server owner.

### Q14. Test addresses 31 and 33 (D8)
**[OPS / owner]** Not decided yet. Suggestion: delete them at the production switch with the rest of the test data.
