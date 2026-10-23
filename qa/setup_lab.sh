#!/bin/bash
# Creates the QA lab: a throwaway SQLite database, fake AI, a stand-in for Meta's WhatsApp API,
# random lab logins. Never touches backend/.env's database or any real service.
set -e
QA="$(cd "$(dirname "$0")" && pwd)"
LAB="${QA_LAB:-$QA/.lab}"
mkdir -p "$LAB"
if [ ! -f "$LAB/lab.env" ]; then
  cat > "$LAB/lab.env" <<ENV
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8011
FRONTEND_URL=http://localhost:3011
CORS_ALLOWED_ORIGINS=http://localhost:3011,http://127.0.0.1:3011
DB_CONNECTION=sqlite
DB_DATABASE=$LAB/lab.sqlite
DB_URL=
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=array
MAIL_MAILER=log
RESEND_API_KEY=
AI_DRIVER=fake
OPENAI_API_KEY=
GOOGLE_MAPS_API_KEY=
SENTRY_LARAVEL_DSN=
BACKUP_S3_BUCKET=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
WHATSAPP_GRAPH_BASE=http://127.0.0.1:8019
WHATSAPP_APP_SECRET=$(openssl rand -hex 16)
WHATSAPP_VERIFY_SIGNATURE=true
WHATSAPP_VERIFY_TOKEN=lab-verify
WHATSAPP_PLATFORM_ACCESS_TOKEN=lab-platform-token
WHATSAPP_META_APP_ID=
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=
OWNER_EMAIL=owner@qa.test
OWNER_PASSWORD=$(openssl rand -hex 12)
PHP_CLI_SERVER_WORKERS=6
ENV
  chmod 600 "$LAB/lab.env"
fi
cat > "$LAB/lab.sh" <<SH
#!/bin/bash
# Runs a command in backend/ with the lab environment.
set -a; source "$LAB/lab.env"; set +a
cd "$QA/../backend"
exec "\$@"
SH
chmod +x "$LAB/lab.sh"
: > "$LAB/graph.jsonl"; : > "$LAB/lab.sqlite"; rm -f "$LAB/state.json" "$LAB/results.jsonl"
"$LAB/lab.sh" php artisan migrate:fresh --seed --force
"$LAB/lab.sh" php artisan tinker --execute='DB::select("PRAGMA journal_mode=WAL");'
echo "Lab ready in $LAB"
