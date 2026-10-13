// Admin + landing kit & badge compositor — GENERATED, DO NOT EDIT BY HAND.
//
// Regenerate:  bash scripts/build-kit-compositor.sh
//
// Source:      assets/Components/KitBadgeV2/kit16.ts
// Source sha:  15e38c8f16ea9593
//
// No build timestamp on purpose: the output is then byte-identical when
// the source has not changed, so a rebuild produces an empty diff and
// any diff at all means the sprite really moved.
//
// Everything below is that file passed through esbuild (type-stripping
// only, no other transformation) plus scripts/kit-compositor/entry.ts,
// which adapts it to the window API the kit widget and landing page
// call. The build verifies the source against its pixel-hash parity
// fixture first, so a drifted port fails rather than shipping.
//
// Exposes: window.composeKitSvg(config, type, crop, scale)
//          window.cleanInitials
//          window.KIT_SPRITE_OPTIONS, window.DEFAULT_KIT_CONFIG
(() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropSymbols = Object.getOwnPropertySymbols;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __propIsEnum = Object.prototype.propertyIsEnumerable;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __spreadValues = (a, b) => {
    for (var prop in b || (b = {}))
      if (__hasOwnProp.call(b, prop))
        __defNormalProp(a, prop, b[prop]);
    if (__getOwnPropSymbols)
      for (var prop of __getOwnPropSymbols(b)) {
        if (__propIsEnum.call(b, prop))
          __defNormalProp(a, prop, b[prop]);
      }
    return a;
  };

  // kit16.ts (from the app, see header)
  var WHITE = "#f4f3ee";
  var BLACK = "#1a1a1a";
  var OUTLINE = "#1d1612";
  var KIT_COLORS = ["#c8202f", "#7a1f2b", "#f07a1a", "#f2c230", "#1f8a4c", "#0f4d33", "#7fb8e6", "#1f4fb8", "#1b2a4a", "#5b2c83", "#f4f3ee", "#1a1a1a"];
  var KIT_STYLES = [
    { id: "plain", label: "Plain" },
    { id: "stripes", label: "Stripes" },
    { id: "hoops", label: "Hoops" },
    { id: "halves", label: "Halves" },
    { id: "sash", label: "Sash" },
    { id: "band", label: "Band" },
    { id: "sleeves", label: "Sleeves" }
  ];
  var KIT_PARTS = [{ id: "primary", label: "Primary" }, { id: "secondary", label: "Secondary" }, { id: "white", label: "White" }, { id: "black", label: "Black" }];
  var BADGE_SHAPES = [
    { id: "shield", label: "Shield" },
    { id: "crest", label: "Crest" },
    { id: "round", label: "Round" },
    { id: "diamond", label: "Diamond" },
    { id: "hex", label: "Hex" }
  ];
  var BADGE_PATTERNS = [
    { id: "plain", label: "Plain" },
    { id: "stripes", label: "Stripes" },
    { id: "hoops", label: "Hoops" },
    { id: "halves", label: "Halves" },
    { id: "quarters", label: "Quarters" },
    { id: "chevron", label: "Chevron" }
  ];
  var BADGE_CENTRES = [
    { id: "none", label: "None" },
    { id: "initials", label: "Initials" },
    { id: "star", label: "Star" },
    { id: "ball", label: "Ball" },
    { id: "crown", label: "Crown" }
  ];
  var DEFAULT_KIT_CONFIG = {
    kit: "stripes",
    primary: "#c8202f",
    secondary: "#f4f3ee",
    shorts: "black",
    socks: "primary",
    badgeShape: "shield",
    badgePattern: "plain",
    badgeCentre: "initials",
    initials: "FC",
    badgeFill: "#c8202f",
    badgeTrim: "#f4f3ee",
    badgeSymbol: "#f2c230"
  };
  var SHIRT_CROP = [3, 0, 28, 20];
  var ICONS = {
    star: [
      "......XX......",
      "......XX......",
      ".....XXXX.....",
      ".....XXXX.....",
      "XXXXXXXXXXXXXX",
      ".XXXXXXXXXXXX.",
      "..XXXXXXXXXX..",
      "...XXXXXXXX...",
      "...XXXXXXXX...",
      "..XXXXXXXXXX..",
      "..XXXX..XXXX..",
      ".XXXX....XXXX.",
      ".XXX......XXX.",
      ".X..........X."
    ],
    ball: [
      ".....XXXX.....",
      "...XXXXXXXX...",
      "..XXXooooXXX..",
      ".XXXooooooXXX.",
      ".XoXXooooXXoX.",
      "XooXXXXXXXXooX",
      "XoXXXXXXXXXXoX",
      "XXXXXXXXXXXXXX",
      "XXoXXXXXXXXoXX",
      ".XooXXXXXXooX.",
      ".XXooXooXooXX.",
      "..XXXooooXXX..",
      "...XXXXXXXX...",
      ".....XXXX....."
    ],
    crown: [
      "..............",
      ".X....XX....X.",
      "XXX..XXXX..XXX",
      ".X....XX....X.",
      ".XX..XXXX..XX.",
      ".XXX.XXXX.XXX.",
      ".XXXXXXXXXXXX.",
      ".XXXXXXXXXXXX.",
      ".XXoXXooXXoXX.",
      ".XXXXXXXXXXXX.",
      ".XXXXXXXXXXXX.",
      "..............",
      "..............",
      ".............."
    ]
  };
  var GLYPHS = {
    A: "010101111101101",
    B: "110101110101110",
    C: "011100100100011",
    D: "110101101101110",
    E: "111100110100111",
    F: "111100110100100",
    G: "011100101101011",
    H: "101101111101101",
    I: "111010010010111",
    J: "001001001101010",
    K: "101101110101101",
    L: "100100100100111",
    M: "101111111101101",
    N: "110101101101101",
    O: "010101101101010",
    P: "110101110100100",
    Q: "010101101110011",
    R: "110101110101101",
    S: "011100010001110",
    T: "111010010010010",
    U: "101101101101111",
    V: "101101101101010",
    W: "101101111111101",
    X: "101101010101101",
    Y: "101101010010010",
    Z: "111001010100111",
    0: "111101101101111",
    1: "010110010010111",
    2: "110001010100111",
    3: "110001010001110",
    4: "101101111001001",
    5: "111100110001110",
    6: "011100111101111",
    7: "111001010010010",
    8: "111101111101111",
    9: "111101111001110"
  };
  var cleanInitials = (s) => (s || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 3);
  var colCache = /* @__PURE__ */ new Map();
  var hex2 = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, "0");
  var ch = (c, i) => parseInt(c.substr(i, 2), 16);
  function shade(c, f) {
    const k = c + "*" + f;
    let v = colCache.get(k);
    if (v) return v;
    v = "#" + [1, 3, 5].map((i) => hex2(ch(c, i) * f)).join("");
    colCache.set(k, v);
    return v;
  }
  function mix(a, b, t) {
    const k = a + b + t;
    let v = colCache.get(k);
    if (v) return v;
    v = "#" + [1, 3, 5].map((i) => Math.round(ch(a, i) * (1 - t) + ch(b, i) * t).toString(16).padStart(2, "0")).join("");
    colCache.set(k, v);
    return v;
  }
  function makeGrid(W, H) {
    const g = Array.from({ length: H }, () => Array(W).fill(null));
    const pt = Array.from({ length: H }, () => Array(W).fill(null));
    const set = (x, y, v, p) => {
      if (x < 0 || x >= W || y < 0 || y >= H) return;
      g[y][x] = v;
      pt[y][x] = v === null ? null : p !== void 0 ? p : pt[y][x] || "misc";
    };
    const r = (x0, x1, y0, y1, v, p) => {
      for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) set(x, y, v, p);
    };
    return { g, pt, set, r };
  }
  function addOutline(g, W, H, outline) {
    const PW = W + 2, PH = H + 2;
    const pad = Array.from({ length: PH }, () => Array(PW).fill(null));
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) pad[y + 1][x + 1] = g[y][x];
    const solid = (x, y) => x >= 0 && y >= 0 && x < PW && y < PH && !!pad[y][x];
    const add = [];
    for (let y = 0; y < PH; y++) for (let x = 0; x < PW; x++)
      if (!pad[y][x] && (solid(x - 1, y) || solid(x + 1, y) || solid(x, y - 1) || solid(x, y + 1))) add.push([x, y]);
    if (outline) add.forEach(([x, y]) => {
      pad[y][x] = OUTLINE;
    });
    return pad;
  }
  var KL = { cx: 16, torso: 16, bodyL: 8, bodyR: 23, armL: 4, armR: 27, neckL: 14, neckR: 17, torsoTop: 0 };
  var WIDE = /* @__PURE__ */ new Set(["body", "shorts", "field"]);
  function shirtColor(x, ry, style, P, Q) {
    const L = KL, cx = L.cx, sleeve = x < L.bodyL || x > L.bodyR, u = x >= cx ? x - cx : cx - 1 - x;
    if (ry >= 6 && ry <= 7 && sleeve) return style === "sleeves" ? P : Q;
    if (ry <= 1 && (x >= L.neckL - 2 && x < L.neckL || x > L.neckR && x <= L.neckR + 2)) return Q;
    switch (style) {
      case "stripes":
        return !sleeve && Math.floor(u / 2) % 2 === 0 ? Q : P;
      case "hoops":
        return ry >= 2 && Math.floor(ry / 2) % 2 === 1 ? Q : P;
      case "halves":
        return x < cx ? P : Q;
      case "sash":
        return !sleeve && ry >= 2 && Math.abs(x - L.bodyL - (ry - 2)) <= 2 ? Q : P;
      case "band":
        return !sleeve && ry >= 6 && ry <= 9 ? Q : P;
      case "sleeves":
        return sleeve ? Q : P;
      default:
        return P;
    }
  }
  function buildKitGrid(input, outline = false) {
    const c = __spreadValues(__spreadValues({}, DEFAULT_KIT_CONFIG), input);
    const L = KL, cx = L.cx, tt = L.torsoTop, W = 32, H = 38, { g, pt, set, r } = makeGrid(W, H);
    const P = c.primary, Q = c.secondary;
    const col = (k) => ({ primary: P, secondary: Q, white: WHITE, black: BLACK })[k];
    for (let ry = 0; ry < L.torso; ry++) for (let x = L.armL; x <= L.armR; x++) {
      let on;
      if (ry === 0) on = x >= L.armL + 2 && x < L.neckL || x > L.neckR && x <= L.armR - 2;
      else if (ry === 1) on = x >= L.armL + 1 && x < L.neckL || x > L.neckR && x <= L.armR - 1;
      else if (ry <= 7) on = true;
      else on = x >= L.bodyL && x <= L.bodyR;
      if (on) set(x, tt + ry, shirtColor(x, ry, c.kit, P, Q), x < L.bodyL ? "sleeveL" : x > L.bodyR ? "sleeveR" : "body");
    }
    const IN = shade(P, 0.42);
    r(L.neckL, L.neckR, tt, tt, Q, "collar");
    r(L.neckL, L.neckR, tt + 1, tt + 1, IN, "inner");
    r(cx - 1, cx, tt + 2, tt + 2, IN, "inner");
    set(cx - 2, tt + 2, Q, "body");
    set(cx + 1, tt + 2, Q, "body");
    r(cx - 1, cx, tt + 3, tt + 3, Q, "body");
    const s0 = tt + L.torso + 2;
    r(L.bodyL, L.bodyR, s0, s0 + 5, col(c.shorts), "shorts");
    r(cx - 2, cx + 1, s0 + 4, s0 + 5, null);
    const sm = col(c.socks), sb = c.socks === "primary" ? Q : P, k0 = s0 + 8;
    const legs = [[cx - 6, cx - 3, "legL", -2], [cx + 2, cx + 5, "legR", 2]];
    for (const [x0, x1, part, toe] of legs) {
      r(x0, x1, k0, k0 + 1, sb, part);
      r(x0, x1, k0 + 2, k0 + 9, sm, part);
      r(toe < 0 ? x0 - 2 : x0, toe < 0 ? x1 : x1 + 2, k0 + 10, k0 + 11, sm, part);
    }
    const fold = (x, y) => {
      const v = g[y] && g[y][x];
      if (v) g[y][x] = shade(v, 0.82);
    };
    for (let ry = 9; ry < L.torso - 1; ry++) {
      fold(L.bodyL + 3 + Math.floor((ry - 9) / 3), tt + ry);
      fold(L.bodyR - 3 - Math.floor((ry - 9) / 2), tt + ry);
    }
    for (let k = 0; k < 3; k++) {
      fold(cx - 5 + k, s0 + 1 + k);
      fold(cx + 4 - k, s0 + 2 + k);
    }
    const grp = (p) => p === "sleeveL" || p === "sleeveR" || p === "collar" ? "body" : p;
    const same = (x, y, p) => x >= 0 && y >= 0 && x < W && y < H && grp(pt[y][x]) === grp(p);
    const out = g.map((row) => row.slice());
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const p = pt[y][x], v = g[y][x];
      if (!p || !v || p === "inner") continue;
      let f = 1;
      const gp = grp(p);
      if (!same(x + 1, y, p)) f *= 0.74;
      else if (WIDE.has(gp) && !same(x + 2, y, p)) f *= 0.88;
      if (!same(x, y + 1, p)) f *= 0.86;
      let o = f < 1 ? shade(v, f) : v;
      if (f === 1 && (y === 0 || !g[y - 1][x])) o = mix(v, "#ffffff", 0.22);
      out[y][x] = o;
    }
    return addOutline(out, W, H, outline);
  }
  function inShape(shape, x, y) {
    const X = x + 0.5, Y = y + 0.5, dx = Math.abs(X - 15), dy = Math.abs(Y - 15);
    switch (shape) {
      case "round":
        return dx * dx + dy * dy <= 14.8 * 14.8;
      case "diamond":
        return dx + dy <= 15.2;
      case "hex":
        return dy <= 15 && dx <= (dy <= 6 ? 14.6 : 14.6 - (dy - 6) * 14.6 / 9);
      case "crest":
        if (Y <= 2 && dx <= 4) return false;
        return shieldShape(dx, Y);
      case "shield":
        return shieldShape(dx, Y);
    }
    return false;
  }
  function shieldShape(dx, Y) {
    if (Y <= 19) return dx <= 13;
    const t = (Y - 19) / 13;
    return t <= 1 && dx <= 13 * (1 - Math.pow(t, 1.7));
  }
  function buildBadgeGrid(input, outline = false) {
    const b = __spreadValues(__spreadValues({}, DEFAULT_KIT_CONFIG), input);
    const W = 30, H = 32, { g, pt, set } = makeGrid(W, H);
    const F = b.badgeFill, T = b.badgeTrim, S = b.badgeSymbol;
    const tall = b.badgeShape === "shield" || b.badgeShape === "crest", cy = tall ? 13 : 15;
    const inside = (x, y) => x >= 0 && x < W && y >= 0 && y < H && inShape(b.badgeShape, x, y);
    const near = (x, y, d) => {
      for (let j = -d; j <= d; j++) for (let i = -d; i <= d; i++) if (Math.abs(i) + Math.abs(j) <= d && !inside(x + i, y + j)) return true;
      return false;
    };
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      if (!inside(x, y)) continue;
      if (near(x, y, 2)) {
        set(x, y, T, "trim");
        continue;
      }
      let v = F;
      switch (b.badgePattern) {
        case "stripes": {
          const d = Math.abs(x + 0.5 - 15) - 0.5;
          v = Math.floor((d + 3) / 4) % 2 ? T : F;
          break;
        }
        case "hoops":
          v = Math.floor((y + 2) / 4) % 2 ? T : F;
          break;
        case "halves":
          v = x < 15 ? F : T;
          break;
        case "quarters":
          v = x < 15 === y < cy ? F : T;
          break;
        case "chevron": {
          const k = y + Math.abs(x + 0.5 - 15);
          v = k >= cy + 9 && k < cy + 13 ? T : F;
          break;
        }
      }
      set(x, y, v, "field");
    }
    if (b.badgeCentre === "initials") {
      const txt = cleanInitials(b.initials);
      const gap = txt.length === 3 ? 1 : 2, w = txt.length * 6 + (txt.length - 1) * gap, x0 = 15 - Math.ceil(w / 2), y0 = cy - 5;
      [...txt].forEach((chr, n) => {
        const gl = GLYPHS[chr];
        if (!gl) return;
        for (let j = 0; j < 5; j++) for (let i = 0; i < 3; i++) if (gl[j * 3 + i] === "1") {
          const px = x0 + n * (6 + gap) + i * 2, py = y0 + j * 2;
          for (let q = 0; q < 4; q++) set(px + (q & 1), py + (q >> 1), S, "sym");
        }
      });
    } else if (b.badgeCentre === "star" || b.badgeCentre === "ball" || b.badgeCentre === "crown") {
      const dark = S === BLACK ? "#5a5f66" : "#1a1a1a";
      ICONS[b.badgeCentre].forEach((row, j) => [...row].forEach((chr, i) => {
        if (chr === "X") set(8 + i, cy - 7 + j, S, "sym");
        else if (chr === "o") set(8 + i, cy - 7 + j, dark, "symd");
      }));
    }
    const P = (x, y) => x >= 0 && y >= 0 && x < W && y < H ? pt[y][x] : null;
    const isSym = (p) => p === "sym" || p === "symd";
    const out = g.map((row) => row.slice());
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const p = pt[y][x], v = g[y][x];
      if (!p || !v) continue;
      let o = v;
      if (p === "trim") {
        if (!P(x, y - 1) || !P(x - 1, y)) o = mix(v, "#ffffff", 0.3);
        else if (!P(x, y + 1) || !P(x + 1, y)) o = shade(v, 0.68);
        else if (P(x, y + 1) === "field" || P(x + 1, y) === "field") o = shade(v, 0.84);
      } else if (p === "field") {
        if (isSym(P(x - 1, y - 1))) o = shade(v, 0.66);
        else if (P(x, y - 1) === "trim" || P(x - 1, y) === "trim") o = shade(v, 0.78);
        else if (x + y < 20 && (x + y) % 9 === 0) o = mix(v, "#ffffff", 0.1);
      } else if (isSym(p)) {
        let f = 1;
        if (!isSym(P(x + 1, y))) f *= 0.74;
        if (!isSym(P(x, y + 1))) f *= 0.84;
        o = f < 1 ? shade(v, f) : !isSym(P(x, y - 1)) ? mix(v, "#ffffff", 0.3) : v;
      }
      out[y][x] = o;
    }
    return addOutline(out, W, H, outline);
  }
  var SEAM = 0.04;
  function toImage(grid, rect) {
    const [x0, y0, w, h] = rect != null ? rect : [0, 0, grid[0].length, grid.length];
    const byColor = /* @__PURE__ */ new Map();
    for (let y = 0; y < h; y++) {
      const row = grid[y + y0];
      let x = 0;
      while (x < w) {
        const c = row ? row[x + x0] : null;
        if (!c) {
          x++;
          continue;
        }
        let e = x + 1;
        while (e < w && row[e + x0] === c) e++;
        const rw = e - x + SEAM;
        let list = byColor.get(c);
        if (!list) {
          list = [];
          byColor.set(c, list);
        }
        list.push(`M${x} ${y}h${rw}v${1 + SEAM}h${-rw}z`);
        x = e;
      }
    }
    return { width: w, height: h, paths: Array.from(byColor, ([color, parts]) => ({ color, d: parts.join("") })) };
  }
  function renderKit(config, type = "kit", crop = "full", outline = false) {
    if (type === "badge") return toImage(buildBadgeGrid(config, outline));
    return toImage(buildKitGrid(config, outline), crop === "shirt" ? SHIRT_CROP : void 0);
  }

  // scripts/kit-compositor/entry.ts
  var CROP_ALIASES = { large: "full", small: "shirt" };
  var SCALE_RATIO = 0.5;
  function composeKitSvg(config, type, size, scale, opts = {}) {
    var _a, _b;
    const resolvedCrop = size == null ? "full" : (_a = CROP_ALIASES[size]) != null ? _a : size;
    const image = renderKit(config != null ? config : {}, type != null ? type : "kit", resolvedCrop, (_b = opts.outline) != null ? _b : false);
    const px = scale * SCALE_RATIO;
    const paths = image.paths.map((p) => `<path d="${p.d}" fill="${p.color}"></path>`).join("");
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" width="${image.width * px}" height="${image.height * px}">${paths}</svg>`;
  }
  window.composeKitSvg = composeKitSvg;
  window.cleanInitials = cleanInitials;
  window.KIT_SPRITE_OPTIONS = {
    KIT_COLORS,
    KIT_STYLES,
    KIT_PARTS,
    BADGE_SHAPES,
    BADGE_PATTERNS,
    BADGE_CENTRES
  };
  window.DEFAULT_KIT_CONFIG = DEFAULT_KIT_CONFIG;
})();
