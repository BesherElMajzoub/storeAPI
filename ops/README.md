# Production (api.otantikqueen.com)

CloudPanel site on Contabo, SSH alias `queen` (user `apiqueen`, no sudo).
App lives in `~/htdocs/api.otantikqueen.com`, served from its `public/` by PHP-FPM **8.5**.

## Deploy

Push to `main`, then:

```bash
ssh queen 'bash ~/htdocs/api.otantikqueen.com/ops/deploy-production.sh'
```

It fast-forwards to `origin/main`, runs `composer install --no-dev`, `migrate --force`,
the idempotent `ProductionSeeder` (roles and permissions only, never demo data),
rebuilds caches, syncs the crontab from [`ops/crontab`](crontab) and restarts the
queue workers.

## Background processes

No Supervisor (no root), so cron keeps everything alive (see [`ops/crontab`](crontab)):

- `schedule:run` every minute.
- Worker for `notifications,default` and a separate worker for `media`, each guarded
  by `flock` so only one runs; a dead or restarted worker is back within a minute.

Check them: `ssh queen 'pgrep -af queue:work; crontab -l'`.

## Secrets

`.env` exists only on the server (`~/htdocs/api.otantikqueen.com/.env`). Edit it there,
then re-run the deploy script (or `php8.5 artisan config:cache && php8.5 artisan queue:restart`).
Verify with `php8.5 artisan app:production-readiness`.
