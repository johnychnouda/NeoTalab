#!/bin/sh
# Start command of the Railway queue service: `docker/queue.sh`.
# Runs QUEUE_WORKERS workers side by side (default 20) so one slow AI reply never makes other
# customers wait. Each worker restarts every hour (fresh memory) and after a crash.
set -e

php artisan config:cache

# Railway stops the service with SIGTERM: pass it on so each worker finishes its current message.
trap 'trap "" TERM INT; kill -TERM 0; wait; exit 0' TERM INT

i=0
while [ "$i" -lt "${QUEUE_WORKERS:-20}" ]; do
  (
    trap 'exit 0' TERM INT
    while true; do
      # --timeout stays below the database queue retry_after (150s) so a slow voice note
      # cannot be started a second time while the first attempt is still running.
      php artisan queue:work --tries=3 --timeout=75 --max-time=3600 || true
      sleep 1
    done
  ) &
  i=$((i + 1))
done

wait
