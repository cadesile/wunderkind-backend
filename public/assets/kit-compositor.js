// Admin kit/badge compositor — plain-JS mirror of the kit sprite's pure
// pixel-drawing logic. Mechanical type-stripping only, per the sprite's own
// instruction not to change the drawing logic — this must stay a faithful
// port, not a reinterpretation.
//
// Source: assets/Components/KitBadge/kitSprite.ts (buildKitGrid/buildBadgeGrid/
// toKitRuns) and KitSprite.tsx (the rects-with-seam-overlap SVG technique),
// from the wunderkind-app kit/badge sprite component.

const WHITE = '#f4f3ee';
const BLACK = '#1a1a1a';

const DEFAULT_KIT_CONFIG = {
  kit: 'stripes', primary: '#c8202f', secondary: '#f4f3ee', shorts: 'black', socks: 'primary',
  badgeShape: 'shield', badgePattern: 'plain', badgeCentre: 'initials', initials: 'FC',
  badgeFill: '#c8202f', badgeTrim: '#f4f3ee', badgeSymbol: '#f2c230',
};

const KIT_WIDTH = 16;
const KIT_HEIGHT = 20;
const BADGE_WIDTH = 15;
const BADGE_HEIGHT = 16;

/** Crop windows [x0, y0, w, h]. */
const KIT_CROPS = {
  large: [0, 0, KIT_WIDTH, KIT_HEIGHT], // shirt, shorts, socks
  small: [1, 0, 14, 10],                // shirt only
};
const BADGE_CROP = [0, 0, BADGE_WIDTH, BADGE_HEIGHT];

const ICONS = {
  star: ['...X...', '..XXX..', 'XXXXXXX', '.XXXXX.', '..XXX..', '.XX.XX.', '.X...X.'],
  ball: ['..XXX..', '.XXXXX.', 'XXXoXXX', 'XXoooXX', 'XXXoXXX', '.XXXXX.', '..XXX..'],
  crown: ['.......', 'X..X..X', 'XX.X.XX', 'XXXXXXX', 'XXXXXXX', 'XXXXXXX', '.......'],
};

// 3x5 pixel font, row-major bits.
const GLYPHS = {
  A: '010101111101101', B: '110101110101110', C: '011100100100011', D: '110101101101110', E: '111100110100111',
  F: '111100110100100', G: '011100101101011', H: '101101111101101', I: '111010010010111', J: '001001001101010',
  K: '101101110101101', L: '100100100100111', M: '101111111101101', N: '110101101101101', O: '010101101101010',
  P: '110101110100100', Q: '010101101110011', R: '110101110101101', S: '011100010001110', T: '111010010010010',
  U: '101101101101111', V: '101101101101010', W: '101101111111101', X: '101101010101101', Y: '101101010010010',
  Z: '111001010100111', 0: '111101101101111', 1: '010110010010111', 2: '110001010100111', 3: '110001010001110',
  4: '101101111001001', 5: '111100110001110', 6: '011100111101111', 7: '111001010010010', 8: '111101111101111', 9: '111101111001110',
};

function makeGrid(W, H) {
  const g = Array.from({ length: H }, () => Array(W).fill(null));
  const set = (x, y, v) => { if (x >= 0 && x < W && y >= 0 && y < H) g[y][x] = v; };
  const r = (x0, x1, y0, y1, v) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) set(x, y, v); };
  return { g, set, r };
}

// Identical to the player sprite's shirt pattern (y in player-grid rows 13–20).
function shirtColor(x, y, style, P, Q) {
  const sleeve = x <= 3 || x >= 12;
  if (y === 16 && sleeve) return style === 'sleeves' ? P : Q;
  if (y === 13 && (x === 6 || x === 9)) return Q;
  switch (style) {
    case 'stripes': return !sleeve && [5, 7, 8, 10].includes(x) ? Q : P;
    case 'hoops': return y >= 14 && y % 2 === 0 ? Q : P;
    case 'halves': return x <= 7 ? P : Q;
    case 'sash': return !sleeve && y >= 14 && Math.abs((x - 4) - (y - 14)) <= 1 ? Q : P;
    case 'band': return !sleeve && (y === 16 || y === 17) ? Q : P;
    case 'sleeves': return sleeve ? Q : P;
    default: return P;
  }
}

const mix = (a, b, t) =>
  '#' + [1, 3, 5].map(i => Math.round(parseInt(a.substr(i, 2), 16) * (1 - t) + parseInt(b.substr(i, 2), 16) * t).toString(16).padStart(2, '0')).join('');

/** Flat-lay kit: shirt (rows 0–8), shorts (10–12), socks (14–19). 16x20. */
function buildKitGrid(input) {
  const c = { ...DEFAULT_KIT_CONFIG, ...input };
  const { g, set, r } = makeGrid(KIT_WIDTH, KIT_HEIGHT);
  const P = c.primary, Q = c.secondary;
  const col = (k) => ({ primary: P, secondary: Q, white: WHITE, black: BLACK })[k];

  for (let py = 13; py <= 20; py++) for (let x = 2; x <= 13; x++) {
    const on = py === 13 ? ((x >= 3 && x <= 6) || (x >= 9 && x <= 12)) : (py <= 16 ? true : (x >= 4 && x <= 11));
    if (on) set(x, py - 12, shirtColor(x, py, c.kit, P, Q));
  }
  const inner = mix(P, '#000000', 0.4);
  set(7, 1, inner); set(8, 1, inner);
  if (['plain', 'band', 'sleeves'].includes(c.kit)) set(9, 3, Q);

  r(4, 11, 10, 12, col(c.shorts)); set(7, 12, null); set(8, 12, null);

  const sm = col(c.socks), sb = c.socks === 'primary' ? Q : P;
  r(5, 6, 14, 14, sb); r(9, 10, 14, 14, sb); r(5, 6, 15, 18, sm); r(9, 10, 15, 18, sm);
  r(4, 6, 19, 19, sm); r(9, 11, 19, 19, sm);
  return g;
}

