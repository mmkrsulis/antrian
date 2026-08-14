#!/bin/sh
set -eu
base_url="${BASE_URL:-http://127.0.0.1:8090}"
username="${OPERATOR_USERNAME:?OPERATOR_USERNAME is required}"
password="${OPERATOR_PASSWORD:?OPERATOR_PASSWORD is required}"
cookie="$(mktemp)"
login="$(curl -fsS -c "$cookie" "$base_url/login?client=operator")"
token="$(printf '%s' "$login" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p')"
curl -fsS -o /dev/null -b "$cookie" -c "$cookie" --data-urlencode "_csrf=$token" --data-urlencode 'client=operator' --data-urlencode "username=$username" --data-urlencode "password=$password" --data-urlencode 'remember=1' "$base_url/login"
grep -q 'queue_remember' "$cookie"
curl -fsS -b "$cookie" "$base_url/api/operator/session" | grep -q '"authenticated":true'
notification_page="$(curl -fsS -b "$cookie" "$base_url/operator/notifications")"
printf '%s' "$notification_page" | grep -q 'Suara antrean baru'
printf '%s' "$notification_page" | grep -q 'name="play_mode"'
curl -fsS -b "$cookie" "$base_url/operator" | grep -q 'href="/operator/notifications"'
echo "Operator persistent session and notification page: 5 assertions passed."
