#!/usr/bin/env bash
# Vytvorí balík na nahratie na hosting: dist/diet-manager-<verzia>.zip
# Štruktúra balíka zodpovedá Websupportu: web/ (verejný priečinok) + app/ (neverejný).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:-$(php -r "require 'app/bootstrap.php'; echo APP_VERSION;" 2>/dev/null || echo dev)}"
OUT="dist/diet-manager"
rm -rf dist && mkdir -p "$OUT"

(cd app && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet)

cp -r public "$OUT/web"
mkdir -p "$OUT/app"
cp -r app/src app/schema app/bin app/vendor app/bootstrap.php app/composer.json app/.htaccess "$OUT/app/"
cp app/config.sample.php "$OUT/app/"
mkdir -p "$OUT/app/storage/photos" "$OUT/app/storage/backups" "$OUT/app/storage/logs"
cp app/storage/.htaccess "$OUT/app/storage/"
cp docs/NASADENIE.md "$OUT/NAVOD-NASADENIE.md"

# Odstránenie nepotrebných súborov z knižníc (testy, dokumentácia)
find "$OUT/app/vendor" -type d \( -name tests -o -name Tests -o -name docs -o -name examples -o -name fixtures -o -name .github -o -name .git \) -prune -exec rm -rf {} +

(cd dist && zip -qr "diet-manager-${VERSION}.zip" diet-manager)
echo "Hotovo: dist/diet-manager-${VERSION}.zip ($(du -h "dist/diet-manager-${VERSION}.zip" | cut -f1))"
