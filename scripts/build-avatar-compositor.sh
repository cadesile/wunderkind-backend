#!/usr/bin/env bash
#
# Regenerates public/assets/avatar-compositor.js from the app's avatar16.ts.
#
#   bash scripts/build-avatar-compositor.sh [path/to/avatar16.ts]
#
# WHY A BUILD STEP
#
# This repo's standing rule (CLAUDE.md, Avatar Appearance) is that the admin
# compositor is a VERBATIM port of the app's sprite logic — "do not hand-
# reinterpret the pixel math — port it verbatim if the sprite's drawing logic
# ever changes". avatar16.ts is ~480 lines of pixel maths and is itself a frozen
# 1:1 port of the approved web builder, guarded by a pixel-hash fixture.
#
# A hand transcription cannot credibly honour "verbatim" a second time. esbuild
# can: it strips types and changes nothing else. The only hand-written part of
# the bundle is scripts/avatar-compositor/entry.ts, which just adapts the
# renderer to the window API the admin widget and landing page already call.
#
# The parity fixture is re-checked on every run. A drifted port fails the build
# rather than shipping a silently-wrong avatar.
#
# Requires: the wunderkind-app checkout beside this one (for avatar16.ts, its
# parity fixture and its esbuild binary). Nothing is written to that repo.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_DIR="${APP_DIR:-../wunderkind-app}"
SRC="${1:-$APP_DIR/src/components/avatar-sprite/avatar16.ts}"
FIXTURE="$(dirname "$SRC")/__fixtures__/parity.json"
OUT="public/assets/avatar-compositor.js"
ESBUILD="$APP_DIR/node_modules/.bin/esbuild"

[[ -f "$SRC" ]]     || { echo "avatar16.ts not found at $SRC" >&2; exit 1; }
[[ -f "$FIXTURE" ]] || { echo "parity fixture not found at $FIXTURE" >&2; exit 1; }
[[ -x "$ESBUILD" ]] || { echo "esbuild not found at $ESBUILD (run npm install in $APP_DIR)" >&2; exit 1; }

# The parity step runs from a temp dir, so every path handed to it must be
# absolute — a relative one silently resolves against the wrong base.
SRC="$(cd "$(dirname "$SRC")" && pwd)/$(basename "$SRC")"
FIXTURE="$(cd "$(dirname "$FIXTURE")" && pwd)/$(basename "$FIXTURE")"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cp "$SRC" "$WORK/avatar16.ts"
cp scripts/avatar-compositor/entry.ts "$WORK/entry.ts"

# ── 1. Parity gate: the source must still match the approved builder ──────────
"$ESBUILD" "$WORK/avatar16.ts" --format=esm --target=es2020 --outfile="$WORK/avatar16.mjs" >/dev/null

cat > "$WORK/parity.mjs" <<'PARITY'
import { readFileSync } from 'node:fs';
import { buildGrid, DEFAULT_AVATAR_CONFIG } from './avatar16.mjs';

const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const hash = (grid) => {
  let h = 0x811c9dc5, filled = 0;
  for (let y = 0; y < grid.length; y++) for (let x = 0; x < grid[y].length; x++) {
    const v = grid[y][x];
    if (v === null || v === undefined) continue;
    filled++;
    for (const c of `${x},${y},${v};`) { h ^= c.charCodeAt(0); h = Math.imul(h, 0x01000193) >>> 0; }
  }
  return { fnv: h.toString(16).padStart(8, '0'), filled };
};

let fail = 0;
for (const [name, c] of Object.entries(fx.cases)) {
  const { grid } = buildGrid({ ...DEFAULT_AVATAR_CONFIG, ...c.config }, c.options ?? {});
  const got = hash(grid);
  if (got.fnv !== c.fnv || got.filled !== c.filled) {
    fail++;
    console.error(`  FAIL ${name}: expected ${c.fnv}/${c.filled}, got ${got.fnv}/${got.filled}`);
  }
}
const total = Object.keys(fx.cases).length;
console.log(`  parity: ${total - fail}/${total} cases match`);
process.exit(fail ? 1 : 0);
PARITY

echo "Checking parity against $FIXTURE"
( cd "$WORK" && node parity.mjs "$FIXTURE" )

# ── 2. Bundle entry + avatar16 into one browser IIFE ─────────────────────────
"$ESBUILD" "$WORK/entry.ts" \
  --bundle --format=iife --target=es2017 --outfile="$WORK/bundle.js" >/dev/null

# esbuild stamps each module's source path into a comment, and $WORK is a random
# mktemp directory — so without this the bundle differs on every run even when
# nothing changed. Rewrite them to stable names so a no-op rebuild is a no-op diff.
sed -i '' -e "s#// .*/avatar16\.ts\$#// avatar16.ts (from the app, see header)#" \
          -e "s#// .*/entry\.ts\$#// scripts/avatar-compositor/entry.ts#" "$WORK/bundle.js"

SRC_HASH="$(shasum -a 256 "$SRC" | cut -c1-16)"

{
  echo "// Admin + landing avatar compositor — GENERATED, DO NOT EDIT BY HAND."
  echo "//"
  echo "// Regenerate:  bash scripts/build-avatar-compositor.sh"
  echo "//"
  echo "// Source:      wunderkind-app/src/components/avatar-sprite/avatar16.ts"
  echo "// Source sha:  $SRC_HASH"
  echo "//"
  echo "// No build timestamp on purpose: the output is then byte-identical when"
  echo "// the source has not changed, so a rebuild produces an empty diff and"
  echo "// any diff at all means the sprite really moved."
  echo "//"
  echo "// Everything below is that file passed through esbuild (type-stripping"
  echo "// only, no other transformation) plus scripts/avatar-compositor/entry.ts,"
  echo "// which adapts it to the window API the admin widget and landing page"
  echo "// call. The build verifies the source against its pixel-hash parity"
  echo "// fixture first, so a drifted port fails rather than shipping."
  echo "//"
  echo "// Exposes: window.composePlayerSpriteSvg(config, type, crop, scale, opts)"
  echo "//          window.SKIN_SHADES"
  echo "//          window.AVATAR_BODY_SIZE { heightToBodySize, weightToBodySize }"
  echo "//          window.DEFAULT_AVATAR_CONFIG"
  cat "$WORK/bundle.js"
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, source sha $SRC_HASH)"
