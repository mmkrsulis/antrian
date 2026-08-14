#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; SDK="${ANDROID_SDK_ROOT:-$HOME/.cache/reka-android-sdk}"; BT="$SDK/build-tools/35.0.0"; JAR="$SDK/platforms/android-35/android.jar"; SRC="$ROOT/deployment/android/notifier"; OUT="$SRC/build"
rm -rf "$OUT"; mkdir -p "$OUT/compiled" "$OUT/generated" "$OUT/classes" "$OUT/dex"
"$BT/aapt2" compile --dir "$SRC/res" -o "$OUT/resources.zip"
"$BT/aapt2" link -I "$JAR" --manifest "$SRC/AndroidManifest.xml" --min-sdk-version 26 --target-sdk-version 35 --version-code 4 --version-name 1.2.1 --java "$OUT/generated" -o "$OUT/base.apk" "$OUT/resources.zip"
find "$SRC/src" "$OUT/generated" -name '*.java' -print0 | xargs -0 javac -source 8 -target 8 -bootclasspath "$JAR" -d "$OUT/classes"
"$BT/d8" --lib "$JAR" --min-api 26 --output "$OUT/dex" $(find "$OUT/classes" -name '*.class')
(cd "$OUT/dex" && zip -q "$OUT/base.apk" classes.dex)
"$BT/zipalign" -f 4 "$OUT/base.apk" "$OUT/aligned.apk"
KEY="$SRC/reka-release.keystore"; if [[ ! -f "$KEY" ]]; then keytool -genkeypair -keystore "$KEY" -storepass rekaqueue -keypass rekaqueue -alias reka -keyalg RSA -keysize 2048 -validity 10000 -dname "CN=Reka Queue, O=rekakarsa, C=ID"; fi
"$BT/apksigner" sign --ks "$KEY" --ks-pass pass:rekaqueue --key-pass pass:rekaqueue --out "$ROOT/deployment/RekaQueueNotifier.apk" "$OUT/aligned.apk"
"$BT/apksigner" verify --verbose "$ROOT/deployment/RekaQueueNotifier.apk"
