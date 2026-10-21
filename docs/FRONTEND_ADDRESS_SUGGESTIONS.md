# Frontend Integration: Better Address Suggestions

Backend review of the "Better Address Suggestions" proposal (19 Oct 2026). Written for the frontend developer. **Nothing in this document is live yet.** It says what the backend will change, what the frontend should send, and how the field should behave in the meantime.

## 1. In one minute

| Topic | Backend view |
| --- | --- |
| Diagnosis | **Confirmed.** `GeoapifyService::autocomplete` sends Geoapify only `text`, `lang`, `limit`. There is no country filter and no location bias, so suggestions can come from anywhere. |
| US-only filter | **Done in code** (not yet deployed): autocomplete sends `filter=countrycode:us&lang=en`, and place details are also requested in English. |
| Hints from the frontend (`country`, `postal_code`, `state`) | Agreed. **The frontend can ship this now:** the backend ignores unknown query parameters today, so sending them changes nothing until the backend uses them. |
| Bias order | Agreed, with one change: a chosen **state does not override the IP** when the IP is inside that state (section 4). There is no frontend impact. |
| Quote shipping only for a finished street | Agreed. This is frontend only (section 5). |
| Outage answer | The backend will answer `200` with an empty list. **Until then it answers 429/500/502/504**, so the field must already treat any error as "no suggestions" (section 3). |

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

| Today | After the backend change |
| --- | --- |
| `422` when `q` or `session` is invalid | same |
| `429` when our limit (60/min) is reached, or Geoapify's quota is used up | `429` only for our own limit; a Geoapify quota error becomes an empty list |
| `500` when the Geoapify key is missing, `502` when Geoapify fails, `504` on a timeout | `200` with `data: []` |

**Frontend rule, from now on:** any non-`200` answer means "no suggestions". Close the dropdown, show no error message, and let the customer type the address by hand. The order must never depend on a suggestion.

## 4. What the backend will do (for reference, no frontend work)

The backend picks one location to favour. The first rule that has an answer wins:

1. `postal_code` → the centre of that ZIP (Census ZCTA table, stored locally).
2. `state` → if the customer's IP location is inside that state, the IP location; otherwise the state's centre. *This changes the proposal's order.* The centre of a large state is a poor guess: California's centre is near Fresno, so it would push results away from a customer in Los Angeles.
3. The customer's IP → their approximate area (DB-IP Lite file, stored locally; the IP is not stored or sent to Geoapify).
4. Nothing → no bias. The US filter still applies.

A bias only **reorders** the results. It never hides an address in another state, so gift addresses are still found.

The backend also does the following:
- Requests to Geoapify always include `filter=countrycode:us` and `lang=en`. Today the language follows the app locale, so an Arabic session could get Arabic place names. After the change, all suggestions are in English.
- Identical requests are cached for 24 hours. The cache key is the text and the bias point rounded to about 10 km, never the IP.
- Every request logs which bias was used (`zip`, `state`, `ip`, `none`) and the result count, without the address text.

**Timing:** before launch, the US filter and `lang=en`. In the first week after launch, the hints, the ZIP/state/IP bias, the cache, the outage answer and the logging.

## 5. Shipping quote: only for a finished street

Today, every 400 ms pause in the street field requests a new shipping quote once city, state and ZIP are filled. A half-typed street gets quoted, then quoted again.

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

| ID | Do | Expect | Needs backend change |
| --- | --- | --- | --- |
| ADDR-01 | Signed-in customer with a Redondo Beach default address adds a new address, types "179 N Harbor" | Redondo Beach, CA in the first three suggestions; the request has `postal_code=90277` | yes |
| ADDR-02 | Guest in Los Angeles, no ZIP or state, types "200 Main St" | Southern California first | yes |
| ADDR-03 | ZIP 10001 entered, then the street typed | New York first; the request has `postal_code=10001` | yes |
| ADDR-04 | Gift: full street and city in another state | That address is still found | yes |
| ADDR-05 | Any query | No suggestion outside the US | US filter only |
| ADDR-06 | Geoapify unreachable | No suggestions, no error message; the order can be placed | no (frontend rule, section 3) |
| ADDR-07 | Saved city, state, ZIP; retype the street slowly | No shipping request while typing; one on choosing a suggestion or leaving the field | no |
| ADDR-09 | Saved city, state, ZIP; type a new street and press Enter without leaving the field | One quote for the new street; no "rates changed" notice; no second press | no |
| ADDR-11 | Guest in Los Angeles chooses state CA, then types the street | Los Angeles results first, not central California | yes |

ADDR-08 (cache) and ADDR-10 (real client IP on the production server) are backend checks.
