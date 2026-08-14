#!/bin/sh
set -eu
base_url="${BASE_URL:-http://192.168.12.68:8090}"
username="${OPERATOR_USERNAME:-DAPODIK}"
password="${OPERATOR_PASSWORD:-123456789}"
cookie="$(mktemp)"
login="$(curl -fsS -c "$cookie" "$base_url/login?client=operator")"
token="$(printf '%s' "$login" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p')"
curl -fsS -o /dev/null -b "$cookie" -c "$cookie" --data-urlencode "_csrf=$token" --data-urlencode 'client=operator' --data-urlencode "username=$username" --data-urlencode "password=$password" --data-urlencode 'remember=1' "$base_url/login"
grep -q 'queue_remember' "$cookie"
curl -fsS -b "$cookie" "$base_url/api/operator/session" | grep -q '"authenticated":true'
curl -fsS -b "$cookie" "$base_url/operator/notifications" | grep -q 'Suara antrean baru'
curl -fsS -b "$cookie" "$base_url/operator" | grep -q 'href="/operator/notifications"'
echo "Operator persistent session and notification page: 4 assertions passed."
