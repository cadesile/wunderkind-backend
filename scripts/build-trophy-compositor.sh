#!/usr/bin/env bash
#
# Regenerates public/assets/trophy-compositor.js from the trophy sprite source.
#
#   bash scripts/build-trophy-compositor.sh [path/to/trophy16.ts]
#
# The third sibling of scripts/build-avatar-compositor.sh and
# scripts/build-kit-compositor.sh, deliberately kept as a separate
# self-contained script rather than factored into a shared one: the three differ
# in where their source lives and in how their parity fixtures are shaped (the
# avatar's hashes buildGrid().grid; the kit's has separate `kit`/`badge` case
# sets; this one is a flat `cases` map whose entries carry their own render
# `options`). Three ~110-line scripts each readable end to end beat a four-file
# abstraction in shell.
#
# WHY A BUILD STEP
#
# This repo's standing rule (CLAUDE.md, Trophy Identity) is that the admin
# compositor is a VERBATIM port of the app's sprite logic. trophy16.ts is itself
# a frozen 1:1 port of the approved web "Trophy Builder", guarded by a
# pixel-hash fixture. A hand transcription cannot credibly honour "verbatim" a
# second time; esbuild can, since it strips types and changes nothing else.
#
# The only hand-written part of the bundle is scripts/trophy-compositor/entry.ts,
# which adapts the renderer to the window API the widget and templates call. The
# parity fixture is re-checked on every run, so a drifted port fails the build
# rather than shipping a silently-wrong trophy.
#
# Requires the wunderkind-app checkout beside this one, for its esbuild binary
# only. Nothing is written to that repo.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_DIR="${APP_DIR:-../wunderkind-app}"
SRC="${1:-${TROPHY_DIR:-../assets/Components/trophy16}/trophy16.ts}"
FIXTURE="$(dirname "$SRC")/__fixtures__/parity.json"
OUT="public/assets/trophy-compositor.js"
ESBUILD="$APP_DIR/node_modules/.bin/esbuild"

[[ -f "$SRC" ]]     || { echo "trophy16.ts not found at $SRC" >&2; exit 1; }
[[ -f "$FIXTURE" ]] || { echo "parity fixture not found at $FIXTURE" >&2; exit 1; }
[[ -x "$ESBUILD" ]] || { echo "esbuild not found at $ESBUILD (run npm install in $APP_DIR)" >&2; exit 1; }

# The parity step runs from a temp dir, so every path handed to it must be
# absolute — a relative one silently resolves against the wrong base.
SRC="$(cd "$(dirname "$SRC")" && pwd)/$(basename "$SRC")"
FIXTURE="$(cd "$(dirname "$FIXTURE")" && pwd)/$(basename "$FIXTURE")"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cp "$SRC" "$WORK/trophy16.ts"
cp scripts/trophy-compositor/entry.ts "$WORK/entry.ts"

# ── 1. Parity gate: the source must still match the approved builder ──────────
"$ESBUILD" "$WORK/trophy16.ts" --format=esm --target=es2020 --outfile="$WORK/trophy16.mjs" >/dev/null

cat > "$WORK/parity.mjs" <<'PARITY'
import { readFileSync } from 'node:fs';
import { buildTrophyGrid, DEFAULT_TROPHY_CONFIG } from './trophy16.mjs';

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
for (const [name, c] of Object.entries(fx.cases)) {
  total++;
  // Each case carries its own render options — the fixture covers shadow on/off
  // and outline on/off. Dropping them silently collapses that coverage and lets
  // a shadow or outline regression through.
  const got = hash(buildTrophyGrid({ ...DEFAULT_TROPHY_CONFIG, ...c.config }, c.options ?? {}));
  if (got.fnv !== c.fnv || got.filled !== c.filled || got.w !== c.width || got.h !== c.height) {
    fail++;
    console.error(`  FAIL ${name}: want ${c.fnv}/${c.filled}/${c.width}x${c.height}, got ${got.fnv}/${got.filled}/${got.w}x${got.h}`);
  }
}
console.log(`  parity: ${total - fail}/${total} cases match`);
process.exit(fail ? 1 : 0);
PARITY

echo "Checking parity against $FIXTURE"
( cd "$WORK" && node parity.mjs "$FIXTURE" )

# ── 2. Bundle entry + trophy16 into one browser IIFE ─────────────────────────
"$ESBUILD" "$WORK/entry.ts" \
  --bundle --format=iife --target=es2017 --outfile="$WORK/bundle.js" >/dev/null

# esbuild stamps each module's source path into a comment, and $WORK is a random
# mktemp directory — so without this the bundle differs on every run even when
# nothing changed. Rewrite them to stable names so a no-op rebuild is a no-op diff.
sed -i '' -e "s#// .*/trophy16\.ts\$#// trophy16.ts (from the shared assets, see header)#" \
          -e "s#// .*/entry\.ts\$#// scripts/trophy-compositor/entry.ts#" "$WORK/bundle.js"

SRC_HASH="$(shasum -a 256 "$SRC" | cut -c1-16)"

{
  echo "// Admin + landing trophy compositor — GENERATED, DO NOT EDIT BY HAND."
  echo "//"
  echo "// Regenerate:  bash scripts/build-trophy-compositor.sh"
  echo "//"
  echo "// Source:      assets/Components/trophy16/trophy16.ts"
  echo "// Source sha:  $SRC_HASH"
  echo "//"
  echo "// No build timestamp on purpose: the output is then byte-identical when"
  echo "// the source has not changed, so a rebuild produces an empty diff and"
  echo "// any diff at all means the sprite really moved."
  echo "//"
  echo "// Everything below is that file passed through esbuild (type-stripping"
  echo "// only, no other transformation) plus scripts/trophy-compositor/entry.ts,"
  echo "// which adapts it to the window API the trophy widget, the admin leagues"
  echo "// screen and the landing page's honours board call. The build verifies"
  echo "// the source against its pixel-hash parity fixture first, so a drifted"
  echo "// port fails rather than shipping."
  echo "//"
  echo "// Exposes: window.composeTrophySvg(config, scale, opts)"
  echo "//          window.randomTrophyConfig()"
  echo "//          window.TROPHY_SPRITE_OPTIONS, window.DEFAULT_TROPHY_CONFIG"
  echo "//          window.TROPHY_BG, window.TROPHY_SPRITE_SIZE"
  cat "$WORK/bundle.js"
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, source sha $SRC_HASH)"
