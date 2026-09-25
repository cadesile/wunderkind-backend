// Admin avatar compositor — plain-JS mirror of the new player/staff sprite's
// pure pixel-drawing logic. Mechanical type-stripping only, per the sprite's
// own instruction not to change the pixel-drawing logic — this must stay a
// faithful port, not a reinterpretation.
//
// Source: assets/Components/NPCPerson/sprite.ts (buildGrid/toRuns) and
// PlayerSprite.tsx (the rects-with-seam-overlap SVG technique), from the
// wunderkind-app sprite component.

const WIDTH = 18;
const HEIGHT = 31;
const WHITE = '#f4f3ee';
const BLACK = '#1a1a1a';

const SKINS = [
  { id: 's1', c: '#f6d3b3', d: '#e0b28c' }, { id: 's2', c: '#ecc095', d: '#d39f73' },
  { id: 's3', c: '#d49c64', d: '#b9804c' }, { id: 's4', c: '#b37548', d: '#965d36' },
  { id: 's5', c: '#8a5230', d: '#704023' }, { id: 's6', c: '#5c3822', d: '#472a18' },
];
const HAIR_COLORS = ['#1c1410', '#4a2c1a', '#8a5a2b', '#c8602a', '#e6bd55', '#d9d5cc'];
const KIT_COLORS = ['#c8202f', '#7a1f2b', '#f07a1a', '#f2c230', '#1f8a4c', '#0f4d33', '#7fb8e6', '#1f4fb8', '#1b2a4a', '#5b2c83', '#f4f3ee', '#1a1a1a'];
const LIP_COLORS = ['#c9575e', '#d98a7e', '#b8302f', '#a8424a', '#8a4a3a', '#6e2f3f'];
const HAIR_STYLES = ['bald', 'buzz', 'crop', 'quiff', 'mohawk', 'afro', 'long', 'bun', 'cornrows'];
const FACES = ['neutral', 'happy', 'wink', 'focused', 'shout', 'sad', 'frustrated', 'angry', 'cool'];
const FACIAL_HAIR = ['none', 'stubble', 'beard'];
const KIT_STYLES = ['plain', 'stripes', 'hoops', 'halves', 'sash', 'band', 'sleeves'];
const OUTFITS = ['coat', 'track', 'suit', 'jumper'];
const KIT_PARTS = ['primary', 'secondary', 'white', 'black'];

const DEFAULT_CONFIG = {
  hair: 'crop', hairColor: '#c8602a', headband: false, skin: 's1', face: 'neutral', facial: 'none', lip: '#c9575e',
  primary: '#c8202f', secondary: '#f4f3ee',
  kit: 'stripes', shorts: 'black', socks: 'primary',
  outfit: 'coat', trousers: 'black', glasses: false,
};

/** Crop windows [x0, y0, w, h] into the 18x31 grid. */
const CROPS = {
  large: [0, 0, WIDTH, HEIGHT],
  small: [1, 0, 14, 17], // head + shoulders
};

function hairPx(style) {
  const p = []; const r = (x0, x1, y0, y1) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) p.push([x, y]); };
  switch (style) {
    case 'buzz': r(5, 10, 3, 3); r(4, 11, 4, 4); r(4, 4, 5, 5); r(11, 11, 5, 5); break;
    case 'crop': r(5, 10, 2, 2); r(4, 11, 3, 4); r(4, 5, 5, 5); r(10, 11, 5, 5); r(4, 4, 6, 7); r(11, 11, 6, 7); break;
    case 'quiff': r(7, 10, 0, 0); r(5, 11, 1, 1); r(4, 11, 2, 4); r(4, 5, 5, 5); r(10, 11, 5, 5); r(4, 4, 6, 6); r(11, 11, 6, 6); break;
    case 'mohawk': r(7, 8, 0, 4); break;
    case 'afro': r(5, 10, 0, 0); r(3, 12, 1, 1); r(2, 13, 2, 5); r(2, 4, 6, 6); r(11, 13, 6, 6); r(2, 2, 7, 7); r(13, 13, 7, 7); break;
    case 'long': r(5, 10, 2, 2); r(4, 11, 3, 4); r(4, 5, 5, 5); r(10, 11, 5, 5); r(3, 3, 4, 14); r(12, 12, 4, 14); r(4, 4, 6, 9); r(11, 11, 6, 9); break;
    case 'cornrows': [4, 6, 9, 11].forEach(x => r(x, x, 3, 5)); r(6, 6, 2, 2); r(9, 9, 2, 2); break;
    case 'bun': r(6, 9, 0, 1); r(5, 10, 2, 2); r(4, 11, 3, 4); r(4, 4, 5, 5); r(11, 11, 5, 5); break;
  }
  return p;
}

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

