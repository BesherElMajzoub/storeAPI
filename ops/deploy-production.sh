#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-$HOME/htdocs/apis.otantikqueen.com}"
PENDING_MARKER="${PENDING_MARKER:-$HOME/DEPLOYMENT_PENDING_storeapi}"

if [[ -f "$PENDING_MARKER" ]]; then
    echo "Deployment is blocked: production database and secrets are still pending." >&2
    exit 1
fi

cd "$APP_DIR"

git fetch origin main
git checkout main
git merge --ff-only origin/main

composer install \
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

php artisan down --retry=15 || true
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan storage:link --force
php artisan l5-swagger:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

clpctl system:permissions:reset --directories=770 --files=660 --path="$APP_DIR"

php artisan up
trap - EXIT

php artisan route:list --path=api/v1/health
echo "Deployed $(git rev-parse --short=7 HEAD) successfully."
