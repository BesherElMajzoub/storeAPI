# Otantik Queen — Store API

Laravel 12 backend API for the Otantik Queen e-commerce storefront: catalog,
cart/checkout, Stripe payments, EasyPost shipping, coupons, wishlist,
reviews, and an admin back office.

## Requirements

- PHP ^8.2 with the extensions Laravel 12 requires (mbstring, pdo_mysql,
  openssl, etc.)
- Composer
- MySQL (or another Laravel-supported DB for local dev — see below for the
  DB the test suite specifically requires)
- Node.js + npm (for the Vite-built admin/asset pipeline)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set at minimum:

- `DB_*` — your local database connection.
- `STRIPE_SECRET`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` — required
  for checkout/payments to work at all (use Stripe test-mode keys locally).
- `EASYPOST_API_KEY`, `EASYPOST_WEBHOOK_SECRET` — required for shipping
  rates/labels/tracking.
- `GOOGLE_CLIENT_ID` — required for "Sign in with Google".
- `GOOGLE_PLACES_API_KEY` / `GEOAPIFY_API_KEY` — required for address
  autocomplete (only one provider is needed, set by `LOCATION_PROVIDER`).
- `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` — must match the storefront's
  actual origin(s) for CORS and cookie-based auth to work.

Then:

```bash
php artisan migrate
php artisan db:seed
```

Demo accounts (admin + sample products) are only created in `local`/`testing`
environments — the seeders refuse to run them in production. Never reuse a
local demo password for a deployed account.

Or run all of the above (except editing `.env`'s service keys) in one step:

```bash
composer setup
```

## Running the app

```bash
composer dev
```

This starts the HTTP server, queue worker, log tailer (`pail`), and Vite dev
server together. Or run `php artisan serve` on its own if you don't need the
queue worker/asset pipeline locally.

- **Base URL**: `http://localhost:8000/api/v1`
- **API docs**: Swagger UI at `/api/documentation` (via `l5-swagger`), or
  import `postman_collection.json` into Postman.

## Running tests

The test suite requires its own **MySQL** database — it does not use SQLite
or your dev database. `phpunit.xml` points tests at
`127.0.0.1:3308` / database `storeapi_testing` / user `root` with no
password. Start a MySQL instance matching that (a local install or a
container mapped to port 3308 both work), then:

```bash
composer test
```

or directly:

```bash
php artisan test
```

Static analysis and style:

```bash
./vendor/bin/pint --test    # code style, no changes
./vendor/bin/pint            # code style, auto-fix
./vendor/bin/phpstan analyse --level=5
```

External providers (Stripe, EasyPost, Geoapify, Google, Telegram, mail) are
always faked or mocked in tests — the suite never makes a real network call
to any of them, and never needs real API keys to pass.

## Architecture

- **Controllers** (`app/Http/Controllers/Api/V1`): thin — validate via a
  `FormRequest`, call a service, return a `Resource`.
- **Services** (`app/Services`): business logic (pricing, inventory,
  shipping, OTP, Stripe/EasyPost integration, etc.).
- **Models** (`app/Models`): Eloquent models and relationships.
- **Requests** (`app/Http/Requests`): all input validation.
- **Resources** (`app/Http/Resources`): all API response shapes.
- **Contracts** (`app/Contracts`) + provider bindings in
  `AppServiceProvider`: external services (EasyPost, geolocation) are
  swappable behind an interface; tests bind a fake implementation.
- **Auth**: Laravel Sanctum (Bearer tokens for API clients, stateful
  httpOnly-cookie sessions for the first-party SPA) plus a custom
  `admin-access` Gate backed by a role/permission system.

Frozen API contracts the frontend depends on — do not change a route,
request field, response shape, status code, or error format without
updating these and coordinating with the frontend:

- `docs/AUTHENTICATION_CONTRACT.md`
- `docs/PAYMENT_CHECKOUT_CONTRACT.md`
- `docs/SHIPPING_CONTRACT.md`
- `docs/API_V1_ROUTE_MIDDLEWARE.md`

## License

The Laravel framework is open-sourced software licensed under the
[MIT license](https://opensource.org/licenses/MIT).
