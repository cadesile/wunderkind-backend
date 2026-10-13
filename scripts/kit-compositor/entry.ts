// Entry point for the admin/landing kit + badge compositor bundle.
//
// This file is the ONLY hand-written part of public/assets/kit-compositor.js.
// Everything else is kit16.ts passed through esbuild with no transformation
// beyond type-stripping — see scripts/build-kit-compositor.sh.
//
// Same reasoning as the avatar compositor: this repo's standing rule is to
// re-port the sprite's drawing logic VERBATIM whenever it changes, and a hand
// transcription cannot credibly make that promise twice. The build re-checks
// the sprite's own pixel-hash parity fixture every run.

import {
  renderKit,
  cleanInitials,
  DEFAULT_KIT_CONFIG,
  KIT_COLORS,
  KIT_STYLES,
  KIT_PARTS,
  BADGE_SHAPES,
  BADGE_PATTERNS,
  BADGE_CENTRES,
  type KitConfig,
  type KitCrop,
  type KitSpriteType,
} from './kit16';

/**
 * Old crop vocabulary, still used by every existing caller: landing.js passes
 * 'small' for the ticker/leaderboard kit chip and null for badges; the kit
 * identity widget passes 'large'/'small'; club_profile.html.twig passes
 * 'large' and null.
 */
type LegacyCrop = 'large' | 'small';

const CROP_ALIASES: Record<LegacyCrop, KitCrop> = { large: 'full', small: 'shirt' };

/**
 * dp per NEW sprite pixel, per unit of the OLD `scale` argument.
 *
 * The 16-bit grids are roughly twice the old ones per axis (kit 34x40 against
 * 16x20, badge 32x34 against 15x16), so halving keeps every existing caller at
 * the size it renders today. The asset author's stated equivalence is old
 * `scale={8}` to new `scale={4}`. Not exactly 2x — the kit comes out ~6% larger
 * — which is what these flex/inline-sized containers already absorb, and is the
 * same trade the avatar compositor makes. Matches SCALE_RATIO there; if one
 * moves, move both.
 */
const SCALE_RATIO = 0.5;

/**
 * Builds an SVG string for the admin kit-identity widget, the club profile
 * previews and the landing page's club chips.
 *
 * Signature unchanged from the 8-bit compositor on purpose, including the
 * 'large'/'small' crop names, so no caller needs editing. `KitConfig` itself is
 * unchanged between the two sprite generations — same keys, values and defaults
 * — so stored club identities need no migration.
 */
function composeKitSvg(
  config: Partial<KitConfig> | null | undefined,
  type: KitSpriteType | undefined,
  size: LegacyCrop | KitCrop | null | undefined,
  scale: number,
): string {
  const resolvedCrop: KitCrop = size == null
    ? 'full'
    : (CROP_ALIASES as Record<string, KitCrop>)[size] ?? (size as KitCrop);

  // renderKit ignores the crop for badges, as the 8-bit version did.
  const image = renderKit(config ?? {}, type ?? 'kit', resolvedCrop);

  const px = scale * SCALE_RATIO;
  const paths = image.paths
    .map((p) => `<path d="${p.d}" fill="${p.color}"></path>`)
    .join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" `
    + `width="${image.width * px}" height="${image.height * px}">${paths}</svg>`;
}

declare const window: Record<string, unknown>;

window.composeKitSvg = composeKitSvg;

// The widget sanitises typed initials before they reach the sprite. Exposing the
// sprite's own cleaner means the two can't disagree about what "ABCD" becomes —
// the server does the same job in KitIdentityType::mapFormsToData().
window.cleanInitials = cleanInitials;

// Option lists and defaults, so the widget can build its pickers from the
// sprite's own vocabulary rather than a second hand-maintained copy.
window.KIT_SPRITE_OPTIONS = {
  KIT_COLORS, KIT_STYLES, KIT_PARTS, BADGE_SHAPES, BADGE_PATTERNS, BADGE_CENTRES,
};
window.DEFAULT_KIT_CONFIG = DEFAULT_KIT_CONFIG;
