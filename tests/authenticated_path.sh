#!/bin/sh
set -eu
base_url="${BASE_URL:-http://127.0.0.1:8090}"
admin_username="${ADMIN_USERNAME:-admin}"
admin_password="${ADMIN_PASSWORD:?ADMIN_PASSWORD is required}"
cookie_file="$(mktemp)"
login_page="$(curl -fsS -c "$cookie_file" "$base_url/login")"
token="$(printf '%s' "$login_page" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p')"
[ -n "$token" ]
dashboard="$(curl -fsS -L -b "$cookie_file" -c "$cookie_file" --data-urlencode "_csrf=$token" --data-urlencode "username=$admin_username" --data-urlencode "password=$admin_password" "$base_url/login")"
printf '%s' "$dashboard" | grep -q 'Dashboard hari ini'
operator_page="$(curl -fsS -b "$cookie_file" "$base_url/operator")"
printf '%s' "$operator_page" | grep -q 'operator-screen'
if printf '%s' "$operator_page" | grep -q 'DISPLAY MEDIA'; then echo 'Display media settings must not appear for operators.' >&2; exit 1; fi
settings_page="$(curl -fsS -b "$cookie_file" "$base_url/admin/settings")"
printf '%s' "$settings_page" | grep -q 'Application Settings'
printf '%s' "$settings_page" | grep -q 'primary_color'
printf '%s' "$settings_page" | grep -q 'header_image'
printf '%s' "$settings_page" | grep -q 'Display Media'
printf '%s' "$settings_page" | grep -q 'Local folder playlist'
curl -fsS -b "$cookie_file" "$base_url/admin/counters" | grep -q 'Counter Management'
settings_response="$(curl -fsS -b "$cookie_file" -H "X-CSRF-Token: $token" -F "_csrf=$token" -F 'media_type=none' -F 'media_url=' -F 'media_muted=1' "$base_url/api/admin/display-settings")"
printf '%s' "$settings_response" | grep -q '"display_media_type":"none"'
curl -fsS -b "$cookie_file" "$base_url/reports" | grep -q 'Laporan hari ini'
echo "Authenticated-path smoke test passed."