/** Builds the full 18x31 colour grid (null = transparent). */
function buildGrid(input, type, opts = {}) {
  const c = { ...DEFAULT_CONFIG, ...input };
  const W = WIDTH, H = HEIGHT;
  const g = Array.from({ length: H }, () => Array(W).fill(null));
  const set = (x, y, v) => { if (x >= 0 && x < W && y >= 0 && y < H) g[y][x] = v; };
  const r = (x0, x1, y0, y1, v) => { for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) set(x, y, v); };
  const sk = SKINS.find(s => s.id === c.skin) || SKINS[0];
  const S = sk.c, D = sk.d, P = c.primary, Q = c.secondary;
  const col = (k) => ({ primary: P, secondary: Q, white: WHITE, black: BLACK })[k];
  const staff = type === 'staff';
  const ball = !!opts.ball && !staff;

  if (opts.shadow) { r(3, 12, 30, 30, 'rgba(0,0,0,.18)'); if (ball) r(12, 16, 30, 30, 'rgba(0,0,0,.18)'); }

  if (staff) {
    const o = c.outfit, T = col(c.trousers), other = T === P ? Q : P;
    r(4, 11, 21, 22, T); r(4, 6, 23, 28, T); r(9, 11, 23, 28, T);
    if (o === 'track') { r(4, 4, 21, 28, other); r(11, 11, 21, 28, other); }
    const shoe = o === 'track' ? WHITE : '#141414';
    r(4, 6, 29, 29, shoe); r(9, 11, 29, 29, shoe);
    for (let y = 13; y <= 20; y++) for (let x = 2; x <= 13; x++) {
      const on = y === 13 ? ((x >= 3 && x <= 6) || (x >= 9 && x <= 12)) : (y <= 19 ? true : (x >= 4 && x <= 11));
      if (on) set(x, y, P);
    }
    r(2, 3, 20, 20, S); r(12, 13, 20, 20, S);
    switch (o) {
      case 'coat': r(7, 8, 14, 20, Q); r(3, 6, 21, 25, P); r(9, 12, 21, 25, P); r(7, 8, 23, 25, null); break;
      case 'track': r(6, 9, 13, 13, Q); r(2, 2, 14, 19, Q); r(13, 13, 14, 19, Q); r(4, 11, 20, 20, Q); break;
      case 'suit': r(6, 9, 13, 13, WHITE); set(6, 14, WHITE); set(9, 14, WHITE); set(6, 15, WHITE); set(9, 15, WHITE); r(7, 8, 14, 17, Q); break;
      case 'jumper': set(6, 13, Q); set(9, 13, Q); r(2, 3, 19, 19, Q); r(12, 13, 19, 19, Q); r(4, 11, 20, 20, Q); break;
    }
  } else {
    // legs, socks, boots
    r(5, 6, 24, 25, S); r(9, 10, 24, 25, S);
    const sm = col(c.socks), sb = c.socks === 'primary' ? Q : P;
    r(5, 6, 26, 26, sb); r(9, 10, 26, 26, sb); r(5, 6, 27, 28, sm); r(9, 10, 27, 28, sm);
    r(4, 6, 29, 29, '#141414'); r(9, 11, 29, 29, '#141414');
    // shorts
    r(4, 11, 21, 23, col(c.shorts)); set(7, 23, null); set(8, 23, null);
    // arms
    r(2, 3, 17, 20, S); r(12, 13, 17, 20, S);
    // shirt
    for (let y = 13; y <= 20; y++) for (let x = 2; x <= 13; x++) {
      const on = y === 13 ? ((x >= 3 && x <= 6) || (x >= 9 && x <= 12)) : (y <= 16 ? true : (x >= 4 && x <= 11));
      if (on) set(x, y, shirtColor(x, y, c.kit, P, Q));
    }
    if (['plain', 'band', 'sleeves'].includes(c.kit)) set(9, 15, Q);
  }
  // head
  if (!(staff && (c.outfit === 'track' || c.outfit === 'suit'))) r(7, 8, 13, 13, D);
  r(4, 11, 3, 12, S); set(4, 3, null); set(11, 3, null); set(4, 12, null); set(11, 12, null);
  r(3, 3, 7, 9, S); r(12, 12, 7, 9, S); set(3, 8, D); set(12, 8, D);
  // hair
  for (const [x, y] of hairPx(c.hair)) set(x, y, c.hairColor);
  if (c.hair === 'cornrows') [5, 7, 8, 10].forEach(x => r(x, x, 3, 5, D));
  if (c.facial === 'stubble' || c.facial === 'beard') {
    const fc = c.facial === 'beard' ? c.hairColor : mix(S, c.hairColor, 0.4);
    set(4, 9, fc); set(11, 9, fc); r(4, 5, 10, 10, fc); r(10, 11, 10, 10, fc); r(4, 11, 11, 11, fc); r(5, 10, 12, 12, fc);
    if (c.facial === 'beard') { r(6, 9, 9, 9, fc); r(4, 11, 12, 12, fc); r(5, 10, 13, 13, fc); r(6, 9, 10, 10, S); }
    else r(6, 9, 9, 9, fc);
  }
  // face
  const E = '#1a1414', M = c.lip || '#c9575e', B = c.hair === 'bald' ? D : c.hairColor;
  const eyes = () => { set(6, 8, E); set(9, 8, E); };
  const smile = () => { set(6, 10, M); set(9, 10, M); r(7, 8, 11, 11, M); };
  const brows = () => { set(5, 6, B); set(6, 7, B); set(10, 6, B); set(9, 7, B); };
  switch (c.face) {
    case 'happy': eyes(); smile(); break;
    case 'wink': r(5, 6, 8, 8, E); set(9, 8, E); smile(); break;
    case 'focused': eyes(); brows(); r(7, 8, 10, 10, M); break;
    case 'shout': eyes(); brows(); r(6, 9, 10, 10, '#5b1a1f'); r(7, 8, 11, 11, '#5b1a1f'); break;
    case 'sad': eyes(); set(5, 7, B); set(6, 6, B); set(9, 6, B); set(10, 7, B); r(7, 8, 10, 10, M); set(6, 11, M); set(9, 11, M); break;
    case 'frustrated': eyes(); r(5, 6, 7, 7, B); r(9, 10, 7, 7, B); set(6, 11, M); set(7, 10, M); set(8, 11, M); set(9, 10, M); break;
    case 'angry': eyes(); brows(); r(6, 9, 10, 10, WHITE); set(5, 10, M); set(10, 10, M); r(6, 9, 11, 11, M); break;
    case 'cool': r(5, 10, 8, 8, '#111'); r(5, 6, 9, 9, '#111'); r(9, 10, 9, 9, '#111'); r(8, 9, 10, 10, M); break;
    default: eyes(); r(7, 8, 10, 10, M);
  }
  if (staff && c.glasses && c.face !== 'cool') {
    const F = '#6a4b33', L = '#dfe8ee';
    [5, 7, 8, 10].forEach(x => { set(x, 8, F); set(x, 9, F); }); set(6, 9, L); set(9, 9, L);
  }
  if (c.headband) for (let x = 0; x < WIDTH; x++) if (g[5][x]) g[5][x] = WHITE;
  // ball
  if (ball) {
    const pat = ['.WWW.', 'WKWKW', 'WWKWW', 'WKWKW', '.WWW.'];
    pat.forEach((row, j) => [...row].forEach((ch, i) => { if (ch !== '.') set(12 + i, 25 + j, ch === 'W' ? '#f6f6f2' : '#1a1a1a'); }));
  }
  return g;
}

