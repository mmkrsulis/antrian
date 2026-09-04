#!/usr/bin/env bash
set -Eeuo pipefail

REPOSITORY_URL="${REKA_QUEUE_REPOSITORY_URL:-https://github.com/mmkrsulis/antrian.git}"
INSTALL_DIR="${REKA_QUEUE_INSTALL_DIR:-/opt/reka-queue}"
APP_PORT="${REKA_QUEUE_PORT:-8090}"
ADMIN_USERNAME="${REKA_QUEUE_ADMIN_USERNAME:-admin}"

info() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\nError: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "Perintah '$1' belum tersedia."; }

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  fail "Jalankan installer sebagai root, misalnya: curl -fsSL https://raw.githubusercontent.com/mmkrsulis/antrian/main/install.sh | sudo bash"
fi

need git
need docker
need openssl
need ip
need awk
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 belum tersedia."
[[ "$APP_PORT" =~ ^[0-9]+$ ]] && (( APP_PORT >= 1 && APP_PORT <= 65535 )) || fail "REKA_QUEUE_PORT tidak valid."
[[ "$ADMIN_USERNAME" =~ ^[a-zA-Z0-9._-]{3,50}$ ]] || fail "REKA_QUEUE_ADMIN_USERNAME tidak valid."

if [ -e "$INSTALL_DIR" ]; then
  fail "Direktori $INSTALL_DIR sudah ada. Installer berhenti agar data lama tidak tertimpa."
fi

info "Mengunduh Reka Queue"
git clone --depth 1 "$REPOSITORY_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

umask 077
DB_PASSWORD="$(openssl rand -hex 24)"
ADMIN_PASSWORD="${REKA_QUEUE_ADMIN_PASSWORD:-$(openssl rand -base64 18 | tr -d '/+=')}"
DISPLAY_KEY="$(openssl rand -hex 24)"
ONLINE_KEY="$(openssl rand -hex 32)"
PRIMARY_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')"
PRIMARY_IP="${PRIMARY_IP:-127.0.0.1}"
SECONDARY_IP="$(command -v tailscale >/dev/null 2>&1 && tailscale ip -4 2>/dev/null | head -n1 || true)"
if [ -z "$SECONDARY_IP" ] || [ "$SECONDARY_IP" = "$PRIMARY_IP" ]; then SECONDARY_IP="127.0.0.1"; fi
if [ "$SECONDARY_IP" = "$PRIMARY_IP" ]; then SECONDARY_IP="127.0.0.2"; fi

cat > .env.live <<EOF
APP_NAME="Reka Queue Management"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://${PRIMARY_IP}:${APP_PORT}
APP_TIMEZONE=Asia/Jakarta
DB_HOST=db
DB_PORT=3306
DB_DATABASE=queue_management
DB_USERNAME=queue_user
DB_PASSWORD=${DB_PASSWORD}
LIVE_ADMIN_PASSWORD=${ADMIN_PASSWORD}
ADMIN_USERNAME=${ADMIN_USERNAME}
ADMIN_NAME=Administrator
SESSION_SECURE=false
DISPLAY_ACCESS_KEY=${DISPLAY_KEY}
ONLINE_API_KEY=${ONLINE_KEY}
QUEUE_BIND_IP=${PRIMARY_IP}
QUEUE_LAN_IP=${SECONDARY_IP}
QUEUE_PORT=${APP_PORT}
EOF

info "Membangun container dan menyiapkan database"
docker compose --env-file .env.live up -d --build
docker compose --env-file .env.live exec -T web php /var/www/html/bin/install-live.php

info "Instalasi selesai"
printf 'URL      : http://%s:%s\n' "$PRIMARY_IP" "$APP_PORT"
printf 'Username : %s\n' "$ADMIN_USERNAME"
printf 'Password : %s\n' "$ADMIN_PASSWORD"
printf '\nSimpan password ini sekarang. Konfigurasi privat berada di %s/.env.live\n' "$INSTALL_DIR"
