// Admin + landing trophy compositor — GENERATED, DO NOT EDIT BY HAND.
//
// Regenerate:  bash scripts/build-trophy-compositor.sh
//
// Source:      assets/Components/trophy16/trophy16.ts
// Source sha:  5535cd5ebd4f3eb3
//
// No build timestamp on purpose: the output is then byte-identical when
// the source has not changed, so a rebuild produces an empty diff and
// any diff at all means the sprite really moved.
//
// Everything below is that file passed through esbuild (type-stripping
// only, no other transformation) plus scripts/trophy-compositor/entry.ts,
// which adapts it to the window API the trophy widget, the admin leagues
// screen and the landing page's honours board call. The build verifies
// the source against its pixel-hash parity fixture first, so a drifted
// port fails rather than shipping.
//
// Exposes: window.composeTrophySvg(config, scale, opts)
//          window.randomTrophyConfig()
//          window.TROPHY_SPRITE_OPTIONS, window.DEFAULT_TROPHY_CONFIG
//          window.TROPHY_BG, window.TROPHY_SPRITE_SIZE
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

  // trophy16.ts (from the shared assets, see header)
  var W = 32;
  var H = 44;
  var CX = 16;
  var BASE = 41;
  var TROPHY_WIDTH = W + 2;
  var TROPHY_HEIGHT = H + 2;
  var OUTLINE = "#1d1612";
  var TROPHY_BG = "#2a8a86";
  var METALS = {
    gold: ["#fff6b8", "#ffdc4f", "#f6b62e", "#e08f1e", "#b8641a", "#7a3d12"],
    silver: ["#ffffff", "#e6eaee", "#c5ccd3", "#9aa3ad", "#6f7782", "#4a5058"]
  };
  var WOOD = ["#9a5f3a", "#7a4329", "#5e321f", "#472516", "#331a10"];
  var GOLD_PARTS = /* @__PURE__ */ new Set(["rim", "band", "frim", "knot", "handle", "finial", "emblem", "plaque", "ball"]);
  var WOODP = /* @__PURE__ */ new Set(["ptop", "plinth", "pbase"]);
  var KIT_COLORS = ["#c8202f", "#7a1f2b", "#f07a1a", "#f2c230", "#1f8a4c", "#0f4d33", "#7fb8e6", "#1f4fb8", "#1b2a4a", "#5b2c83", "#f4f3ee", "#1a1a1a"];
  var TROPHY_STYLES = [
    { id: "classic", label: "Classic" },
    { id: "goblet", label: "Goblet" },
    { id: "loving", label: "Loving cup" },
    { id: "urn", label: "Urn" },
    { id: "chalice", label: "Chalice" },
    { id: "tall", label: "Tall cup" },
    { id: "winged", label: "Winged" },
    { id: "league", label: "League cup" },
    { id: "ball", label: "Ball" },
    { id: "bowl", label: "Bowl" }
  ];
  var TROPHY_FINISHES = [
    { id: "gold", label: "Gold" },
    { id: "twotone", label: "Gold & silver" },
    { id: "silver", label: "Silver" }
  ];
  var DEFAULT_TROPHY_CONFIG = {
    style: "classic",
    finish: "gold",
    ribbons: true,
    ribbonPrimary: "#c8202f",
    ribbonSecondary: "#f4f3ee"
  };
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
  function profile(style) {
    const rows = [], hs = [];
    let emblem = null, rib = null;
    const a = (part, n, w0, w1 = w0, ease) => {
      for (let i = 0; i < n; i++) {
        const t = n === 1 ? 0 : i / (n - 1), e = ease === "in" ? t * t : ease === "out" ? 1 - (1 - t) * (1 - t) : t;
        rows.push({ part, hw: Math.round(w0 + (w1 - w0) * e) });
      }
    };
    switch (style) {
      case "classic":
        a("rim", 1, 11);
        a("lip", 1, 11);
        a("bowl", 4, 11);
        a("bowl", 8, 11, 5, "in");
        a("bowl", 2, 4, 3);
        a("stem", 2, 2);
        a("knot", 2, 3);
        a("stem", 5, 2);
        a("foot", 1, 4);
        a("foot", 2, 6, 8, "out");
        a("frim", 2, 10);
        hs.push([[10, 1], [12, 1], [13, 2], [13, 8], [12, 10], [7, 12]]);
        emblem = 6;
        rib = [13, 5];
        break;
      case "goblet":
        a("rim", 1, 7);
        a("lip", 1, 7);
        a("bowl", 3, 7);
        a("bowl", 5, 7, 3, "in");
        a("stem", 1, 2);
        a("knot", 2, 3);
        a("stem", 3, 1);
        a("foot", 1, 3);
        a("foot", 1, 5);
        a("ptop", 1, 7);
        a("plinth", 6, 7);
        a("pbase", 1, 8);
        hs.push([[6, 1], [8, 1], [9, 2], [9, 5], [8, 7], [4, 8]]);
        rib = [9, 3];
        break;
      case "loving":
        a("rim", 1, 10);
        a("lip", 1, 10);
        a("bowl", 4, 10);
        a("bowl", 7, 10, 4, "in");
        a("stem", 4, 2);
        a("foot", 1, 4);
        a("foot", 4, 6, 10, "out");
        a("frim", 1, 11);
        hs.push([[9, 2], [12, 2], [14, 4], [14, 7], [12, 9], [6, 10]]);
        rib = [14, 5];
        break;
      case "urn":
        a("finial", 2, 1);
        a("finial", 1, 2);
        a("lid", 1, 3);
        a("lid", 2, 5, 6);
        a("rim", 1, 7);
        a("lip", 1, 6);
        a("band", 1, 6);
        a("bowl", 3, 6, 7, "out");
        a("bowl", 4, 7);
        a("bowl", 4, 7, 3, "in");
        a("stem", 2, 2);
        a("foot", 1, 4);
        a("foot", 1, 5);
        a("ptop", 1, 7);
        a("plinth", 2, 7);
        a("ptop", 1, 9);
        a("plinth", 4, 9);
        break;
      case "chalice":
        a("rim", 1, 9);
        a("lip", 1, 9);
        a("bowl", 10, 9, 3);
        a("bowl", 1, 2);
        a("stem", 3, 1);
        a("knot", 1, 2);
        a("stem", 2, 1);
        a("foot", 1, 3);
        a("foot", 1, 4);
        a("ptop", 1, 7);
        a("plinth", 5, 7);
        a("pbase", 1, 8);
        break;
      case "tall":
        a("rim", 1, 6);
        a("lip", 1, 6);
        a("bowl", 5, 6, 5);
        a("bowl", 3, 5, 3);
        a("knot", 1, 2);
        a("stem", 2, 1);
        a("band", 2, 3);
        a("stem", 6, 1);
        a("foot", 1, 3);
        a("foot", 1, 4);
        a("ptop", 1, 7);
        a("plinth", 3, 7);
        a("pbase", 1, 8);
        break;
      case "winged":
        a("rim", 1, 8);
        a("lip", 1, 8);
        a("bowl", 3, 8);
        a("bowl", 6, 8, 3, "in");
        a("stem", 2, 1);
        a("knot", 1, 2);
        a("stem", 2, 1);
        a("foot", 1, 3);
        a("foot", 1, 5);
        a("ptop", 1, 8);
        a("plinth", 4, 8);
        a("pbase", 1, 9);
        hs.push([[7, 0], [10, 0], [12, 1], [12, 3], [11, 5], [6, 7]]);
        rib = [12, 2];
        break;
      case "league":
        a("rim", 1, 8);
        a("lip", 1, 8);
        a("bowl", 7, 8, 7);
        a("bowl", 4, 7, 3, "in");
        a("stem", 1, 2);
        a("knot", 2, 3);
        a("stem", 2, 2);
        a("foot", 1, 4);
        a("ptop", 1, 7);
        a("plinth", 4, 7);
        a("pbase", 1, 8);
        hs.push([[7, 1], [9, 1], [10, 2], [10, 6]]);
        rib = [10, 6];
        break;
      case "ball":
        a("ball", 1, 2);
        a("ball", 1, 4);
        a("ball", 4, 5);
        a("ball", 1, 4);
        a("ball", 1, 2);
        a("band", 1, 2);
        a("stem", 1, 1);
        a("stem", 5, 2);
        a("band", 1, 3);
        a("foot", 1, 4);
        a("ptop", 1, 6);
        a("plinth", 5, 6);
        a("pbase", 1, 7);
        break;
      case "bowl":
        a("rim", 1, 14);
        a("lip", 1, 14);
        a("bowl", 1, 13);
        a("bowl", 7, 13, 3, "in");
        a("stem", 2, 2);
        a("knot", 1, 3);
        a("stem", 1, 2);
        a("foot", 1, 4);
        a("foot", 1, 6);
        a("ptop", 1, 9);
        a("plinth", 4, 9);
        a("pbase", 1, 10);
        break;
    }
    return { rows, hs, emblem, rib };
  }
  function ramp(k, w) {
    if (w <= 2) return k === 0 ? 1 : 3;
    if (w <= 4) return [1, 0, 2, 3][k];
    const t = (k + 0.5) / w;
    return t < 0.1 ? 2 : t < 0.2 ? 1 : t < 0.32 ? 0 : t < 0.58 ? 1 : t < 0.76 ? 2 : t < 0.9 ? 3 : 4;
  }
  function buildTrophyGrid(input, opts = {}) {
    var _a;
    const c = __spreadValues(__spreadValues({}, DEFAULT_TROPHY_CONFIG), input);
    const shadow = !!opts.shadow, outline = (_a = opts.outline) != null ? _a : false, cx = CX;
    const g = Array.from({ length: H }, () => Array(W).fill(null));
    const pt = Array.from({ length: H }, () => Array(W).fill(null));
    const inb = (x, y) => x >= 0 && y >= 0 && x < W && y < H;
    const put = (x, y, v, p) => {
      if (inb(x, y)) {
        g[y][x] = v;
        pt[y][x] = p;
      }
    };
    const { rows, hs, emblem, rib } = profile(c.style);
    const n = rows.length, y0 = BASE + 1 - n;
    const pal = (part) => METALS[c.finish === "silver" ? "silver" : c.finish === "gold" ? "gold" : GOLD_PARTS.has(part) ? "gold" : "silver"];
    const clamp = (v) => Math.max(0, Math.min(5, v));
    if (shadow) {
      const hw = rows[n - 1].hw, sh = "rgba(0,0,0,.18)";
      for (let x = cx - hw - 2; x < cx + hw + 2; x++) put(x, BASE + 1, sh, "shadow");
      for (let x = cx - hw; x < cx + hw; x++) put(x, BASE + 2, sh, "shadow");
    }
    for (const poly of hs) for (let k = 0; k < poly.length - 1; k++) {
      const [d0, r0] = poly[k], [d1, r1] = poly[k + 1], steps = Math.max(Math.abs(d1 - d0), Math.abs(r1 - r0), 1);
      for (let s = 0; s <= steps; s++) {
        const d = Math.round(d0 + (d1 - d0) * s / steps), rr = Math.round(r0 + (r1 - r0) * s / steps);
        for (const dd of [d, d + 1]) for (const yy of [rr, rr + 1]) {
          put(cx - 1 - dd, y0 + yy, "#000", "handleL");
          put(cx + dd, y0 + yy, "#000", "handleR");
        }
      }
    }
    const start = [], end = [];
    for (let i = 0; i < n; i++) start[i] = i > 0 && rows[i - 1].part === rows[i].part ? start[i - 1] : i;
    for (let i = n - 1; i >= 0; i--) end[i] = i < n - 1 && rows[i + 1].part === rows[i].part ? end[i + 1] : i;
    for (let i = 0; i < n; i++) {
      const { part, hw } = rows[i], w = hw * 2, y = y0 + i, prev = i > 0 ? rows[i - 1] : null, pHw = prev ? prev.hw : 0;
      if (WOODP.has(part)) {
        for (let k = 0; k < w; k++) {
          const x = cx - hw + k, off = x - cx, d = off < 0 ? -off - 1 : off, t = (k + 0.5) / w;
          let tone = t < 0.08 ? 0 : t < 0.74 ? 1 : t < 0.9 ? 2 : 3;
          if (part === "ptop") tone = prev && !WOODP.has(prev.part) && d < pHw ? 2 : t < 0.8 ? 0 : 1;
          else if (part === "pbase" || i === end[i]) tone = Math.min(4, tone + 1);
          put(x, y, WOOD[tone], part);
        }
        continue;
      }
      const P = pal(part);
      let shift = 0;
      if (part === "rim" || part === "band" || part === "frim") shift -= 1;
      if (part === "lip") shift += 1;
      if (part === "bowl" && end[i] - i < 2) shift += 1;
      if (part === "ball") {
        if (i - start[i] < 2) shift -= 1;
        if (end[i] - i < 2) shift += 1;
      }
      if (prev && prev.part !== part && pHw >= hw + 2) shift += 1;
      let glint = part === "rim";
      for (let k = 0; k < w; k++) {
        const x = cx - hw + k, off = x - cx, d = off < 0 ? -off - 1 : off, base = ramp(k, w);
        let tone = base + shift;
        if (!prev || d >= pHw) tone -= 1;
        let v = P[clamp(tone)];
        if (glint && base === 0) {
          v = "#ffffff";
          glint = false;
        }
        put(x, y, v, part);
      }
    }
    for (let i = 0; i < n; i++) if (rows[i].part === "plinth" && start[i] === i) {
      const L = end[i] - i + 1;
      if (L < 3) continue;
      const ph = L >= 5 ? 3 : 2, ps = i + Math.floor((L - ph) / 2), hwP = Math.min(5, rows[i].hw - 2), P = pal("plaque");
      for (let j = 0; j < ph; j++) for (let k = 0; k < hwP * 2; k++) {
        let tone = j === 0 ? ph === 3 ? 0 : 1 : j === ph - 1 ? 3 : 1;
        if (ph === 3 && j === 1 && k > 0 && k < hwP * 2 - 1 && k % 2 === 0) tone = 3;
        if (k === hwP * 2 - 1) tone += 1;
        put(cx - hwP + k, y0 + ps + j, P[clamp(tone)], "plaque");
      }
    }
    if (emblem !== null) {
      const P = pal("emblem"), ey = y0 + emblem + 0.5;
      for (let y = Math.floor(ey) - 3; y <= Math.floor(ey) + 3; y++) for (let x = cx - 4; x < cx + 4; x++) {
        if (!inb(x, y) || pt[y][x] !== "bowl") continue;
        const dist = Math.hypot(x + 0.5 - cx, y + 0.5 - ey);
        if (dist < 1.6) put(x, y, P[x < cx && y < ey ? 0 : 1], "emblem");
        else if (dist < 2.7) put(x, y, P[3], "emblem");
      }
    }
    const bi = rows.findIndex((r) => r.part === "ball");
    if (bi >= 0) {
      const P = pal("ball"), by = y0 + bi;
      const panels = [[cx - 1, by + 3], [cx, by + 3], [cx - 1, by + 4], [cx, by + 4], [cx - 4, by + 2], [cx + 3, by + 5], [cx - 3, by + 6], [cx + 2, by + 1]];
      for (const [x, y] of panels) if (inb(x, y) && pt[y][x] === "ball") put(x, y, P[3], "ball");
    }
    const occ = (x, y) => inb(x, y) && !!pt[y][x] && pt[y][x] !== "shadow";
    const HP = pal("handle");
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const p = pt[y][x];
      if (p !== "handleL" && p !== "handleR") continue;
      let tone = p === "handleL" ? 1 : 3;
      if (!occ(x, y - 1)) tone -= 1;
      else if (!occ(x, y + 1)) tone += 1;
      g[y][x] = HP[clamp(tone)];
    }
    if (c.ribbons) {
      const RP = c.ribbonPrimary || "#c8202f", RS = c.ribbonSecondary || "#f4f3ee", mir = (x) => 2 * cx - 1 - x;
      const tail = (x0, ty, len, v, part, sway, mirror) => {
        for (let j = 0; j < len; j++) {
          const sx = x0 + (j >= Math.floor(len / 2) ? sway : 0);
          for (let k = 0; k < 3; k++) {
            if (j === len - 1 && k === 1) continue;
            put(sx + k, ty + j, v, part);
            if (mirror) put(mir(sx + k), ty + j, v, part);
          }
        }
      };
      if (rib) {
        const [d, row] = rib, x0 = cx - 1 - (d + 1), ky = y0 + row;
        tail(x0 + 2, ky + 2, 8, RS, "ribS", 1, true);
        tail(x0, ky + 1, 10, RP, "ribP", 0, true);
        for (let k = 0; k < 3; k++) for (let j = 0; j < 2; j++) {
          put(x0 + k, ky + j, RP, "ribK");
          put(mir(x0 + k), ky + j, RP, "ribK");
        }
      } else {
        const si = rows.findIndex((r) => r.part === "stem"), ky = y0 + (si < 0 ? Math.floor(n / 2) : si);
        const pi = rows.findIndex((r) => WOODP.has(r.part)), room = (pi < 0 ? BASE + 1 : y0 + pi) - 1 - (ky + 2);
        const lenP = Math.max(3, Math.min(8, room)), lenS = Math.max(3, Math.min(7, room - 1));
        tail(cx - 3, ky + 2, lenP, RP, "ribP", -1, false);
        tail(cx, ky + 2, lenS, RS, "ribS", 1, false);
        for (let j = -1; j <= 1; j++) for (let k = 0; k < 3; k++) {
          put(cx - 5 + k, ky + j, RP, "ribK");
          put(cx + 2 + k, ky + j, RP, "ribK");
        }
        for (let j = 0; j < 2; j++) for (let k = 0; k < 2; k++) put(cx - 1 + k, ky + j, RS, "ribC");
      }
      const isR = (q) => q === "ribP" || q === "ribS" || q === "ribK" || q === "ribC";
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
        const q = pt[y][x];
        if (!isR(q)) continue;
        const v = g[y][x], l = x > 0 ? pt[y][x - 1] : null, r = x < W - 1 ? pt[y][x + 1] : null, dn = y < H - 1 ? pt[y + 1][x] : null;
        let o = v;
        if (q === "ribK") o = l !== q ? mix(v, "#ffffff", 0.2) : dn !== q ? shade(v, 0.7) : shade(v, 0.86);
        else if (l !== q) o = mix(v, "#ffffff", 0.25);
        else if (r !== q) o = shade(v, 0.74);
        else if (y % 4 === 0) o = shade(v, 0.9);
        g[y][x] = o;
      }
    }
    const PW = W + 2, PH = H + 2;
    const pad = Array.from({ length: PH }, () => Array(PW).fill(null));
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) pad[y + 1][x + 1] = g[y][x];
    if (outline) {
      const solid = (x, y) => {
        const v = x >= 0 && y >= 0 && x < PW && y < PH ? pad[y][x] : null;
        return !!v && v[0] === "#";
      };
      const add = [];
      for (let y = 0; y < PH; y++) for (let x = 0; x < PW; x++) {
        const v = pad[y][x];
        if ((v === null || v[0] !== "#") && (solid(x - 1, y) || solid(x + 1, y) || solid(x, y - 1) || solid(x, y + 1))) add.push([x, y]);
      }
      add.forEach(([x, y]) => {
        pad[y][x] = OUTLINE;
      });
    }
    return pad;
  }
  var SEAM = 0.04;
  function toImage(grid) {
    const h = grid.length, w = grid[0].length, byColor = /* @__PURE__ */ new Map();
    for (let y = 0; y < h; y++) {
      const row = grid[y];
      let x = 0;
      while (x < w) {
        const c = row[x];
        if (!c) {
          x++;
          continue;
        }
        let e = x + 1;
        while (e < w && row[e] === c) e++;
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
  function renderTrophy(config, opts = {}) {
    return toImage(buildTrophyGrid(config, opts));
  }

  // scripts/trophy-compositor/entry.ts
  function composeTrophySvg(config, scale = 4, opts = {}) {
    var _a, _b, _c, _d, _e;
    const background = (_a = opts.background) != null ? _a : "none";
    const onColour = background === "colour";
    const image = renderTrophy(config != null ? config : {}, {
      shadow: (_b = opts.shadow) != null ? _b : onColour,
      outline: (_c = opts.outline) != null ? _c : false
    });
    const pad = Math.max(0, Math.round((_d = opts.padding) != null ? _d : 0));
    const w = image.width + pad * 2;
    const h = image.height + pad * 2;
    const bg = onColour ? `<rect x="0" y="0" width="${w}" height="${h}" fill="${(_e = opts.backgroundColor) != null ? _e : TROPHY_BG}"></rect>` : "";
    const paths = image.paths.map((p) => `<path d="${p.d}" fill="${p.color}"></path>`).join("");
    const content = pad > 0 ? `<g transform="translate(${pad} ${pad})">${paths}</g>` : paths;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w * scale}" height="${h * scale}">${bg}${content}</svg>`;
  }
  function randomTrophyConfig() {
    const pick = (xs) => xs[Math.floor(Math.random() * xs.length)];
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
      ribbonSecondary
    };
  }
  window.composeTrophySvg = composeTrophySvg;
  window.randomTrophyConfig = randomTrophyConfig;
  window.TROPHY_SPRITE_OPTIONS = { TROPHY_STYLES, TROPHY_FINISHES, KIT_COLORS };
  window.DEFAULT_TROPHY_CONFIG = DEFAULT_TROPHY_CONFIG;
  window.TROPHY_BG = TROPHY_BG;
  window.TROPHY_SPRITE_SIZE = { width: TROPHY_WIDTH, height: TROPHY_HEIGHT };
})();
