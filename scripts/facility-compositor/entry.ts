// Entry point for the landing-page facility compositor bundle.
//
// This file is the ONLY hand-written part of public/assets/facility-compositor.js.
// Everything else in that bundle is the facility sprite drop's four site sprites
// plus the isoEngine and plotSite they share, passed through esbuild with no
// transformation beyond type-stripping — see scripts/build-facility-compositor.sh.
//
// Supersedes scripts/stadium-compositor/entry.ts, which bundles the stadium
// alone. Both are built and shipped for now: landing.js still loads
// stadium-compositor.js, and retiring that file is the landing task's call, not
// this one's. composeStadiumSvg here keeps the OLD signature exactly, so the
// switch is a one-line <script> change with no call-site edits.
//
// The whole point of one bundle rather than four is isoEngine: it is ~32KB and
// every site imports it, so four separate bundles would ship four copies of it
// to a browser that only ever needs one.

import {
  renderStadium,
  DEFAULT_STADIUM_CONFIG,
  STADIUM_FACILITIES,
  STADIUM_SLUGS,
  type StadiumConfig,
} from './stadiumConfig';
import {
  renderTrainingGround,
  DEFAULT_TRAINING_GROUND_CONFIG,
  TRAINING_FACILITIES,
  type TrainingGroundConfig,
} from './trainingGroundConfig';
import {
  renderMedicalCentre,
  DEFAULT_MEDICAL_CENTRE_CONFIG,
  MEDICAL_FACILITIES,
  type MedicalCentreConfig,
} from './medicalCentreConfig';
import {
  renderScouting,
  DEFAULT_SCOUTING_CONFIG,
  SCOUTING_FACILITIES,
  type ScoutingConfig,
} from './scoutingConfig';
import type { IsoImage } from './isoEngine';

/**
 * Render options forwarded straight to the sprite.
 *
 * `facility` is what makes single-site tiles possible — the design renders one
 * facility per tile, and the stands as cropped single-facility renders — so it
 * must survive the trip through here rather than being dropped on the floor.
 * `rotation` and `time` likewise: the page offers both.
 */
interface ComposeOptions {
  rotation?: number;
  time?: string;
  facility?: string;
  selected?: string;
  [key: string]: unknown;
}

/**
 * One image → one SVG string, at `scale` dp per sprite pixel.
 *
 * Shared by all four sites so they cannot drift in viewBox handling or in how
 * paths are emitted. `scale` passes straight through with no SCALE_RATIO — see
 * the stadium entry's own note on why these sites differ from the avatar and kit
 * compositors there.
 */
function toSvg(image: IsoImage, scale: number): string {
  const paths = image.paths
    .map((p) => `<path d="${p.d}" fill="${p.color}"></path>`)
    .join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" `
    + `width="${image.width * scale}" height="${image.height * scale}">${paths}</svg>`;
}

/**
 * Every DEFAULT_* config in this drop is the web builder's *demo* config, not a
 * neutral one — facilities switched on, mid-range levels, a scoreboard showing a
 * result. Merging over it means an omitted key reads as "show the demo value",
 * never "unset", which is exactly why the backend sends on/off booleans
 * explicitly next to the levels rather than relying on absence.
 */
function merge<T>(defaults: T, config: Partial<T> | null | undefined): T {
  return { ...defaults, ...(config ?? {}) };
}

/**
 * The stadium, and the ONE seam where its look-affecting options are set.
 *
 * ── Why they are set explicitly ─────────────────────────────────────────────
 *
 * The v3 drop's contract requires every look-affecting option to be set
 * explicitly at a single seam, because an option left implicit means a future
 * drop can change what this backend renders without a line of this repo
 * changing. Every backend stadium comes through here — the Club Spotlight, the
 * Rich List leader card and the Facilities section — so this is that seam.
 *
 * `arch` + `stand` are v3's own defaults, deliberately restated rather than
 * inherited. They are a VISIBLE change from the previous drop: the floodlights
 * curve in over the corners and are shorter, and the scoreboard moves from the
 * north-west corner onto the back stand's roof (which stand depends on
 * `rotation`). Spread STADIUM_V2_LOOK into `opts` at a call site to pin the old
 * look for one surface without moving the others.
 *
 * ── `surrounds` arrives as a config key and leaves as a render option ───────
 *
 * StadiumConfig is frozen in v3 and `surrounds` is an OPTION, not a config key.
 * But the backend still has to get a per-club value from PHP to the browser, and
 * FacilityConfigBuilder::surroundsFor() carries it inside the stadium json blob
 * so it needs no column of its own. This is the one place that asymmetry is
 * resolved: the key is lifted out of the config into the options and deleted
 * from the config, so what reaches the renderer is exactly a StadiumConfig.
 *
 * An explicit `opts.surrounds` always wins. A payload without the key — every
 * ClubSpotlight row written before v3 — falls through to the sprite's own
 * 'parkland', which is the previous drop's trees-only look, so an old row
 * renders as it always did rather than as someone else's city.
 */
