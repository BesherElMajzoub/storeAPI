# Authentication Contract

**API version:** v1
**Last verified:** September 26, 2026

Two authentication modes are supported side by side. Nothing about the existing Bearer-token flow changed — this document mainly describes the new, optional httpOnly-cookie mode for the web SPA.

## Mode 1 — Bearer token (unchanged, still fully supported)

Exactly as before: `POST /api/v1/auth/login` (and `/register`, `/auth/google`) return `data.access_token`; send it as `Authorization: Bearer <token>` on every subsequent request. Nothing here needs to change if you keep using this mode.

## Mode 2 — httpOnly session cookie (new)

The same login/register/google endpoints **also** issue a first-party, httpOnly session cookie when the request is recognized as coming from the web frontend. You do not need to read or store this cookie yourself — the browser handles it — but your HTTP client must be configured correctly for it to work:

1. **Send credentials on every request.** Axios: `axios.defaults.withCredentials = true` (or per-request `{ withCredentials: true }`). `fetch`: `{ credentials: 'include' }`.
2. **Fetch a CSRF cookie once per session, before the first login/register/state-changing request:**
   ```
   GET /sanctum/csrf-cookie
   ```
   This sets an `XSRF-TOKEN` cookie. Axios reads it automatically and sends it back as an `X-XSRF-TOKEN` header on every request — no manual work needed if you're using Axios with `withCredentials: true`. If you're using plain `fetch`, you must read the `XSRF-TOKEN` cookie yourself and set the `X-XSRF-TOKEN` header on every non-GET request.
3. **The request's `Origin`/`Referer` must match a domain the backend is configured to trust** (`SANCTUM_STATEFUL_DOMAINS` — see "Still needed from you" below). If it doesn't match, the request is treated exactly like a Bearer-token client: no cookie is set, no CSRF is enforced, and it must carry an `Authorization` header instead.

Once this is set up, `/api/v1/auth/login` (etc.) sets the session cookie automatically. From then on, **you don't need to send `Authorization` at all** — the browser sends the cookie on every request to the API's origin, and `auth:sanctum`-protected routes authenticate from it.

### Login/register/google-login response is unchanged

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "access_token": "1|abc...",
    "token_type": "Bearer",
    "user": { "...": "..." }
  },
  "errors": null
}
```

`access_token` is still returned even when the cookie was also set. You can simply ignore it if you're using cookie mode — there's no need to store it in `localStorage` anymore.

### Logout

`POST /api/v1/auth/logout` now also invalidates the session and rotates the CSRF token when called from a cookie-authenticated request. After logout, the old cookie can no longer authenticate anything — you don't need to manually clear it client-side (though the browser will drop it once it's rejected/replaced).

## Guest checkout — not supported, by design

`POST /api/v1/orders` requires authentication. There is no guest-checkout path and none is planned — if a customer isn't logged in, send them through login/register first. This was explicitly confirmed, not just left unimplemented.

## Ownership and ambient auth

Nothing about IDOR/ownership protection changed. A cookie-authenticated request and a Bearer-token request are checked identically by every existing authorization rule (e.g. `GET /orders/{id}` for an order you don't own still returns `404`).

## Still needed from you / ops before this goes live

1. **Set `SANCTUM_STATEFUL_DOMAINS` in the production environment** to your actual frontend host(s) (e.g. `otantikqueen.com`), without a scheme. Right now it only lists local dev hosts (`localhost`, `127.0.0.1`, etc.) — cookie auth will silently fall back to "not recognized as frontend" (no cookie, no CSRF) against production until this is set.
2. **Confirm your frontend's production domain relationship to the API.** If the frontend is served from a subdomain of the same registrable domain as the API (e.g. `otantikqueen.com` frontend + `apis.otantikqueen.com` API) — the current config (`SESSION_SAME_SITE=lax`) works correctly with no further changes. **If the frontend is on a genuinely different domain** (a different registrable domain entirely, e.g. a Vercel/Netlify URL not on `otantikqueen.com`), the cookie needs `SameSite=None` instead of `Lax`, which also requires `SESSION_SECURE_COOKIE=true` (HTTPS only) — tell us if this is your setup and we'll adjust the config.
3. **Set `SESSION_SECURE_COOKIE=true` in production** (should already be true anywhere served over HTTPS, but confirm — see `.env.example`).
4. **Decide whether you're switching to cookie mode at all**, or keeping Bearer tokens. Both work identically as far as the backend is concerned; this is purely a frontend architecture call (cookie mode removes the need to store a token in `localStorage`, which is more resistant to XSS token theft, at the cost of needing the CSRF-cookie dance above).

Nothing else is required from the backend side — the middleware, login/logout wiring, and CORS (`supports_credentials: true` was already configured) are all in place and tested.
