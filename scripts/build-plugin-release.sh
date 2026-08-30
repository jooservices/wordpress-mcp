#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="$ROOT/packages/wordpress-plugin"
STAGE="$ROOT/build/wordpress-chatgpt"
VERSION="$(sed -n 's/^ \* Version: *//p' "$PLUGIN_SRC/wordpress-chatgpt.php" | head -1)"
ZIP="$ROOT/build/wordpress-chatgpt-${VERSION}.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

rsync -a \
  --exclude '.phpunit.result.cache' \
  --exclude 'build/' \
  --exclude 'tests/' \
  --exclude 'tools/' \
  --exclude 'vendor/' \
  --exclude 'Dockerfile' \
  --exclude 'phpunit.xml' \
  --exclude 'phpstan.neon' \
  --exclude 'phpmd.xml.dist' \
  --exclude 'phpcs.xml.dist' \
  --exclude 'pint.json' \
  "$PLUGIN_SRC/" "$STAGE/"

docker compose -f "$ROOT/docker-compose.yml" run --rm \
  -v "$STAGE:/plugin" \
  php sh -c 'cd /plugin && composer install --no-dev --no-interaction --optimize-autoloader'

(
  cd "$ROOT/build"
  rm -f "$(basename "$ZIP")"
  zip -rq "$(basename "$ZIP")" wordpress-chatgpt
)

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
