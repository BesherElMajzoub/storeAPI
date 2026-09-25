# Shipping Contract

**API version:** v1  
**Last verified:** September 26, 2026

The preferred flow uses server-side product and variant measurements. All public shipping endpoints are rate-limited by the API middleware and return JSON.

## Provider driver

`EASYPOST_DRIVER=easypost` is the required production setting. Non-production staging environments may explicitly use `EASYPOST_DRIVER=fake` to exercise address, quote, checkout, label, tracking, and webhook integration without contacting EasyPost.

The fake driver:

- is refused when `APP_ENV=production`;
- returns deterministic `MockCarrier` Ground and Express rates;
- persists its mock shipment/rate/tracker state in the configured Laravel cache for 24 hours;
- produces non-deliverable `example.test` label and tracking URLs;
- validates mock webhooks with `X-Mock-Signature`, containing `hash_hmac('sha256', raw_request_body, EASYPOST_WEBHOOK_SECRET)`.

The fake driver is integration infrastructure only. It does not prove carrier availability, address deliverability, label billing, or live webhook delivery. `php artisan app:production-readiness` fails unless the real `easypost` driver is selected.

## Address verification

`POST /api/v1/shipping/verify-address`

Required fields: `name`, `street1`, `city`, `state`, `zip`, and a two-letter `country`. Optional fields: `street2` and `phone`.

- `200`: `data` contains the provider-normalized address.
- `422`: address validation or provider verification failed; details are in `errors.address`.

## Get rates: current contract

`POST /api/v1/shipping/rates`

```json
{
  "address": {
    "name": "Customer Name",
    "line1": "179 N Harbor Dr",
    "line2": null,
    "city": "Redondo Beach",
    "state": "CA",
    "postal_code": "90277",
    "country": "US",
    "phone": "310-808-5243"
  },
  "items": [
    {
      "product_id": 10,
      "variant_id": 25,
      "quantity": 2
    }
  ]
}
```

Rules:

- `items` must contain 1-50 lines; quantity is 1-100 per line.
- `variant_id` is nullable, but when present it must belong to the selected product.
- The product must be published, its category must be active, and sufficient stock must exist.
- Product or variant shipping measurements are resolved by the server. A variant value overrides the product value; missing variant values fall back to the product.
- Supported destination countries come from `services.easypost.supported_countries`; the current default is US only.
- The server selects the smallest configured package that fits the cart's dimensions, volume, and total weight.

Successful response (`200`):

```json
{
  "success": true,
  "message": "Shipping rates retrieved.",
  "data": [
    {
      "rate_id": "rate_...",
      "carrier": "USPS",
      "service": "Priority",
      "amount": 8.45,
      "eta_days": 3,
      "expires_at": "2026-09-25T12:15:00+00:00"
    }
  ],
  "errors": null
}
```

`expires_at` is authoritative. The default quote lifetime is 15 minutes and is configurable through `EASYPOST_QUOTE_TTL_MINUTES`.

## Deprecated v1 parcel contract

For compatibility, the endpoint still accepts `address + parcel` without `items`. The caller supplies `parcel.length`, `width`, `height`, and `weight`; dimensions are inches and weight is ounces.

The response carries the HTTP header `Deprecation: true` and this shape:

```json
{
  "data": {
    "shipment_id": "shp_...",
    "rates": [
      {
        "id": "rate_...",
        "carrier": "USPS",
        "service": "Priority",
        "rate": 8.45,
        "currency": "USD",
        "delivery_days": 3,
        "delivery_date": null,
        "expires_at": "2026-09-25T12:15:00+00:00"
      }
    ]
  }
}
```

New clients must use the item-based contract.

## Select a rate during checkout

Send the returned `rate_id` as `shipping_rate_id` to `POST /api/v1/orders` with exactly the same address and cart.

Before reserving inventory, the backend verifies that:

- the quote exists, is unused, and has not expired;
- address, item, and calculated parcel fingerprints still match;
- the rate still belongs to the same provider shipment and currency;
- every product and variant is still available with sufficient stock.

On successful order creation the quote is atomically marked consumed. A Stripe-session creation failure releases it again as part of compensating cleanup.

## Shipping error responses

Domain validation errors use `422`:

```json
{
  "success": false,
  "message": "The cart changed. Request new shipping rates.",
  "data": null,
  "errors": {
    "shipping": ["The cart changed. Request new shipping rates."],
    "code": "shipping_items_changed"
  }
}
```

During order creation the message is under `errors.shipping_rate_id`; `errors.code` remains the machine-readable code.

| Code | Meaning |
|---|---|
| `unsupported_destination` | Destination country is not supported |
| `unavailable_product` | Product is missing, unpublished, or belongs to an inactive category |
| `invalid_variant` | Variant does not belong to the selected product |
| `insufficient_stock` | Requested quantity exceeds current stock |
| `shipping_configuration` | Measurements are missing or the cart fits no configured package |
| `unserviceable_address` | Provider returned no rates for the address |
| `invalid_shipping_rate` | Quote is missing, expired, consumed, unavailable, or does not match its provider shipment |
| `shipping_address_changed` | Checkout address differs from the quoted address |
| `shipping_items_changed` | Checkout products, variants, or quantities differ from the quote |
| `shipping_parcel_changed` | Recalculated parcel differs from the quoted parcel |

Request-schema validation also returns `422`, but uses normal field error keys and may not include `errors.code`. Provider failures return `503` and should be treated as retryable.
