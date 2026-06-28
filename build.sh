#!/usr/bin/env bash
# 將外掛打包成可上傳 WordPress 的 zip。
# 產出:build/hncb-gateway.zip,內層資料夾為 hncb-gateway/
set -euo pipefail

SLUG="hncb-gateway"
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/build"
STAGE="$OUT/$SLUG"

rm -rf "$OUT"
mkdir -p "$STAGE"

# 只放外掛實際需要的檔案
cp "$ROOT/hncb-gateway.php" "$STAGE/"
cp "$ROOT/README.md"        "$STAGE/"
cp "$ROOT/LICENSE"          "$STAGE/"

cd "$OUT"
zip -rq "$SLUG.zip" "$SLUG" -x "*.DS_Store"
rm -rf "$STAGE"

echo "已產生:$OUT/$SLUG.zip"
