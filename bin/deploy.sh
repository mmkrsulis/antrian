#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_dir"

env_file=${ENV_FILE:-.env.live}
[ -f "$env_file" ] || { echo "Environment file not found: $env_file" >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo 'git is required.' >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo 'docker is required.' >&2; exit 1; }

if [ -n "$(git status --porcelain)" ] && [ "${ALLOW_DIRTY_DEPLOY:-0}" != 1 ]; then
    echo 'Refusing to deploy a dirty worktree. Commit changes or set ALLOW_DIRTY_DEPLOY=1.' >&2
    exit 1
fi

APP_COMMIT=$(git rev-parse HEAD)
APP_VERSION=${APP_VERSION:-$(git describe --tags --always --dirty)}
APP_BUILT_AT=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
export APP_VERSION APP_COMMIT APP_BUILT_AT

short_commit=$(printf '%s' "$APP_COMMIT" | cut -c1-7)
echo "Deploying Reka Queue $APP_VERSION ($short_commit)"
docker compose --env-file "$env_file" build --pull web
docker compose --env-file "$env_file" up -d --force-recreate web
docker compose --env-file "$env_file" exec -T web php /var/www/html/bin/install-live.php

bind_ip=$(sed -n 's/^QUEUE_BIND_IP=//p' "$env_file" | tail -1 | tr -d '"\r')
port=$(sed -n 's/^QUEUE_PORT=//p' "$env_file" | tail -1 | tr -d '"\r')
base_url=${BASE_URL:-http://${bind_ip:-127.0.0.1}:${port:-8090}}
health=$(curl -fsS "$base_url/health")
printf '%s' "$health" | grep -Fq "\"commit\":\"$APP_COMMIT\"" || {
    echo "Deployment verification failed: /health does not report $APP_COMMIT" >&2
    exit 1
}
echo "Deployment verified at $base_url/health"
