#!/bin/zsh
set -euo pipefail

ROOT="/Users/johnychnouda/Desktop/NeoTalab"
BACKEND="$ROOT/backend"
LOG="/tmp/neotalab-tunnel.log"

echo "== NeoTalab local stack =="

# Free old listeners if needed
if lsof -nP -iTCP:8001 -sTCP:LISTEN >/dev/null 2>&1; then
  echo "API already listening on :8001"
else
  echo "Starting API on :8001..."
  osascript -e "tell application \"Terminal\" to do script \"cd '$BACKEND' && php artisan serve --port=8001 --host=127.0.0.1\""
fi

echo "Starting queue worker..."
osascript -e "tell application \"Terminal\" to do script \"cd '$BACKEND' && php artisan queue:work --tries=3 --timeout=90\""

echo "Starting Cloudflare tunnel..."
rm -f "$LOG"
osascript -e "tell application \"Terminal\" to do script \"cloudflared tunnel --url http://127.0.0.1:8001 | tee $LOG\""

echo "Waiting for tunnel URL..."
URL=""
for i in {1..30}; do
  if [[ -f "$LOG" ]]; then
    URL=$(rg -o 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" 2>/dev/null | rg -v 'api\.trycloudflare\.com' | tail -1 || true)
    if [[ -n "$URL" ]]; then
      break
    fi
  fi
  sleep 1
done

if [[ -z "$URL" ]]; then
  echo "Tunnel URL not ready yet. Check the cloudflared Terminal window / $LOG"
  exit 1
fi

echo ""
echo "Tunnel is up: $URL"
echo "Set Vercel env NEXT_PUBLIC_API_URL to:"
echo "  ${URL}/api/v1"
echo ""
echo "Then Redeploy on Vercel, and update Meta webhook callback to:"
echo "  ${URL}/api/v1/webhooks/whatsapp"
echo ""
open "https://vercel.com/dashboard"
