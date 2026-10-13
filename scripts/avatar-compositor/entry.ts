// Entry point for the admin/landing avatar compositor bundle.
//
// This file is the ONLY hand-written part of public/assets/avatar-compositor.js.
// Everything else in that bundle is avatar16.ts passed through esbuild with no
// transformation beyond type-stripping — see scripts/build-avatar-compositor.sh.
//
// Why a build step rather than a hand-port: avatar16.ts is a frozen 1:1 port of
// the approved web builder, guarded by a pixel-hash parity fixture, and this
// repo's standing rule is to re-port it VERBATIM whenever its drawing logic
// changes. A hand transcription of ~480 lines of pixel maths cannot credibly
// make that promise twice; esbuild can, and the build script re-checks the
// parity fixture every run.

import {
  renderAvatar,
  SKINS,
  DEFAULT_AVATAR_CONFIG,
  type AvatarConfig,
  type BodySize,
  type Crop,
  type Role,
} from './avatar16';

/**
 * Old crop vocabulary, still used by every existing call site
 * (appearance-widget.js passes 'small'; landing.js passes 'large').
 */
type LegacyCrop = 'small' | 'large';

const CROP_ALIASES: Record<LegacyCrop, Crop> = { large: 'full', small: 'head' };

/**
 * dp per NEW sprite pixel, per unit of the OLD `scale` argument.
 *
 * The 16-bit grid is twice the old one per axis (full body 38x68 against 18x31,
 * head 28x30 against 14x17), so halving keeps every existing caller rendering
 * at exactly the size it does today without touching any of them. Matches the
 * app's own SCALE_RATIO in PlayerSprite.tsx — if one moves, move both.
 */
const SCALE_RATIO = 0.5;

/** Height in centimetres to the sprite's build tier. Bands: 145-169 / 170-184 / 185-200. */
function heightToBodySize(cm: unknown): BodySize {
  if (typeof cm !== 'number' || !Number.isFinite(cm)) return 'medium';
  if (cm < 170) return 'small';
  if (cm < 185) return 'medium';
  return 'large';
}

/** Weight in kilograms to the sprite's build tier. Bands: 58-69 / 70-84 / 85-100. */
function weightToBodySize(kg: unknown): BodySize {
  if (typeof kg !== 'number' || !Number.isFinite(kg)) return 'medium';
  if (kg < 70) return 'small';
  if (kg < 85) return 'medium';
  return 'large';
}

interface ComposeOptions {
  ball?: boolean;
  shadow?: boolean;
  outline?: boolean;
  /**
   * Build tier, as the sprite itself expects it ('small' | 'medium' | 'large').
   * A raw cm measurement is accepted too and mapped through the bands above —
   * callers holding a real `Player.height` need not derive the tier themselves.
   * Either form wins over a tier stored in the config.
   */
  height?: BodySize | number | null;
  /** Build tier, or a raw kg measurement. Same rules as `height`. */
  weight?: BodySize | number | null;
  /** @deprecated Alias for `height` — kept so older call sites keep working. */
  heightCm?: number | null;
  /** @deprecated Alias for `weight` — kept so older call sites keep working. */
  weightKg?: number | null;
}

/**
 * Normalises whatever a caller passed for height/weight into the sprite's own
 * `'small' | 'medium' | 'large'` vocabulary: a tier passes straight through, a
 * number goes through the measurement bands, anything else is ignored so the
 * stored config (or the sprite's own default) still decides.
 */
function resolveBodySize(
  value: BodySize | number | null | undefined,
  toBodySize: (n: unknown) => BodySize,
): BodySize | undefined {
  if (value == null) return undefined;
  if (typeof value === 'number') return toBodySize(value);
  return value === 'small' || value === 'medium' || value === 'large' ? value : undefined;
}

/**
 * Builds an SVG string for the admin live preview, the appearance widget's
 * thumbnails and the landing page's avatars.
 *
 * Signature is unchanged from the 8-bit compositor on purpose — `type` and the
 * 'small'/'large' crop names are kept so the existing callers keep working
 * untouched. The build-tier and outline options are additive.
 */
function composePlayerSpriteSvg(
  config: Partial<AvatarConfig> | null | undefined,
  type: Role | undefined,
  crop: LegacyCrop | Crop | undefined,
  scale: number,
  opts: ComposeOptions = {},
): string {
  const resolvedCrop: Crop = crop == null
    ? 'full'
    : (CROP_ALIASES as Record<string, Crop>)[crop] ?? (crop as Crop);

  // A stored config may already carry a tier (the avatar builder writes one).
  // A tier or measurement passed by the caller is better evidence, so it wins.
  const merged: Partial<AvatarConfig> = { ...config };
  if (type) merged.role = type;

  const height = resolveBodySize(opts.height ?? opts.heightCm, heightToBodySize);
  const weight = resolveBodySize(opts.weight ?? opts.weightKg, weightToBodySize);
  if (height) merged.height = height;
  if (weight) merged.weight = weight;

  const image = renderAvatar(merged, {
    crop: resolvedCrop,
    // renderAvatar already refuses a ball for staff, so this only has to honour
    // the caller and the crop.
    ball: !!opts.ball && resolvedCrop === 'full',
    shadow: !!opts.shadow && resolvedCrop === 'full',
    // No outline by default, matching composeKitSvg (kit-compositor/entry.ts).
    // avatar16.ts's own renderAvatar defaults this to true, but the admin
    // previews and the landing page both want the unoutlined look; the flag is
    // here so a caller can opt back in without a signature change.
    outline: opts.outline ?? false,
  });

  const px = scale * SCALE_RATIO;
  const paths = image.paths
    .map((p) => `<path d="${p.d}" fill="${p.color}"></path>`)
    .join('');

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" `
    + `width="${image.width * px}" height="${image.height * px}">${paths}</svg>`;
}

declare const window: Record<string, unknown>;

window.composePlayerSpriteSvg = composePlayerSpriteSvg;

// Shade lookup for the widget's skin swatches (base + darker shade), since
// `skin` is stored as an id, not a hex, unlike the other colour fields.
window.SKIN_SHADES = Object.fromEntries(SKINS.map((s) => [s.id, { c: s.c, d: s.d }]));

// Exposed so the admin widget can offer Height/Weight controls and preview a
// real player's build without re-deriving the bands.
window.AVATAR_BODY_SIZE = { heightToBodySize, weightToBodySize };
window.DEFAULT_AVATAR_CONFIG = DEFAULT_AVATAR_CONFIG;
