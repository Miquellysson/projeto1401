#!/usr/bin/env bash
set -euo pipefail
BASE="http://localhost:8888"
COOKIE_JAR="curl-session.txt"
rm -f "$COOKIE_JAR"

log(){ printf '\n### %s\n' "$1"; }

log "Fetching CSRF token"
raw=$(curl -sS -c "$COOKIE_JAR" "$BASE/?route=csrf")
log "Raw CSRF response: $raw"
export RAW_CSRF="$raw"
csrf=$(python3 -c 'import json,os; print(json.loads(os.environ["RAW_CSRF"]).get("csrf",""))')
if [[ -z "$csrf" ]]; then
  echo "Failed to capture CSRF token" >&2
  exit 1
fi
log "Using CSRF token: $csrf"

log "Adding product 1 to cart"
add_resp=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-CSRF-Token: $csrf" \
  -d "id=1&csrf=$csrf" \
  "$BASE/?route=add_cart")
log "Response: $add_resp"

log "Fetching cart page snippet"
curl -sS -b "$COOKIE_JAR" "$BASE/?route=cart" | head -n 60

