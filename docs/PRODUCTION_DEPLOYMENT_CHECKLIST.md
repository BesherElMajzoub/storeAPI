# Production deployment and evidence checklist

This checklist must be run on the actual production/staging infrastructure. Do not paste secret values into tickets, logs, screenshots, or this document.

## 1. Before deployment

- [ ] Revoke and rotate the previously committed Telegram bot credential.
- [ ] Rotate mail credentials that appeared in Git history.
- [ ] Confirm production has unique DB, Stripe live, Stripe webhook, EasyPost, mail, and application secrets.
- [ ] Confirm `.env` is outside the public document root and is not tracked by Git.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, `TELESCOPE_ENABLED=false`, `L5_SWAGGER_GENERATE_ALWAYS=false`.
- [ ] Set `APP_URL=https://apis.otantikqueen.com` and `FRONTEND_URL=https://otantikqueen.com`.
- [ ] Use a durable queue connection, not `sync`; configure a supervised worker for the default, mail, and `notifications` queues.
- [ ] Configure the scheduler to run every minute and alert when its heartbeat stops.
- [ ] For Nginx/PHP-FPM, deny script execution under `/storage` and set the request-body limit to 45 MB or lower. Apache deployments also receive `storage/app/public/.htaccess`.

## 2. Backup and restore evidence

- [ ] Take an encrypted database backup and store it off-server.
- [ ] Restore that backup into a new isolated database instance.
- [ ] Run migrations/status and read-only smoke queries against the restored instance.
- [ ] Record backup timestamp, restore timestamp, object location/retention, operator, and result in the deployment ticket.
- [ ] Schedule daily backups plus retention/expiry alerts.

Do not mark backup readiness complete after backup creation alone; the restore must succeed.

## 3. Deploy

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan l5-swagger:generate
php artisan app:production-readiness
```

The final command is read-only and must show PASS for every row. It checks environment/debug/HTTPS URLs, application key, token expiry, Stripe live/webhook secrets, EasyPost, queue, mail, Telescope, UTC, utf8mb4, known demo accounts, and test/debug routes.

## 4. Demo-account removal

- [ ] Search production for the known demo addresses identified by `app:production-readiness`.
- [ ] Take a backup before mutation.
- [ ] Delete accounts with no required business history; for accounts tied to retained orders, replace the shared address/password with a unique controlled identity according to the retention policy.
- [ ] Re-run `php artisan app:production-readiness` and confirm `No known demo accounts: PASS`.

The repository audit cannot perform or certify this production-data operation. The local development database contains known demo data by design; production seeders now refuse user creation.

## 5. Live HTTP/security checks

```bash
curl -I http://apis.otantikqueen.com/api/v1/products
curl -I https://apis.otantikqueen.com/api/v1/products
curl -i -X OPTIONS https://apis.otantikqueen.com/api/v1/products \
  -H "Origin: https://otantikqueen.com" \
  -H "Access-Control-Request-Method: GET"
curl -i -X OPTIONS https://apis.otantikqueen.com/api/v1/products \
  -H "Origin: https://attacker.example" \
  -H "Access-Control-Request-Method: GET"
```

- [ ] HTTP redirects to HTTPS without serving application content.
- [ ] Certificate is valid for `apis.otantikqueen.com`, including chain and expiry.
- [ ] HTTPS API response includes HSTS and `X-Content-Type-Options: nosniff`.
- [ ] Official storefront origin is allowed.
- [ ] Unlisted origin is not echoed/allowed and no wildcard is returned.
- [ ] A controlled 500 response in staging exposes no exception, trace, SQL, or path.

## 6. Payments

- [ ] In Stripe test mode on the deployed stack: create order → checkout → signed webhook → paid order → exactly one confirmation email.
- [ ] Replay the event; state and email count stay unchanged.
- [ ] Send/observe an expired session; order is cancelled and reserved stock is released once.
- [ ] Exercise payment cancellation/failure and verify the order does not become paid.
- [ ] Exercise a partial refund; `refunded_amount` changes while order remains paid and stock is not restocked.
- [ ] Exercise a full admin refund; order becomes refunded and stock is restored once.
- [ ] Confirm the production key begins with the live-key prefix and the registered webhook secret belongs to this endpoint/account. Never record the values.

## 7. Shipping

- [ ] Verify a deliverable US address and obtain rates.
- [ ] Verify an international address and obtain rates/customs-compatible output where applicable.
- [ ] Purchase a test label, receive a signed tracking webhook, and retrieve tracking through the customer order endpoint.
- [ ] Confirm missing/invalid EasyPost webhook signatures fail closed.
- [ ] Confirm the customer response includes `tracking_number` and no other customer's data.

## 8. Monitoring and operations

- [ ] Configure API uptime monitoring on a stable public endpoint.
- [ ] Configure exception/error-rate alerting and redact authorization/payment data.
- [ ] Trigger one controlled alert and record successful delivery.
- [ ] Confirm daily log rotation and retention.
- [ ] Stop a queue worker in staging and confirm supervision restarts it/alerts.
- [ ] Stop scheduler heartbeat in staging and confirm an alert.
- [ ] Monitor failed jobs and test retry/recovery.

## Final sign-off

- Deployment commit: ____________________
- Environment: ____________________
- Operator/date: ____________________
- Backup restore evidence: ____________________
- Monitoring alert evidence: ____________________
- Stripe deployed-flow evidence: ____________________
- EasyPost real-address evidence: ____________________
- `app:production-readiness` result: ____________________
- Final decision: [ ] approved  [ ] rejected
