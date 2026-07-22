#!/usr/bin/env bash
# Writes WhatsApp Meta credentials into backend/.env
# Usage:
#   WHATSAPP_META_APP_ID=123 \
#   WHATSAPP_APP_SECRET=secret \
#   WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=456 \
#   WHATSAPP_PLATFORM_ACCESS_TOKEN=EAAxxx \
#   ./scripts/configure-whatsapp-env.sh
#
# WHATSAPP_VERIFY_TOKEN is kept from .env unless WHATSAPP_VERIFY_TOKEN is set.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"

required=(WHATSAPP_META_APP_ID WHATSAPP_APP_SECRET WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID WHATSAPP_PLATFORM_ACCESS_TOKEN)
missing=()
for key in "${required[@]}"; do
  if [[ -z "${!key:-}" ]]; then
    missing+=("$key")
  fi
done

if (( ${#missing[@]} > 0 )); then
  echo "Missing required environment variables:" >&2
  printf '  %s\n' "${missing[@]}" >&2
  echo "" >&2
  echo "Get them from https://developers.facebook.com/apps/ → your app → WhatsApp" >&2
  echo "Then re-run with all four variables set." >&2
  exit 1
fi

VERIFY="${WHATSAPP_VERIFY_TOKEN:-neotalab-verify}"

python3 - <<PY
from pathlib import Path
import re

env_path = Path("$ENV_FILE")
text = env_path.read_text() if env_path.exists() else ""

updates = {
    "WHATSAPP_META_APP_ID": "$WHATSAPP_META_APP_ID",
    "WHATSAPP_APP_SECRET": "$WHATSAPP_APP_SECRET",
    "WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID": "$WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID",
    "WHATSAPP_PLATFORM_ACCESS_TOKEN": "$WHATSAPP_PLATFORM_ACCESS_TOKEN",
    "WHATSAPP_VERIFY_TOKEN": "$VERIFY",
}

for key, value in updates.items():
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.M)
    line = f"{key}={value}"
    if pattern.search(text):
        text = pattern.sub(line, text)
    else:
        if text and not text.endswith("\n"):
            text += "\n"
        text += line + "\n"

env_path.write_text(text)
print(f"Updated {env_path}")
PY

cd "$ROOT"
php artisan config:clear
echo "Done. Verify with: php artisan tinker --execute=\"echo config('whatsapp.meta_app_id');\""
