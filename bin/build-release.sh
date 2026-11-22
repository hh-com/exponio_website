#!/usr/bin/env bash
# Baut ein fertiges Upload-Paket für den Server – dort sind dann weder Composer noch Node nötig.
# Ergebnis: var/release/live-JJJJ-MM-TT-HHMM.zip; der Inhalt gehört in den Ordner live/ auf dem Server.
# Nicht enthalten (bleiben auf dem Server unangetastet): .env.local, var/, public/media.
# Benötigt lokal: php, composer, npm, rsync, zip.
set -euo pipefail

cd "$(dirname "$0")/.."
root=$(pwd)
zipfile="$root/var/release/live-$(date +%Y-%m-%d-%H%M).zip"
build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT

echo "1/4 Assets bauen …"
npm run build --silent

echo "2/4 Projektdateien kopieren …"
rsync -a \
    --exclude='/.git/' --exclude='/.env.local' --exclude='/.env.*.local' \
    --exclude='/var/' --exclude='/vendor/' --exclude='/node_modules/' \
    --exclude='/public/media' --exclude='/public/bundles/' \
    --exclude='/tests/' --exclude='/.spec/' --exclude='/.claude/' --exclude='/.phpunit.cache/' \
    --exclude='/test-results/' --exclude='/playwright-report/' --exclude='/blob-report/' \
    ./ "$build/"

echo "3/4 PHP-Pakete ohne Entwicklerwerkzeuge installieren …"
# APP_SECRET ist nur ein Platzhalter für den Build; der Cache wird danach verworfen (er enthält lokale Pfade).
(cd "$build" && APP_ENV=prod APP_SECRET=build-only composer install \
    --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet)
rm -rf "$build/var"

echo "4/4 Zip packen …"
mkdir -p "$root/var/release"
(cd "$build" && zip -qr "$zipfile" .)
echo "Fertig: ${zipfile#"$root/"} ($(du -h "$zipfile" | cut -f1))"
