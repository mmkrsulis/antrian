#!/usr/bin/env bash
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
source_dir="$repo_dir/deployment/windows-server"
stage_dir=$(mktemp -d)
trap 'rm -rf "$stage_dir"' EXIT
package_dir="$stage_dir/RekaQueue-Windows-Server"
mkdir -p "$package_dir/payload/app"
cp -a "$source_dir/." "$package_dir/"
rm -rf "$package_dir/payload"
mkdir -p "$package_dir/payload/app"
for item in app bin bootstrap database public; do cp -a "$repo_dir/$item" "$package_dir/payload/app/"; done
cp "$repo_dir/.env.example" "$package_dir/payload/app/.env.example"
find "$package_dir/payload/app" -type f -name '*.log' -delete
if [ -d "$package_dir/payload/app/public/uploads" ]; then
  find "$package_dir/payload/app/public/uploads" -mindepth 1 -delete
fi
chmod -R a+rX "$package_dir"
output="$repo_dir/deployment/reka-queue-windows-server.zip"
rm -f "$output"
(cd "$stage_dir" && zip -qr "$output" RekaQueue-Windows-Server)
chmod 644 "$output"
echo "$output"
