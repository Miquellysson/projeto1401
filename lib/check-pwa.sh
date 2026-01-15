 
  #!/usr/bin/env bash
  set -euo pipefail

  BASE_URL="http://localhost:8888"

  log() {
    printf '\n### %s\n' "$1"
  }

  check_head() {
    log "HEAD $1"
    curl -I "$1"
  }

  fetch_body() {
    log "GET $1 ($2)"
    curl "$1"
  }

  check_head "$BASE_URL/"
  check_head "$BASE_URL/manifest.webmanifest"
  fetch_body "$BASE_URL/manifest.webmanifest" "manifest body"

  check_head "$BASE_URL/sw.js"
  fetch_body "$BASE_URL/sw.js" "service worker (first lines)" | head

  check_head "$BASE_URL/assets/icons/admin-192.png"
  check_head "$BASE_URL/assets/icons/admin-512.png"
  BASH

  
