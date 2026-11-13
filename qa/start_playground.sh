#!/bin/bash
# Boots the NeoTalab WhatsApp playground (lab DB + fake Meta + UI). Never production.
set -e
QA="$(cd "$(dirname "$0")" && pwd)"
LAB="${QA_LAB:-$QA/.lab}"
cd "$QA"

if [ ! -f "$LAB/lab.env" ] || [ ! -f "$LAB/lab.sqlite" ]; then
  echo "→ Creating lab…"
  bash "$QA/setup_lab.sh"
fi

# shellcheck disable=SC1091
set -a; source "$LAB/lab.env"; set +a
export QA_LAB="$LAB" QA_ENVFILE="$LAB/lab.env" QA_STATE="$LAB/state.json" QA_GRAPHLOG="$LAB/graph.jsonl" QA_LABSH="$LAB/lab.sh"

need_seed=0
if [ ! -f "$LAB/state.json" ]; then
  need_seed=1
else
  python3 -c 'import json,sys; s=json.load(open(sys.argv[1])); sys.exit(0 if s.get("shops") else 1)' "$LAB/state.json" || need_seed=1
fi

# Mock Graph (fake Meta)
if ! curl -sf "http://127.0.0.1:${MOCK_GRAPH_PORT:-8019}/" >/dev/null 2>&1; then
  echo "→ Mock Graph on :${MOCK_GRAPH_PORT:-8019}"
  : > "$LAB/graph.jsonl"
  nohup python3 "$QA/mock_graph.py" "$LAB/graph.jsonl" >/dev/null 2>&1 &
  echo $! > "$LAB/mock_graph.pid"
  disown || true
  sleep 0.4
fi

# Lab API
if ! curl -sf "http://127.0.0.1:8011/up" >/dev/null 2>&1; then
  echo "→ Lab API on :8011"
  nohup "$LAB/lab.sh" php artisan serve --host=127.0.0.1 --port=8011 \
    >>"$LAB/api.log" 2>&1 &
  echo $! > "$LAB/api.pid"
  disown || true
  for i in $(seq 1 40); do
    curl -sf "http://127.0.0.1:8011/up" >/dev/null 2>&1 && break
    sleep 0.25
  done
fi

# Lab queue worker (must use lab.env). nohup so it survives the starter shell exiting.
if [ -f "$LAB/queue.pid" ] && kill -0 "$(cat "$LAB/queue.pid")" 2>/dev/null; then
  echo "→ Queue worker already running (pid $(cat "$LAB/queue.pid"))"
else
  echo "→ Queue worker (lab)"
  nohup "$LAB/lab.sh" php artisan queue:work --sleep=1 --tries=3 --timeout=90 \
    >>"$LAB/queue.log" 2>&1 &
  echo $! > "$LAB/queue.pid"
  disown || true
fi

if [ "$need_seed" = 1 ]; then
  echo "→ Seeding shops + menus…"
  python3 "$QA/s01_setup.py"
  python3 "$QA/s02_catalog.py"
fi

echo "→ Playground coverage seeds (Whish, pickup, kg, days, staff-check)…"
python3 "$QA/seed_playground.py"

echo "→ Playground UI on http://127.0.0.1:8025/"
exec python3 "$QA/playground/server.py"
