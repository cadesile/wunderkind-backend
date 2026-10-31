#!/usr/bin/env bash
#
# Regenerates public/assets/facility-compositor.js (all four sites) and the PHP
# slug map that has to agree with it, from the facility sprite drop.
#
#   bash scripts/build-facility-compositor.sh [path/to/facilities]
#
# The fifth sibling of scripts/build-{avatar,kit,trophy,stadium}-compositor.sh,
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
# The only hand-written part of the bundle is scripts/facility-compositor/entry.ts,
# which adapts the four renderers to the window API the landing page calls.
#
# WHY THIS EXISTS ALONGSIDE build-stadium-compositor.sh
#
# That script ships the stadium alone, which is all the old landing page drew.
# The redesign draws all four sites, and isoEngine (~32KB) is shared by every
# one of them — so four bundles would ship four copies of it. This builds one.
# Both scripts are kept until the landing page switches its <script> tag:
# composeStadiumSvg keeps an identical signature here, so that switch is a
# one-line change, and keeping the old file working in the meantime means the
# two tasks do not have to land together.
#
# WHY IT ALSO GENERATES PHP
#
# The sprite source is the only place that states which game facility slug draws
# as which sprite id (`slug` on each facility, plus STADIUM_SLUGS). The backend
# needs that same mapping to turn a club's ClubFacility rows into sprite configs.
# Hand-copying it into PHP would be a second copy free to drift the moment the
# drop renames an id, so it is generated from the source here instead — same
# GENERATED/DO-NOT-EDIT rule as the bundle itself. The PHP file is committed so
# nothing at runtime depends on the external drop being checked out.
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
# The stadium-v3 drop renamed every site module from *Sprite.ts to *Config.ts and
# added stadiumSurrounds.ts, and it is integrated into the app rather than living
# in a standalone drop directory — assets/Components/facilitiesV2/ no longer
# exists. So the default source is now the app's own copy, the same convention
# scripts/build-avatar-compositor.sh already uses. Still read-only: nothing is
# written to that repo.
SRC_DIR="${1:-${FACILITIES_DIR:-$APP_DIR/src/components/facility-sprites}}"
FIXTURE="$SRC_DIR/__fixtures__/parity.json"
# The stadium has its own fixture from v3 onwards, with two case sets: `cases`
# (the v3 look) and `v2Look` (the previous drop's 63 hashes, re-rendered with
# STADIUM_V2_LOOK). Verified: v2Look's keys and hashes are identical to the old
# four-site fixture's `stadium` map, so that map is deliberately skipped below
# rather than checked twice.
STADIUM_FIXTURE="$SRC_DIR/__fixtures__/stadium-v3.parity.json"
STADIUM_GEOMETRY="$SRC_DIR/__fixtures__/stadium-v3.geometry.json"
OUT="public/assets/facility-compositor.js"
PHP_OUT="src/Service/Facility/FacilitySpriteMap.php"
ESBUILD="$APP_DIR/node_modules/.bin/esbuild"

[[ -f "$SRC_DIR/stadiumConfig.ts" ]] || { echo "stadiumConfig.ts not found in $SRC_DIR" >&2; exit 1; }
[[ -f "$FIXTURE" ]] || { echo "parity fixture not found at $FIXTURE" >&2; exit 1; }
[[ -f "$STADIUM_FIXTURE" ]] || { echo "stadium v3 parity fixture not found at $STADIUM_FIXTURE" >&2; exit 1; }
[[ -f "$STADIUM_GEOMETRY" ]] || { echo "stadium v3 geometry fixture not found at $STADIUM_GEOMETRY" >&2; exit 1; }
[[ -x "$ESBUILD" ]] || { echo "esbuild not found at $ESBUILD (run npm install in $APP_DIR)" >&2; exit 1; }

# The parity step runs from a temp dir, so every path handed to it must be
# absolute — a relative one silently resolves against the wrong base.
SRC_DIR="$(cd "$SRC_DIR" && pwd)"
FIXTURE="$SRC_DIR/__fixtures__/parity.json"
STADIUM_FIXTURE="$SRC_DIR/__fixtures__/stadium-v3.parity.json"
STADIUM_GEOMETRY="$SRC_DIR/__fixtures__/stadium-v3.geometry.json"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

for f in isoEngine plotSite stadiumConfig stadiumSurrounds trainingGroundConfig medicalCentreConfig scoutingConfig; do
  cp "$SRC_DIR/$f.ts" "$WORK/$f.ts"
