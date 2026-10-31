// Entry point for the landing-page stadium compositor bundle.
//
// This file is the ONLY hand-written part of public/assets/stadium-compositor.js.
// Everything else in that bundle is the facility sprite drop's stadiumSprite.ts
// and isoEngine.ts passed through esbuild with no transformation beyond
// type-stripping — see scripts/build-stadium-compositor.sh.
//
// Why a build step rather than a hand-port: the v1 bundle WAS a hand port, made
// when isoEngine.ts was ~19KB and only the pieces stadiumSprite.ts touched had
// to be transcribed. v2's isoEngine is ~32KB with new lighting, massing and tree
// code, and the drop ships 301 pixel hashes that a hand transcription has no way
// to honour. Same mechanised-verbatim rule as scripts/build-{avatar,kit,trophy}-
// compositor.sh; the build re-checks the parity fixture every run, so a drifted
// port fails rather than shipping a silently-wrong stadium.

import {
  renderStadium,
  DEFAULT_STADIUM_CONFIG,
  type StadiumConfig,
  type StadiumRenderOptions,
} from './stadiumConfig';

/**
 * Builds an SVG string for the landing page's Club Spotlight stadium.
 *
 * Signature unchanged from the v1 hand port, so the one call site in
 * public/landing.js works untouched.
 *
 * `config` is merged over DEFAULT_STADIUM_CONFIG, which is the web builder's
 * *demo* config rather than a neutral one (red seats, a 2-1 scoreboard, a shop
 * and car park switched on). Omitting a key therefore means "show the demo
 * value", never "unset" — which is why ClubSpotlightService::buildStadiumConfig()
 * sends the on/off booleans explicitly alongside the levels. Every `*Level` key
 * is on the sprite's own 1-10 scale, not the backend's FacilityTemplate 1-5.
 *
 * `scale` is dp per sprite px, straight through — no SCALE_RATIO, unlike the
 * avatar and kit compositors, which halve it so pre-16-bit callers keep their
 * old on-screen size. The v2 stadium image is bigger than v1's (324x240 against
 * 276x216) and the drop's scale-equivalence table offers 0.852 to cancel that
 * out, but the only caller pins the rendered height in CSS
 * (`.spotlight-stadium svg { height: 160px }`), so scale sets nothing but the
 * rasterisation detail there and a fractional ratio would buy nothing.
 *
 * `opts.selected` is forwarded but unused here: the dimming it applies is
 * painted into the pixels as a mix toward BACKDROP[time] (`#ebe7de` by day), so
 * it only reads correctly against that backdrop — not against the spotlight
 * card's green pitch band.
 */
function composeStadiumSvg(
  config: Partial<StadiumConfig> | null | undefined,
  opts: StadiumRenderOptions | null | undefined,
  scale: number = 2,
): string {
  // Both of the look options below are restated explicitly, and `surrounds` is
  // lifted out of the config blob into the options. See the equivalent block in
  // scripts/facility-compositor/entry.ts for the full reasoning — these two must
  // keep emitting byte-identical output, which a test asserts.
  const merged: Record<string, unknown> = { ...DEFAULT_STADIUM_CONFIG, ...(config ?? {}) };

  const carried = merged.surrounds;
  delete merged.surrounds;

  const options = {
    floodlightStyle: 'arch',
    scoreboardPlacement: 'stand',
    ...(typeof carried === 'string' ? { surrounds: carried } : {}),
    ...(opts ?? {}),
  } as StadiumRenderOptions;

  const image = renderStadium(merged as unknown as StadiumConfig, options);

  const paths = image.paths
    .map((p) => `<path d="${p.d}" fill="${p.color}"></path>`)
    .join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" `
    + `width="${image.width * scale}" height="${image.height * scale}">${paths}</svg>`;
}

declare const window: Record<string, unknown>;

window.composeStadiumSvg = composeStadiumSvg;
// Exposed so a caller can read the demo defaults rather than keeping a second
// hand-maintained copy of them. Note they changed in v2 (stand and building
// levels moved), which is another reason not to copy them anywhere.
window.DEFAULT_STADIUM_CONFIG = DEFAULT_STADIUM_CONFIG;
