#!/usr/bin/env bash
set -euo pipefail
BASE="http://localhost:8888"
COOKIE_JAR="curl-session.txt"
rm -f "$COOKIE_JAR"

log(){ printf '\n### %s\n' "$1"; }

# 1. Captura CSRF
token_json=$(curl -sS -c "$COOKIE_JAR" "$BASE/?route=csrf")
log "Raw CSRF response: $token_json"
export TOKEN_JSON="$token_json"
csrf=$(python3 - <<'PY'
import json, os
print(json.loads(os.environ['TOKEN_JSON']).get('csrf',''))
PY
)
if [[ -z "$csrf" ]]; then
  echo "Falha ao obter CSRF" >&2
  exit 1
fi
log "Usando CSRF: $csrf"

# 2. Adiciona produto 1 ao carrinho
add_resp=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-CSRF-Token: $csrf" \
  -d "id=1&csrf=$csrf" \
  "$BASE/?route=add_cart")
log "Resposta add_cart: $add_resp"

# 3. Exibe cabeçalho carrinho
log "Trecho do carrinho"
curl -sS -b "$COOKIE_JAR" "$BASE/?route=cart" | head -n 40

# 4. Envia pedido (forma de pagamento square)
log "POST place_order"
place_headers=$(mktemp)
place_body=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -D "$place_headers" -X POST \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-CSRF-Token: $csrf" \
  -d "csrf=$csrf" \
  -d "name=Teste QA" \
  -d "email=cliente1@teste.com" \
  -d "phone=11999990000" \
  -d "address=Rua Teste, 123" \
  -d "city=Sao Paulo" \
  -d "state=SP" \
  -d "zipcode=01000-000" \
  -d "payment=square" \
  "$BASE/?route=place_order")
cat "$place_headers"
log "Body place_order (início)"
printf '%s\n' "$place_body" | head -n 40

# 5. Se houve redirect para sucesso, segue
order_url=$(awk '/^Location:/{print $2}' "$place_headers" | tr -d '\r')
if [[ -n "$order_url" ]]; then
  log "Order success snippet ($order_url)"
  curl -sS -b "$COOKIE_JAR" "$BASE/$order_url" | head -n 60
fi

# 6. Painel admin (login page)
log "Admin login snippet"
curl -sS "$BASE/admin.php" | head -n 40

