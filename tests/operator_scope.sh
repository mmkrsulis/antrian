#!/bin/sh
set -eu
base_url="${BASE_URL:-http://127.0.0.1:8090}"
operator_username="${OPERATOR_USERNAME:?OPERATOR_USERNAME is required}"
operator_password="${OPERATOR_PASSWORD:?OPERATOR_PASSWORD is required}"
counter_name="${OPERATOR_COUNTER:?OPERATOR_COUNTER is required}"
service_one="${OPERATOR_SERVICE_ONE:?OPERATOR_SERVICE_ONE is required}"
service_two="${OPERATOR_SERVICE_TWO:?OPERATOR_SERVICE_TWO is required}"
unauthorized_service="${UNAUTHORIZED_SERVICE:?UNAUTHORIZED_SERVICE is required}"
cookie_file="$(mktemp)"
login_page="$(curl -fsS -c "$cookie_file" "$base_url/login")"
token="$(printf '%s' "$login_page" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p')"
curl -fsS -L -b "$cookie_file" -c "$cookie_file" --data-urlencode "_csrf=$token" --data-urlencode "username=$operator_username" --data-urlencode "password=$operator_password" "$base_url/login" >/dev/null
operator="$(curl -fsS -b "$cookie_file" "$base_url/operator")"
dashboard="$(curl -fsS -b "$cookie_file" "$base_url/dashboard")"
printf '%s' "$operator" | grep -q "$counter_name"
printf '%s' "$operator" | grep -q "$service_one"
printf '%s' "$operator" | grep -q "$service_two"
if printf '%s' "$operator" | grep -q ">$unauthorized_service<"; then echo 'Operator can see an unauthorized service.' >&2; exit 1; fi
printf '%s' "$dashboard" | grep -q 'Layanan Anda:'
printf '%s' "$dashboard" | grep -q "$service_one"
printf '%s' "$dashboard" | grep -q "$service_two"
if printf '%s' "$dashboard" | grep -q "$unauthorized_service"; then echo 'Operator dashboard shows an unauthorized service.' >&2; exit 1; fi
printf '%s' "$operator" | grep -q 'Queue Notifications'
cursor="$(printf '%s' "$operator" | sed -n 's/.*"cursor":\([0-9]*\).*/\1/p')"
kiosk_cookie="$(mktemp)";kiosk_page="$(curl -fsS -c "$kiosk_cookie" "$base_url/kiosk")";kiosk_token="$(printf '%s' "$kiosk_page" | sed -n 's/.*window.QUEUE={csrf:"\([^"]*\)"}.*/\1/p')"
curl -fsS -b "$kiosk_cookie" -H 'Content-Type: application/json' -H "X-CSRF-Token: $kiosk_token" -d '{"service_id":1}' "$base_url/api/tickets" >/dev/null
alerts="$(curl -fsS -b "$cookie_file" "$base_url/api/operator/notifications?after=$cursor")"
printf '%s' "$alerts" | grep -q "$service_one"
settings="$(curl -fsS -b "$cookie_file" -H "X-CSRF-Token: $token" -F "_csrf=$token" -F 'enabled=1' -F 'sound_type=bell' -F 'volume=0.65' "$base_url/api/operator/notification-settings")"
printf '%s' "$settings" | grep -q '"sound_type":"bell"'
echo "Operator service-scope test passed."
