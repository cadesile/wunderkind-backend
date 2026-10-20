#!/usr/bin/env bash
#
# Regenerates public/assets/stadium-compositor.js from the facility sprite drop.
#
#   bash scripts/build-stadium-compositor.sh [path/to/facilities]
#
# The fourth sibling of scripts/build-{avatar,kit,trophy}-compositor.sh,
# deliberately kept as a separate self-contained script rather than factored
# into a shared one: the four differ in where their source lives and in how
# their parity fixtures are shaped (the avatar's hashes buildGrid().grid; the
# kit's has separate `kit`/`badge` case sets; the trophy's is a flat `cases` map;
# this one is four per-site maps whose cases carry rotation/time/selected/
# facility options and are hashed from a PIXEL BUFFER, not a grid). Four
# ~130-line scripts each readable end to end beat a five-file abstraction in
# shell.
#
# WHY A BUILD STEP
#
# The previous stadium-compositor.js was a HAND port of v1's isoEngine.ts +
# stadiumSprite.ts — viable when isoEngine was ~19KB and only the handful of
# helpers the stadium touched had to be transcribed. The v2 drop is ~32KB of
# isoEngine alone (new lighting in paintPixels, shared massing, five tree kinds)
# and ships 301 pixel hashes. A hand transcription cannot credibly honour
# "verbatim" against that; esbuild can, since it strips types and changes
# nothing else. This brings the stadium under the same rule the other three
# compositors already follow.
#
# The only hand-written part of the bundle is scripts/stadium-compositor/entry.ts,
# which adapts the renderer to the window API landing.js calls.
#
# WHAT THE PARITY GATE COVERS
#
# All four sites in the drop (stadium, training ground, medical centre,
# scouting), not just the stadium — isoEngine.ts is shared, so a drift in any
# site's hashes is a drift in the stadium's own renderer, and catching it here
# is one `cp` away. Only the stadium is BUNDLED: the backend renders nothing
# else, and plotSite.ts plus the other three sprite files are copied in for the
# gate only. The legacy/ (look="v1") tree is deliberately not checked — nothing
# here renders v1.
#
# Requires the wunderkind-app checkout beside this one, for its esbuild binary
# only. Nothing is written to that repo.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_DIR="${APP_DIR:-../wunderkind-app}"
SRC_DIR="${1:-${FACILITIES_DIR:-../assets/Components/facilitiesV2/facilities}}"
FIXTURE="$SRC_DIR/__fixtures__/parity.json"
OUT="public/assets/stadium-compositor.js"
ESBUILD="$APP_DIR/node_modules/.bin/esbuild"

[[ -f "$SRC_DIR/stadiumSprite.ts" ]] || { echo "stadiumSprite.ts not found in $SRC_DIR" >&2; exit 1; }
[[ -f "$FIXTURE" ]] || { echo "parity fixture not found at $FIXTURE" >&2; exit 1; }
[[ -x "$ESBUILD" ]] || { echo "esbuild not found at $ESBUILD (run npm install in $APP_DIR)" >&2; exit 1; }

# The parity step runs from a temp dir, so every path handed to it must be
# absolute — a relative one silently resolves against the wrong base.
SRC_DIR="$(cd "$SRC_DIR" && pwd)"
FIXTURE="$SRC_DIR/__fixtures__/parity.json"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

for f in isoEngine plotSite stadiumSprite trainingGroundSprite medicalCentreSprite scoutingSprite; do
  cp "$SRC_DIR/$f.ts" "$WORK/$f.ts"
done
cp scripts/stadium-compositor/entry.ts "$WORK/entry.ts"

# ── 1. Parity gate: the source must still match the approved web builders ─────
for f in isoEngine plotSite stadiumSprite trainingGroundSprite medicalCentreSprite scoutingSprite; do
  "$ESBUILD" "$WORK/$f.ts" --format=esm --target=es2020 --outfile="$WORK/$f.mjs" >/dev/null
