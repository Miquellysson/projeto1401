#!/usr/bin/env bash
set -euo pipefail
BASE="http://localhost:8888"

head_req(){
  printf '\n### HEAD %s\n' "$1"
  curl -sS -I "$1"
}

get_snippet(){
  printf '\n### GET %s (first lines)\n' "$1"
  curl -sS "$1" | head -n 25
}

head_req "$BASE/"
get_snippet "$BASE/"

head_req "$BASE/?route=cart"
get_snippet "$BASE/?route=cart"

head_req "$BASE/?route=checkout"
get_snippet "$BASE/?route=checkout"

head_req "$BASE/admin.php"
get_snippet "$BASE/admin.php"

head_req "$BASE/manifest.php"
get_snippet "$BASE/manifest.php"

head_req "$BASE/assets/theme.css"
head_req "$BASE/assets/js/a2hs.js"
