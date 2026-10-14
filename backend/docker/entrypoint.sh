#!/bin/sh
set -e

php artisan config:cache
php artisan route:cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

# Scheduler (nightly reports, token pruning) runs inside the API service, so no extra Railway
# service is needed. The queue service uses its own start command and does not run this script.
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
  (
    while true; do
      php artisan schedule:run --no-interaction || true
      sleep 60
    done
  ) &
fi

# FrankenPHP serves public/ with several PHP threads, so one slow request never blocks the others.
exec frankenphp php-server --root public/ --listen ":${PORT:-8000}"
