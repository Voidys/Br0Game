#!/usr/bin/env bash
#
# Converts the shipped theme images to WebP source files and deletes the originals.
#
#   tools/images/convert-theme-images.sh [--dry-run] [--jobs N] [path ...]
#
# Default paths: public/styles/theme plus the referenced images of public/styles/resource/images
# (see RESOURCE_FILES below). Run it before tools/images/dedupe-theme-assets.php; running it again only
# touches images that are still JPG/PNG/GIF (a new theme, new artwork).
#
# Needs the libwebp tools (cwebp, gif2webp) and `file`:
#   Debian/Ubuntu   sudo apt-get install webp file
#   DDEV            ddev exec "sudo apt-get install -y webp", then: ddev exec tools/images/convert-theme-images.sh
#   macOS           brew install webp
#
# Rules
#   - .png/.jpg/.jpeg/.gif become x.webp next to the original; the original is deleted when the WebP is
#     smaller or when the image exceeded the size limit of its category (it is scaled down, aspect kept,
#     never upscaled). Otherwise the WebP is dropped and the original stays.
#   - Size limit = 1.5 x the largest size the category is shown at (CSS px, max edge):
#       achievements 108 (72 px modal), gebaeude 300 (up to 200 px, tooltips), planeten 300 (200 px overview),
#       everything else unlimited (UI sprites and page backgrounds).
#   - Lossy WebP quality 90 (-m 6). PNG: the smaller of lossy q90 and lossless. GIF (animated too, all frames,
#     timing and loop kept): the smallest of gif2webp lossless / mixed / lossy q90. Alpha is kept.
#   - Existing .webp files are left alone unless an edge is above the limit (then re-encoded at q90).
#   - The real format is detected (some *.gif are JPEG/PNG); a file named .gif/.png/.jpg that really is WebP
#     is only renamed.
#   - Oversized GIFs are kept as they are (gif2webp cannot scale); the log lists them.
#   - `foo.png.webp` copies made by `bin/console app:images:webp` are removed first (they are generated).
#   - References: theme.css url()s and the resource images' references in src/, templates/ and public/scripts/
#     are rewritten to the .webp name. Theme images asked for through theme_asset('x.gif') need nothing,
#     the resolver falls back to x.webp (App\Game\Core\ThemeAssets).
#
# Log: var/convert-theme-images.tsv (status, path, bytes before, bytes after); results are cached by source
# content in var/convert-theme-images.cache (delete it to force a fresh run).
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO"

DRY=0 JOBS=$(nproc 2>/dev/null || echo 4)
PATHS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY=1 ;;
        --jobs) JOBS="$2"; shift ;;
        -h|--help) sed -n '3,33p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) PATHS+=("$1") ;;
    esac
    shift
done

# referenced images of the shared resource folder that can become WebP (the rest has no static reference
# or is read by something else: meta.png/banner.jpg for sharing, fancybox/ and battlesim/ are unused)
RESOURCE_FILES=(
    public/styles/resource/images/admin/i.gif
    public/styles/resource/images/alliance/CLOSE.png
    public/styles/resource/images/false.png
    public/styles/resource/images/true.png
    public/styles/resource/images/icons/lock.png
    public/styles/resource/images/user.png
    public/styles/resource/images/pr0game.gif
)

for tool in cwebp gif2webp file; do
    command -v "$tool" >/dev/null 2>&1 || { echo "missing tool: $tool (see header for installation)" >&2; exit 1; }
done

LOG="$REPO/var/convert-theme-images.tsv"
CACHE="$REPO/var/convert-theme-images.cache"   # result per source content: the same image in nine themes is converted once
mkdir -p "$REPO/var" "$CACHE"
: > "$LOG"
export DRY LOG CACHE

