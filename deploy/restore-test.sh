#!/usr/bin/env bash
# Restore drill: load a backup into a throwaway MySQL container (never the real
# database) and compare row counts table by table with production.
#   deploy/restore-test.sh                       # newest backup
#   deploy/restore-test.sh /path/db-<stamp>.sql.gz
set -euo pipefail

APP_DIR="${APP_DIR:-/home/yruadmin/aura-agent-backend}"
DEST="${BACKUP_DIR:-/home/yruadmin/backups/aura-backend}"
DUMP="${1:-$(ls -1t "$DEST"/db-*.sql.gz | head -1)}"
STAMP="$(basename "$DUMP" .sql.gz | sed 's/^db-//')"
TEST_CONTAINER="aura-restore-test"
TEST_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"

DB_USERNAME="$(grep -E '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
DB_DATABASE="$(grep -E '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"')"

cleanup() { docker rm -f "$TEST_CONTAINER" > /dev/null 2>&1 || true; }
trap cleanup EXIT

echo "restore test of $DUMP"
(cd "$DEST" && sha256sum -c --quiet "SHA256SUMS-$STAMP") && echo "checksums OK"

docker run -d --name "$TEST_CONTAINER" -e MYSQL_ROOT_PASSWORD="$TEST_PASSWORD" -e MYSQL_DATABASE=restore_test mysql:8.0 > /dev/null
for _ in $(seq 1 60); do
    docker exec -e MYSQL_PWD="$TEST_PASSWORD" "$TEST_CONTAINER" mysql -uroot -e 'SELECT 1' restore_test > /dev/null 2>&1 && break
    sleep 2
done

started=$(date +%s)
gunzip -c "$DUMP" | docker exec -i -e MYSQL_PWD="$TEST_PASSWORD" "$TEST_CONTAINER" mysql -uroot restore_test
echo "restored in $(( $(date +%s) - started ))s"

count_sql="SELECT CONCAT(table_name) FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name"
tables=$(docker exec -e MYSQL_PWD="$TEST_PASSWORD" "$TEST_CONTAINER" mysql -uroot -N -e "$count_sql" restore_test)

printf '%-28s %10s %10s\n' table backup production
fail=0
for t in $tables; do
    restored=$(docker exec -e MYSQL_PWD="$TEST_PASSWORD" "$TEST_CONTAINER" mysql -uroot -N -e "SELECT COUNT(*) FROM \`$t\`" restore_test)
    live=$(docker exec -e MYSQL_PWD="$DB_PASSWORD" mysql_database mysql -u"$DB_USERNAME" -N -e "SELECT COUNT(*) FROM \`$t\`" "$DB_DATABASE")
    mark=""; [ "$restored" = "$live" ] || mark="  (changed since backup)"
    printf '%-28s %10s %10s%s\n' "$t" "$restored" "$live" "$mark"
    [ -n "$restored" ] || fail=1
done

[ "$fail" = 0 ] && echo "RESTORE TEST PASSED: $(echo "$tables" | wc -w) tables restored" || { echo "RESTORE TEST FAILED"; exit 1; }
