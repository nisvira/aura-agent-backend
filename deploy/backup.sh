#!/usr/bin/env bash
# Daily backup of the Aura backend (cron: 30 2 * * *).
#   db-<stamp>.sql.gz       MySQL database aura_agent_db (consistent InnoDB snapshot)
#   storage-<stamp>.tar.gz  Laravel storage volume (logs, framework files)
#   env-<stamp>             .env (secrets — the folder is mode 700, files 600)
#   SHA256SUMS-<stamp>      checksums of the three files above
# Files older than KEEP_DAYS are deleted. Restore steps: deploy/RESTORE.md
set -euo pipefail

APP_DIR="${APP_DIR:-/home/yruadmin/aura-agent-backend}"
DEST="${BACKUP_DIR:-/home/yruadmin/backups/aura-backend}"
KEEP_DAYS="${KEEP_DAYS:-14}"
DB_CONTAINER="${DB_CONTAINER:-mysql_database}"
STORAGE_VOLUME="${STORAGE_VOLUME:-aura-agent-backend_storage}"
STAMP="$(date +%Y%m%d-%H%M%S)"

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

DB_USERNAME="$(grep -E '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
DB_DATABASE="$(grep -E '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"

echo "[$(date -Is)] backup $STAMP start"

# 1. Database. --no-tablespaces: the app user has no PROCESS privilege.
docker exec -e MYSQL_PWD="$DB_PASSWORD" "$DB_CONTAINER" \
    mysqldump --single-transaction --routines --triggers --no-tablespaces \
    -u"$DB_USERNAME" "$DB_DATABASE" | gzip > "$DEST/db-$STAMP.sql.gz"

# 2. Storage volume, read-only, using the backend image (no extra image to pull).
docker run --rm --user "$(id -u):$(id -g)" --entrypoint tar \
    -v "$STORAGE_VOLUME":/data:ro -v "$DEST":/backup \
    aura-agent-backend:latest czf "/backup/storage-$STAMP.tar.gz" -C /data .

# 3. Environment file.
cp "$APP_DIR/.env" "$DEST/env-$STAMP"

# Verify the archives are readable, then record checksums.
gzip -t "$DEST/db-$STAMP.sql.gz"
tar tzf "$DEST/storage-$STAMP.tar.gz" > /dev/null
(cd "$DEST" && sha256sum "db-$STAMP.sql.gz" "storage-$STAMP.tar.gz" "env-$STAMP" > "SHA256SUMS-$STAMP")

find "$DEST" -maxdepth 1 -type f \( -name 'db-*' -o -name 'storage-*' -o -name 'env-*' -o -name 'SHA256SUMS-*' \) \
    -mtime +"$KEEP_DAYS" -delete

echo "[$(date -Is)] backup $STAMP done: $(du -ch "$DEST"/*-"$STAMP"* | tail -1 | cut -f1)"