limit_for() {
    case "$1" in
        */achievements/*) echo 108 ;;
        */gebaeude/*|*/planeten/*) echo 300 ;;
        *) echo 0 ;;
    esac
}
export -f limit_for

# prints "W H" of a PNG/JPEG/GIF/WebP
dims() {
    file -b "$1" | grep -oE '[0-9]+ ?x ?[0-9]+' | tail -1 | tr -d ' ' | tr 'x' ' '
}
export -f dims

convert_one() {
    local f="$1" ext out key limit w h edge orig best bs tmp cand size oversize=0 scale_args=()
    ext="${f##*.}"; ext="${ext,,}"
    case "$(file -b --mime-type "$f")" in      # the real format counts, not the extension (some .gif are JPEG/PNG)
        image/gif) ext=gif ;; image/png) ext=png ;; image/jpeg) ext=jpg ;; image/webp) ext=webp ;;
    esac
    out="${f%.*}.webp"
    orig=$(stat -c %s "$f")
    limit=$(limit_for "$f")

    if [ "$ext" = webp ] && [[ "${f,,}" != *.webp ]]; then               # mislabelled WebP
        [ -e "$out" ] || { [ "$DRY" = 1 ] || mv "$f" "$out"; printf 'renamed\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; }
        return
    fi
    read -r w h <<< "$(dims "$f")"
    [ -n "${w:-}" ] || { printf 'skipped-nodims\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; return; }
    edge=$(( w > h ? w : h ))
    if [ "$limit" -gt 0 ] && [ "$edge" -gt "$limit" ]; then
        oversize=1
        if [ "$w" -ge "$h" ]; then scale_args=(-resize "$limit" 0); else scale_args=(-resize 0 "$limit"); fi
    fi

    if [ "$ext" = webp ]; then                                          # only shrink oversized WebP
        [ "$oversize" = 1 ] || return
        tmp="$f.tmp.webp"
        if cwebp -quiet -q 90 -m 6 "${scale_args[@]}" "$f" -o "$tmp" 2>/dev/null; then
            if [ "$DRY" = 1 ]; then rm -f "$tmp"; else mv "$tmp" "$f"; fi
            printf 'resized-webp\t%s\t%s\t%s\n' "$f" "$orig" "$(stat -c %s "$f")" >> "$LOG"
        else rm -f "$tmp"; printf 'failed\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; fi
        return
    fi
    [ -e "$out" ] && { printf 'skipped-exists\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; return; }

    key="$(sha1sum "$f" | cut -d' ' -f1)-$limit"
    if [ -e "$CACHE/$key.webp" ]; then
        [ "$DRY" = 1 ] || { cp "$CACHE/$key.webp" "$out"; rm -f "$f"; }
        printf 'converted-%s\t%s\t%s\t%s\n' "$ext" "$f" "$orig" "$(stat -c %s "$CACHE/$key.webp")" >> "$LOG"; return
    elif [ -e "$CACHE/$key.keep" ]; then
        printf 'kept-original\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; return
    fi
    best="" bs=0
    try() {   # try <suffix> <command...>: keeps the smallest candidate in $best
        local suffix="$1"; shift
        cand="$out.$suffix.tmp"
        if "$@" -o "$cand" >/dev/null 2>&1 && [ -s "$cand" ]; then
            size=$(stat -c %s "$cand")
            if [ -z "$best" ] || [ "$size" -lt "$bs" ]; then [ -n "$best" ] && rm -f "$best"; best="$cand"; bs="$size"; else rm -f "$cand"; fi
        else rm -f "$cand"; fi
    }
    case "$ext" in
        gif)
            if [ "$oversize" = 1 ]; then printf 'kept-oversized-gif\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; return; fi
            try ll gif2webp -quiet -q 100 -m 6 "$f"
            try mx gif2webp -quiet -mixed -q 90 -m 6 "$f"
            try ly gif2webp -quiet -lossy -q 90 -m 6 "$f"
            ;;
        png)
            try ly cwebp -quiet -q 90 -m 6 -alpha_q 90 "${scale_args[@]}" "$f"
            try ll cwebp -quiet -lossless -z 9 "${scale_args[@]}" "$f"
            ;;
        jpg|jpeg)
            try ly cwebp -quiet -q 90 -m 6 "${scale_args[@]}" "$f"
            ;;
        *) return ;;
    esac
    if [ -z "$best" ]; then printf 'failed\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"; return; fi
    if [ "$bs" -lt "$orig" ] || [ "$oversize" = 1 ]; then
        if [ "$DRY" = 1 ]; then rm -f "$best"; else cp "$best" "$CACHE/$key.webp"; mv "$best" "$out"; rm -f "$f"; fi
        printf 'converted-%s\t%s\t%s\t%s\n' "$ext" "$f" "$orig" "$bs" >> "$LOG"
    else
        rm -f "$best"; [ "$DRY" = 1 ] || : > "$CACHE/$key.keep"
        printf 'kept-original\t%s\t%s\t%s\n' "$f" "$orig" "$orig" >> "$LOG"
    fi
}
export -f convert_one

if [ ${#PATHS[@]} -eq 0 ]; then
    PATHS=(public/styles/theme "${RESOURCE_FILES[@]}")
fi

echo "==> removing generated foo.png.webp copies"
for p in "${PATHS[@]}"; do
    [ -d "$p" ] && [ "$DRY" = 0 ] && find "$p" -type f \( -iname '*.png.webp' -o -iname '*.jpg.webp' -o -iname '*.jpeg.webp' -o -iname '*.gif.webp' \) -delete
done

echo "==> converting (jobs: $JOBS)"
# shellcheck disable=SC2016  # the body is expanded by the child shell, not here
for p in "${PATHS[@]}"; do
    if [ -d "$p" ]; then find "$p" -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.gif' -o -iname '*.webp' \) ! -iname '*.png.webp' ! -iname '*.jpg.webp' ! -iname '*.jpeg.webp' ! -iname '*.gif.webp' -print0
    elif [ -f "$p" ]; then printf '%s\0' "$p"; fi
done | xargs -0 -r -n 1 -P "$JOBS" bash -c 'convert_one "$1" || true' _

echo "==> summary ($LOG)"
awk -F'\t' '{n[$1]++; b[$1]+=$3; a[$1]+=$4} END {for (k in n) printf "%-22s %6d files  %10d -> %10d bytes\n", k, n[k], b[k], a[k]}' "$LOG" | sort

[ "$DRY" = 1 ] && exit 0

echo "==> rewriting references"
# theme.css url()s: <name>.<png|jpg|gif> -> .webp when only the WebP exists now
for css in public/styles/theme/*/theme.css; do
    dir="$(dirname "$css")"
    grep -oE "url\(['\"]?[^)'\"]+\.(png|jpe?g|gif)['\"]?\)" "$css" | sed -E "s/^url\(['\"]?//; s/['\"]?\)$//" | sort -u | while read -r ref; do
        case "$ref" in http*|//*|data:*) continue ;; esac
        if [ ! -e "$dir/$ref" ] && [ -e "$dir/${ref%.*}.webp" ]; then
            sed -i "s#${ref}#${ref%.*}.webp#g" "$css"
        fi
    done
done
# resource images: every converted file, by its path below images/
awk -F'\t' '$1 ~ /^converted-/ && $2 ~ /^public\/styles\/resource\/images\// {print $2}' "$LOG" | while read -r f; do
    rel="${f#public/styles/resource/}"
    grep -rlF "$rel" src templates public/scripts public/styles/resource/css 2>/dev/null | while read -r user; do
        sed -i "s#${rel}#${rel%.*}.webp#g" "$user"
    done
done
echo "done"
