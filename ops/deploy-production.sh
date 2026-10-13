#!/usr/bin/env bash
#
# Production deploy for api.otantikqueen.com (CloudPanel, site user without sudo).
#
#   ssh queen 'bash ~/htdocs/api.otantikqueen.com/ops/deploy-production.sh'
#
# Pulls origin/main, installs dependencies, migrates (never seeds), rebuilds
# caches, syncs the crontab block (scheduler + queue workers) and restarts the
# workers. Safe to re-run.

set -Eeuo pipefail

# Files must stay group-writable: PHP-FPM runs as the site owner, deploys and
# cron run as the SSH user, both in the same group.
umask 007

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
# Must match the PHP version selected for the site in CloudPanel.
PHP_BIN="${PHP_BIN:-php8.5}"
BRANCH="${BRANCH:-main}"
HEALTH_URL="${HEALTH_URL:-https://api.otantikqueen.com/api/v1/health}"
PENDING_MARKER="${PENDING_MARKER:-$HOME/DEPLOYMENT_PENDING_storeapi}"

if [[ -f "$PENDING_MARKER" ]]; then
    echo "Deployment is blocked: $PENDING_MARKER exists." >&2
    exit 1
fi

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    echo "Missing $APP_DIR/.env — create it from .env.example first." >&2
    exit 1
fi

exec 9>"$APP_DIR/storage/framework/deploy.lock"
if ! flock -n 9; then
    echo "Another deployment is already running." >&2
    exit 1
fi

artisan() { "$PHP_BIN" artisan "$@"; }

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"

"$PHP_BIN" "$(command -v composer)" install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

set_env() {
    local key="$1"
    local value="$2"
    local escaped

    escaped="$(printf '%s' "$value" | sed 's/[&|]/\\&/g')"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env APP_VERSION "$(git rev-parse --short=7 HEAD)"
set_env APP_DEPLOYED_AT "$(date -u +%Y-%m-%dT%H:%M:%SZ)"

artisan down --retry=15 || true
trap 'artisan up >/dev/null 2>&1 || true' EXIT

artisan migrate --force --no-interaction
artisan optimize:clear
artisan storage:link --force
artisan l5-swagger:generate
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache

# Replace only our block in the crontab, keeping any entries added by hand.
CRON_BLOCK="$(sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP__|$(command -v "$PHP_BIN")|g" ops/crontab)"
{
    crontab -l 2>/dev/null | sed '/^# BEGIN storeapi/,/^# END storeapi/d' || true
    printf '%s\n' "$CRON_BLOCK"
} | crontab -

# Workers exit after their current job; cron starts fresh ones within a minute.
artisan queue:restart

artisan up
trap - EXIT

if command -v curl >/dev/null; then
    echo "Health: $(curl -fsS "$HEALTH_URL" || echo 'FAILED')"
fi
echo "Deployed $(git rev-parse --short=7 HEAD) successfully."
