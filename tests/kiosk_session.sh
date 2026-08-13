#!/bin/sh
set -eu
base_url="${BASE_URL:-http://192.168.12.68:8090}"
cookie_file="$(mktemp)"
kiosk="$(curl -fsS -c "$cookie_file" "$base_url/kiosk")"
token="$(printf '%s' "$kiosk" | sed -n 's/.*window.QUEUE={csrf:"\([^"]*\)"}.*/\1/p')"
[ -n "$token" ]
session="$(curl -fsS -b "$cookie_file" -c "$cookie_file" "$base_url/api/kiosk/session")"
refreshed="$(printf '%s' "$session" | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p')"
[ -n "$refreshed" ]
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_file" -H 'Content-Type: application/json' -H "X-CSRF-Token: $refreshed" -d '{"service_id":0}' "$base_url/api/tickets")"
[ "$status" = 422 ]
curl -fsS "$base_url/assets/kiosk.js" | grep -q 'response.status===419'
echo "Kiosk session refresh and CSRF retry: 4 assertions passed."
