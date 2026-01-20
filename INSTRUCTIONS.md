# Laravel Store API Backend Steps

## 1. Setup Environment

Ensure your `.env` is configured with database credentials.

## 2. Run Migrations

Run the following command to create all tables (Store, Users, Roles, etc.):

```bash
php artisan migrate
```

## 3. Seed Database

Populate the database with Roles, Admin User, and Sample Products:

```bash
php artisan db:seed
```

**Admin Credentials:**

- Email: `admin@store.com`
- Password: `password123`

**Demo User:**

- Email: `user@store.com`
- Password: `password123`

## 4. Install API Support (If needed)

If you haven't already, confirm `api.php` is registered. (I have updated `bootstrap/app.php` for you).

## 5. Serve Application

```bash
php artisan serve
```

## API Structure

- **Base URL**: `http://localhost:8000/api/v1`
- **Documentation**: Import `postman_collection.json` into Postman.

### Key Endpoints

- **POST** `/api/v1/auth/login`
- **GET** `/api/v1/products`
- **GET** `/api/v1/categories`
- **POST** `/api/v1/orders` (Requires Auth)
- **GET** `/api/v1/admin/dashboard` (Requires Admin Auth)

## Architecture Notes

- **Controllers**: Located in `app/Http/Controllers/Api/V1`
- **Models**: Located in `app/Models` with Relationships defined.
- **Requests**: Validation logic in `app/Http/Requests`.
- **Resources**: API Responses using `JsonResource` in `app/Http/Resources`.
- **Policy/Auth**: Uses Laravel Sanctum and a custom Gate `admin-access`.
