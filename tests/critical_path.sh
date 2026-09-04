#!/bin/sh
set -eu
base_url="${BASE_URL:-http://127.0.0.1:8090}"
cookie_file="$(mktemp)"
trap 'rm -f "$cookie_file"' EXIT

curl -fsS "$base_url/health" | grep -q '"ok"'
page="$(curl -fsS -c "$cookie_file" "$base_url/kiosk")"
token="$(printf '%s' "$page" | sed -n 's/.*window.QUEUE={csrf:"\([^"]*\)".*/\1/p')"
[ -n "$token" ]
ticket="$(curl -fsS -b "$cookie_file" -H 'Content-Type: application/json' -H "X-CSRF-Token: $token" -d '{"service_id":1}' "$base_url/api/tickets")"
printf '%s' "$ticket" | grep -Eq '"ticket_number":"[[:alnum:]-]+"'
public_id="$(printf '%s' "$ticket" | sed -n 's/.*"public_id":"\([^"]*\)".*/\1/p')"
ticket_page="$(curl -fsS "$base_url/ticket/$public_id")"
printf '%s' "$ticket_page" | grep -q 'Mohon menunggu'
printf '%s' "$ticket_page" | grep -q "addEventListener('load'"
if printf '%s' "$ticket_page" | grep -q 'Status:'; then echo 'Ticket status must not be displayed.' >&2; exit 1; fi
display_key="${DISPLAY_ACCESS_KEY:?DISPLAY_ACCESS_KEY is required}"
curl -fsS "$base_url/display?key=$display_key" | grep -q 'NOMOR ANTREAN DIPANGGIL'
echo "Critical-path smoke test passed."
