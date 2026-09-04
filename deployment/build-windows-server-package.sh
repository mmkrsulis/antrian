#!/usr/bin/env bash
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
runtime_dir="$repo_dir/deployment/windows-server/runtime/native"
mkdir -p "$runtime_dir"

php_name="php-8.4.24-nts-Win32-vs17-x64.zip"
php_url="https://windows.php.net/downloads/releases/$php_name"
php_sha256="86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb"
caddy_name="caddy_2.11.4_windows_amd64.zip"
caddy_url="https://github.com/caddyserver/caddy/releases/download/v2.11.4/$caddy_name"
caddy_sha256="1708333f79e274c7697285afe6d592ab39314e0b131e9ec6bea08ad27df62ebf"
winsw_name="WinSW-x64.exe"
winsw_url="https://github.com/winsw/winsw/releases/download/v2.12.0/$winsw_name"
winsw_sha256="05b82d46ad331cc16bdc00de5c6332c1ef818df8ceefcd49c726553209b3a0da"
vc_runtime="$repo_dir/deployment/windows-server/runtime/vc_redist.x64.exe"
vc_runtime_sha256="843068991daaa1f73ad9f6239bce4d0f6a07a51f18c37ea2a867e9beca71295c"

download_and_verify() {
  local url=$1 target=$2 checksum=$3
  if [[ ! -f "$target" ]]; then curl -fL --retry 3 --retry-delay 3 "$url" -o "$target"; fi
  echo "$checksum  $target" | sha256sum -c -
}
download_and_verify "$php_url" "$runtime_dir/$php_name" "$php_sha256"
download_and_verify "$caddy_url" "$runtime_dir/$caddy_name" "$caddy_sha256"
download_and_verify "$winsw_url" "$runtime_dir/$winsw_name" "$winsw_sha256"
if [[ ! -f "$vc_runtime" ]]; then curl -fL --retry 3 --retry-delay 3 'https://aka.ms/vc14/vc_redist.x64.exe' -o "$vc_runtime"; fi
echo "$vc_runtime_sha256  $vc_runtime" | sha256sum -c -
unzip -tq "$runtime_dir/$php_name" >/dev/null
unzip -tq "$runtime_dir/$caddy_name" >/dev/null

cd "$repo_dir/deployment/windows-server/installer"
makensis -V3 RekaQueueServer.nsi
installer="$repo_dir/deployment/windows-server/RekaQueueServerSetup.exe"
test -s "$installer"
cp "$installer" "$repo_dir/deployment/RekaQueueServerSetup.exe"
chmod 644 "$installer" "$repo_dir/deployment/RekaQueueServerSetup.exe"
installer_size=$(stat -c %s "$installer")
installer_sha256=$(sha256sum "$installer"|awk '{print $1}')
cat > "$repo_dir/deployment/windows-server/release-manifest.txt" <<EOF
Reka Queue Server Setup 2.1.0
Filename: RekaQueueServerSetup.exe
Size: $installer_size bytes
SHA-256: $installer_sha256

XAMPP-free bundled runtime sources:
- PHP 8.4.24 NTS Windows x64 (official PHP for Windows)
  SHA-256: $php_sha256
- Caddy 2.11.4 Windows amd64 (official Caddy release)
  SHA-256: $caddy_sha256
- WinSW 2.12.0 x64 (official WinSW release)
  SHA-256: $winsw_sha256
- Microsoft Visual C++ Redistributable x64
  SHA-256: $vc_runtime_sha256
EOF
printf '%s  %s\n' "$installer_sha256" "$installer"
