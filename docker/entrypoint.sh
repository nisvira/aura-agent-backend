#!/bin/sh
set -e

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Cache config/routes/views from the mounted .env; re-run on every start so .env edits apply after a restart.
php artisan optimize

exec "$@"