done
cp scripts/facility-compositor/entry.ts "$WORK/entry.ts"

# ── 1. Parity gate: the source must still match the approved web builders ─────
for f in isoEngine plotSite stadiumConfig stadiumSurrounds trainingGroundConfig medicalCentreConfig scoutingConfig; do
  "$ESBUILD" "$WORK/$f.ts" --format=esm --target=es2020 --outfile="$WORK/$f.mjs" >/dev/null
done
# esbuild leaves the import specifiers as written ('./isoEngine'), which Node's
# ESM loader will not resolve to a .mjs — rewrite them to the emitted filenames.
sed -i '' -e "s#\(from \"\./[A-Za-z]*\)\"#\1.mjs\"#g" "$WORK"/*.mjs

cat > "$WORK/parity.mjs" <<'PARITY'
import { readFileSync } from 'node:fs';
import { parityHash } from './isoEngine.mjs';
import {
  stadiumPixels, DEFAULT_STADIUM_CONFIG, STADIUM_V2_LOOK,
  STADIUM_GEOMETRY, FLOODLIGHT_ARCH, SCOREBOARD_STAND,
} from './stadiumConfig.mjs';
import { SURROUND_RING, SURROUND_SPURS, SURROUND_TREE_DENSITY, SURROUND_THUMB } from './stadiumSurrounds.mjs';
import { trainingGroundPixels, DEFAULT_TRAINING_GROUND_CONFIG } from './trainingGroundConfig.mjs';
import { medicalCentrePixels, DEFAULT_MEDICAL_CENTRE_CONFIG } from './medicalCentreConfig.mjs';
import { scoutingPixels, DEFAULT_SCOUTING_CONFIG } from './scoutingConfig.mjs';

// parityHash() comes from the sprite's own isoEngine rather than being
// reimplemented here, so the gate cannot drift from the hash the fixture was
// generated with.
const sites = {
  training: [trainingGroundPixels, DEFAULT_TRAINING_GROUND_CONFIG],
  medical:  [medicalCentrePixels, DEFAULT_MEDICAL_CENTRE_CONFIG],
  scouting: [scoutingPixels, DEFAULT_SCOUTING_CONFIG],
};

const fx        = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const stadiumFx = JSON.parse(readFileSync(process.argv[3], 'utf8'));
const geomFx    = JSON.parse(readFileSync(process.argv[4], 'utf8'));

let fail = 0, total = 0;

// The two fixture generations shape their render options differently, and the
// difference is invisible until a cropped facility tile silently renders as the
// whole site: the four-site fixture puts rotation/time/selected/facility FLAT on
// the case, while stadium-v3 nests everything under `options`. Read both, and
// forward whatever is there WHOLESALE — cherry-picking keys (as this gate used
// to) collapses v3's surrounds/floodlightStyle/scoreboardPlacement coverage to
// the defaults.
const optionsOf = (c) => {
  if (c.options) return c.options;
  const { rotation, time, selected, facility } = c;
  const o = {};
  if (rotation !== undefined) o.rotation = rotation;
  if (time !== undefined) o.time = time;
  if (selected !== undefined) o.selected = selected;
  if (facility !== undefined) o.facility = facility;
  return o;
};

const check = (label, pixels, defaults, c) => {
  total++;
  const got = parityHash(pixels({ ...defaults, ...(c.config ?? {}) }, optionsOf(c)));
  if (got.fnv !== c.fnv || got.opaque !== c.opaque || got.width !== c.width || got.height !== c.height) {
    fail++;
    console.error(`  FAIL ${label}: want ${c.fnv}/${c.opaque}/${c.width}x${c.height}, got ${got.fnv}/${got.opaque}/${got.width}x${got.height}`);
  }
};

// The three sites whose fixture shape is unchanged. The four-site fixture's own
// `stadium` map is skipped deliberately — it IS stadium-v3's `v2Look`, verified
// key-for-key and hash-for-hash, so checking it here would only duplicate work.
for (const [site, cases] of Object.entries(fx)) {
  if (site.startsWith('_')) continue;
  if (site === 'stadium') continue;
  const entry = sites[site];
  if (!entry) { console.error(`  FAIL ${site}: fixture has a site this gate does not render`); fail++; continue; }
  for (const [name, c] of Object.entries(cases)) check(`${site}/${name}`, ...entry, c);
}

// The stadium, both ways round: the v3 look, and the v2 look restored via
// STADIUM_V2_LOOK. The second set is what proves v3 did not quietly move
// anything it claimed to leave alone.
for (const [name, c] of Object.entries(stadiumFx.cases)) {
  check(`stadium/${name}`, stadiumPixels, DEFAULT_STADIUM_CONFIG, c);
}
for (const [name, c] of Object.entries(stadiumFx.v2Look)) {
  check(`stadium/v2Look/${name}`, stadiumPixels, DEFAULT_STADIUM_CONFIG, {
    ...c,
    options: { ...STADIUM_V2_LOOK, ...optionsOf(c) },
  });
}

// Geometry is exported as data and mirrored in the fixture, so a silent change
// to the ring, the spurs, the arch maths or the scoreboard's stand is caught
// here rather than by eye. A different class of drift from the pixel hashes:
// these are the numbers a HOST sizes its containers from.
const geomChecks = {
  'image':               [[STADIUM_GEOMETRY.imageWidth, STADIUM_GEOMETRY.imageHeight], [geomFx.image.width, geomFx.image.height]],
  'surroundRing':        [SURROUND_RING, geomFx.surroundRing],
  'surroundSpurs':       [SURROUND_SPURS, geomFx.surroundSpurs],
  'surroundTreeDensity': [SURROUND_TREE_DENSITY, geomFx.surroundTreeDensity],
  'floodlightArch':      [FLOODLIGHT_ARCH, geomFx.floodlightArch],
  'scoreboardStand':     [SCOREBOARD_STAND, geomFx.scoreboardStand],
};

for (const [label, [got, want]] of Object.entries(geomChecks)) {
  // Subset compare: the fixture entries carry documentation keys the exported
  // constants do not, so every key the FIXTURE names must match, and extra
  // exported keys are not a failure.
  const flat = (v) => JSON.stringify(v);
  let ok = true;
  if (Array.isArray(want)) {
    ok = flat(got) === flat(want);
  } else if (want && typeof want === 'object') {
    for (const k of Object.keys(want)) {
      if (got?.[k] === undefined) continue;
      if (flat(got[k]) !== flat(want[k])) { ok = false; console.error(`  FAIL geometry/${label}.${k}: want ${flat(want[k])}, got ${flat(got[k])}`); }
    }
  }
  if (!ok) fail++;
}

console.log(`  parity: ${total - fail}/${total} cases match (${Object.keys(stadiumFx.cases).length} stadium v3 + ${Object.keys(stadiumFx.v2Look).length} v2-look + the other three sites), geometry checked`);
process.exit(fail ? 1 : 0);
PARITY

echo "Checking parity against $FIXTURE + $STADIUM_FIXTURE"
( cd "$WORK" && node parity.mjs "$FIXTURE" "$STADIUM_FIXTURE" "$STADIUM_GEOMETRY" )

# ── 2. Bundle entry + all four sites + isoEngine into one browser IIFE ───────
"$ESBUILD" "$WORK/entry.ts" \
  --bundle --format=iife --target=es2017 --outfile="$WORK/bundle.js" >/dev/null

# esbuild stamps each module's source path into a comment, and $WORK is a random
# mktemp directory — so without this the bundle differs on every run even when
# nothing changed. Rewrite them to stable names so a no-op rebuild is a no-op diff.
sed -i '' -e "s#// .*/\([A-Za-z]*\)\.ts\$#// \1.ts (from the shared assets, see header)#" \
          -e "s#// entry\.ts (from the shared assets, see header)\$#// scripts/facility-compositor/entry.ts#" "$WORK/bundle.js"