done
# esbuild leaves the import specifiers as written ('./isoEngine'), which Node's
# ESM loader will not resolve to a .mjs — rewrite them to the emitted filenames.
sed -i '' -e "s#\(from \"\./[A-Za-z]*\)\"#\1.mjs\"#g" "$WORK"/*.mjs

cat > "$WORK/parity.mjs" <<'PARITY'
import { readFileSync } from 'node:fs';
import { parityHash } from './isoEngine.mjs';
import { stadiumPixels, DEFAULT_STADIUM_CONFIG } from './stadiumSprite.mjs';
import { trainingGroundPixels, DEFAULT_TRAINING_GROUND_CONFIG } from './trainingGroundSprite.mjs';
import { medicalCentrePixels, DEFAULT_MEDICAL_CENTRE_CONFIG } from './medicalCentreSprite.mjs';
import { scoutingPixels, DEFAULT_SCOUTING_CONFIG } from './scoutingSprite.mjs';

// parityHash() comes from the sprite's own isoEngine rather than being
// reimplemented here, so the gate cannot drift from the hash the fixture was
// generated with.
const sites = {
  stadium:  [stadiumPixels, DEFAULT_STADIUM_CONFIG],
  training: [trainingGroundPixels, DEFAULT_TRAINING_GROUND_CONFIG],
  medical:  [medicalCentrePixels, DEFAULT_MEDICAL_CENTRE_CONFIG],
  scouting: [scoutingPixels, DEFAULT_SCOUTING_CONFIG],
};

const fx = JSON.parse(readFileSync(process.argv[2], 'utf8'));
let fail = 0, total = 0;

for (const [site, cases] of Object.entries(fx)) {
  if (site.startsWith('_')) continue;
  const entry = sites[site];
  if (!entry) { console.error(`  FAIL ${site}: fixture has a site this gate does not render`); fail++; continue; }
  const [pixels, defaults] = entry;

  for (const [name, c] of Object.entries(cases)) {
    total++;
    // Each case carries its own render options — the fixture covers all four
    // rotations, day/night, every `selected` slug and per-facility crops.
    // Dropping them silently collapses that coverage.
    const got = parityHash(pixels({ ...defaults, ...c.config }, {
      rotation: c.rotation, time: c.time, selected: c.selected, facility: c.facility,
    }));
    if (got.fnv !== c.fnv || got.opaque !== c.opaque || got.width !== c.width || got.height !== c.height) {
      fail++;
      console.error(`  FAIL ${site}/${name}: want ${c.fnv}/${c.opaque}/${c.width}x${c.height}, got ${got.fnv}/${got.opaque}/${got.width}x${got.height}`);
    }
  }
}
console.log(`  parity: ${total - fail}/${total} cases match`);
process.exit(fail ? 1 : 0);
PARITY

echo "Checking parity against $FIXTURE"
( cd "$WORK" && node parity.mjs "$FIXTURE" )

# ── 2. Bundle entry + stadiumSprite + isoEngine into one browser IIFE ────────
"$ESBUILD" "$WORK/entry.ts" \
  --bundle --format=iife --target=es2017 --outfile="$WORK/bundle.js" >/dev/null

# esbuild stamps each module's source path into a comment, and $WORK is a random
# mktemp directory — so without this the bundle differs on every run even when
# nothing changed. Rewrite them to stable names so a no-op rebuild is a no-op diff.
sed -i '' -e "s#// .*/isoEngine\.ts\$#// isoEngine.ts (from the shared assets, see header)#" \
          -e "s#// .*/stadiumSprite\.ts\$#// stadiumSprite.ts (from the shared assets, see header)#" \
          -e "s#// .*/entry\.ts\$#// scripts/stadium-compositor/entry.ts#" "$WORK/bundle.js"

SRC_HASH="$(cat "$SRC_DIR/isoEngine.ts" "$SRC_DIR/stadiumSprite.ts" | shasum -a 256 | cut -c1-16)"

{
  echo "// Landing-page stadium compositor — GENERATED, DO NOT EDIT BY HAND."
  echo "//"
  echo "// Regenerate:  bash scripts/build-stadium-compositor.sh"
  echo "//"
  echo "// Source:      assets/Components/facilitiesV2/facilities/{isoEngine,stadiumSprite}.ts"
  echo "// Source sha:  $SRC_HASH"
  echo "//"
  echo "// No build timestamp on purpose: the output is then byte-identical when"
  echo "// the source has not changed, so a rebuild produces an empty diff and"
  echo "// any diff at all means the sprite really moved."
  echo "//"
  echo "// Everything below is those two files passed through esbuild (type-"
  echo "// stripping only, no other transformation) plus"
  echo "// scripts/stadium-compositor/entry.ts, which adapts them to the window"
  echo "// API the landing page's Club Spotlight calls. The build verifies the"
  echo "// whole facility drop against its 301-case pixel-hash parity fixture"
  echo "// first, so a drifted port fails rather than shipping."
  echo "//"
  echo "// Only the stadium is bundled; the training-ground, medical-centre and"
  echo "// scouting sites in the same drop are parity-checked but not shipped,"
  echo "// because nothing in this repo renders them."
  echo "//"
  echo "// Exposes: window.composeStadiumSvg(config, opts, scale)"
  echo "//          window.DEFAULT_STADIUM_CONFIG"
  cat "$WORK/bundle.js"
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, source sha $SRC_HASH)"
