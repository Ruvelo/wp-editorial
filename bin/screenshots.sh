#!/usr/bin/env bash
# Regenerates .wordpress-org/ (screenshots, banners, icons) from the local
# demo site. Run bin/dev-site.sh --fresh and `wp server --path=.wp --port=8998`
# first. Needs a Chrome or chrome-headless-shell binary (set CHROME=...).
set -euo pipefail
cd "$(dirname "$0")/.."

BASE=${BASE:-http://localhost:8998}
CHROME=${CHROME:-$(ls -d ~/.cache/ms-playwright/chromium_headless_shell-*/*/ 2>/dev/null | tail -1)chrome-headless-shell}
[ -x "$CHROME" ] || { echo "Chrome not found; set CHROME=/path/to/chrome" >&2; exit 1; }
OUT=.wordpress-org
mkdir -p "$OUT"

shot() { # file, url, width,height, [budget ms], [scale]
    for attempt in 1 2 3; do # Headless Chrome sometimes hangs on a page; try again.
        timeout 120 "$CHROME" --headless --no-sandbox --hide-scrollbars --force-device-scale-factor="${5:-2}" \
            --virtual-time-budget="${4:-5000}" --window-size="$3" --screenshot="$PWD/$1" "$2" 2>/dev/null >/dev/null && break
    done
    convert "$1" -strip -define png:compression-level=9 "$1"
    echo "$1"
}
edit() { # who, post id, extra query: the block editor, signed in for real
    local to="/wp-admin/post.php?post=$2&action=edit${3:-}"
    echo "$BASE/?shot_login=$1&to=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1],safe=""))' "$to")"
}
id() { wp eval --path=.wp "echo get_page_by_path('$1', OBJECT, ['post', 'page'])->ID;"; }

RETRIES=$(id how-halyard-retries-failed-payments)
DUNNING=$(id five-dunning-emails-that-win-customers-back)
NORTHWIND=$(id northwind-cut-days-sales-outstanding-by-18-days)
WEBINAR=$(id webinar-closing-the-books-in-three-days)
INES="shot_as=ines.laurent"

shot "$OUT/screenshot-1.png"  "$BASE/wp-admin/admin.php?page=ruvelo-editorial&view=mine&$INES"                         1440,900
shot "$OUT/screenshot-2.png"  "$(edit ines.laurent "$RETRIES")"                                                         1440,1360 30000
shot "$OUT/screenshot-3.png"  "$(edit ines.laurent "$RETRIES" "&shot_select=3&shot_click=Request%20changes")"           1440,1500 30000
shot "$OUT/screenshot-4.png"  "$(edit maya "$NORTHWIND" "&shot_publish=1")"                                             1440,900  30000
shot "$OUT/screenshot-5.png"  "$BASE/wp-admin/admin.php?page=ruvelo-editorial-review&post=$DUNNING&shot_as=tom.reyes"   1440,1350
shot "$OUT/screenshot-6.png"  "$(edit kenji.mori "$WEBINAR" "&shot_click=Ask%20for%20a%20review")"                       1440,1400 30000
shot "$OUT/screenshot-7.png"  "$BASE/wp-admin/edit.php?shot_as=maya"                                                    1440,900
shot "$OUT/screenshot-8.png"  "$BASE/wp-admin/index.php?$INES"                                                          1440,820
shot "$OUT/screenshot-9.png"  "$BASE/wp-admin/admin.php?page=ruvelo-editorial-settings&shot_as=maya"                    1440,1150
shot "$OUT/screenshot-10.png" "$BASE/wp-admin/admin.php?page=ruvelo-editorial&view=mine&$INES&shot_theme=dark"          1440,900
shot "$OUT/screenshot-11.png" "$BASE/wp-admin/admin.php?page=ruvelo-editorial&view=mine&$INES"                         390,844 5000 2

# Banners and icons, from bin/art/.
convert "$OUT/screenshot-1.png" -crop 2200x1250+330+230 -resize 1300x bin/art/queue-crop.png
shot "$OUT/banner-772x250.png"   "file://$PWD/bin/art/banner.html" 772,250 3000 1
shot "$OUT/banner-1544x500.png"  "file://$PWD/bin/art/banner.html" 772,250 3000 2
"$CHROME" --headless --no-sandbox --hide-scrollbars --default-background-color=00000000 --force-device-scale-factor=1 --virtual-time-budget=3000 --window-size=128,128 --screenshot="$OUT/icon-128x128.png" "file://$PWD/bin/art/icon.html" 2>/dev/null >/dev/null
"$CHROME" --headless --no-sandbox --hide-scrollbars --default-background-color=00000000 --force-device-scale-factor=2 --virtual-time-budget=3000 --window-size=128,128 --screenshot="$OUT/icon-256x256.png" "file://$PWD/bin/art/icon.html" 2>/dev/null >/dev/null
rm -f bin/art/queue-crop.png
echo "Done. Look at every image before committing."
