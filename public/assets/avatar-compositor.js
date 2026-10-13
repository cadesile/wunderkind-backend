// Admin + landing avatar compositor — GENERATED, DO NOT EDIT BY HAND.
//
// Regenerate:  bash scripts/build-avatar-compositor.sh
//
// Source:      wunderkind-app/src/components/avatar-sprite/avatar16.ts
// Source sha:  2ffce711f26b3367
//
// No build timestamp on purpose: the output is then byte-identical when
// the source has not changed, so a rebuild produces an empty diff and
// any diff at all means the sprite really moved.
//
// Everything below is that file passed through esbuild (type-stripping
// only, no other transformation) plus scripts/avatar-compositor/entry.ts,
// which adapts it to the window API the admin widget and landing page
// call. The build verifies the source against its pixel-hash parity
// fixture first, so a drifted port fails rather than shipping.
//
// Exposes: window.composePlayerSpriteSvg(config, type, crop, scale, opts)
//          window.SKIN_SHADES
//          window.AVATAR_BODY_SIZE { heightToBodySize, weightToBodySize }
//          window.DEFAULT_AVATAR_CONFIG
(() => {
  var __defProp = Object.defineProperty;
  var __defProps = Object.defineProperties;
  var __getOwnPropDescs = Object.getOwnPropertyDescriptors;
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
  var __spreadProps = (a, b) => __defProps(a, __getOwnPropDescs(b));

  // avatar16.ts (from the app, see header)
  var W = 36;
  var H = 66;
  var FULL_WIDTH = W + 2;
  var FULL_HEIGHT = H + 2;
  var CX = 16;
  var WHITE = "#f4f3ee";
  var BLACK = "#1a1a1a";
  var OUTLINE = "#1d1612";
  var SKINS = [
    { id: "s1", c: "#f6d3b3", d: "#e0b28c" },
    { id: "s2", c: "#ecc095", d: "#d39f73" },
    { id: "s3", c: "#d49c64", d: "#b9804c" },
    { id: "s4", c: "#b37548", d: "#965d36" },
    { id: "s5", c: "#8a5230", d: "#704023" },
    { id: "s6", c: "#5c3822", d: "#472a18" }
  ];
  var DEFAULT_AVATAR_CONFIG = {
    role: "player",
    hair: "crop",
    hairColor: "#c8602a",
    headband: false,
    skin: "s1",
    face: "neutral",
    facial: "none",
    lip: "#c9575e",
    primary: "#c8202f",
    secondary: "#f4f3ee",
    height: "medium",
    weight: "medium",
    kit: "stripes",
    shorts: "black",
    socks: "primary",
    outfit: "coat",
    trousers: "black",
    glasses: false
  };
  var HEIGHT = { small: { torso: 14, knee: 2 }, medium: { torso: 16, knee: 4 }, large: { torso: 18, knee: 6 } };
  var WEIGHT = {
    small: { body: 12, arm: 3, leg: 3, neck: 4 },
    medium: { body: 16, arm: 4, leg: 4, neck: 4 },
    large: { body: 18, arm: 5, leg: 5, neck: 6 }
  };
  var WIDE = /* @__PURE__ */ new Set(["head", "body", "shorts", "hips", "ball", "beard"]);
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
  var lumOf = (h) => ch(h, 1) * 0.299 + ch(h, 3) * 0.587 + ch(h, 5) * 0.114;
  function layout(c) {
    const h = HEIGHT[c.height] || HEIGHT.medium, w = WEIGHT[c.weight] || WEIGHT.medium, cx = CX;
    const kneeTop = 56 - h.knee, shortsTop = kneeTop - 6, torsoTop = shortsTop - h.torso;
    const bodyL = cx - w.body / 2, bodyR = cx + w.body / 2 - 1;
    return __spreadProps(__spreadValues(__spreadValues({}, h), w), {
      T: torsoTop - 20,
      torsoTop,
      torsoEnd: shortsTop - 1,
      shortsTop,
      kneeTop,
      bodyL,
      bodyR,
      armL: bodyL - w.arm,
      armR: bodyR + w.arm,
      neckL: cx - w.neck / 2,
      neckR: cx + w.neck / 2 - 1,
      legL: [cx - 2 - w.leg, cx - 3],
      legR: [cx + 2, cx + 1 + w.leg]
    });
  }
  function hairPx(style) {
    const p = [];
    const r = (x0, x1, y0, y1) => {
      for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) p.push([x, y]);
    };
    switch (style) {
      case "buzz":
        r(5, 10, 3, 3);
        r(4, 11, 4, 4);
        r(4, 4, 5, 5);
        r(11, 11, 5, 5);
        break;
      case "crop":
        r(5, 10, 2, 2);
        r(4, 11, 3, 4);
        r(4, 5, 5, 5);
        r(10, 11, 5, 5);
        r(4, 4, 6, 7);
        r(11, 11, 6, 7);
        break;
      case "quiff":
        r(7, 10, 0, 0);
        r(5, 11, 1, 1);
        r(4, 11, 2, 4);
        r(4, 5, 5, 5);
        r(10, 11, 5, 5);
        r(4, 4, 6, 6);
        r(11, 11, 6, 6);
        break;
      case "mohawk":
        r(7, 8, 0, 4);
        break;
      case "afro":
        r(5, 10, 0, 0);
        r(3, 12, 1, 1);
        r(2, 13, 2, 5);
        r(2, 4, 6, 6);
        r(11, 13, 6, 6);
        r(2, 2, 7, 7);
        r(13, 13, 7, 7);
        break;
      case "long":
        r(5, 10, 2, 2);
        r(4, 11, 3, 4);
        r(4, 5, 5, 5);
        r(10, 11, 5, 5);
        r(3, 3, 4, 14);
        r(12, 12, 4, 14);
        r(4, 4, 6, 9);
        r(11, 11, 6, 9);
        break;
      case "cornrows":
        [4, 6, 9, 11].forEach((x) => r(x, x, 3, 5));
        r(6, 6, 2, 2);
        r(9, 9, 2, 2);
        break;
      case "bun":
        r(6, 9, 0, 1);
        r(5, 10, 2, 2);
        r(4, 11, 3, 4);
        r(4, 4, 5, 5);
        r(11, 11, 5, 5);
        break;
    }
    return p;
  }
  function shirtColor(x, ry, style, P, Q, L) {
    const cx = CX, sleeve = x < L.bodyL || x > L.bodyR, u = x >= cx ? x - cx : cx - 1 - x;
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
  function torso(L, set, sleeveEnd, colorAt) {
    for (let ry = 0; ry < L.torso; ry++) for (let x = L.armL; x <= L.armR; x++) {
      let on;
      if (ry === 0) on = x >= L.armL + 2 && x < L.neckL || x > L.neckR && x <= L.armR - 2;
      else if (ry === 1) on = x >= L.armL + 1 && x < L.neckL || x > L.neckR && x <= L.armR - 1;
      else if (ry <= sleeveEnd) on = true;
      else on = x >= L.bodyL && x <= L.bodyR;
      if (on) set(x, L.torsoTop + ry, colorAt(x, ry), x < L.bodyL ? "sleeveL" : x > L.bodyR ? "sleeveR" : "body");
    }
  }
  function staffBody(c, L, set, r, S, P, Q, col) {
    const cx = CX, o = c.outfit, T = col(c.trousers), other = T === P ? Q : P;
    const tt = L.torsoTop, te = L.torsoEnd, top = te + 1;
    r(L.bodyL, L.bodyR, top, top + 3, T, "hips");
    r(L.bodyL, cx - 3, top + 4, 61, T, "legL");
    r(cx + 2, L.bodyR, top + 4, 61, T, "legR");
    if (o === "track") {
      r(L.bodyL, L.bodyL, top, 61, other);
      r(L.bodyR, L.bodyR, top, 61, other);
    }
    const shoe = o === "track" ? WHITE : "#141414";
    r(L.bodyL - 1, cx - 3, 62, 63, shoe, "bootL");
    r(cx + 2, L.bodyR + 1, 62, 63, shoe, "bootR");
    torso(L, set, L.torso - 3, () => P);
    r(L.armL, L.bodyL - 1, te - 1, te, S, "armL");
    r(L.bodyR + 1, L.armR, te - 1, te, S, "armR");
    const Wt = WHITE;
    switch (o) {
      case "coat":
        r(cx - 2, cx + 1, tt + 2, te, Q);
        r(L.bodyL - 1, cx - 3, top, top + 9, P, "coatL");
        r(cx + 2, L.bodyR + 1, top, top + 9, P, "coatR");
        break;
      case "track":
        r(L.neckL - 2, L.neckR + 2, tt, tt + 1, Q, "body");
        r(L.armL, L.armL, tt + 2, te - 2, Q);
        r(L.armR, L.armR, tt + 2, te - 2, Q);
        r(L.bodyL, L.bodyR, te - 1, te, Q);
        break;
      case "suit": {
        const lum = lumOf(P);
        const lapel = shade(P, 0.74), btn = lum < 80 ? mix(P, "#ffffff", 0.45) : shade(P, 0.5), cg = "#b9b6ad";
        r(cx - 3, cx + 2, tt, tt + 3, Wt, "body");
        r(cx - 2, cx + 1, tt + 4, tt + 7, Wt);
        set(cx - 3, tt + 1, cg);
        set(cx + 2, tt + 1, cg);
        set(cx - 2, tt + 2, cg);
        set(cx + 1, tt + 2, cg);
        r(cx - 4, cx - 4, tt, tt + 3, lapel);
        r(cx + 3, cx + 3, tt, tt + 3, lapel);
        r(cx - 3, cx - 3, tt + 4, tt + 7, lapel);
        r(cx + 2, cx + 2, tt + 4, tt + 7, lapel);
        const tie = lumOf(Q) < 200 ? Q : lum < 200 ? P : "#2b2f3a";
        r(cx - 1, cx, tt, tt + 1, shade(tie, 0.85));
        r(cx - 1, cx, tt + 2, tt + 7, tie);
        set(cx - 1, tt + 8, tie);
        for (let y = tt + 8; y <= te; y++) set(cx, y, lapel);
        for (const y of [tt + 9, tt + 12]) if (y <= te - 1) set(cx - 1, y, btn);
        break;
      }
      case "jumper":
        r(L.neckL - 2, L.neckL - 1, tt, tt + 1, Q);
        r(L.neckR + 1, L.neckR + 2, tt, tt + 1, Q);
        r(L.armL, L.bodyL - 1, te - 3, te - 2, Q);
        r(L.bodyR + 1, L.armR, te - 3, te - 2, Q);
        r(L.bodyL, L.bodyR, te - 1, te, Q);
        break;
    }
  }
  function buildGrid(input, opts = {}) {
    var _a;
    const c = __spreadValues(__spreadValues({}, DEFAULT_AVATAR_CONFIG), input);
    const ball = !!opts.ball && c.role !== "staff", shadow = !!opts.shadow, outline = (_a = opts.outline) != null ? _a : true;
    const cx = CX, L = layout(c), T = L.T;
    const g = Array.from({ length: H }, () => Array(W).fill(null));
    const pt = Array.from({ length: H }, () => Array(W).fill(null));
    const set = (x, y, v, p) => {
      if (x < 0 || x >= W || y < 0 || y >= H) return;
      const val = typeof v === "function" ? v(x, y) : v;
      g[y][x] = val;
      pt[y][x] = val === null ? null : p !== void 0 ? p : pt[y][x] || "misc";
    };
    const r = (x0, x1, y0, y1, v, p) => {
      for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) set(x, y, v, p);
    };
    const oy = T - 6;
    const R = (x0, x1, y0, y1, v, p) => r(2 * x0, 2 * x1 + 1, oy + 2 * y0, oy + 2 * y1 + 1, v, p);
    const sk = SKINS.find((s) => s.id === c.skin) || SKINS[0];
    const S = sk.c, D = sk.d, P = c.primary, Q = c.secondary, hc = c.hairColor;
    const col = (k) => ({ primary: P, secondary: Q, white: WHITE, black: BLACK })[k];
    if (shadow) {
      const sh = "rgba(0,0,0,.18)";
      r(cx - 10, cx + 9, 64, 64, sh, null);
      r(cx - 8, cx + 7, 65, 65, sh, null);
      if (ball) {
        r(25, 34, 64, 64, sh, null);
        r(27, 32, 65, 65, sh, null);
      }
    }
    const staff = c.role === "staff";
    if (staff) staffBody(c, L, set, r, S, P, Q, col);
    else {
      const [l0, l1] = L.legL, [q0, q1] = L.legR, sm = col(c.socks), sb = c.socks === "primary" ? Q : P;
      r(l0, l1, L.kneeTop, 55, S, "legL");
      r(q0, q1, L.kneeTop, 55, S, "legR");
      r(l0, l1, 56, 57, sb, "legL");
      r(q0, q1, 56, 57, sb, "legR");
      r(l0, l1, 58, 60, sm, "legL");
      r(q0, q1, 58, 60, sm, "legR");
      const BK = "#18181b", SW = "#e9e9e4", ST = "#8a8f96";
      r(l0, l1, 61, 61, BK, "bootL");
      r(q0, q1, 61, 61, BK, "bootR");
      r(l0 - 2, l1, 62, 63, BK, "bootL");
      r(q0, q1 + 2, 62, 63, BK, "bootR");
      r(l0, l0 + 1, 62, 62, SW);
      r(q1 - 1, q1, 62, 62, SW);
      for (const x of [l0 - 1, l1 - 1, q0 + 1, q1 + 1]) set(x, 64, ST, "stud");
      r(L.bodyL, L.bodyR, L.shortsTop, L.shortsTop + 5, col(c.shorts), "shorts");
      r(cx - 2, cx + 1, L.shortsTop + 4, L.shortsTop + 5, null);
      torso(L, set, 7, (x, ry) => shirtColor(x, ry, c.kit, P, Q, L));
      r(L.armL, L.bodyL - 1, L.torsoTop + 8, L.torsoEnd, S, "armL");
      r(L.bodyR + 1, L.armR, L.torsoTop + 8, L.torsoEnd, S, "armR");
      const fold = (x, y) => {
        const v = g[y] && g[y][x];
        if (v && v[0] === "#") g[y][x] = shade(v, 0.82);
      };
      for (let ry = 9; ry < L.torso - 1; ry++) {
        fold(L.bodyL + 3 + Math.floor((ry - 9) / 3), L.torsoTop + ry);
        fold(L.bodyR - 3 - Math.floor((ry - 9) / 2), L.torsoTop + ry);
      }
      for (let k = 0; k < 3; k++) {
        fold(cx - 5 + k, L.shortsTop + 1 + k);
        fold(cx + 4 - k, L.shortsTop + 2 + k);
      }
      const G = "#d8a93a", tt = L.torsoTop;
      ["GGG", "GXG", ".G."].forEach((row, j) => [...row].forEach((chr, i) => {
        if (chr !== ".") set(cx + 3 + i, tt + 4 + j, chr === "G" ? G : Q);
      }));
    }
    if (!(staff && (c.outfit === "track" || c.outfit === "suit"))) r(L.neckL, L.neckR, L.torsoTop, L.torsoTop + 1, D, "neck");
    if (!staff) {
      const tt = L.torsoTop;
      r(cx - 1, cx, tt + 2, tt + 2, D, "neck");
      set(cx - 2, tt + 2, Q);
      set(cx + 1, tt + 2, Q);
      r(cx - 1, cx, tt + 3, tt + 3, Q);
    }
    for (let ry = 0; ry < 20; ry++) {
      const w = ry === 0 || ry === 19 ? 12 : ry === 1 || ry === 18 ? 14 : 16;
      r(cx - w / 2, cx + w / 2 - 1, T + ry, T + ry, S, "head");
    }
    r(cx - 10, cx - 9, T + 8, T + 13, S, "earL");
    r(cx + 8, cx + 9, T + 8, T + 13, S, "earR");
    for (const [x, y] of [[cx - 10, T + 8], [cx - 10, T + 13], [cx + 9, T + 8], [cx + 9, T + 13]]) set(x, y, null);
    r(cx - 9, cx - 9, T + 10, T + 11, D);
    r(cx + 8, cx + 8, T + 10, T + 11, D);
    for (const [x, y] of hairPx(c.hair)) R(x, x, y, y, hc, "hair");
    if (c.hair === "cornrows") [5, 7, 8, 10].forEach((x) => R(x, x, 3, 5, D, "head"));
    const kill = [];
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (pt[y][x] === "hair") {
      const up = y === 0 || g[y - 1][x] === null, lf = x === 0 || g[y][x - 1] === null, rt = x === W - 1 || g[y][x + 1] === null;
      if (up && (lf || rt)) kill.push([x, y]);
    }
    kill.forEach(([x, y]) => set(x, y, null));
    if (c.hair === "afro" || c.hair === "buzz") {
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (pt[y][x] === "hair") {
        if (c.hair === "afro" && (x * 3 + y * 5) % 7 === 0) g[y][x] = shade(hc, 0.8);
        if (c.hair === "buzz" && (x + y) % 2) g[y][x] = mix(S, hc, 0.6);
      }
    }
    if (c.hair === "crop" || c.hair === "quiff" || c.hair === "long" || c.hair === "bun" || c.hair === "mohawk") {
      for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (pt[y][x] === "hair") {
        const k = (x + Math.floor((y - T) / 2) + 40) % 4;
        if (k === 0) g[y][x] = shade(hc, 0.72);
        else if (k === 2) g[y][x] = mix(hc, "#ffffff", y < T + 4 ? 0.26 : 0.12);
      }
    }
    const HL = mix(S, "#ffffff", 0.3);
    set(cx - 1, T + 11, HL);
    set(cx - 1, T + 12, HL);
    set(cx, T + 11, D);
    set(cx, T + 12, D);
    set(cx - 1, T + 13, shade(D, 0.9));
    set(cx, T + 13, D);
    set(cx + 1, T + 13, shade(D, 0.9));
    r(cx - 6, cx - 5, T + 13, T + 13, HL);
    r(cx + 4, cx + 5, T + 13, T + 13, HL);
    r(cx - 9, cx - 9, T + 12, T + 12, D);
    r(cx + 8, cx + 8, T + 12, T + 12, D);
    set(cx - 10, T + 10, shade(S, 0.88));
    set(cx + 9, T + 10, shade(S, 0.88));
    if (c.facial === "stubble" || c.facial === "beard") {
      const beard = c.facial === "beard", fp = beard ? "beard" : void 0;
      const fc = beard ? hc : (x, y) => mix(S, hc, (x + y) % 2 ? 0.55 : 0.28);
      R(4, 4, 9, 9, fc, fp);
      R(11, 11, 9, 9, fc, fp);
      R(4, 5, 10, 10, fc, fp);
      R(10, 11, 10, 10, fc, fp);
      R(4, 11, 11, 11, fc, fp);
      r(cx - 6, cx + 5, T + 18, T + 18, fc, fp);
      r(cx - 5, cx + 4, T + 19, T + 19, fc, fp);
      R(6, 9, 9, 9, fc, fp);
      if (beard) {
        r(cx - 7, cx + 6, T + 18, T + 18, fc, fp);
        r(cx - 6, cx + 5, T + 19, T + 19, fc, fp);
        r(cx - 5, cx + 4, T + 20, T + 20, fc, fp);
        r(cx - 4, cx + 3, T + 21, T + 21, fc, fp);
        r(cx - 4, cx + 3, T + 14, T + 15, S, "head");
      }
    }
    const E = "#1a1414", EW = "#f4f3ee", M = c.lip || "#c9575e", B = c.hair === "bald" ? D : hc, my = T + 15;
    const I = "#6a4024", PU = "#2a1810", LD = shade(M, 0.62), BS = shade(B, 0.78);
    const eyes = () => {
      r(cx - 6, cx - 3, T + 10, T + 10, E);
      set(cx - 7, T + 10, E);
      set(cx - 6, T + 11, EW);
      set(cx - 5, T + 11, I);
      set(cx - 4, T + 11, PU);
      set(cx - 3, T + 11, EW);
      r(cx + 2, cx + 5, T + 10, T + 10, E);
      set(cx + 6, T + 10, E);
      set(cx + 2, T + 11, EW);
      set(cx + 3, T + 11, PU);
      set(cx + 4, T + 11, I);
      set(cx + 5, T + 11, EW);
    };
    const relaxed = () => {
      r(cx - 6, cx - 3, T + 7, T + 7, B);
      r(cx - 7, cx - 6, T + 8, T + 8, B);
      r(cx - 5, cx - 3, T + 8, T + 8, BS);
      r(cx + 2, cx + 5, T + 7, T + 7, B);
      r(cx + 5, cx + 6, T + 8, T + 8, B);
      r(cx + 2, cx + 4, T + 8, T + 8, BS);
    };
    const smile = () => {
      set(cx - 4, my - 2, LD);
      set(cx + 3, my - 2, LD);
      r(cx - 3, cx + 2, my - 1, my - 1, LD);
      r(cx - 3, cx + 2, my, my, "#fbfaf5");
      r(cx - 2, cx + 1, my + 1, my + 1, M);
    };
    const brows = () => {
      r(cx - 6, cx - 5, T + 7, T + 7, B);
      r(cx - 4, cx - 3, T + 8, T + 8, B);
      r(cx + 4, cx + 5, T + 7, T + 7, B);
      r(cx + 2, cx + 3, T + 8, T + 8, B);
    };
    switch (c.face) {
      case "happy":
        eyes();
        relaxed();
        smile();
        break;
      case "wink":
        eyes();
        relaxed();
        r(cx - 7, cx - 3, T + 10, T + 11, S);
        r(cx - 5, cx - 4, T + 10, T + 10, E);
        set(cx - 6, T + 11, E);
        set(cx - 3, T + 11, E);
        smile();
        break;
      case "focused":
        eyes();
        brows();
        r(cx - 2, cx + 1, my, my, M);
        break;
      case "shout": {
        eyes();
        brows();
        const O = "#5b1a1f";
        r(cx - 3, cx + 2, my - 1, my - 1, O);
        r(cx - 4, cx + 3, my, my + 1, O);
        r(cx - 3, cx + 2, my + 2, my + 2, O);
        r(cx - 2, cx + 1, my - 1, my - 1, EW);
        r(cx - 1, cx, my + 1, my + 2, "#c4565c");
        break;
      }
      case "sad":
        eyes();
        r(cx - 6, cx - 5, T + 8, T + 8, B);
        r(cx - 4, cx - 3, T + 7, T + 7, B);
        r(cx + 2, cx + 3, T + 7, T + 7, B);
        r(cx + 4, cx + 5, T + 8, T + 8, B);
        r(cx - 2, cx + 1, my, my, M);
        r(cx - 4, cx - 3, my + 1, my + 1, M);
        r(cx + 2, cx + 3, my + 1, my + 1, M);
        break;
      case "frustrated":
        eyes();
        r(cx - 6, cx - 3, T + 8, T + 8, B);
        r(cx + 2, cx + 5, T + 8, T + 8, B);
        r(cx - 4, cx - 3, my + 1, my + 1, M);
        r(cx - 2, cx - 1, my, my, M);
        r(cx, cx + 1, my + 1, my + 1, M);
        r(cx + 2, cx + 3, my, my, M);
        break;
      case "angry":
        eyes();
        brows();
        r(cx - 4, cx + 3, my - 1, my, EW);
        r(cx - 5, cx - 5, my - 1, my, M);
        r(cx + 4, cx + 4, my - 1, my, M);
        r(cx - 4, cx + 3, my + 1, my + 1, M);
        r(cx - 1, cx - 1, my - 1, my, "#cfccc4");
        break;
      case "cool": {
        const K = "#111111", G = "#4a5560";
        r(cx - 6, cx + 5, T + 10, T + 10, K);
        r(cx - 8, cx - 7, T + 10, T + 10, K);
        r(cx + 6, cx + 7, T + 10, T + 10, K);
        r(cx - 6, cx - 2, T + 11, T + 11, K);
        r(cx + 1, cx + 5, T + 11, T + 11, K);
        r(cx - 5, cx - 3, T + 12, T + 12, K);
        r(cx + 2, cx + 4, T + 12, T + 12, K);
        set(cx - 5, T + 11, G);
        set(cx + 2, T + 11, G);
        r(cx, cx + 2, my, my, M);
        set(cx + 3, my - 1, M);
        break;
      }
      default:
        eyes();
        relaxed();
        r(cx - 2, cx + 1, my, my, M);
        r(cx - 1, cx, my + 1, my + 1, shade(S, 0.86));
    }
    if (staff && c.glasses && c.face !== "cool") {
      const F = Q, Lc = "#dfe8ee";
      const box = (x0, x1) => {
        for (let y = T + 9; y <= T + 13; y++) for (let x = x0; x <= x1; x++) {
          if (y === T + 9 || y === T + 13 || x === x0 || x === x1) set(x, y, F);
          else if (y === T + 12) {
            const v = g[y][x];
            set(x, y, mix(v && v[0] === "#" ? v : S, Lc, 0.5));
          }
        }
      };
      box(cx - 6, cx - 2);
      box(cx + 1, cx + 5);
      r(cx - 1, cx, T + 10, T + 10, F);
      r(cx - 8, cx - 7, T + 10, T + 10, F);
      r(cx + 6, cx + 7, T + 10, T + 10, F);
    }
    if (c.headband) {
      for (let y = T + 4; y <= T + 5; y++) for (let x = 0; x < W; x++) if (pt[y][x] === "head" || pt[y][x] === "hair") set(x, y, WHITE, "band");
    }
    if (ball) {
      const pat = ["..WWWWWW..", ".WWKKKKWW.", "WWWKKKKWWW", "WKWWKKWWKW", "KKWWWWWWKK", "KWWWWWWWWK", "WWKWWWWKWW", "WKKKWWKKKW", ".WKKWWKKW.", "..WWWWWW.."];
      pat.forEach((row, j) => [...row].forEach((chr, i) => {
        if (chr !== ".") set(25 + i, 54 + j, chr === "W" ? i + j > 11 ? "#c9ccd0" : i + j < 4 ? "#ffffff" : "#f0f0ea" : i + j > 11 ? "#101012" : "#2a2a2e", "ball");
      }));
    }
    const out = g.map((row) => row.slice());
    const grp = (p) => p === "sleeveL" || p === "sleeveR" ? "body" : p;
    const same = (x, y, p) => x >= 0 && y >= 0 && x < W && y < H && grp(pt[y][x]) === grp(p);
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const p = pt[y][x], v = g[y][x];
      if (!p || !v || v[0] !== "#") continue;
      let f = 1;
      const rp = x + 1 < W ? pt[y][x + 1] : null, bp = y + 1 < H ? pt[y + 1][x] : null;
      if (p === "armL" && rp === "body" || p === "body" && rp === "armR") f *= 0.55;
      else if (!same(x + 1, y, p)) f *= 0.74;
      else if (WIDE.has(p) && !same(x + 2, y, p)) f *= 0.88;
      if (p === "hair" && bp === "head" || p === "body" && bp === "shorts") f *= 0.62;
      else if (p === "head" && bp === "neck") f *= 0.7;
      else if (!same(x, y + 1, p)) f *= 0.86;
      let o = f < 1 ? shade(v, f) : v;
      if (f === 1 && (y === 0 || g[y - 1][x] === null)) o = mix(v, "#ffffff", 0.22);
      out[y][x] = o;
    }
    const PW = W + 2, PH = H + 2;
    const pad = Array.from({ length: PH }, () => Array(PW).fill(null));
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) pad[y + 1][x + 1] = out[y][x];
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
    return { grid: pad, layout: L };
  }
  function cropRect(crop, L) {
    return crop === "head" ? [3, L.T - 6, 28, 30] : crop === "kit" ? [3, L.T + 20, 28, 36] : [0, 0, FULL_WIDTH, FULL_HEIGHT];
  }
  var SEAM = 0.04;
  function toImage(grid, rect) {
    const [x0, y0, w, h] = rect, byColor = /* @__PURE__ */ new Map();
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
  function renderAvatar(config, opts = {}) {
    var _a;
    const crop = (_a = opts.crop) != null ? _a : "full", full = crop === "full";
    const c = __spreadValues(__spreadValues({}, DEFAULT_AVATAR_CONFIG), config);
    const cfg = crop === "kit" ? __spreadProps(__spreadValues({}, c), { height: "medium", weight: "medium" }) : c;
    const { grid, layout: L } = buildGrid(cfg, { ball: full && !!opts.ball, shadow: full && !!opts.shadow, outline: opts.outline });
    return toImage(grid, cropRect(crop, L));
  }

  // scripts/avatar-compositor/entry.ts
  var CROP_ALIASES = { large: "full", small: "head" };
  var SCALE_RATIO = 0.5;
  function heightToBodySize(cm) {
    if (typeof cm !== "number" || !Number.isFinite(cm)) return "medium";
    if (cm < 170) return "small";
    if (cm < 185) return "medium";
    return "large";
  }
  function weightToBodySize(kg) {
    if (typeof kg !== "number" || !Number.isFinite(kg)) return "medium";
    if (kg < 70) return "small";
    if (kg < 85) return "medium";
    return "large";
  }
  function resolveBodySize(value, toBodySize) {
    if (value == null) return void 0;
    if (typeof value === "number") return toBodySize(value);
    return value === "small" || value === "medium" || value === "large" ? value : void 0;
  }
  function composePlayerSpriteSvg(config, type, crop, scale, opts = {}) {
    var _a, _b, _c, _d;
    const resolvedCrop = crop == null ? "full" : (_a = CROP_ALIASES[crop]) != null ? _a : crop;
    const merged = __spreadValues({}, config);
    if (type) merged.role = type;
    const height = resolveBodySize((_b = opts.height) != null ? _b : opts.heightCm, heightToBodySize);
    const weight = resolveBodySize((_c = opts.weight) != null ? _c : opts.weightKg, weightToBodySize);
    if (height) merged.height = height;
    if (weight) merged.weight = weight;
    const image = renderAvatar(merged, {
      crop: resolvedCrop,
      // renderAvatar already refuses a ball for staff, so this only has to honour
      // the caller and the crop.
      ball: !!opts.ball && resolvedCrop === "full",
      shadow: !!opts.shadow && resolvedCrop === "full",
      // No outline by default, matching composeKitSvg (kit-compositor/entry.ts).
      // avatar16.ts's own renderAvatar defaults this to true, but the admin
      // previews and the landing page both want the unoutlined look; the flag is
      // here so a caller can opt back in without a signature change.
      outline: (_d = opts.outline) != null ? _d : false
    });
    const px = scale * SCALE_RATIO;
    const paths = image.paths.map((p) => `<path d="${p.d}" fill="${p.color}"></path>`).join("");
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" width="${image.width * px}" height="${image.height * px}">${paths}</svg>`;
  }
  window.composePlayerSpriteSvg = composePlayerSpriteSvg;
  window.SKIN_SHADES = Object.fromEntries(SKINS.map((s) => [s.id, { c: s.c, d: s.d }]));
  window.AVATAR_BODY_SIZE = { heightToBodySize, weightToBodySize };
  window.DEFAULT_AVATAR_CONFIG = DEFAULT_AVATAR_CONFIG;
})();
