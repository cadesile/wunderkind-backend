#!/usr/bin/env bash
#
# Regenerates every logo/favicon derivative in public/ from one master image.
#
#   bash scripts/regenerate-logo-assets.sh path/to/new-logo.png
#
# The brand mark is referenced from eight places across the backend, at seven
# different sizes and three formats. Doing this by hand means one of them is
# always left on the old artwork — usually the 16px favicon, which nobody looks
# at until it's wrong in a browser tab. This script is the single place that
# knows the full set.
#
# Where each output is consumed:
#   public/logo_master.png          archival full-size original (not served)
#   public/logo.png                 full size; admin email template, admin login
#   public/images/logo.png          same, served under /images (DashboardController,
#                                   EmailVerificationService)
#   public/images/logo.webp         compressed full-size, for anything web-facing
#   public/images/logo-crest-64.webp landing nav + footer crest
#   public/logo-512.png             og:image (social share card)
#   public/logo-192.png             Android home-screen icon
#   public/logo-180.png             apple-touch-icon
#   public/logo-48.png / -32 / -16  browser favicons
#   public/favicon.ico              legacy /favicon.ico request
#
# Requires: sips (macOS, built in) and cwebp (brew install webp).
#
# NOTE: this covers the BACKEND only. The React Native client in wunderkind-app
# ships its own app icons and splash assets and is a separate repo — update those
# there.

set -euo pipefail

MASTER="${1:-}"

if [[ -z "$MASTER" || ! -f "$MASTER" ]]; then
    echo "usage: bash scripts/regenerate-logo-assets.sh path/to/new-logo.png" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

if ! command -v cwebp >/dev/null 2>&1; then
    echo "cwebp not found — install it with: brew install webp" >&2
    exit 1
fi

echo "Master: $MASTER"
sips -g pixelWidth -g pixelHeight "$MASTER" | tail -2

# Archival copy + the two full-size PNGs that are served.
cp "$MASTER" public/logo_master.png
cp "$MASTER" public/logo.png
cp "$MASTER" public/images/logo.png

# Square PNG derivatives. -Z fits the longest edge, so a square master stays square.
for size in 512 192 180 48 32 16; do
    cp "$MASTER" "public/logo-${size}.png"
    sips -Z "$size" "public/logo-${size}.png" >/dev/null
    echo "  wrote public/logo-${size}.png"
done

# WebP derivatives for the web-facing surfaces.
cwebp -quiet -q 90 "$MASTER" -o public/images/logo.webp
echo "  wrote public/images/logo.webp"

cwebp -quiet -q 90 -resize 64 64 "$MASTER" -o public/images/logo-crest-64.webp
echo "  wrote public/images/logo-crest-64.webp"

# favicon.ico. sips cannot write .ico, and the existing file is byte-identical to
# the 32px PNG — browsers accept a PNG served at that path, and every modern one
# prefers the explicit <link rel="icon"> tags in templates/landing/base.html.twig
# anyway. Keeping the two in step preserves the status quo rather than inventing
# a real multi-resolution ICO that nothing here has ever had.
cp public/logo-32.png public/favicon.ico
echo "  wrote public/favicon.ico (32px PNG payload, as before)"

echo
echo "Done. Hard-refresh to get past the browser's favicon cache."
