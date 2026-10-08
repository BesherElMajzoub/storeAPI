# Frontend Handoff: Saved Address Verification with EasyPost

**Audience:** Storefront frontend  
**API prefix:** `/api/v1`  
**Authentication:** `auth:sanctum` (Bearer token or the configured SPA cookie)

## What changed

The backend now verifies saved shipping addresses with EasyPost before writing
them to the database.

- `POST /profile/addresses` always verifies the address before creating it.
- `PUT /profile/addresses/{id}` verifies the complete resulting address when a
  physical address field changes.
- A rejected address returns HTTP `422`; no address is created and an existing
  address remains unchanged.
- Successful verification may normalize `street`, `city`, `state`,
  `postal_code`, and `country`. Always replace the local address with the
  `data` returned by the API.
- `state` is now accepted and returned by the saved-address endpoints. It was
  previously omitted from this contract.
- `country` must be a two-letter ISO 3166-1 code, preferably uppercase, such as
  `US`, `CA`, or `GB`.

The frontend does **not** need to call `/shipping/verify-address` before saving.
The create/update endpoint now performs that check itself.

## Address shape

```ts
export type AddressLabel = 'home' | 'work' | 'other';

export interface SavedAddress {
  id: number;
  label: AddressLabel;
  full_name: string;
  phone: string;
  country: string;       // Two-letter ISO code
  city: string;
  state: string | null;  // Newly exposed
  area: string | null;
  street: string;
  building: string | null;
  floor: string | null;
  apartment: string | null;
  postal_code: string | null;
  notes: string | null;
  is_default: boolean;
  created_at: string | null;
}

export interface CreateAddressInput {
  label: AddressLabel;
  full_name: string;
  phone: string;
  country: string;
  city: string;
  state?: string | null;
  area?: string | null;
  street: string;
  building?: string | null;
  floor?: string | null;
  apartment?: string | null;
  postal_code?: string | null;
  notes?: string | null;
  is_default?: boolean;
}

export type UpdateAddressInput = Partial<CreateAddressInput>;
```

Required create fields are `label`, `full_name`, `phone`, `country`, `city`,
and `street`. Although `state` and `postal_code` are nullable at the request
schema level for international compatibility, the frontend should send them
whenever the selected country uses them; EasyPost may reject an incomplete
deliverable address.

## Create an address

`POST /api/v1/profile/addresses`

```json
{
  "label": "home",
  "full_name": "Jane Smith",
  "phone": "+13108085243",
  "country": "US",
  "city": "Redondo Beach",
  "state": "CA",
  "street": "179 N Harbor Dr",
  "postal_code": "90277",
  "apartment": "12B",
  "notes": "Leave at the door"
}
```

Successful response: HTTP `201`.

```json
{
  "success": true,
  "message": "Address saved successfully.",
  "data": {
    "id": 41,
    "label": "home",
    "full_name": "Jane Smith",
    "phone": "+13108085243",
    "country": "US",
    "city": "REDONDO BEACH",
    "state": "CA",
    "area": null,
    "street": "179 N HARBOR DR",
    "building": null,
    "floor": null,
    "apartment": "12B",
    "postal_code": "90277-2510",
    "notes": "Leave at the door",
    "is_default": true,
    "created_at": "2026-09-28T18:00:00.000000Z"
  },
  "errors": null
}
```

The first saved address for a user is automatically made the default address.

## Update an address

`PUT /api/v1/profile/addresses/{id}` accepts a partial body.

```json
{
  "city": "Los Angeles",
  "state": "CA",
  "postal_code": "90001"
}
```

Changing any of these fields triggers EasyPost verification:

```text
country, city, state, area, street, building, floor, apartment, postal_code
```

Changing only `label`, `full_name`, `phone`, `notes`, or `is_default` does not
trigger another address verification request.

Successful update response: HTTP `200`, using the same envelope and
`SavedAddress` data shape as create.

## Error handling

### EasyPost rejected the address

HTTP `422`:

```json
{
  "success": false,
  "message": "Address verification failed.",
  "data": null,
  "errors": {
    "address": [
      "Street could not be found."
    ]
  }
}
```

Frontend behavior:

1. Keep the form values; do not close or reset the form.
2. Show `errors.address[0]` as the main address error.
3. Let the customer correct the address and submit again.
4. Do not add the submitted address to local state optimistically. Add or
   replace it only after a successful API response.

Suggested Arabic fallback if `errors.address` is absent:

```text
تعذر التحقق من العنوان. يرجى مراجعة بيانات العنوان والمحاولة مرة أخرى.
```

### Request-schema validation failed

HTTP `422` can also contain field errors rather than `errors.address`:

```json
{
  "message": "The country field must be 2 characters.",
  "errors": {
    "country": ["The country field must be 2 characters."]
  }
}
```

Map these keys directly to their form controls. In particular, the country
selector must submit an ISO code, not a translated country name.

### Other relevant statuses

| Status | Meaning | Frontend action |
|---|---|---|
| `401` | User is not authenticated | Start the login/session-refresh flow |
| `403` | The address belongs to another user | Show a generic forbidden message |
| `404` | Address ID does not exist | Refresh the saved-address list |
| `422` | Form validation or EasyPost verification failed | Keep the form open and show the returned errors |

## Recommended submit handler

```ts
type AddressApiResponse = {
  success: boolean;
  message: string;
  data: SavedAddress | null;
  errors: Record<string, string[]> | null;
};

async function saveAddress(
  input: CreateAddressInput | UpdateAddressInput,
  addressId?: number,
): Promise<SavedAddress> {
  const response = await fetch(
    addressId
      ? `/api/v1/profile/addresses/${addressId}`
      : '/api/v1/profile/addresses',
    {
      method: addressId ? 'PUT' : 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        ...input,
        country: input.country?.toUpperCase(),
      }),
    },
  );

  const payload = (await response.json()) as AddressApiResponse;

  if (!response.ok || !payload.data) {
    const message =
      payload.errors?.address?.[0] ??
      Object.values(payload.errors ?? {}).flat()[0] ??
      payload.message;

    throw new Error(message);
  }

  // Use this server-returned object: its shipping fields may be normalized.
  return payload.data;
}
```

If the project already has a shared API client, keep its authentication and
error classes; the important integration rules are to send `state`, submit a
two-letter `country`, preserve the form on `422`, and use the normalized
address returned by the server.
