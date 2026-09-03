# Shipping and tracking contract

Shipping v1 supports US destinations only. Product measurements use ounces and
inches; variants may override product measurements. The server selects a
configured warehouse package and never accepts checkout shipping prices from a client.

## Rates

`POST /api/v1/shipping/rates`

```json
{
  "address": {
    "line1": "123 Main St",
    "city": "Pasadena",
    "state": "CA",
    "postal_code": "91101",
    "country": "US"
  },
  "items": [
    { "product_id": 12, "variant_id": 34, "quantity": 2 }
  ]
}
```

Successful responses contain an array in `data`:

```json
{
  "success": true,
  "data": [
    {
      "rate_id": "rate_abc123",
      "carrier": "USPS",
      "service": "Priority",
      "amount": 8.45,
      "eta_days": 3
    }
  ],
  "errors": null
}
```

Quotes expire after 15 minutes. Empty carts, unsupported destinations,
unserviceable addresses, missing measurements, and packages that do not fit
return 422. Provider outages/timeouts return 503 and never return an empty
successful rate list.

The old `address + parcel` request and `data.shipment_id + data.rates` response
remain available in API v1 with a `Deprecation: true` response header. Checkout
still recomputes the package and rejects a legacy quote that does not match it.

## Checkout and labels

`POST /api/v1/orders` requires `shipping_rate_id`. The server verifies that the
quote is unexpired/unconsumed, matches the normalized address, items, and
package, then retrieves the EasyPost rate again and uses that amount.

`POST /api/v1/admin/orders/{id}/label` explicitly purchases a label for a paid
order in `processing`. The request body may omit `rate_id` to use the checkout
selection. A supplied ID must belong to the order. Repeating a successful
request returns the existing label without buying another. `/ship` remains a
deprecated API v1 alias.

## Shipment resource and public tracking

Order detail responses contain `shipment: null` until label purchase. Customer
responses never contain `label_url`, EasyPost IDs, or Stripe IDs. Admin order
responses include `label_url` and provider IDs.

Shipment status values are:

`unknown`, `pre_transit`, `in_transit`, `out_for_delivery`,
`available_for_pickup`, `delivered`, `return_to_sender`, `failure`, `cancelled`,
and `error`.

`POST /api/v1/orders/track` accepts `order_number` and `email`, returns only the
stored status/delivery/events snapshot, and uses the same generic 404 for an
unknown order and an email mismatch. It is limited to five attempts per IP and
three attempts per normalized order/email fingerprint per minute.
