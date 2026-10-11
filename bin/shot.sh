#!/usr/bin/env bash
# shot.sh <out.png> <url> [width,height] [scale] — headless Chrome screenshot.
set -euo pipefail
CHROME=${CHROME:-$(ls -d ~/.cache/ms-playwright/chromium_headless_shell-1248/*/ 2>/dev/null)chrome-headless-shell}
"$CHROME" --headless --no-sandbox --hide-scrollbars --force-device-scale-factor="${4:-2}" \
    --virtual-time-budget=4000 --window-size="${3:-1440,1000}" --screenshot="$1" "$2" 2>/dev/null >/dev/null
echo "$1"
