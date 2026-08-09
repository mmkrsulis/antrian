#!/bin/sh
set -eu
base_url="${BASE_URL:-http://192.168.12.68:8090}"
api_key="${ONLINE_API_KEY:?ONLINE_API_KEY is required}"
code="$(curl -sS -o /tmp/reka-unauthorized.json -w '%{http_code}' "$base_url/api/public/services")"
[ "$code" = 401 ]
curl -fsS -H "X-API-Key: $api_key" "$base_url/api/public/services" | grep -q '"data"'
curl -fsS "$base_url/online-registration" | grep -q 'Daftar sebelum datang'
curl -fsS "$base_url/online-check-in" | grep -q 'Aktifkan antrean Anda'
code="$(curl -sS -o /tmp/reka-invalid.json -w '%{http_code}' -H "X-API-Key: $api_key" -H 'Content-Type: application/json' -d '{}' "$base_url/api/public/registrations")"
[ "$code" = 422 ]
grep -q 'error' /tmp/reka-invalid.json
rm -f /tmp/reka-unauthorized.json /tmp/reka-invalid.json
echo "Online registration HTTP API: 6 assertions passed."
