#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PKG="$(mktemp -d)"
trap 'rm -rf "$PKG"' EXIT
mkdir -p "$PKG/DEBIAN" "$PKG/usr/bin" "$PKG/usr/lib/systemd/user" "$PKG/usr/share/doc/reka-queue-notifier"
chmod 0755 "$PKG" "$PKG/DEBIAN"
install -m 0755 "$ROOT/deployment/linux/reka-queue-notifier" "$PKG/usr/bin/reka-queue-notifier"
install -m 0644 "$ROOT/deployment/linux/reka-queue-notifier.service" "$PKG/usr/lib/systemd/user/reka-queue-notifier.service"
install -m 0644 "$ROOT/deployment/linux/README.txt" "$PKG/usr/share/doc/reka-queue-notifier/README"
find "$PKG" -type d -exec chmod 0755 {} +
cat > "$PKG/DEBIAN/control" <<'CONTROL'
Package: reka-queue-notifier
Version: 1.0.0
Section: utils
Priority: optional
Architecture: all
Depends: python3, libnotify-bin
Recommends: libcanberra-gtk3-module
Maintainer: Sulis Setiyawan <rekakarsa>
Description: Native Linux operator notifications for Reka Queue Management
 Receives service-scoped new queue notifications without an open browser tab.
CONTROL
dpkg-deb --root-owner-group --build "$PKG" "$ROOT/deployment/reka-queue-notifier-linux.deb"
