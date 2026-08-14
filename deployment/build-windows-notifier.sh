#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
x86_64-w64-mingw32-gcc -O2 -s -mwindows -o deployment/windows/notifier/RekaQueueNotifier.exe deployment/windows/notifier/reka-queue-notifier.c -lwinhttp -ladvapi32 -lshell32 -lole32 -luuid -lurlmon -lwinmm
makensis -V2 deployment/windows/notifier/RekaQueueNotifierSetup.nsi
chmod 0644 deployment/windows/notifier/RekaQueueNotifier.exe deployment/RekaQueueNotifierSetup.exe
