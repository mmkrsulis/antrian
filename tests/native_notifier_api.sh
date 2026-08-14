#!/bin/sh
set -eu
base_url="${BASE_URL:-http://127.0.0.1:8090}"
operator_username="${OPERATOR_USERNAME:?OPERATOR_USERNAME is required}"
operator_password="${OPERATOR_PASSWORD:?OPERATOR_PASSWORD is required}"
operator_service="${OPERATOR_SERVICE:?OPERATOR_SERVICE is required}"
device_id="codex-native-notifier-test"
cleanup(){ docker compose exec -T db mariadb -u"${DB_USERNAME:?DB_USERNAME is required}" -p"${DB_PASSWORD:?DB_PASSWORD is required}" "${DB_DATABASE:-queue_management}" -e "DELETE FROM notification_devices WHERE device_id='$device_id';" >/dev/null 2>&1 || true; }
trap cleanup EXIT
response="$(curl -fsS -X POST "$base_url/api/client/notifications/register" --data-urlencode "username=$operator_username" --data-urlencode "password=$operator_password" --data-urlencode "device_id=$device_id" --data-urlencode 'device_name=Automated Test')"
token="$(printf '%s' "$response" | docker exec -i queue-management-live-web-1 php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["token"]??"";')"
test "${#token}" -eq 64
poll="$(curl -fsS -H "X-Device-Token: $token" "$base_url/api/client/notifications?after=0")"
printf '%s' "$poll" | EXPECTED_SERVICE="$operator_service" docker compose exec -T web php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!isset($d["cursor"],$d["tickets"],$d["waiting_counts"],$d["settings"])) exit(1); foreach($d["tickets"] as $ticket) if($ticket["service_name"]!==getenv("EXPECTED_SERVICE")) exit(2);'
status="$(curl -sS -o /dev/null -w '%{http_code}' -H 'X-Device-Token: invalid' "$base_url/api/client/notifications")"
test "$status" = 401
echo "Native notifier registration, scope, and authentication: 3 assertions passed."
