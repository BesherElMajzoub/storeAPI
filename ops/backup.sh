#!/usr/bin/env bash
#
# Local backups for api.otantikqueen.com (run nightly from ops/crontab).
#
#   database : gzip'd mysqldump every run          -> ~/backups/databases, kept 14 days
#   files    : uploads + shipping labels, Sundays  -> ~/backups/files, kept 4 weeks
#
# These stay on THIS server, so they do not survive losing it: copy
# ~/backups off-site too (Contabo snapshot / Auto Backup, S3, your own machine).
#
# Restore the database:
#   gunzip -c ~/backups/databases/<file>.sql.gz | mysql -u<user> -p <database>

set -Eeuo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
DB_KEEP_DAYS="${DB_KEEP_DAYS:-14}"
FILES_KEEP_DAYS="${FILES_KEEP_DAYS:-28}"

env_value() {
    local value
    value="$(grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2-)"
    value="${value%\"}"; value="${value#\"}"
    printf '%s' "$value"
}

DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
# Passed through the environment so it never shows up in `ps`.
export MYSQL_PWD="$(env_value DB_PASSWORD)"

stamp="$(date +%F_%H%M)"
mkdir -p "$BACKUP_DIR/databases" "$BACKUP_DIR/files"

dump="$BACKUP_DIR/databases/${DB_NAME}-${stamp}.sql.gz"
if ! mysqldump -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"$DB_USER" \
    --single-transaction --routines --triggers --no-tablespaces "$DB_NAME" | gzip > "$dump"; then
    rm -f "$dump"
    echo "$(date -Is) database dump FAILED" >&2
    exit 1
fi
gzip -t "$dump"
echo "$(date -Is) database: $(basename "$dump") $(du -h "$dump" | cut -f1)"

# Sunday: uploaded media and shipping label PDFs.
if [[ "$(date +%u)" == "7" ]]; then
    files="$BACKUP_DIR/files/storage-${stamp}.tar.gz"
    tar -czf "$files" -C "$APP_DIR/storage/app" --ignore-failed-read public private 2>/dev/null || true
    echo "$(date -Is) files: $(basename "$files") $(du -h "$files" | cut -f1)"
fi

find "$BACKUP_DIR/databases" -name '*.sql.gz' -mtime +"$DB_KEEP_DAYS" -delete
find "$BACKUP_DIR/files" -name '*.tar.gz' -mtime +"$FILES_KEEP_DAYS" -delete
