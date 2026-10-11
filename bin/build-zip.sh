#!/usr/bin/env bash
# Builds build/ruvelo-editorial.zip: the plugin as WordPress.org and the
# GitHub release ship it. No Composer needed at runtime (the plugin has its
# own PSR-4 loader), so vendor/ isn't included.
set -euo pipefail
cd "$(dirname "$0")/.."

out=build/ruvelo-editorial
rm -rf build && mkdir -p "$out/demo"
cp ruvelo-editorial.php uninstall.php readme.txt LICENSE "$out/"
cp -R src assets languages "$out/"
cp demo/content.php "$out/demo/"
if command -v zip >/dev/null; then
    (cd build && zip -qr ruvelo-editorial.zip ruvelo-editorial)
else
    (cd build && python3 -m zipfile -c ruvelo-editorial.zip ruvelo-editorial)
fi
echo "build/ruvelo-editorial.zip ($(du -h build/ruvelo-editorial.zip | cut -f1))"
