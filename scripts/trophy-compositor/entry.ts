// Entry point for the admin/landing trophy compositor bundle.
//
// This file is the ONLY hand-written part of public/assets/trophy-compositor.js.
// Everything else in that bundle is trophy16.ts passed through esbuild with no
// transformation beyond type-stripping — see scripts/build-trophy-compositor.sh.
//
// Why a build step rather than a hand-port: trophy16.ts is a frozen 1:1 port of
// the approved web "Trophy Builder", guarded by a pixel-hash parity fixture, and
// this repo's standing rule is to re-port a sprite VERBATIM whenever its drawing
// logic changes. A hand transcription of ~340 lines of pixel maths cannot
// credibly make that promise twice; esbuild can, and the build script re-checks
// the parity fixture every run. Same shape as scripts/{avatar,kit}-compositor.

import {
  renderTrophy,
  DEFAULT_TROPHY_CONFIG,
  KIT_COLORS,
  TROPHY_STYLES,
  TROPHY_FINISHES,
  TROPHY_BG,
  TROPHY_WIDTH,
  TROPHY_HEIGHT,
  type TrophyConfig,
} from './trophy16';

interface ComposeOptions {
  /** Dark 1px silhouette outline. Default false — see the note below. */
  outline?: boolean;
  /** Ground shadow under the base. Defaults to true with a colour background, false without. */
  shadow?: boolean;
  /**
   * 'colour' paints a flat rect behind the sprite (the designed "cabinet tile"
   * look); 'none' leaves it transparent for inline use in a card or list row.
   */
  background?: 'colour' | 'none';
  /** Used when background is 'colour'. Defaults to the sprite's own teal. */
  backgroundColor?: string;
  /** Breathing room around the sprite, in SPRITE px (not dp) so the viewBox stays integral. */
  padding?: number;
}

/**
 * Builds an SVG string for the admin trophy widget's live preview and style
 * thumbnails, the league cards, and the landing page's honours board.
 *
 * `scale` is dp per sprite px, straight through — unlike composeKitSvg and
 * composePlayerSpriteSvg, which halve it to keep pre-16-bit callers rendering at
 * their old size. The trophy sprite has no 8-bit predecessor and no legacy call
 * sites, so there is nothing to stay compatible with and no SCALE_RATIO here;
 * the integration doc's own default of 4 is this function's default too.
 *
 * Two defaults deliberately diverge from TrophySprite.tsx, whose props are
 * written for a standalone RN tile:
 *   - `background` defaults to 'none', not 'colour'. Every caller here draws
 *     onto a surface that already has a background (an EasyAdmin panel, a
 *     league card, a dark landing-page section); a teal box would fight it.
 *     Pass background:'colour' for the designed look — the widget's main
 *     preview does exactly that.
 *   - `shadow` follows the background, as it does in the component: a ground
 *     shadow with nothing to fall on just reads as grey speckle.
 * `outline` defaults to false, matching both the sprite's own default and the
 * other two compositors.
 */
function composeTrophySvg(
  config: Partial<TrophyConfig> | null | undefined,
  scale: number = 4,
  opts: ComposeOptions = {},
): string {
  const background = opts.background ?? 'none';
  const onColour = background === 'colour';

  const image = renderTrophy(config ?? {}, {
    shadow: opts.shadow ?? onColour,
    outline: opts.outline ?? false,
  });

  const pad = Math.max(0, Math.round(opts.padding ?? 0));
  const w = image.width + pad * 2;
  const h = image.height + pad * 2;

  const bg = onColour
    ? `<rect x="0" y="0" width="${w}" height="${h}" fill="${opts.backgroundColor ?? TROPHY_BG}"></rect>`
    : '';

  const paths = image.paths
    .map((p) => `<path d="${p.d}" fill="${p.color}"></path>`)
    .join('');

  // The sprite draws from (0,0), so padding is applied by shifting the content
  // rather than offsetting the viewBox — keeps the background rect at the origin.
  const content = pad > 0 ? `<g transform="translate(${pad} ${pad})">${paths}</g>` : paths;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" `
    + `width="${w * scale}" height="${h * scale}">${bg}${content}</svg>`;
}

/**
 * The web builder's Randomise, per §5 of the integration doc: uniform style and
 * finish, ribbons on 60% of the time, ribbon colours from the shared kit palette
 * with the secondary always different from the primary.
 *
 * Lives here rather than in trophy16.ts because the sprite file is frozen — and
 * here rather than being re-derived in the widget so the admin and the app roll
 * trophies by the same rules. The backend has no PHP counterpart on purpose:
 * league and competition trophies are admin-chosen or come from the tier
 * defaults table, never generated.
 */
function randomTrophyConfig(): TrophyConfig {
  const pick = <T,>(xs: readonly T[]): T => xs[Math.floor(Math.random() * xs.length)];

  const ribbonPrimary = pick(KIT_COLORS);
  let ribbonSecondary = ribbonPrimary;
  while (ribbonSecondary === ribbonPrimary && KIT_COLORS.length > 1) {
    ribbonSecondary = pick(KIT_COLORS);
  }

  return {
    style: pick(TROPHY_STYLES).id,
    finish: pick(TROPHY_FINISHES).id,
    ribbons: Math.random() < 0.6,
    ribbonPrimary,
    ribbonSecondary,
  };
}

declare const window: Record<string, unknown>;

window.composeTrophySvg = composeTrophySvg;
window.randomTrophyConfig = randomTrophyConfig;

// Option lists, defaults and the designed background colour, so the widget and
// the templates build their pickers from the sprite's own vocabulary rather than
// a second hand-maintained copy. KIT_COLORS is the same 12-colour palette the
// kit sprite uses, which is why the ribbon fields reuse App\Enum\Appearance\KitColor.
window.TROPHY_SPRITE_OPTIONS = { TROPHY_STYLES, TROPHY_FINISHES, KIT_COLORS };
window.DEFAULT_TROPHY_CONFIG = DEFAULT_TROPHY_CONFIG;
window.TROPHY_BG = TROPHY_BG;
window.TROPHY_SPRITE_SIZE = { width: TROPHY_WIDTH, height: TROPHY_HEIGHT };
