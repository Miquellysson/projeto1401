#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8888}"
SESSION_DIR="${TMPDIR:-/tmp}/qa_sessions"
mkdir -p "$SESSION_DIR"

ADMIN_EMAIL="${ADMIN_EMAIL:-ml@mmlins.combr}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-mkcd61la}"

log() { printf '\n[%s] %s\n' "$(date +%H:%M:%S)" "$1"; }
new_cookie() { mktemp "$SESSION_DIR/cookie.XXXXXX"; }

get_json_value() {
  local json="$1" key="$2"
  JSON="$json" KEY="$key" python3 - <<'PY'
import json, os
try:
    print(json.loads(os.environ["JSON"]).get(os.environ["KEY"], ""))
except Exception:
    print("")
PY
}

fetch_csrf() {
  local cookie="$1" response
  response=$(curl -sS -c "$cookie" "$BASE_URL/?route=csrf")
  log "CSRF -> $response"
  local csrf
  csrf=$(get_json_value "$response" csrf)
  if [[ -z "$csrf" ]]; then
    log "Falha ao obter CSRF; interrompendo fluxo"
    return 1
  fi
  printf '%s\n' "$csrf"
}

add_product() {
  local cookie="$1" csrf="$2" product_id="${3:-1}"
  curl -sS -b "$cookie" -c "$cookie" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "X-CSRF-Token: $csrf" \
    --data-urlencode "id=$product_id" \
    --data-urlencode "csrf=$csrf" \
    "$BASE_URL/?route=add_cart"
}

place_order() {
  local cookie="$1" csrf="$2" payment="$3"
  local headers body
  headers=$(mktemp "$SESSION_DIR/headers.XXXX")
  body=$(curl -sS -b "$cookie" -c "$cookie" -D "$headers" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "X-CSRF-Token: $csrf" \
    --data-urlencode "csrf=$csrf" \
    --data-urlencode "name=Teste QA" \
    --data-urlencode "email=cliente1@teste.com" \
    --data-urlencode "phone=11999990000" \
    --data-urlencode "address=Rua Teste, 123" \
    --data-urlencode "city=São Paulo" \
    --data-urlencode "state=SP" \
    --data-urlencode "zipcode=01000-000" \
    --data-urlencode "payment=$payment" \
    "$BASE_URL/?route=place_order")
  local exit_code=$?
  if [[ $exit_code -ne 0 ]]; then
    log "Falha ao chamar place_order"
  fi
  local location
  location=$(awk '/^Location:/{print $2}' "$headers" | tr -d '\r')
  rm -f "$headers"
  printf '%s\n' "$location"
  printf '%s\n' "$body"
  return $exit_code
}

show_cart() {
  local cookie="$1"
  curl -sS -b "$cookie" "$BASE_URL/?route=cart" | head -n 40
}

order_success() {
  local cookie="$1" url="$2"
  curl -sS -b "$cookie" "$BASE_URL/$url" | head -n 60
}

admin_login_and_orders() {
  local cookie login_html csrf
  cookie=$(new_cookie)
  trap 'rm -f "$cookie"' RETURN

  log "Admin login page"
  login_html=$(curl -sS -c "$cookie" "$BASE_URL/admin.php") || { log "Falha ao acessar admin.php"; return; }
  csrf=$(echo "$login_html" | sed -n 's/.*name="csrf" value="\([^\"]*\)".*/\1/p' | head -n1)
  if [[ -z "$csrf" ]]; then
    log "Não encontrei CSRF no admin.php"
    return
  fi

  log "Autenticando admin"
  curl -sS -L -b "$cookie" -c "$cookie" \
    --data-urlencode "csrf=$csrf" \
    --data-urlencode "email=$ADMIN_EMAIL" \
    --data-urlencode "password=$ADMIN_PASSWORD" \
    "$BASE_URL/admin.php" > /dev/null || { log "Falha no POST admin"; return; }

  log "Listando pedidos (orders.php)"
  curl -sS -b "$cookie" "$BASE_URL/orders.php" | head -n 60
}

checkout_square() {
  local cookie csrf add_resp location body exit_code
  cookie=$(new_cookie)
  trap 'rm -f "$cookie"' RETURN

  if ! csrf=$(fetch_csrf "$cookie"); then
    log "Abortando checkout_square"
    return
  fi
  log "Token CSRF: $csrf"

  add_resp=$(add_product "$cookie" "$csrf" 1) || { log "Falha em add_cart"; }
  log "add_cart -> $add_resp"
  if [[ $add_resp == *"400 Bad Request"* ]]; then
    log "Servidor retornou 400 em add_cart; verifique logs"
    return
  fi

  log "Carrinho (trecho)"
  show_cart "$cookie"

  read -r location body < <(place_order "$cookie" "$csrf" "square")
  exit_code=$?
  log "place_order Location: $location"
  log "place_order body (trecho)"
  printf '%s\n' "$body" | head -n 20

  if [[ $exit_code -ne 0 ]]; then
    log "place_order falhou"
    return
  fi

  if [[ -n "$location" ]]; then
    log "order_success"
    order_success "$cookie" "$location"
  fi
}

checkout_zelle() {
  local cookie csrf add_resp location body exit_code
  cookie=$(new_cookie)
  trap 'rm -f "$cookie"' RETURN

  if ! csrf=$(fetch_csrf "$cookie"); then
    log "Abortando checkout_zelle"
    return
  fi
  log "Token CSRF: $csrf"

  add_resp=$(add_product "$cookie" "$csrf" 1) || { log "Falha em add_cart"; }
  log "add_cart -> $add_resp"
  if [[ $add_resp == *"400 Bad Request"* ]]; then
    log "Servidor retornou 400 em add_cart; verifique logs"
    return
  fi

  log "Carrinho (trecho)"
  show_cart "$cookie"

  read -r location body < <(place_order "$cookie" "$csrf" "zelle")
  exit_code=$?
  log "place_order Location: $location"
  log "place_order body (trecho)"
  printf '%s\n' "$body" | head -n 20

  if [[ $exit_code -ne 0 ]]; then
    log "place_order falhou"
    return
  fi

  if [[ -n "$location" ]]; then
    log "order_success"
    order_success "$cookie" "$location"
  fi
}

ping_site() {
  log "HEAD /"
  curl -I "$BASE_URL/"
  log "HEAD manifest.php"
  curl -I "$BASE_URL/manifest.php"
  log "HEAD sw.js"
  curl -I "$BASE_URL/sw.js"
}

usage() {
  cat <<USAGE
Uso: ./qa_suite.sh <comando>

Comandos:
  ping              - Verifica /, manifest.php, sw.js
  checkout-square   - Adiciona produto e finaliza pedido com Square
  checkout-zelle    - Adiciona produto e finaliza pedido com Zelle
  admin-orders      - Faz login no admin e lista pedidos
  all               - Executa ping, checkout-square, checkout-zelle, admin-orders
USAGE
}

case "${1:-help}" in
  ping) ping_site ;;
  checkout-square) checkout_square ;;
  checkout-zelle) checkout_zelle ;;
  admin-orders) admin_login_and_orders ;;
  all)
    ping_site
    checkout_square
    checkout_zelle
    admin_login_and_orders
    ;;
  *) usage ;;
esac