SRC_HASH="$(cat "$SRC_DIR"/{isoEngine,plotSite,stadiumConfig,stadiumSurrounds,trainingGroundConfig,medicalCentreConfig,scoutingConfig}.ts | shasum -a 256 | cut -c1-16)"

{
  echo "// Landing-page facility compositor — GENERATED, DO NOT EDIT BY HAND."
  echo "//"
  echo "// Regenerate:  bash scripts/build-facility-compositor.sh"
  echo "//"
  echo "// Source:      assets/Components/facilitiesV2/facilities/{isoEngine,plotSite,"
  echo "//              stadiumSprite,trainingGroundSprite,medicalCentreSprite,scoutingSprite}.ts"
  echo "// Source sha:  $SRC_HASH"
  echo "//"
  echo "// No build timestamp on purpose: the output is then byte-identical when"
  echo "// the source has not changed, so a rebuild produces an empty diff and"
  echo "// any diff at all means the sprite really moved."
  echo "//"
  echo "// Everything below is those files passed through esbuild (type-stripping"
  echo "// only, no other transformation) plus scripts/facility-compositor/entry.ts,"
  echo "// which adapts them to the window API the landing page calls. The build"
  echo "// verifies the drop against its pixel-hash parity fixture first, so a"
  echo "// drifted port fails rather than shipping."
  echo "//"
  echo "// All four sites share ONE copy of isoEngine here — that is why this"
  echo "// bundle exists rather than four per-site ones."
  echo "//"
  echo "// Exposes: window.composeStadiumSvg(config, opts, scale)"
  echo "//          window.composeTrainingGroundSvg(config, opts, scale)"
  echo "//          window.composeMedicalCentreSvg(config, opts, scale)"
  echo "//          window.composeScoutingSvg(config, opts, scale)"
  echo "//          window.DEFAULT_{STADIUM,TRAINING_GROUND,MEDICAL_CENTRE,SCOUTING}_CONFIG"
  echo "//          window.FACILITY_SITES  (ids, labels and game slugs per site)"
  cat "$WORK/bundle.js"
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT" | tr -d ' ') lines, source sha $SRC_HASH)"

# ── 4. Generate the PHP slug map from the same source ────────────────────────
#
# Read out of the bundle rather than re-parsing the TypeScript: the bundle has
# already resolved TRAINING_FACILITIES/STADIUM_SLUGS through the drop's own
# code, so what is emitted here is what the browser will actually use. A regex
# over the .ts source would be a second, weaker parser.
cat > "$WORK/dump-map.mjs" <<'DUMP'
import { readFileSync } from 'node:fs';
const src = readFileSync(process.argv[2], 'utf8');
const window = {};
new Function('window', src)(window);
const out = {};
for (const [site, def] of Object.entries(window.FACILITY_SITES)) out[site] = def.slugs;
process.stdout.write(JSON.stringify(out, null, 2));
DUMP

MAP_JSON="$(node "$WORK/dump-map.mjs" "$OUT")"

mkdir -p "$(dirname "$PHP_OUT")"
node - "$MAP_JSON" "$SRC_HASH" > "$PHP_OUT" <<'PHPGEN'
const [map, sha] = [JSON.parse(process.argv[2]), process.argv[3]];
const lines = [];
lines.push('<?php');
lines.push('');
lines.push('declare(strict_types=1);');
lines.push('');
lines.push('namespace App\\Service\\Facility;');
lines.push('');
lines.push('/**');
lines.push(' * Which game facility slug draws as which sprite id — GENERATED, DO NOT EDIT BY HAND.');
lines.push(' *');
lines.push(' * Regenerate:  bash scripts/build-facility-compositor.sh');
lines.push(' * Source sha:  ' + sha);
lines.push(' *');
lines.push(' * The sprite drop authors a `slug` on every facility (and STADIUM_SLUGS for the');
lines.push(' * stadium), so the mapping is stated once, upstream, in the same file that draws');
lines.push(' * the thing. This class is that statement compiled into PHP so the backend can');
lines.push(' * build sprite configs from ClubFacility rows without a hand-maintained second');
lines.push(' * copy free to drift the moment the drop renames an id.');
lines.push(' *');
lines.push(' * Generated from the built bundle, not by re-parsing the TypeScript, so what is');
lines.push(' * here is exactly what window.FACILITY_SITES gives the browser.');
lines.push(' */');
lines.push('final class FacilitySpriteMap');
lines.push('{');
lines.push('    /** @var array<string, array<string, string>> site => (game slug => sprite id) */');
lines.push('    public const SITES = [');
for (const [site, slugs] of Object.entries(map)) {
  lines.push("        '" + site + "' => [");
  for (const [slug, id] of Object.entries(slugs)) {
    lines.push("            '" + slug + "' => '" + id + "',");
  }
  lines.push('        ],');
}
lines.push('    ];');
lines.push('');
lines.push('    /** Every game facility slug the sprites can draw, across all four sites. */');
lines.push('    public static function knownSlugs(): array');
lines.push('    {');
lines.push('        return array_merge(...array_map(array_keys(...), array_values(self::SITES)));');
lines.push('    }');
lines.push('}');
process.stdout.write(lines.join('\n') + '\n');
PHPGEN

echo "Wrote $PHP_OUT ($(node -e "const m=JSON.parse(process.argv[1]);console.log(Object.values(m).reduce((n,s)=>n+Object.keys(s).length,0))" "$MAP_JSON") slugs)"