function composeStadiumSvg(
  config: Partial<StadiumConfig> | null | undefined,
  opts: ComposeOptions | null | undefined,
  scale: number = 2,
): string {
  const merged: Record<string, unknown> = merge(DEFAULT_STADIUM_CONFIG, config) as Record<string, unknown>;

  const carried = merged.surrounds;
  delete merged.surrounds;

  const options: ComposeOptions = {
    floodlightStyle: 'arch',
    scoreboardPlacement: 'stand',
    ...(typeof carried === 'string' ? { surrounds: carried } : {}),
    ...(opts ?? {}),
  };

  return toSvg(renderStadium(merged as unknown as StadiumConfig, options as never), scale);
}

function composeTrainingGroundSvg(
  config: Partial<TrainingGroundConfig> | null | undefined,
  opts: ComposeOptions | null | undefined,
  scale: number = 2,
): string {
  return toSvg(renderTrainingGround(merge(DEFAULT_TRAINING_GROUND_CONFIG, config), (opts ?? {}) as never), scale);
}

function composeMedicalCentreSvg(
  config: Partial<MedicalCentreConfig> | null | undefined,
  opts: ComposeOptions | null | undefined,
  scale: number = 2,
): string {
  return toSvg(renderMedicalCentre(merge(DEFAULT_MEDICAL_CENTRE_CONFIG, config), (opts ?? {}) as never), scale);
}

function composeScoutingSvg(
  config: Partial<ScoutingConfig> | null | undefined,
  opts: ComposeOptions | null | undefined,
  scale: number = 2,
): string {
  return toSvg(renderScouting(merge(DEFAULT_SCOUTING_CONFIG, config), (opts ?? {}) as never), scale);
}

/**
 * The four sites' facility ids, labels and game slugs, derived from the drop's
 * own exports rather than hand-copied.
 *
 * The slug is the load-bearing part: it is the game's own FacilityTemplate slug
 * (training_pitch, biometric_hub, …), authored on each facility in the sprite
 * source. That makes this bundle the single statement of which game facility
 * draws as which sprite id, and it is what scripts/build-facility-compositor.sh
 * reads to generate the PHP side of the same mapping — so the two cannot
 * disagree. The stadium keeps its slugs in a separate STADIUM_SLUGS map
 * (slug → part), inverted here into the same {id, slug} shape as the plot sites.
 */
const FACILITY_SITES = {
  training: {
    ids: TRAINING_FACILITIES.map((f) => f.id),
    labels: Object.fromEntries(TRAINING_FACILITIES.map((f) => [f.id, f.labelKey])),
    slugs: Object.fromEntries(TRAINING_FACILITIES.map((f) => [f.slug, f.id])),
  },
  medical: {
    ids: MEDICAL_FACILITIES.map((f) => f.id),
    labels: Object.fromEntries(MEDICAL_FACILITIES.map((f) => [f.id, f.labelKey])),
    slugs: Object.fromEntries(MEDICAL_FACILITIES.map((f) => [f.slug, f.id])),
  },
  scouting: {
    ids: SCOUTING_FACILITIES.map((f) => f.id),
    labels: Object.fromEntries(SCOUTING_FACILITIES.map((f) => [f.id, f.labelKey])),
    slugs: Object.fromEntries(SCOUTING_FACILITIES.map((f) => [f.slug, f.id])),
  },
  stadium: {
    ids: STADIUM_FACILITIES.map((f) => f.id),
    labels: Object.fromEntries(STADIUM_FACILITIES.map((f) => [f.id, f.labelKey])),
    slugs: { ...STADIUM_SLUGS },
  },
};

declare const window: Record<string, unknown>;

window.composeStadiumSvg = composeStadiumSvg;
window.composeTrainingGroundSvg = composeTrainingGroundSvg;
window.composeMedicalCentreSvg = composeMedicalCentreSvg;
window.composeScoutingSvg = composeScoutingSvg;

window.DEFAULT_STADIUM_CONFIG = DEFAULT_STADIUM_CONFIG;
window.DEFAULT_TRAINING_GROUND_CONFIG = DEFAULT_TRAINING_GROUND_CONFIG;
window.DEFAULT_MEDICAL_CENTRE_CONFIG = DEFAULT_MEDICAL_CENTRE_CONFIG;
window.DEFAULT_SCOUTING_CONFIG = DEFAULT_SCOUTING_CONFIG;

window.FACILITY_SITES = FACILITY_SITES;
