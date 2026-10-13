#!/usr/bin/env bash
#
# Regenerates public/assets/kit-compositor.js from the kit+badge sprite source.
#
#   bash scripts/build-kit-compositor.sh [path/to/kit16.ts]
#
# The sibling of scripts/build-avatar-compositor.sh, deliberately kept as a
# separate self-contained script rather than factored into a shared one: the two
# differ in where their source lives and in how their parity fixtures are shaped
# (this one has separate `kit` and `badge` case sets and hashes the raw grid
# builders; the avatar's hashes buildGrid().grid). Two ~110-line scripts each
# readable end to end beat a three-file abstraction in shell.
#
# WHY A BUILD STEP
#
# This repo's standing rule (CLAUDE.md, Kit & Badge Identity) is that the admin
# compositor is a VERBATIM port of the app's sprite logic. kit16.ts is itself a
# frozen 1:1 port of the approved web Kit Builder v2, guarded by a pixel-hash
# fixture. A hand transcription cannot credibly honour "verbatim" a second time;
# esbuild can, since it strips types and changes nothing else.
#
# The only hand-written part of the bundle is scripts/kit-compositor/entry.ts,
# which adapts the renderer to the window API the widget and landing page
# already call. The parity fixture is re-checked on every run, so a drifted port
# fails the build rather than shipping a silently-wrong kit.
#
# Requires the wunderkind-app checkout beside this one, for its esbuild binary
# only. Nothing is written to that repo.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_DIR="${APP_DIR:-../wunderkind-app}"
SRC="${1:-${KITBADGE_DIR:-../assets/Components/KitBadgeV2}/kit16.ts}"
FIXTURE="$(dirname "$SRC")/__fixtures__/parity.json"
OUT="public/assets/kit-compositor.js"
ESBUILD="$APP_DIR/node_modules/.bin/esbuild"

[[ -f "$SRC" ]]     || { echo "kit16.ts not found at $SRC" >&2; exit 1; }
[[ -f "$FIXTURE" ]] || { echo "parity fixture not found at $FIXTURE" >&2; exit 1; }
[[ -x "$ESBUILD" ]] || { echo "esbuild not found at $ESBUILD (run npm install in $APP_DIR)" >&2; exit 1; }

# The parity step runs from a temp dir, so every path handed to it must be
# absolute — a relative one silently resolves against the wrong base.
SRC="$(cd "$(dirname "$SRC")" && pwd)/$(basename "$SRC")"
FIXTURE="$(cd "$(dirname "$FIXTURE")" && pwd)/$(basename "$FIXTURE")"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cp "$SRC" "$WORK/kit16.ts"
cp scripts/kit-compositor/entry.ts "$WORK/entry.ts"

# ── 1. Parity gate: the source must still match the approved builder ──────────
"$ESBUILD" "$WORK/kit16.ts" --format=esm --target=es2020 --outfile="$WORK/kit16.mjs" >/dev/null

cat > "$WORK/parity.mjs" <<'PARITY'
import { readFileSync } from 'node:fs';
import { buildKitGrid, buildBadgeGrid, DEFAULT_KIT_CONFIG } from './kit16.mjs';

const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const hash = (grid) => {
  let h = 0x811c9dc5, filled = 0;
  for (let y = 0; y < grid.length; y++) for (let x = 0; x < grid[y].length; x++) {
    const v = grid[y][x];
    if (v === null || v === undefined) continue;
    filled++;
    for (const c of `${x},${y},${v};`) { h ^= c.charCodeAt(0); h = Math.imul(h, 0x01000193) >>> 0; }
  }
  return { fnv: h.toString(16).padStart(8, '0'), filled, w: grid[0].length, h: grid.length };
};

let fail = 0, total = 0;
for (const [section, build] of [['kit', buildKitGrid], ['badge', buildBadgeGrid]]) {
  for (const [name, c] of Object.entries(fx[section])) {
    total++;
    // Each entry carries its own outline flag — the fixture covers both states
    // (keys ending _outline are the on cases). Dropping it silently halves the
    // coverage and lets an outline regression through.
    const got = hash(build({ ...DEFAULT_KIT_CONFIG, ...c.config }, !!c.outline));
    if (got.fnv !== c.fnv || got.filled !== c.filled || got.w !== c.width || got.h !== c.height) {
      fail++;
      console.error(`  FAIL ${section}/${name}: want ${c.fnv}/${c.filled}/${c.width}x${c.height}, got ${got.fnv}/${got.filled}/${got.w}x${got.h}`);
    }
  }
}
console.log(`  parity: ${total - fail}/${total} cases match`);
process.exit(fail ? 1 : 0);
PARITY

echo "Checking parity against $FIXTURE"
( cd "$WORK" && node parity.mjs "$FIXTURE" )

# ── 2. Bundle entry + kit16 into one browser IIFE ─────────────────────────
"$ESBUILD" "$WORK/entry.ts" \
  --bundle --format=iife --target=es2017 --outfile="$WORK/bundle.js" >/dev/null

# esbuild stamps each module's source path into a comment, and $WORK is a random
# mktemp directory — so without this the bundle differs on every run even when
# nothing changed. Rewrite them to stable names so a no-op rebuild is a no-op diff.
sed -i '' -e "s#// .*/kit16\.ts\$#// kit16.ts (from the app, see header)#" \
          -e "s#// .*/entry\.ts\$#// scripts/kit-compositor/entry.ts#" "$WORK/bundle.js"

SRC_HASH="$(shasum -a 256 "$SRC" | cut -c1-16)"

{
  echo "// Admin + landing kit & badge compositor — GENERATED, DO NOT EDIT BY HAND."
  echo "//"
  echo "// Regenerate:  bash scripts/build-kit-compositor.sh"
  echo "//"
  echo "// Source:      assets/Components/KitBadgeV2/kit16.ts"
  echo "// Source sha:  $SRC_HASH"
  echo "//"
  echo "// No build timestamp on purpose: the output is then byte-identical when"
  echo "// the source has not changed, so a rebuild produces an empty diff and"
  echo "// any diff at all means the sprite really moved."
  echo "//"
  echo "// Everything below is that file passed through esbuild (type-stripping"
  echo "// only, no other transformation) plus scripts/kit-compositor/entry.ts,"
  echo "// which adapts it to the window API the kit widget and landing page"
  echo "// call. The build verifies the source against its pixel-hash parity"
  echo "// fixture first, so a drifted port fails rather than shipping."
  echo "//"
  echo "// Exposes: window.composeKitSvg(config, type, crop, scale)"
  echo "//          window.cleanInitials"
  echo "//          window.KIT_SPRITE_OPTIONS, window.DEFAULT_KIT_CONFIG"
  cat "$WORK/bundle.js"
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, source sha $SRC_HASH)"
