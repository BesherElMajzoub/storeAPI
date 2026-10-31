# Frontend Integration: Better Address Suggestions

Backend review of the "Better Address Suggestions" proposal (19 Oct 2026). Written for the frontend developer. **Everything in this document is live** on staging (`apis.otantikqueen.com`) and production (`api.otantikqueen.com`) since 31 Oct 2026. It says what the frontend should send and how the field behaves.

## 1. In one minute

| Topic | Backend view |
| --- | --- |
| Diagnosis | **Confirmed and fixed.** Autocomplete used to send Geoapify only the text, with no country filter and no location bias, so suggestions could come from anywhere. |
| US-only filter | **Live:** autocomplete sends `filter=countrycode:us&lang=en`, and place details are also requested in English. |
| Hints from the frontend (`country`, `postal_code`, `state`) | **Live.** The backend now uses them. Bad or unknown values are ignored, never rejected. |
| Bias order | **Live**, with one change: a chosen **state does not override the IP** when the IP is inside that state (section 4). There is no frontend impact. |
| Quote shipping only for a finished street | Agreed. This is frontend only (section 5). |
| Outage answer | **Live.** A Geoapify failure, quota error, timeout or missing key answers `200` with an empty list. Keep treating any non-`200` as "no suggestions" (section 3). |

## 2. Request

`GET /api/v1/address/autocomplete` is public (no token needed). It is limited to 60 requests a minute per client, shared with `/address/details`.

```
GET /api/v1/address/autocomplete
  ?q=179 N Harbor        required, 2–255 characters (the field should wait for 3)
  &session=<uuid>        required, as today
  &country=US            NEW, optional, ISO 3166-1 alpha-2
  &postal_code=90277     NEW, optional, 5 digits or ZIP+4
  &state=CA              NEW, optional, 2-letter USPS code
```

The response is unchanged: `data[]` of `{ place_id, description, main_text, secondary_text }`.

**Which hints to send**

| Form state | `postal_code` | `state` |
| --- | --- | --- |
| Editing an address, or the ZIP is already filled | the form's ZIP | the form's state |
| New address, signed-in customer with a default address | the **default address's** ZIP, unless the form's state differs from the default address's state | the form's state, if chosen |
| New address, guest | omit | the form's state, if chosen |

- When the customer chooses a state other than their home state, the home ZIP is dropped, because the ZIP comes first and would pull results back home. The request then has `country` and `state` only.
- Always send `country` (the form's country, `US` today).
- Omit a hint when it is empty. Do not send `postal_code=` or `state=`.
- A malformed hint will be **ignored, not rejected**: the backend will not answer `422` for a bad `postal_code` or `state`. Validation stays on the form fields themselves.
- **Never send the customer's IP or location.** The backend reads the IP from the request itself. Browser location (`navigator.geolocation`) is not used.
- Both pages that use the shared address field send the hints: the checkout and the account page's saved-address form.

```ts
interface AddressHints {
  country?: string;      // 'US'
  postalCode?: string;   // '90277' or '90277-1234'
  state?: string;        // 'CA'
}
```

## 3. Errors and outages

| Case | Answer |
| --- | --- |
| `q` or `session` is invalid | `422` |
| Our own limit (60/min) is reached | `429` |
| Geoapify fails, is over quota, times out or its key is missing | `200` with `data: []` |

**Frontend rule:** any non-`200` answer means "no suggestions". Close the dropdown, show no error message, and let the customer type the address by hand. The order must never depend on a suggestion.

## 4. What the backend does (for reference, no frontend work)

The backend picks one location to favour. The first rule that has an answer wins:

1. `postal_code` → the centre of that ZIP (Census ZCTA table, stored locally).
2. `state` → if the customer's IP location is inside that state, the IP location; otherwise the state's centre. *This changes the proposal's order.* The centre of a large state is a poor guess: California's centre is near Fresno, so it would push results away from a customer in Los Angeles.
3. The customer's IP → their approximate area (DB-IP Lite file, stored locally; the IP is not stored or sent to Geoapify).
4. Nothing → no bias. The US filter still applies.

A bias only **reorders** the results. It never hides an address in another state, so gift addresses are still found.

The backend also does the following:
- Requests to Geoapify always include `filter=countrycode:us` and `lang=en`. The language no longer follows the app locale: all suggestions are in English.
- Identical requests are cached for 24 hours. The cache key is the text and the bias point rounded to about 10 km, never the IP.
- Every request logs which bias was used (`zip`, `state`, `ip`, `none`) and the result count, without the address text.

**Status:** all of the above is live on staging and production. The ZIP table and the IP file are refreshed on the servers (the IP file monthly, on the 3rd). The bias used per request is written to `storage/logs/address.log` (no address text, no IP).

**Known limit:** a state with no ZIP and no usable IP (a visitor abroad, a VPN) is biased to the state's centre only. For a common street name such as "200 Main St" that is a weak hint and results can still come from other states. A ZIP or an IP inside the state gives a strong one.

## 5. Shipping quote: only for a finished street

Every 400 ms pause (the old behaviour) in the street field requests a new shipping quote once city, state and ZIP are filled. A half-typed street gets quoted, then quoted again.

The street counts for the quote only when:
1. a suggestion is chosen, or
2. the street field loses focus, or
3. the form is submitted (Enter or Continue).

City, state, ZIP and cart changes re-quote as they do now.

Rule 3 matters. A customer who types a new street and presses Enter without leaving the field must get one quote for the **new** street before the order is placed. Otherwise the backend rejects the old quote, and the customer sees a "rates changed" notice and has to press Continue again.

## 6. Field order

No change. Country stays before the street, and the street stays before city, state and ZIP (the usual US order). Guests therefore usually have no ZIP or state while typing the street, which is why the IP step exists.

## 7. Worth adding on the frontend

- After `/address/details` fills the form, check that `country` is `US`. If it is not, keep the customer's typed text and show the normal country error. The US filter should make this impossible, but the check is cheap.
- Keep the confirmation the customer sees before paying (full address, including city, state and ZIP). A bias makes a wrong pick less likely, but cannot rule it out: a suggestion with the right street name in another state still looks right.

## 8. What to test

| ID | Do | Expect | Backend part |
| --- | --- | --- | --- |
| ADDR-01 | Signed-in customer with a Redondo Beach default address adds a new address, types "179 N Harbor" | Redondo Beach, CA in the first three suggestions; the request has `postal_code=90277` | live |
| ADDR-02 | Guest in Los Angeles, no ZIP or state, types "200 Main St" | Southern California first | live |
| ADDR-03 | ZIP 10001 entered, then the street typed | New York first; the request has `postal_code=10001` | live |
| ADDR-04 | Gift: full street and city in another state | That address is still found | live |
| ADDR-05 | Any query | No suggestion outside the US | US filter only |
| ADDR-06 | Geoapify unreachable | No suggestions, no error message; the order can be placed | no (frontend rule, section 3) |
| ADDR-07 | Saved city, state, ZIP; retype the street slowly | No shipping request while typing; one on choosing a suggestion or leaving the field | no |
| ADDR-09 | Saved city, state, ZIP; type a new street and press Enter without leaving the field | One quote for the new street; no "rates changed" notice; no second press | no |
| ADDR-11 | Guest in Los Angeles chooses state CA, then types the street | Los Angeles results first, not central California | live |

ADDR-08 (cache) and ADDR-10 (real client IP) are backend checks, both done: Laravel sees the visitor's real IP on staging and production.
