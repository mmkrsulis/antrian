#!/usr/bin/env bash
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
runtime="$repo_dir/deployment/windows-server/runtime/xampp-portable-windows-x64-8.2.12-0-VS16.zip"
runtime_sha256="ce3bdf852bd62c7363cb51d66e709b6a9bf5f3ea59bc1712ffda11d9238e5651"
vc_runtime="$repo_dir/deployment/windows-server/runtime/vc_redist.x64.exe"
vc_runtime_sha256="843068991daaa1f73ad9f6239bce4d0f6a07a51f18c37ea2a867e9beca71295c"
if [[ ! -f "$runtime" ]]; then
  echo "Downloading the official Apache Friends Windows runtime..."
  curl -fL --retry 3 --retry-delay 5 \
    'https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/8.2.12/xampp-portable-windows-x64-8.2.12-0-VS16.zip/download' \
    -o "$runtime"
fi
if ! unzip -tq "$runtime" >/dev/null; then
  echo "Bundled runtime ZIP is invalid." >&2
  exit 1
fi
actual_sha256=$(sha256sum "$runtime"|awk '{print $1}')
if [[ -n "$runtime_sha256" && "$actual_sha256" != "$runtime_sha256" ]]; then
  echo "Bundled runtime checksum mismatch." >&2
  exit 1
fi
if [[ ! -f "$vc_runtime" ]]; then
  echo "Downloading the official Microsoft Visual C++ runtime..."
  curl -fL --retry 3 --retry-delay 5 'https://aka.ms/vc14/vc_redist.x64.exe' -o "$vc_runtime"
fi
actual_vc_sha256=$(sha256sum "$vc_runtime"|awk '{print $1}')
if [[ "$actual_vc_sha256" != "$vc_runtime_sha256" ]]; then
  echo "Microsoft Visual C++ runtime checksum mismatch." >&2
  exit 1
fi
cd "$repo_dir/deployment/windows-server/installer"
makensis -V3 RekaQueueServer.nsi
installer="$repo_dir/deployment/windows-server/RekaQueueServerSetup.exe"
test -s "$installer"
cp "$installer" "$repo_dir/deployment/RekaQueueServerSetup.exe"
chmod 644 "$installer" "$repo_dir/deployment/RekaQueueServerSetup.exe"
installer_size=$(stat -c %s "$installer")
installer_sha256=$(sha256sum "$installer"|awk '{print $1}')
cat > "$repo_dir/deployment/windows-server/release-manifest.txt" <<EOF
Reka Queue Server Setup 1.0.1
Filename: RekaQueueServerSetup.exe
Size: $installer_size bytes
SHA-256: $installer_sha256

Bundled runtime sources:
- Apache Friends XAMPP Portable Windows x64 8.2.12-0 VS16
  SHA-256: $runtime_sha256
- Microsoft Visual C++ Redistributable x64
  SHA-256: $vc_runtime_sha256
EOF
printf '%s  %s\n' "$installer_sha256" "$installer"