function inShape(shape, x, y) {
  const dx = Math.abs(x - 7), dy = Math.abs(y - 7);
  switch (shape) {
    case 'round': return y <= 14 && (x - 7) ** 2 + (y - 7) ** 2 <= 55;
    case 'diamond': return y <= 14 && dx + dy <= 7;
    case 'hex': return y <= 14 && dx <= (dy <= 3 ? 7 : 7 - (dy - 3) * 1.75);
    case 'crest': if (y === 0) return (x >= 1 && x <= 5) || (x >= 9 && x <= 13); // falls through
    case 'shield': return y <= 9 ? x >= 1 && x <= 13 : dx <= 15 - y;
  }
  return false;
}

function sanitiseInitials(s) { return (s || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3); }

/** Badge: 15x16 (round / diamond / hex use the top 15 rows). */
function buildBadgeGrid(input) {
  const c = { ...DEFAULT_KIT_CONFIG, ...input };
  const W = BADGE_WIDTH, H = BADGE_HEIGHT;
  const { g, set } = makeGrid(W, H);
  const F = c.badgeFill, T = c.badgeTrim, S = c.badgeSymbol;
  const cy = c.badgeShape === 'shield' || c.badgeShape === 'crest' ? 6 : 7;
  const inside = (x, y) => x >= 0 && x < W && y >= 0 && y < H && inShape(c.badgeShape, x, y);

  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    if (!inside(x, y)) continue;
    const edge = !inside(x - 1, y) || !inside(x + 1, y) || !inside(x, y - 1) || !inside(x, y + 1);
    let v = F;
    if (edge) v = T;
    else switch (c.badgePattern) {
      case 'stripes': v = Math.floor((Math.abs(x - 7) + 1) / 2) % 2 ? T : F; break;
      case 'hoops': v = Math.floor((y + 1) / 2) % 2 ? T : F; break;
      case 'halves': v = x > 7 ? T : F; break;
      case 'quarters': v = (x <= 7) === (y <= cy) ? F : T; break;
      case 'chevron': { const k = y + Math.abs(x - 7); v = k >= cy + 5 && k <= cy + 6 ? T : F; break; }
    }
    set(x, y, v);
  }

  if (c.badgeCentre === 'initials') {
    const txt = sanitiseInitials(c.initials);
    const w = txt.length * 4 - 1, x0 = 7 - Math.floor(w / 2), y0 = cy - 2;
    [...txt].forEach((ch, n) => {
      const gl = GLYPHS[ch]; if (!gl) return;
      for (let j = 0; j < 5; j++) for (let i = 0; i < 3; i++) if (gl[j * 3 + i] === '1') set(x0 + n * 4 + i, y0 + j, S);
    });
  } else if (ICONS[c.badgeCentre]) {
    ICONS[c.badgeCentre].forEach((row, j) => [...row].forEach((ch, i) => {
      if (ch === 'X') set(4 + i, cy - 3 + j, S);
      else if (ch === 'o') set(4 + i, cy - 3 + j, '#1a1a1a');
    }));
  }
  return g;
}

/** Crops the grid and merges horizontal same-colour pixels into runs. */
function toKitRuns(g, crop) {
  const [x0, y0, w, h] = crop;
  const runs = [];
  for (let y = 0; y < h; y++) {
    let x = 0;
    while (x < w) {
      const v = g[y + y0] && g[y + y0][x + x0];
      if (!v) { x++; continue; }
      let len = 1;
      while (x + len < w && g[y + y0] && g[y + y0][x + len + x0] === v) len++;
      runs.push({ x, y, w: len, color: v });
      x += len;
    }
  }
  return { runs, width: w, height: h };
}

const SEAM = 0.04;

/**
 * Builds a full SVG string for the admin live preview/thumbnails, mirroring
 * KitSprite.tsx's rects-with-seam-overlap technique.
 *
 * @param config Partial<KitConfig> — merged over DEFAULT_KIT_CONFIG
 * @param type 'kit' | 'badge'
 * @param size 'large' | 'small' — kit only, ignored for badges
 * @param scale on-screen px per sprite pixel
 */
function composeKitSvg(config, type, size, scale) {
  const { runs, width, height } = type === 'badge'
    ? toKitRuns(buildBadgeGrid(config || {}), BADGE_CROP)
    : toKitRuns(buildKitGrid(config || {}), KIT_CROPS[size || 'large']);
  const rects = runs
    .map((p) => `<rect x="${p.x}" y="${p.y}" width="${p.w + SEAM}" height="${1 + SEAM}" fill="${p.color}"></rect>`)
    .join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width * scale}" height="${height * scale}">${rects}</svg>`;
}

window.composeKitSvg = composeKitSvg;