/**
 * Crops the grid and merges horizontal same-colour pixels into runs, so the
 * renderer draws far fewer rects than one-per-pixel.
 */
function toRuns(g, size) {
  const [x0, y0, w, h] = CROPS[size];
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
 * Builds a full SVG string for the admin live preview / thumbnails, mirroring
 * PlayerSprite.tsx's rects-with-seam-overlap technique (react-native-svg has
 * no crispEdges, so rects overlap by a hair to hide anti-aliasing seams).
 *
 * @param config Partial<SpriteConfig> — merged over DEFAULT_CONFIG
 * @param type 'player' | 'staff'
 * @param crop 'large' | 'small'
 * @param scale on-screen px per sprite pixel
 * @param opts { ball, shadow } — large player only
 */
function composePlayerSpriteSvg(config, type, crop, scale, opts = {}) {
  const grid = buildGrid(config || {}, type, opts);
  const { runs, width, height } = toRuns(grid, crop);
  const rects = runs
    .map((p) => `<rect x="${p.x}" y="${p.y}" width="${p.w + SEAM}" height="${1 + SEAM}" fill="${p.color}"></rect>`)
    .join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width * scale}" height="${height * scale}">${rects}</svg>`;
}

window.composePlayerSpriteSvg = composePlayerSpriteSvg;
// Shade lookup for the admin widget's skin swatches (base + darker shade),
// since `skin` is stored as an id, not a hex, unlike the other color fields.
window.SKIN_SHADES = Object.fromEntries(SKINS.map((s) => [s.id, { c: s.c, d: s.d }]));
