// Landing-page facility compositor — GENERATED, DO NOT EDIT BY HAND.
//
// Regenerate:  bash scripts/build-facility-compositor.sh
//
// Source:      assets/Components/facilitiesV2/facilities/{isoEngine,plotSite,
//              stadiumSprite,trainingGroundSprite,medicalCentreSprite,scoutingSprite}.ts
// Source sha:  0d5b65c38fefcd22
//
// No build timestamp on purpose: the output is then byte-identical when
// the source has not changed, so a rebuild produces an empty diff and
// any diff at all means the sprite really moved.
//
// Everything below is those files passed through esbuild (type-stripping
// only, no other transformation) plus scripts/facility-compositor/entry.ts,
// which adapts them to the window API the landing page calls. The build
// verifies the drop against its pixel-hash parity fixture first, so a
// drifted port fails rather than shipping.
//
// All four sites share ONE copy of isoEngine here — that is why this
// bundle exists rather than four per-site ones.
//
// Exposes: window.composeStadiumSvg(config, opts, scale)
//          window.composeTrainingGroundSvg(config, opts, scale)
//          window.composeMedicalCentreSvg(config, opts, scale)
//          window.composeScoutingSvg(config, opts, scale)
//          window.DEFAULT_{STADIUM,TRAINING_GROUND,MEDICAL_CENTRE,SCOUTING}_CONFIG
//          window.FACILITY_SITES  (ids, labels and game slugs per site)
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

  // isoEngine.ts (from the shared assets, see header)
  var BACKDROP = { day: "#ebe7de", night: "#1f2438" };
  var APP_BACKDROP = { day: "#2d3568", night: BACKDROP.night };
  var DIM = { owned: 0.64, unowned: 0.44 };
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
  function hash(x, y) {
    let h = Math.imul(x, 374761393) + Math.imul(y, 668265263) | 0;
    h = Math.imul(h ^ h >>> 13, 1274126177);
    return (h ^ h >>> 16) >>> 0;
  }
  var cellKey = (x, y) => y * 4096 + x;
  function makeWorld(W, H) {
    const cells = Array.from({ length: W * H }, () => []);
    const occ = new Uint8Array(W * H);
    const gt = new Array(W * H).fill(null);
    const inb = (x, y) => x >= 0 && y >= 0 && x < W && y < H;
    const w = {
      W,
      H,
      cells,
      occ,
      gt,
      owner: null,
      add: (x, y, z0, z1, top, side, flag) => {
        if (!inb(x, y) || !(z1 > z0)) return;
        cells[y * W + x].push([z0, z1, top, side || top, flag != null ? flag : null, w.owner]);
        occ[y * W + x] = 1;
      },
      ground: (x, y, c, flag) => {
        if (!inb(x, y)) return;
        gt[y * W + x] = [c, flag != null ? flag : null, w.owner];
        occ[y * W + x] = 1;
      },
      free: (x0, y0, x1, y1) => {
        for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) if (!inb(x, y) || occ[y * W + x]) return false;
        return true;
      }
    };
    return w;
  }
  function finalize(w, base) {
    const top = base === "grass" ? "#7aa24a" : "#c9c4b8", earth = "#7d5f3f";
    for (let i = 0; i < w.cells.length; i++) {
      const g = w.gt[i];
      w.cells[i].push([-3, 0, g ? g[0] : top, earth, g ? g[1] : null, g ? g[2] : null]);
      w.cells[i].sort((a, b) => a[0] - b[0]);
    }
    return w;
  }
  function trimWorld(w) {
    let x0 = w.W, y0 = w.H, x1 = -1, y1 = -1;
    for (let y = 0; y < w.H; y++) for (let x = 0; x < w.W; x++) if (w.occ[y * w.W + x]) {
      x0 = Math.min(x0, x);
      x1 = Math.max(x1, x);
      y0 = Math.min(y0, y);
      y1 = Math.max(y1, y);
    }
    if (x1 < 0) return w;
    const n = makeWorld(x1 - x0 + 3, y1 - y0 + 3);
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) {
      const i = y * w.W + x, j = (y - y0 + 1) * n.W + (x - x0 + 1);
      n.cells[j] = w.cells[i];
      n.gt[j] = w.gt[i];
      n.occ[j] = w.occ[i];
    }
    return n;
  }
  function rectMask(x, y, bw, bd, cf = 0, cb = 0) {
    const m = /* @__PURE__ */ new Set();
    for (let dy = 0; dy < bd; dy++) for (let dx = 0; dx < bw; dx++) {
      const ex = Math.min(dx, bw - 1 - dx);
      if (ex + (bd - 1 - dy) < cf || ex + dy < cb) continue;
      m.add(cellKey(x + dx, y + dy));
    }
    return m;
  }
  function discMask(cx, cy, r) {
    const m = /* @__PURE__ */ new Set();
    for (let y = Math.floor(cy - r - 1); y <= Math.ceil(cy + r); y++) for (let x = Math.floor(cx - r - 1); x <= Math.ceil(cx + r); x++)
      if (x >= 0 && y >= 0 && Math.hypot(x + 0.5 - cx, y + 0.5 - cy) <= r) m.add(cellKey(x, y));
    return m;
  }
  function volume(w, m, z0, floors, o) {
    var _a, _b;
    const wall = o.wall || "#ece8df", glass = o.glass || "#a9d6e6", gf = o.flag || "win", roof = o.roof || "#a8a49b", trim = o.trim || wall;
    const fh = o.fh || 3, h = z0 + floors * fh, lo = (_a = o.lo) != null ? _a : 0.8, hi = (_b = o.hi) != null ? _b : 0.6;
    for (const k of m) {
      const x = k % 4096, y = k / 4096 | 0;
      const l = m.has(cellKey(x - 1, y)), r = m.has(cellKey(x + 1, y)), u = m.has(cellKey(x, y - 1)), d = m.has(cellKey(x, y + 1));
      if (l && r && u && d) {
        w.add(x, y, z0, h, y % 3 === 0 ? shade(roof, 0.93) : roof, wall);
        continue;
      }
      const corner2 = (!l || !r) && (!u || !d), win = !corner2 && (o.ribbon || (x + y) % 2 === 1);
      for (let f = 0; f < floors; f++) {
        const zb = z0 + f * fh;
        if (f === 0 && o.door && o.door[0] === x && o.door[1] === y) {
          w.add(x, y, zb, zb + 2.2, "#3b3831", "#3b3831");
          w.add(x, y, zb + 2.2, zb + fh, wall, wall);
        } else if (win) {
          w.add(x, y, zb, zb + lo, wall, wall);
          w.add(x, y, zb + lo, zb + fh - hi, glass, glass, gf);
          w.add(x, y, zb + fh - hi, zb + fh, wall, wall);
        } else w.add(x, y, zb, zb + fh, wall, wall);
      }
      if (o.parapet !== false) w.add(x, y, h, h + 0.5, trim, trim);
    }
    return h;
  }
  function massing(w, B, L, o = {}) {
    const t = (L - 1) / 9, fh = o.fh || 3, F = (o.floors || [1, 1, 1, 2, 2, 2, 3, 3, 3, 4])[L - 1];
    const TW = Math.max(3, Math.min(B.w, Math.round(B.w * (0.46 + 0.54 * t)))), TD = Math.max(3, Math.min(B.d, Math.round(B.d * (0.5 + 0.5 * t))));
    const ew = L >= 3 && TW >= 9 ? Math.max(3, Math.round(TW * (L >= 6 ? 0.24 : 0.3))) : 0;
    const ww = L >= 6 && TW - ew >= 9 ? Math.max(3, Math.round(TW * 0.22)) : 0;
    const mw = TW - ew - ww, x0 = B.x + (o.ax === "l" ? 0 : o.ax === "r" ? B.w - TW : B.w - TW >> 1), y0 = o.ay === "b" ? B.y + B.d - TD : B.y;
    const mx = x0 + ww, cf = L >= 5 && mw >= 6 ? L >= 8 ? 2 : 1 : 0, cb = L >= 8 && mw >= 6 ? 1 : 0;
    const mm = rectMask(mx, y0, mw, TD, cf, cb), door = [mx + (mw >> 1), y0 + TD - 1];
    const ed = Math.max(3, Math.round(TD * 0.62)), wd = Math.max(3, Math.round(TD * 0.55));
    const em = ew ? rectMask(mx + mw, y0, ew, ed, L >= 5 ? 1 : 0, L >= 8 ? 1 : 0) : /* @__PURE__ */ new Set();
    const wm = ww ? rectMask(x0, y0 + TD - wd, ww, wd, L >= 7 ? 1 : 0, 1) : /* @__PURE__ */ new Set();
    let dm = /* @__PURE__ */ new Set(), dr = 0, tm = /* @__PURE__ */ new Set(), tw = 0;
    if (L >= 7 && mw >= 7 && TD >= 6) {
      dr = L >= 9 ? 2.6 : 2.1;
      dm = discMask(mx + mw - (ew ? 0 : dr - 0.5), y0 + TD - dr + 0.5, dr);
    }
    if (L >= 10 && mw >= 8 && TD >= 6) {
      tw = mw >= 12 ? 4 : 3;
      tm = rectMask(mx + 1, y0 + 1, tw, tw, 1, 1);
    }
    for (const k of dm) {
      mm.delete(k);
      em.delete(k);
      wm.delete(k);
    }
    for (const k of tm) mm.delete(k);
    const base = { wall: o.wall, roof: o.roof, trim: o.trim, glass: o.glass, flag: o.flag, ribbon: o.ribbon, fh, lo: o.lo, hi: o.hi };
    const h = volume(w, mm, 0, F, __spreadProps(__spreadValues({}, base), { door: o.door === false ? null : door }));
    const eh = em.size ? volume(w, em, 0, Math.max(1, F - 1), base) : 0;
    const wh = wm.size ? volume(w, wm, 0, Math.max(1, F - 1), base) : 0;
    if (dm.size) volume(w, dm, 0, F + (L >= 9 ? 1 : 0), __spreadProps(__spreadValues({}, base), { ribbon: true, lo: 0.4, hi: 0.4, roof: o.trim || o.roof }));
    if (tm.size) volume(w, tm, 0, F + 2, __spreadProps(__spreadValues({}, base), { ribbon: true }));
    const rx0 = mx + 1 + (tw ? tw + 1 : 0), rx1 = mx + mw - 2 - (dr ? Math.ceil(dr) : 0), ry0 = y0 + 1, ry1 = y0 + TD - 2;
    let roofZ = h, roof = { x: rx0, y: ry0, w: rx1 - rx0 + 1, d: ry1 - ry0 + 1 };
    if (L >= 8 && roof.w >= 5 && roof.d >= 4) {
      roofZ = volume(w, rectMask(rx0 + 1, ry0 + 1, roof.w - 2, roof.d - 2, 1, 0), h, 1, __spreadProps(__spreadValues({}, base), { ribbon: true }));
      roof = { x: rx0 + 2, y: ry0 + 2, w: roof.w - 4, d: roof.d - 4 };
    }
    if (L >= 5 && o.door !== false && o.canopy !== false) {
      const [dx, dy] = door, tc = o.trim || "#ece8df";
      for (let k = -1; k <= 1; k++) w.add(dx + k, dy + 1, 2.4, 2.8, tc, tc);
      w.add(dx - 1, dy + 1, 0, 2.4, "#c9ccd0");
      w.add(dx + 1, dy + 1, 0, 2.4, "#c9ccd0");
    }
    return {
      F,
      fh,
      h,
      eh,
      wh,
      roofZ,
      roof,
      door,
      front: y0 + TD,
      main: { x: mx, y: y0, w: mw, d: TD },
      east: ew ? { x: mx + mw, y: y0, w: ew, d: ed } : null,
      west: ww ? { x: x0, y: y0 + TD - wd, w: ww, d: wd } : null
    };
  }
  function roofGarden(w, R, z) {
    for (let dy = 0; dy < R.d; dy++) for (let dx = 0; dx < R.w; dx++) {
      const bed = !dx || !dy || dx === R.w - 1 || dy === R.d - 1;
      w.add(R.x + dx, R.y + dy, z, z + (bed ? 0.8 : 0.3), bed ? "#58a24b" : "#c9a063", bed ? "#7d5f3f" : "#a88350");
    }
  }
  function solar(w, R, z) {
    for (let dy = 0; dy < R.d; dy += 2) for (let dx = 0; dx < R.w; dx++) w.add(R.x + dx, R.y + dy, z, z + 0.6, dx % 3 ? "#2b3a5c" : "#3a4d78", "#9aa0a6");
  }
  var TREE_KINDS = ["round", "oak", "poplar", "conifer", "bush"];
  var TREE_PAL = {
    round: ["#7fbf5a", "#3f8a3a", "#a7d77e", "#2f6e30"],
    oak: ["#78b552", "#3a8136", "#a2d27a", "#2c672d"],
    poplar: ["#7cb85a", "#428c3c", "#a3d27e", "#2f6b31"],
    conifer: ["#5a9a48", "#2d6a33", "#7dbb62", "#22542a"],
    bush: ["#8cc65e", "#4a9140", "#b0dc86", "#367535"]
  };
  function tree(w, x, y, kind) {
    const r = hash(x, y), k = kind || TREE_KINDS[r % TREE_KINDS.length], j = (r >>> 4) % 3;
    const [top, side, lite, dark] = TREE_PAL[k];
    const leaf = (dx, dy, z0, z1, rim) => {
      const X = x + dx, Y = y + dy, hv = hash(X * 3 + 1, Y * 5 + 2), sd = hv % 5 === 0 ? dark : side, tp = rim || hv % 7 === 0 ? lite : top;
      if (z1 - z0 > 2.4) {
        const m = Math.round((z0 + (z1 - z0) * (0.35 + hv % 3 * 0.15)) * 2) / 2;
        w.add(X, Y, z0, m, sd, hv % 4 === 0 ? dark : side);
        w.add(X, Y, m, z1, tp, sd);
      } else w.add(X, Y, z0, z1, tp, sd);
    };
    const disc = (R, fn) => {
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
        const d = Math.hypot(dx, dy);
        if (d <= R + 0.01) fn(dx, dy, d);
      }
    };
    if (k === "bush") {
      disc(1.5, (dx, dy, d) => {
        const hv = hash(x + dx, y + dy);
        if (d > 1.2 && hv % 2) return;
        leaf(dx, dy, 0, 1.6 + hv % 3 * 0.25 - d * 0.3);
      });
      return;
    }
    const trunkH = { round: 2.6, oak: 2.6, poplar: 1.8, conifer: 1.4 }[k] + j * 0.25;
    w.add(x, y, 0, trunkH, "#6b4a2b", "#5a3d23");
    if (k === "round") disc(2.1, (dx, dy, d) => leaf(dx, dy, trunkH - 0.4 + d * 0.3, 6 + j * 0.4 - d * d * 0.35));
    else if (k === "oak") {
      const lobes = [[-0.5, -1, 6.6 + j * 0.3], [1, 0.5, 5.6], [-1, 1, 5.2]];
      disc(2.3, (dx, dy) => {
        const z1 = Math.max(...lobes.map(([lx, ly, lh]) => lh - Math.hypot(dx - lx, dy - ly) * 1.2));
        if (z1 > trunkH + 0.8) leaf(dx, dy, trunkH - 0.2, Math.round(z1 * 2) / 2);
      });
    } else if (k === "poplar") disc(1.5, (dx, dy, d) => leaf(dx, dy, trunkH + d * 0.6, 8.5 + j * 0.5 - d * d * 1.1, d > 1.2));
    else {
      const tiers = [[2, 1.6], [1.5, 1.6], [1, 1.6], [0.5, 1.4], [0, 1.2]];
      let z = trunkH - 0.2;
      tiers.forEach(([R, th], ti) => {
        const nR = tiers[ti + 1] ? tiers[ti + 1][0] : -1;
        disc(R, (dx, dy, d) => leaf(dx, dy, z, z + th, d > nR + 0.01));
        z += th;
      });
    }
  }
  function scatterTrees(w) {
    for (let y = 2, row = 0; y < w.H - 2; y += 5, row++) for (let x = 2 + row % 2 * 2; x < w.W - 2; x += 5) {
      const r = hash(x * 7, y * 11), jx = x + r % 3 - 1, jy = y + (r >>> 2) % 3 - 1;
      if (w.free(jx - 2, jy - 2, jx + 2, jy + 2)) tree(w, jx, jy);
      else if (w.free(x - 2, y - 2, x + 2, y + 2)) tree(w, x, y);
    }
  }
  function plotConstruction(w, x0, y0, pw, pd) {
    const P = "#9aa0a6", Wd = "#c9a063", Cn = "#b8b3a8", Y = "#f2c230", Yd = "#b88a12", Dk = "#3b3831", glass = "#a9d6e6";
    const solid = /* @__PURE__ */ new Set();
    const at2 = (dx, dy, z0, z1, top2, sd, flag) => {
      if (dx < 0 || dy < 0 || dx >= pw || dy >= pd) return;
      w.add(x0 + dx, y0 + dy, z0, z1, top2, sd, flag);
      if (z0 < 1) solid.add(dx + "," + dy);
    };
    for (let dy = 0; dy < pd; dy++) for (let dx = 0; dx < pw; dx++) w.ground(x0 + dx, y0 + dy, (dx * 73 + dy * 151) % 7 === 0 ? "#8a6c48" : "#9c7d56", null);
    const gate = Math.floor(pw / 2);
    for (let dy = 0; dy < pd; dy++) for (let dx = 0; dx < pw; dx++) {
      if (dx > 0 && dy > 0 && dx < pw - 1 && dy < pd - 1) continue;
      if (dy === pd - 1 && Math.abs(dx - gate) <= 1) continue;
      const k = dy === 0 || dy === pd - 1 ? dx : dy;
      at2(dx, dy, 0, 1.5, Math.floor(k / 2) % 2 ? "#f07a1a" : "#f4f3ee");
    }
    const cw = Math.max(3, Math.round(pw * 0.4)), cd = Math.max(2, Math.min(pd - 5, Math.round(pd * 0.55))), ex = 3 + cw, ey = 3 + cd;
    for (let dx = 2; dx <= ex; dx++) for (let dy = 2; dy <= ey; dy++) {
      if (dx > 2 && dy > 2 && dx < ex && dy < ey) {
        at2(dx, dy, 0, dx % 3 === 0 && dy % 3 === 0 ? 7 : 4, Cn, "#8f8a80");
        continue;
      }
      const k = dx === 2 || dx === ex ? dy : dx;
      if (k % 3 === 2) at2(dx, dy, 0, 8.5, P, P);
      else for (const z of [2.5, 5, 8]) at2(dx, dy, z, z + 0.5, Wd, Wd);
    }
    const fx = ex + 2, mx = pw - 3, top = Math.min(22, 12 + Math.round(Math.max(pw, pd) * 0.25));
    if (mx >= fx) {
      for (let z = 0; z < top; z += 2) at2(mx, 2, z, z + 2, Y, z / 2 % 2 ? Yd : Y);
      at2(mx, 3, top - 2, top, Y, glass);
      const jl = Math.min(mx - 1, 12), hk = Math.max(mx - jl, fx - 1);
      for (let dx = mx - jl; dx <= Math.min(pw - 1, mx + 2); dx++) at2(dx, 2, top, top + 1, Y, Y);
      at2(Math.min(pw - 1, mx + 2), 2, top - 1.5, top, Cn, Cn);
      if (hk < mx) {
        at2(hk, 2, top - 8, top, Dk, Dk);
        at2(hk, 2, top - 9.5, top - 8, Wd, Wd);
      }
    }
    if (fx + 4 <= pw - 2) {
      const r0 = pd - 4;
      for (let dx = 0; dx < 3; dx++) for (let r = 0; r < 2; r++) {
        at2(fx + dx, r0 + r, 0, 0.5, Dk, Dk);
        at2(fx + dx, r0 + r, 0.5, 2, Y, Y);
      }
      at2(fx, r0 + 1, 2, 3.5, Y, glass);
      at2(fx + 3, r0, 1.5, 2.5, Y, Y);
      at2(fx + 4, r0, 0.5, 1.5, Dk, Dk);
    }
    if (fx + 2 < mx) for (let dx = 0; dx < 3; dx++) for (let r = 2; r <= 3; r++) at2(fx + dx, r, 0, 1.5, "#8f8a80", "#f07a1a");
    const piles = [[Math.round((fx + pw) / 2), Math.round(pd / 2), 2.2], [Math.max(fx, pw - 4), pd - 2, 1.8], [Math.round(cw / 2) + 3, pd - 2, 1.5]];
    const rub = ["#8f8a80", "#a3927a", "#77705f"];
    for (let dy = 1; dy < pd - 1; dy++) for (let dx = 1; dx < pw - 1; dx++) {
      if (solid.has(dx + "," + dy) || dx >= 2 && dx <= ex && dy >= 2 && dy <= ey) continue;
      let h = 0;
      for (const [pi, pr, phh] of piles) h = Math.max(h, phh - Math.hypot(dx - pi, (dy - pr) * 1.3) * 0.7);
      h = Math.round(h * 2) / 2;
      if (h > 0) at2(dx, dy, 0, h, rub[(dx * 31 + dy * 17) % 3], "#6f675c");
    }
  }
  function field(w, x0, y0, fw, fh, pattern, gh, gz) {
    const mx = Math.floor(fw / 2), my = Math.floor(fh / 2), r = Math.max(2, Math.round(fh / 6));
    const pb = Math.round(fw * 0.16), ph = Math.floor(fh * 0.29), sb = Math.max(1, Math.round(fw * 0.055)), sh = Math.max(1, Math.round(fh * 0.12)), ps = Math.round(fw * 0.11);
    for (let py = 0; py < fh; py++) for (let px = 0; px < fw; px++) {
      const q = px < mx ? px : fw - 1 - px, d = Math.abs(py - my);
      const line = px === 0 || py === 0 || px === fw - 1 || py === fh - 1 || px === mx || Math.abs(Math.hypot(px - mx, py - my) - r) < 0.5 || q === pb && d <= ph || d === ph && q <= pb || q === sb && d <= sh || d === sh && q <= sb || q === ps && d === 0;
      const a = Math.floor(px / 3), b = Math.floor(py / 3), alt = pattern === "stripes" ? a % 2 : pattern === "checks" ? (a + b) % 2 : 0;
      w.ground(x0 + px, y0 + py, line ? "#eef0e8" : alt ? "#529538" : "#5da53e", "pitch");
    }
    for (const gx of [x0 - 1, x0 + fw]) for (let d = -gh; d <= gh; d++) {
      if (Math.abs(d) === gh) w.add(gx, y0 + my + d, 0, gz, "#f4f3ee", "#f4f3ee");
      w.add(gx, y0 + my + d, gz, gz + 0.5, "#f4f3ee", "#f4f3ee");
    }
  }
  function nets(w, x, y0, y1) {
    for (let y = y0; y <= y1; y++) {
      const c = (y - y0) % 4 ? "#3c5a44" : "#7d8288";
      w.add(x, y, 0, 5, c, c);
    }
  }
  function bleacher(w, x0, x1, y, a) {
    for (let x = x0; x <= x1; x++) {
      w.add(x, y, 0, 5, "#e9e6df", "#e9e6df");
      w.add(x, y + 1, 0, 2, a, "#8f8a80");
      w.add(x, y + 2, 0, 1, shade(a, 0.84), "#8f8a80");
      for (let r = 0; r < 3; r++) w.add(x, y + r, 5, 5.5, "#f4f3ee", "#f4f3ee");
    }
  }
  function mast(w, x, y, dx, dy, h) {
    w.add(x, y, 0, h, "#a3a8ae", "#a3a8ae");
    for (const [a, b] of [[0, 0], [dx, 0], [0, dy], [dx, dy]]) w.add(x + a, y + b, h, h + 2, "#d9dde2", "#fff3b0", "glow");
  }
  function dish(w, x, y, z) {
    w.add(x + 1, y + 1, z, z + 1.5, "#9aa0a6", "#9aa0a6");
    for (let dy = 0; dy < 3; dy++) for (let dx = 0; dx < 3; dx++) {
      const t = z + 1.5 + (2 - dy) * 0.5;
      w.add(x + dx, y + dy, t, t + 0.5, "#eceae4", "#c9c6bd");
    }
  }
  function car(w, x, y, col) {
    for (let dx = 0; dx < 2; dx++) for (let dy = 0; dy < 3; dy++) {
      w.add(x + dx, y + dy, 0, 1, col, col);
      if (dy === 1) w.add(x + dx, y + dy, 1, 1.5, shade(col, 1.08), "#2b3440");
    }
  }
  function ambulance(w, x, y) {
    for (let dx = 0; dx < 4; dx++) for (let dy = 0; dy < 2; dy++) {
      const X = x + dx, Y = y + dy, bat = (dx + dy) % 2 ? "#d7e43a" : "#1f5fb8";
      w.add(X, Y, 0, 0.4, "#2b2b2b", "#2b2b2b");
      w.add(X, Y, 0.4, 1.4, "#f4f3ee", bat);
      if (dx === 0) w.add(X, Y, 1.4, 1.9, "#f4f3ee", "#2b3440");
      else w.add(X, Y, 1.4, 2.6, dx === 1 ? "#4aa3ff" : "#f4f3ee", "#f4f3ee", dx === 1 ? "glow" : null);
    }
  }
  function trailer(w, x, y, a) {
    for (let dx = 0; dx < 6; dx++) for (let dy = 0; dy < 2; dy++) {
      const X = x + dx, Y = y + dy;
      w.add(X, Y, 0, 0.4, "#2b2b2b", "#2b2b2b");
      w.add(X, Y, 0.4, 1.4, "#f4f3ee", "#f4f3ee");
      w.add(X, Y, 1.4, 1.8, "#f4f3ee", a);
      w.add(X, Y, 1.8, 2.8, "#f4f3ee", "#f4f3ee");
    }
  }
  function parasol(w, x, y, a) {
    w.add(x, y, 0, 0.8, "#f4f3ee", "#c9c6bd");
    w.add(x, y, 0.8, 2.2, "#9aa0a6", "#9aa0a6");
    for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) w.add(x + dx, y + dy, 2.2, 2.5, (dx + dy) % 2 ? "#f4f3ee" : a, a);
  }
  function cage(w, x, y, fw, fh, pattern, z, lamps2) {
    const my = Math.floor(fh / 2), F = "#3c5a44", P = "#7d8288";
    const put = (X, Y, z0, z1, t, s, f) => z ? w.add(X, Y, z + z0, z + z1, t, s, f) : z0 === 0 && z1 === 0 ? w.ground(X, Y, t, f) : w.add(X, Y, z0, z1, t, s, f);
    for (let py = 0; py < fh; py++) for (let px = 0; px < fw; px++) {
      const line = px === 0 || py === 0 || px === fw - 1 || py === fh - 1 || px === Math.floor(fw / 2);
      const alt = pattern === "stripes" ? Math.floor(px / 2) % 2 : pattern === "checks" ? (Math.floor(px / 2) + Math.floor(py / 2)) % 2 : 0;
      const col = line ? "#eef0e8" : alt ? "#529538" : "#5da53e";
      if (z) w.add(x + px, y + py, z, z + 0.3, col, "#4a8531", "pitch");
      else w.ground(x + px, y + py, col, "pitch");
    }
    for (let py = -1; py <= fh; py++) for (let px = -1; px <= fw; px++) {
      if (px >= 0 && py >= 0 && px < fw && py < fh) continue;
      const corner2 = (px < 0 || px === fw) && (py < 0 || py === fh), goal = (px < 0 || px === fw) && Math.abs(py - my) <= 1;
      if (goal) {
        if (Math.abs(py - my) === 1) put(x + px, y + py, 0.3, 1.5, "#f4f3ee", "#f4f3ee");
        put(x + px, y + py, 1.5, 1.9, "#f4f3ee", "#f4f3ee");
        continue;
      }
      put(x + px, y + py, z ? 0.3 : 0, corner2 ? 3 : 1.6, corner2 ? P : F, corner2 ? P : F);
      if (lamps2 && corner2) {
        put(x + px, y + py, 3, 7, P, P);
        put(x + px, y + py, 7, 8, "#d9dde2", "#fff3b0", "glow");
      }
    }
  }
  var SEAM = 0.04;
  function paintPixels(w, rot, zmax, night, lit, crop, opts = {}) {
    var _a, _b, _c;
    const sel = (_a = opts.selected) != null ? _a : null, selRect = (_b = opts.selectedRect) != null ? _b : null;
    const W = w.W, H = w.H, Wr = rot % 2 ? H : W, Hr = rot % 2 ? W : H;
    const ox = (Hr - 1) * 2, oy = zmax * 2 + 2;
    const cw = (Wr + Hr - 2) * 2 + 4, chh = oy + (Wr + Hr - 2) + 10;
    const px = new Array(cw * chh).fill(null);
    const fill = (x, y, rw, rh, c) => {
      for (let yy = Math.max(0, y); yy < Math.min(chh, y + rh); yy++)
        for (let xx = Math.max(0, x); xx < Math.min(cw, x + rw); xx++) px[yy * cw + xx] = c;
    };
    const bg = (_c = opts.backdrop) != null ? _c : night ? BACKDROP.night : BACKDROP.day;
    let dk = 0;
    const col = (c, f) => {
      const v = !night || f === "glow" || f === "win" ? c : mix(c, "#141a33", f === "pitch" && lit ? 0.25 : 0.62);
      return dk ? mix(v, bg, dk) : v;
    };
    const toI = (u, v) => rot === 0 ? v * W + u : rot === 1 ? (H - 1 - u) * W + v : rot === 2 ? (H - 1 - v) * W + (W - 1 - u) : u * W + (W - 1 - v);
    const R = new Float32Array(Wr * Hr);
    for (let u = 0; u < Wr; u++) for (let v = 0; v < Hr; v++) {
      let m = -9;
      for (const sg of w.cells[toI(u, v)]) if (sg[1] > m) m = sg[1];
      R[u * Hr + v] = m;
    }
    const hr = (u, v) => u < 0 || v < 0 || u >= Wr || v >= Hr ? -9 : R[u * Hr + v];
    const shadowed = (u, v, z) => hr(u - 1, v + 1) >= z + 1 || hr(u - 1, v) >= z + 1.5 || hr(u, v + 1) >= z + 1.5 || hr(u - 2, v + 2) >= z + 3 || hr(u - 2, v + 1) >= z + 2.5 || hr(u - 1, v + 2) >= z + 2.5 || hr(u - 3, v + 3) >= z + 4.5;
    for (let s = 0; s <= Wr + Hr - 2; s++) for (let u = Math.max(0, s - Hr + 1); u <= Math.min(s, Wr - 1); u++) {
      const v = s - u, i = toI(u, v), segs = w.cells[i];
      if (!segs.length) continue;
      const x = i % W, y = i / W | 0, sx = (u - v) * 2 + ox, sy = u + v + oy, peak = R[u * Hr + v], backL = hr(u - 1, v), backR = hr(u, v - 1);
      const inSel = !!selRect && x >= selRect.x && y >= selRect.y && x < selRect.x + selRect.w && y < selRect.y + selRect.d;
      for (const [z0, z1, top0, side0, flag, own] of segs) {
        dk = !sel || inSel || own === sel ? 0 : own ? DIM.owned : DIM.unowned;
        let top = top0, side = side0, f = flag;
        if (night && f === "win") {
          if ((x * 3 + y * 7 + Math.round(z0)) % 5) top = side = "#ffd98a";
          else f = null;
        }
        const yt = Math.round(sy - z1 * 2), h = Math.round((z1 - z0) * 2), g = f === "glow" || f === "win";
        if (h > 0) {
          fill(sx, yt + 2, 2, h, col(g ? side : shade(side, 0.84), f));
          fill(sx + 2, yt + 2, 2, h, col(shade(side, g ? 0.9 : 0.68), f));
          if (f === "win" && h >= 2) {
            const mc = col(shade(side, 0.7), f);
            if (u % 2 === 0) fill(sx, yt + 2, 1, h, mc);
            if (v % 2 === 0) fill(sx + 3, yt + 2, 1, h, mc);
            if (!night) fill(sx, yt + 2, 3, 1, col(mix(side, "#ffffff", 0.45), "win"));
          }
          if (z0 <= 0 && z1 > 0 && h >= 3 && !g) {
            fill(sx, yt + 1 + h, 2, 1, col(shade(side, 0.7), f));
            fill(sx + 2, yt + 1 + h, 2, 1, col(shade(side, 0.55), f));
          }
        }
        const tc = !night && !g && shadowed(u, v, z1) ? shade(top, 0.8) : top;
        fill(sx, yt, 4, 2, col(tc, f));
        if (z1 > 0.3 && z1 >= peak - 0.01 && !g) {
          const rc = col(mix(tc, "#ffffff", 0.28), f);
          if (backL < z1 - 0.3) fill(sx, yt, 2, 1, rc);
          if (backR < z1 - 0.3) fill(sx + 2, yt, 2, 1, rc);
        }
      }
    }
    if (!crop) return { px, stride: cw, x0: 0, y0: 0, width: cw, height: chh };
    let x0 = cw, y0 = chh, x1 = -1, y1 = -1;
    for (let y = 0; y < chh; y++) for (let x = 0; x < cw; x++) if (px[y * cw + x]) {
      if (x < x0) x0 = x;
      if (x > x1) x1 = x;
      if (y < y0) y0 = y;
      if (y > y1) y1 = y;
    }
    if (x1 < 0) return { px, stride: cw, x0: 0, y0: 0, width: 0, height: 0 };
    return { px, stride: cw, x0, y0, width: x1 - x0 + 1, height: y1 - y0 + 1 };
  }
  function toImage(b) {
    if (!b.width) return { width: 0, height: 0, paths: [] };
    const byColor = /* @__PURE__ */ new Map();
    for (let y = b.y0; y < b.y0 + b.height; y++) {
      let x = b.x0;
      while (x < b.x0 + b.width) {
        const c = b.px[y * b.stride + x];
        if (!c) {
          x++;
          continue;
        }
        let e = x + 1;
        while (e < b.x0 + b.width && b.px[y * b.stride + e] === c) e++;
        const rw = e - x + SEAM;
        let list = byColor.get(c);
        if (!list) {
          list = [];
          byColor.set(c, list);
        }
        list.push(`M${x - b.x0} ${y - b.y0}h${rw}v${1 + SEAM}h${-rw}z`);
        x = e;
      }
    }
    return { width: b.width, height: b.height, paths: Array.from(byColor, ([color, parts]) => ({ color, d: parts.join("") })) };
  }
  function paint(w, rot, zmax, night, lit, crop, opts = {}) {
    return toImage(paintPixels(w, rot, zmax, night, lit, crop, opts));
  }

  // stadiumSurrounds.ts (from the shared assets, see header)
  var SURROUNDS = ["parkland", "suburban", "terraces", "city", "industrial"].map((id) => ({ id, labelKey: `facilities.stadium.surrounds.${id}` }));
  var SURROUND_TREE_DENSITY = { parkland: 1, suburban: 1, terraces: 0.35, city: 0.5, industrial: 0.25 };
  var SURROUND_RING = { x0: 10, y0: 9, x1: 76, y1: 65 };
  var SURROUND_SPURS = {
    suburban: { top: [40], bottom: [44], left: [36], right: [30] },
    terraces: { top: [24, 52], bottom: [28, 56], left: [24, 46], right: [24, 46] },
    city: { top: [26, 52], bottom: [30, 56], left: [36], right: [36] },
    industrial: { top: [44], bottom: [40], left: [30], right: [40] }
  };
  var SURROUND_THUMB = { W: 26, H: 21 };
  var PAVE = "#d4cfc3";
  var CAR_COLS = ["#c8202f", "#f4f3ee", "#1f4fb8", "#1a1a1a", "#f2c230", "#1f8a4c", "#7fb8e6"];
  var isBuiltUp = (v) => !!v && v !== "parkland" && v in SURROUND_SPURS;
  var at = (b, a, c) => b.ax === "x" ? [a, c] : [c, a];
  function clearGround(w, x, y) {
    if (x < 0 || y < 0 || x >= w.W || y >= w.H) return false;
    const i = y * w.W + x;
    return !w.gt[i] && !w.cells[i].some((s) => s[0] < 0.6);
  }
  function empty(w, x0, y0, x1, y1) {
    for (let y = y0; y <= y1; y++) for (let x = x0; x <= x1; x++) if (x < 0 || y < 0 || x >= w.W || y >= w.H || w.cells[y * w.W + x].length) return false;
    return true;
  }
  var asphalt = (x, y) => hash(x * 3 + 7, y * 5 + 1) % 6 ? "#5d6066" : "#575a60";
  function roads(w, v) {
    const R = SURROUND_RING, S2 = SURROUND_SPURS[v], lanes = /* @__PURE__ */ new Set();
    const lane = (x, y) => {
      if (clearGround(w, x, y)) {
        w.ground(x, y, asphalt(x, y), null);
        lanes.add(cellKey(x, y));
      }
    };
    const pave = (x, y) => {
      if (clearGround(w, x, y)) w.ground(x, y, PAVE, null);
    };
    const spurs = (fn) => {
      for (const a of S2.top) for (let y = 0; y < R.y0; y++) fn(a, y, "v");
      for (const a of S2.bottom) for (let y = R.y1 + 1; y < w.H; y++) fn(a, y, "v");
      for (const a of S2.left) for (let x = 0; x < R.x0; x++) fn(x, a, "h");
      for (const a of S2.right) for (let x = R.x1 + 1; x < w.W; x++) fn(x, a, "h");
    };
    spurs((x, y, d) => {
      lane(x, y);
      if (d === "v") lane(x + 1, y);
      else lane(x, y + 1);
    });
    for (let y = R.y0; y <= R.y1; y++) for (let x = R.x0; x <= R.x1; x++) if (x <= R.x0 + 1 || x >= R.x1 - 1 || y <= R.y0 + 1 || y >= R.y1 - 1) lane(x, y);
    spurs((x, y, d) => {
      if (d === "v") {
        pave(x - 1, y);
        pave(x + 2, y);
      } else {
        pave(x, y - 1);
        pave(x, y + 2);
      }
    });
    for (let y = R.y0 - 1; y <= R.y1 + 1; y++) for (let x = R.x0 - 1; x <= R.x1 + 1; x++) {
      const outer = x === R.x0 - 1 || x === R.x1 + 1 || y === R.y0 - 1 || y === R.y1 + 1;
      const inner = x >= R.x0 + 2 && x <= R.x1 - 2 && y >= R.y0 + 2 && y <= R.y1 - 2 && (x === R.x0 + 2 || x === R.x1 - 2 || y === R.y0 + 2 || y === R.y1 - 2);
      if (outer || inner) pave(x, y);
    }
    return lanes;
  }
  function car2(w, x, y, horiz, col) {
    for (const [cx, cy] of [[x, y], horiz ? [x + 1, y] : [x, y + 1]]) {
      w.add(cx, cy, 0, 0.9, col, col);
      w.add(cx, cy, 0.9, 1.5, shade(col, 1.08), "#2b3440");
    }
  }
  function cars(w, lanes) {
    const has = (x, y) => lanes.has(cellKey(x, y)), ok = (x, y) => has(x, y) && !w.cells[y * w.W + x].length;
    for (const k of [...lanes]) {
      const x = k % 4096, y = k / 4096 | 0, r = hash(x * 7 + 3, y * 13 + 5);
      if (r % 31) continue;
      const col = CAR_COLS[(r >>> 5) % 7];
      if (ok(x, y) && ok(x + 1, y) && has(x - 1, y) && has(x + 2, y) && !(has(x, y - 1) && has(x, y + 1))) car2(w, x, y, true, col);
      else if (ok(x, y) && ok(x, y + 1) && has(x, y - 1) && has(x, y + 2) && !(has(x - 1, y) && has(x + 1, y))) car2(w, x, y, false, col);
    }
  }
  function lamp(w, x, y) {
    w.add(x, y, 0, 4, "#5a5d63", "#5a5d63");
    w.add(x, y, 4, 4.6, "#f4f1e6", "#fff3b0", "glow");
  }
  function lamps(w) {
    const R = SURROUND_RING, pts = [];
    for (let x = R.x0 + 3; x <= R.x1 - 3; x += 8) pts.push([x, R.y0 - 1], [x, R.y1 + 1]);
    for (let y = R.y0 + 4; y <= R.y1 - 3; y += 8) pts.push([R.x0 - 1, y], [R.x1 + 1, y]);
    for (const [x, y] of pts) {
      const i = y * w.W + x, g = w.gt[i];
      if (g && g[0] === PAVE && !w.cells[i].length) lamp(w, x, y);
    }
  }
  function bands(w, deep) {
    const R = SURROUND_RING, W = w.W, H = w.H;
    return [
      { ax: "x", a0: 0, a1: W - 1, c0: deep ? 1 : 3, c1: R.y0 - 3, face: 1, back: 0, front: R.y0 - 2 },
      { ax: "x", a0: 0, a1: W - 1, c0: R.y1 + 3, c1: deep ? H - 2 : R.y1 + 6, face: -1, back: H - 1, front: R.y1 + 2 },
      { ax: "y", a0: R.y0 - 1, a1: R.y1 + 1, c0: deep ? 1 : 4, c1: R.x0 - 3, face: 1, back: 0, front: R.x0 - 2 },
      { ax: "y", a0: R.y0 - 1, a1: R.y1 + 1, c0: R.x1 + 3, c1: deep ? W - 2 : R.x1 + 6, face: -1, back: W - 1, front: R.x1 + 2 }
    ];
  }
  function lots(w, b, lenFn, gap, fn) {
    for (let p = b.a0; p <= b.a1; ) {
      const len = lenFn(p), e = p + len - 1, [x0, y0] = at(b, p, b.c0), [x1, y1] = at(b, e, b.c1);
      if (e <= b.a1 && w.free(x0, y0, x1, y1)) {
        fn({ x: x0, y: y0, w: x1 - x0 + 1, d: y1 - y0 + 1 }, p, len);
        p = e + 1 + gap;
      } else p++;
    }
  }
  function plot(w, b, a0, a1, colorFn) {
    const c0 = Math.min(b.back, b.front), c1 = Math.max(b.back, b.front);
    for (let a = a0; a <= a1; a++) for (let c = c0; c <= c1; c++) {
      const [x, y] = at(b, a, c);
      if (x < 0 || y < 0 || x >= w.W || y >= w.H || w.occ[y * w.W + x]) continue;
      w.ground(x, y, colorFn(x, y, c, a - a0), null);
    }
  }
  function pitched(w, R, ax, face, o) {
    const along = ax === "x" ? R.w : R.d, across = ax === "x" ? R.d : R.w, fh = o.hw / o.floors, mid = across - 1 >> 1, glass = o.glass || "#86a6b8";
    const xy = (a, b) => ax === "x" ? [R.x + a, R.y + b] : [R.x + b, R.y + a];
    let ridge = 0;
    for (let a = 0; a < along; a++) for (let b = 0; b < across; b++) {
      const [x, y] = xy(a, b), wall = typeof o.wall === "function" ? o.wall(a) : o.wall;
      const fb = face > 0 ? b === across - 1 : b === 0, end = a === 0 || a === along - 1, edge = end || b === 0 || b === across - 1;
      if (!edge) w.add(x, y, 0, o.hw, wall, wall);
      else for (let f = 0; f < o.floors; f++) {
        const zb = f * fh, door = f === 0 && fb && !end && !!o.doors && o.doors(a);
        const win = !door && (end ? f === o.floors - 1 && (b === mid || b === across - 1 - mid) && b > 0 && b < across - 1 : o.win ? o.win(a, f, fb) : a % 2 === 1);
        if (door) {
          w.add(x, y, zb, zb + fh * 0.8, "#4a3a2c", "#4a3a2c");
          w.add(x, y, zb + fh * 0.8, zb + fh, wall, wall);
        } else if (win) {
          w.add(x, y, zb, zb + 0.6, wall, wall);
          w.add(x, y, zb + 0.6, zb + fh - 0.4, glass, glass, "win");
          w.add(x, y, zb + fh - 0.4, zb + fh, wall, wall);
        } else w.add(x, y, zb, zb + fh, wall, wall);
      }
      const rz = o.hw + 0.5 + Math.min(b, across - 1 - b) * (o.pitch || 0.9);
      ridge = Math.max(ridge, rz);
      w.add(x, y, o.hw, rz, o.roof, end ? wall : shade(o.roof, 0.8));
    }
    for (const a of o.chim || []) {
      const [x, y] = xy(a, mid);
      w.add(x, y, ridge, ridge + 1.4, "#94503a", "#7d4130");
      w.add(x, y, ridge + 1.4, ridge + 1.7, "#5b4a42", "#5b4a42");
    }
  }
  function house(w, R, b, p, len, gap) {
    const h = hash(R.x * 5 + 1, R.y * 7 + 3), door = len >> 1;
    plot(w, b, p, p + len - 1 + gap, (x, y, c, a) => c === b.front && a === door ? PAVE : hash(x, y) % 5 ? "#86b356" : "#7aa64b");
    pitched(w, R, b.ax, b.face, {
      hw: 5,
      floors: 2,
      wall: ["#efe6d6", "#e6d3b3", "#f3efe7", "#d9c4a2", "#c98f6b"][h % 5],
      roof: ["#a9533c", "#8c4a3a", "#5d6670", "#9a6a44"][(h >>> 3) % 4],
      doors: (a) => a === door,
      win: (a, f) => a % 2 === 1 || f > 0 && a === door,
      chim: [(h >>> 6) % 2 ? 1 : len - 2]
    });
    for (let a = 0; a < len; a++) if (a !== door) {
      const [x, y] = at(b, p + a, b.front);
      if (empty(w, x, y, x, y)) w.add(x, y, 0, 0.9, "#5f9a46", "#3f7a33");
    }
    const e = b.face > 0 ? b.c0 : b.c1;
    if (Math.abs(e - b.back) >= 3 && (h >>> 9) % 3) {
      const [x, y] = at(b, p + 1 + h % Math.max(1, len - 2), Math.round((b.back + e - b.face) / 2));
      if (empty(w, x - 1, y - 1, x + 1, y + 1)) tree(w, x, y, "bush");
    }
  }
  function terrace(w, R, b, p, len, gap) {
    const h = hash(R.x * 3 + 2, R.y * 11 + 5), bricks = ["#a4563f", "#984a36", "#ae6249", "#8f4a3a"], e = b.face > 0 ? b.c0 : b.c1;
    plot(w, b, p, p + len - 1 + gap, (x, y, c, a) => a >= len ? "#bdb7aa" : c === b.front ? PAVE : "#b9ae9a");
    const chim = [];
    for (let a = 3; a < len - 1; a += 3) chim.push(a);
    pitched(w, R, b.ax, b.face, {
      hw: 5,
      floors: 2,
      wall: (a) => bricks[(Math.floor(a / 3) + h) % 4],
      roof: "#59606b",
      pitch: 0.8,
      chim,
      doors: (a) => a % 3 === 1,
      win: (a, f, fb) => a % 3 === 2 || a % 3 === 1 && (f > 0 || !fb)
    });
    for (let a = 3; a < len; a += 3) for (let c = b.back; c !== e; c += b.face) {
      const [x, y] = at(b, p + a, c);
      if (empty(w, x, y, x, y)) w.add(x, y, 0, 1, "#9a4c37", "#86412f");
    }
  }
  function cityBlock(w, R, b, p, len, gap) {
    const h = hash(R.x * 7 + 5, R.y * 3 + 9), floors = 2 + h % 4, ribbon = (h >>> 4) % 2 === 1, wall = ["#dcd8cf", "#c3c8ce", "#e9e3d6", "#b4bac2"][(h >>> 2) % 4];
    plot(w, b, p, p + len - 1 + gap, (x, y) => hash(x, y) % 7 ? "#cfcabe" : "#c4bfb3");
    const door = at(b, p + (len >> 1), b.face > 0 ? b.c1 : b.c0);
    const top = volume(w, rectMask(R.x, R.y, R.w, R.d), 0, floors, { wall, glass: ribbon ? "#8fb8cc" : "#a9d6e6", ribbon, roof: "#9b978f", trim: shade(wall, 0.86), door });
    const [ux, uy] = at(b, p + 1 + (h >>> 7) % Math.max(1, len - 3), b.c0 + b.c1 >> 1);
    for (let k = 0; k < 2; k++) {
      const [x, y] = b.ax === "x" ? [ux + k, uy] : [ux, uy + k];
      w.add(x, y, top + 0.5, top + 1.7, "#c9ccd0", "#a3a8ae");
    }
  }
  function works(w, R, b, p, len, gap) {
    const h = hash(R.x * 9 + 4, R.y * 5 + 7), across = b.ax === "x" ? R.d : R.w;
    const cell = (a, c) => b.ax === "x" ? [R.x + a, R.y + c] : [R.x + c, R.y + a];
    plot(w, b, p, p + len - 1 + gap, (x, y) => hash(x, y) % 6 ? "#a9a497" : "#a19c8f");
    if (len <= 4) {
      const cols = ["#c8502f", "#2f6db0", "#2f8a5a", "#d8a63a", "#8a8f96"];
      for (let c = 0; c < across; c += 2) {
        const n = 1 + hash(p + c, R.x + R.y) % 3;
        for (let k = 0; k < n; k++) {
          const col = cols[hash(p * 3 + k, c * 7 + 1) % 5];
          for (let a = 0; a < 3; a++) {
            const [x, y] = cell(a, c);
            w.add(x, y, k * 1.4, k * 1.4 + 1.3, col, a % 2 ? col : shade(col, 0.86));
          }
        }
      }
      return;
    }
    const pal = [["#aeb5bc", "#9ea6ae"], ["#7f93a8", "#72869b"], ["#c2b08a", "#b3a17c"]][h % 3];
    for (let a = 0; a < len; a++) for (let c = 0; c < across; c++) {
      const [x, y] = cell(a, c), fb = b.face > 0 ? c === across - 1 : c === 0, end = a === 0 || a === len - 1, edge = end || c === 0 || c === across - 1, col = pal[a % 2];
      if (fb && !end && a % 4 === 2) {
        w.add(x, y, 0, 2.8, "#6b7076", "#62676d");
        w.add(x, y, 2.8, 4, col, col);
      } else if (edge && !end && a % 2 === 0) {
        w.add(x, y, 0, 3, col, col);
        w.add(x, y, 3, 3.6, "#a9d6e6", "#a9d6e6", "win");
        w.add(x, y, 3.6, 4, col, col);
      } else w.add(x, y, 0, 4, "#8d949b", col);
      const hi = c % 2 === 1;
      w.add(x, y, 4, hi ? 5.4 : 4.5, "#8d949b", hi ? "#a9d6e6" : "#7b8288", hi ? "win" : null);
    }
    if (h % 4 === 0) {
      const [x, y] = cell(1, b.face > 0 ? 0 : across - 1);
      w.add(x, y, 5.4, 13, "#9a4c37", "#86412f");
      w.add(x, y, 13, 14, "#e8e2d6", "#e8e2d6");
      w.add(x, y, 14, 16, "#9a4c37", "#86412f");
    }
  }
  function surroundLots(w, v, bs) {
    for (const b of bs) {
      const H = (p) => hash(p * 31 + b.c0 * 3, b.face * 7 + 11);
      if (v === "suburban") lots(w, b, (p) => 5 + H(p) % 2, 1, (R, p, len) => house(w, R, b, p, len, 1));
      else if (v === "terraces") lots(w, b, (p) => [9, 12, 15][H(p) % 3], 2, (R, p, len) => terrace(w, R, b, p, len, 2));
      else if (v === "city") lots(w, b, (p) => 6 + H(p) % 4, 2, (R, p, len) => cityBlock(w, R, b, p, len, 2));
      else lots(w, b, (p) => H(p) % 3 === 0 ? 4 : 10 + H(p) % 3, 1, (R, p, len) => works(w, R, b, p, len, 1));
    }
  }
  function scatterTreesThinned(w, p) {
    for (let y = 2, row = 0; y < w.H - 2; y += 5, row++) for (let x = 2 + row % 2 * 2; x < w.W - 2; x += 5) {
      if (p < 1 && hash(x * 13 + 1, y * 17 + 3) % 100 >= p * 100) continue;
      const r = hash(x * 7, y * 11), jx = x + r % 3 - 1, jy = y + (r >>> 2) % 3 - 1;
      if (w.free(jx - 2, jy - 2, jx + 2, jy + 2)) tree(w, jx, jy);
      else if (w.free(x - 2, y - 2, x + 2, y + 2)) tree(w, x, y);
    }
  }
  function drawSurrounds(w, v) {
    if (isBuiltUp(v)) {
      const lanes = roads(w, v);
      surroundLots(w, v, bands(w, v === "city" || v === "industrial"));
      cars(w, lanes);
      lamps(w);
    }
    scatterTreesThinned(w, SURROUND_TREE_DENSITY[isBuiltUp(v) ? v : "parkland"]);
  }
  function buildSurroundThumb(v, base) {
    if (!isBuiltUp(v)) {
      const w2 = makeWorld(14, 13);
      tree(w2, 3, 3, "oak");
      tree(w2, 9, 3, "conifer");
      tree(w2, 3, 9, "round");
      tree(w2, 9, 10, "bush");
      tree(w2, 11, 7, "poplar");
      return finalize(w2, base);
    }
    const w = makeWorld(SURROUND_THUMB.W, SURROUND_THUMB.H), lanes = /* @__PURE__ */ new Set(), deep = v === "city" || v === "industrial";
    for (let x = 0; x < SURROUND_THUMB.W; x++) {
      for (const y of [11, 12]) {
        w.ground(x, y, asphalt(x, y), null);
        lanes.add(cellKey(x, y));
      }
      w.ground(x, 10, PAVE, null);
      w.ground(x, 13, PAVE, null);
    }
    surroundLots(w, v, [{ ax: "x", a0: 1, a1: 24, c0: deep ? 3 : 5, c1: 8, face: 1, back: 0, front: 9 }]);
    car2(w, 7, 12, true, "#c8202f");
    car2(w, 17, 11, true, "#1f4fb8");
    lamp(w, 3, 13);
    lamp(w, 21, 13);
    scatterTreesThinned(w, Math.max(0.5, SURROUND_TREE_DENSITY[v]));
    return finalize(w, base);
  }

  // stadiumConfig.ts (from the shared assets, see header)
  var DEFAULT_STADIUM_CONFIG = {
    north: 7,
    east: 3,
    south: 2,
    west: 5,
    northConstruction: false,
    eastConstruction: false,
    southConstruction: false,
    westConstruction: false,
    seat: "#c8202f",
    roof: "#f4f3ee",
    floodlights: true,
    scoreboard: true,
    shop: true,
    museum: false,
    carpark: true,
    trees: true,
    shopLevel: 2,
    museumLevel: 3,
    carparkLevel: 2,
    home: 2,
    away: 1,
    pitch: "stripes",
    base: "concrete"
  };
  var STAND_SIDES = ["north", "east", "south", "west"];
  var STADIUM_FACILITIES = ["floodlights", "scoreboard", "shop", "museum", "carpark", "trees"].map((id) => ({ id, labelKey: `facilities.stadium.${id}` }));
  var STADIUM_SLUGS = {
    north_stand: "north",
    east_stand: "east",
    south_stand: "south",
    west_stand: "west",
    club_shop: "shop",
    museum: "museum",
    car_park: "carpark"
  };
  var STADIUM_COST = {
    north: 25e6,
    east: 19e6,
    south: 25e6,
    west: 19e6,
    shop: 48e5,
    museum: 7e6,
    carpark: 53e5
  };
  var FLOODLIGHT_ARCH = { base: 8, perLevel: 0.8, reach: 6 };
  var SCOREBOARD_STAND = { 0: "north", 1: "west", 2: "south", 3: "east" };
  var STADIUM_GEOMETRY = { M: 23, FW: 37, FH: 25, IW: 41, IH: 29, W: 87, H: 75, ZMAX: 34, THUMB_ZMAX: 20 };
  var S = STADIUM_GEOMETRY;
  var GLYPHS = {
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
  var STANDS_SET = ["north", "east", "south", "west"];
  var BUILDINGS_SET = ["shop", "museum", "carpark"];
  function sizeFactor(id) {
    const g = STANDS_SET.includes(id) ? STANDS_SET : BUILDINGS_SET;
    return Math.sqrt(STADIUM_COST[id] / Math.max(...g.map((k) => STADIUM_COST[k])));
  }
  var standDepth = (id, L) => Math.max(2, Math.round((L + 1) * sizeFactor(id)));
  function isLine(px, py) {
    const FW = S.FW, FH = S.FH;
    if (px === 0 || px === FW - 1 || py === 0 || py === FH - 1 || px === 18) return true;
    if (Math.abs(Math.hypot(px - 18, py - 12) - 4) < 0.5) return true;
    const q = px < 18 ? px : FW - 1 - px;
    if (q === 6 && py >= 5 && py <= 19 || (py === 5 || py === 19) && q <= 6) return true;
    if (q === 2 && py >= 9 && py <= 15 || (py === 9 || py === 15) && q <= 2) return true;
    return q === 4 && py === 12;
  }
  function standFrame(side) {
    const M = S.M, IW = S.IW, IH = S.IH;
    const len = side === "north" || side === "south" ? IW : IH;
    const pos = (i, r) => side === "north" ? [M + i, M - 1 - r] : side === "south" ? [M + i, M + IH + r] : side === "west" ? [M - 1 - r, M + i] : [M + IW + r, M + i];
    return { len, pos };
  }
  function standConstruction(w, side) {
    const { len, pos } = standFrame(side), D = 8, solid = /* @__PURE__ */ new Set();
    const at2 = (i, r, z0, z1, top2, sd, flag) => {
      if (i < 0 || i >= len || r < 0 || r >= D) return;
      const [x, y] = pos(i, r);
      w.add(x, y, z0, z1, top2, sd, flag);
      if (z0 < 1) solid.add(i + "," + r);
    };
    const P = "#9aa0a6", Wd = "#c9a063", Cn = "#b8b3a8", Y = "#f2c230", Yd = "#b88a12", Dk = "#3b3831", glass = "#a9d6e6";
    for (let i = 0; i < len; i++) for (let r = 0; r < D; r++) {
      const [x, y] = pos(i, r);
      w.ground(x, y, (i * 73 + r * 151) % 7 === 0 ? "#8a6c48" : "#9c7d56", null);
    }
    for (let i = 0; i < len; i++) if (i % 6 !== 5) at2(i, 0, 0, 1.2, Math.floor(i / 2) % 2 ? "#f07a1a" : "#f4f3ee");
    const s0 = Math.round(len * 0.3), s1 = s0 + (len > 30 ? 14 : 11);
    for (let i = s0; i <= s1; i++) {
      at2(i, 4, 0, 1, Cn, "#8f8a80");
      at2(i, 5, 0, 4, Cn, "#8f8a80");
      for (const r of [3, 6]) {
        if ((i - s0) % 3 === 0) at2(i, r, 0, 9.5, P, P);
        else for (const z of [3, 6, 9]) at2(i, r, z, z + 0.5, Wd, Wd);
      }
    }
    const ic = Math.min(len - 2, s1 + 3), top = 22, hi = Math.min(len - 1, ic + 8);
    for (let z = 0; z < top; z += 2) at2(ic, 5, z, z + 2, Y, z / 2 % 2 ? Yd : Y);
    at2(ic, 4, top - 2, top, Y, glass);
    for (let i = ic - 3; i <= Math.min(len - 1, ic + 9); i++) at2(i, 5, top, top + 1, Y, Y);
    at2(ic - 3, 5, top - 1.5, top, Cn, Cn);
    at2(ic - 2, 5, top - 1.5, top, Cn, Cn);
    at2(hi, 5, 12, top, Dk, Dk);
    at2(hi, 5, 10.5, 12, Wd, Wd);
    const di = 2;
    for (let dx = 0; dx < 3; dx++) for (let r = 5; r <= 6; r++) {
      at2(di + dx, r, 0, 0.5, Dk, Dk);
      at2(di + dx, r, 0.5, 2, Y, Y);
    }
    at2(di, 6, 2, 3.5, Y, glass);
    at2(di + 1, 4, 2, 2.5, Y, Y);
    at2(di + 1, 3, 1.5, 2.5, Y, Y);
    at2(di + 1, 2, 0.5, 1.5, Dk, Dk);
    for (let dx = 0; dx < 3; dx++) for (let r = 2; r <= 3; r++) at2(len - 5 + dx, r, 0, 1.5, "#8f8a80", "#f07a1a");
    const piles = [[Math.round(len * 0.2), 2, 2.5], [Math.round(len * 0.55), 1.5, 2], [len - 8, 5, 2.2]];
    const rub = ["#8f8a80", "#a3927a", "#77705f"];
    for (let i = 0; i < len; i++) for (let r = 1; r < D; r++) {
      if (solid.has(i + "," + r)) continue;
      let h = 0;
      for (const [pi, pr, ph] of piles) h = Math.max(h, ph - Math.hypot(i - pi, (r - pr) * 1.3) * 0.7);
      h = Math.round(h * 2) / 2;
      if (h > 0) at2(i, r, 0, h, rub[(i * 31 + r * 17) % 3], "#6f675c");
    }
  }
  function backWall(w, x, y, hb, L) {
    const Wl = "#e9e6df";
    if (L >= 6 && hb >= 7) {
      w.add(x, y, 0, hb - 3.2, Wl, Wl);
      w.add(x, y, hb - 3.2, hb - 1.4, "#a9d6e6", "#a9d6e6", "win");
      w.add(x, y, hb - 1.4, hb, Wl, Wl);
    } else w.add(x, y, 0, hb, Wl, Wl);
  }
  function stand(w, side, L, c, ends) {
    const D = standDepth(side, L), { len, pos } = standFrame(side);
    const roofed = L >= 4, rs = L >= 8 ? 1 : Math.floor(D * 0.4), k = L >= 5 ? Math.min(D - 1, 2 + (L >> 1)) : 0;
    for (let i = 0; i < len; i++) {
      const first = i < len / 2, e = first ? i : len - 1 - i, filled = ends[first ? 0 : 1];
      const cut = !filled && e < k ? Math.round((k - e) * (k - e) / k) : 0, Di = Math.max(2, D - cut), hb = Di + 2, rr = Math.min(rs, Di - 1);
      for (let r = 0; r < Di; r++) {
        const [x, y] = pos(i, r), covered = roofed && r >= rr;
        if (r === Di - 1 && L >= 3) backWall(w, x, y, hb, L);
        else if (covered && !filled && e === 0) w.add(x, y, 0, hb, "#e9e6df", "#e9e6df");
        else w.add(x, y, 0, 1 + r, i % 10 === 5 ? "#bdb8ac" : r % 2 ? c.seat : shade(c.seat, 0.84), "#8f8a80");
        if (covered) {
          const fas = L >= 7 && r === rr;
          w.add(x, y, hb, hb + 1, fas ? shade(c.roof, 0.82) : c.roof, fas ? shade(c.roof, 0.7) : c.roof);
        }
      }
    }
  }
  function corner(w, sx, sy, Dc, L, c) {
    const M = S.M, ox = sx < 0 ? M - 1 : M + S.IW, oy = sy < 0 ? M - 1 : M + S.IH, rs = L >= 8 ? 1 : Math.floor(Dc * 0.4), hb = Dc + 2;
    for (let a = 0; a < Dc; a++) for (let b = 0; b < Dc; b++) {
      const rr = Math.hypot(a + 0.5, b + 0.5);
      if (rr > Dc) continue;
      const r = Math.min(Dc - 1, Math.floor(rr)), x = ox + sx * a, y = oy + sy * b;
      if (r === Dc - 1) backWall(w, x, y, hb, L);
      else w.add(x, y, 0, 1 + r, r % 2 ? c.seat : shade(c.seat, 0.84), "#8f8a80");
      if (r >= rs) w.add(x, y, hb, hb + 1, c.roof, c.roof);
    }
  }
  function scoreCols(home, away) {
    const digits = (n) => {
      const out = [];
      String(n).split("").forEach((chr, i) => {
        if (i) out.push(null);
        out.push([chr, 0], [chr, 1], [chr, 2]);
      });
      return out;
    };
    return [null, ...digits(home), null, "dash", null, ...digits(away), null];
  }
  var scoreboardWidth = (home, away) => scoreCols(home, away).length;
  function scoreboard(w, x0, y0, home, away, zb = 0, lift = 5, rev = false, alongY = false) {
    const B = "#17181c", Y = "#ffd23a", G = "#8a9096";
    const cols = scoreCols(home, away), n = cols.length, at2 = (c) => alongY ? [x0, y0 + c] : [x0 + c, y0];
    if (rev) cols.reverse();
    w.add(...at2(1), zb, zb + lift, G, G);
    w.add(...at2(n - 2), zb, zb + lift, G, G);
    for (let c = 0; c < n; c++) for (let z = zb + lift; z < zb + lift + 7; z++) {
      const j = zb + lift + 5 - z, cc = cols[c];
      let lit = false;
      if (j >= 0 && j < 5 && cc) lit = cc === "dash" ? j === 2 : GLYPHS[cc[0]][j * 3 + cc[1]] === "1";
      w.add(...at2(c), z, z + 1, B, lit ? Y : B, lit ? "glow" : null);
    }
  }
  function archMast(w, x, y, dx, dy, h, reach) {
    let pk = 0;
    for (let z = 0; z < h; z++) {
      const k = reach ? Math.round(reach * ((z + 1) / h) ** 2) : 0;
      w.add(x + dx * k, y + dy * k, z, z + 1, "#a3a8ae", "#a3a8ae");
      if (k !== pk) w.add(x + dx * pk, y + dy * pk, z, z + 0.5, "#a3a8ae", "#a3a8ae");
      pk = k;
    }
    const hx = x + dx * pk, hy = y + dy * pk;
    for (const [a, b] of [[0, 0], [dx, 0], [0, dy], [dx, dy]]) w.add(hx + a, hy + b, h, h + (reach ? 1.5 : 2), "#d9dde2", "#fff3b0", "glow");
  }
  function shop(w, ax, ay, seat, L) {
    const s = sizeFactor("shop"), Bw = Math.round(17 * s), Bd = Math.round(10 * s);
    const M = massing(w, { x: ax, y: ay - Bd, w: Bw, d: Bd }, L, { wall: "#ece8df", trim: seat, roof: "#a8a49b", ribbon: true, ax: "l", ay: "b", canopy: false, fh: 3.5, floors: [1, 1, 1, 1, 2, 2, 2, 2, 3, 3] });
    for (let dx = 0; dx < M.main.w; dx++) {
      const k = dx % 2 ? seat : "#f4f3ee";
      w.add(M.main.x + dx, M.front, 2.6, 3, k, k);
    }
    if (L >= 3) {
      const mid = M.main.x + (M.main.w >> 1);
      for (let dx = -1; dx <= 1; dx++) w.add(mid + dx, M.front - 1, M.h + 0.5, M.h + 2, seat, "#f4f3ee");
    }
  }
  function museum(w, ax, ay, L) {
    const Sd = "#dcd3bd", Lt = "#efe9da", R = "#bfb49a", door = "#4a3f33", dome = "#8fb3a4", s = sizeFactor("museum");
    const bw = Math.min(17, Math.round((5 + L * 1.2) * s)) | 1, d = Math.min(8, Math.round((3 + L * 0.5) * s)), h = 3 + Math.round(L * 0.55), mid = bw >> 1;
    const pw = L >= 6 ? Math.min(4, 2 + (L - 6 >> 1)) : 0, x0 = ax - bw + 1, y0 = ay, fp = /* @__PURE__ */ new Map();
    for (let dx = 0; dx < bw; dx++) {
      const wing = pw > 0 && (dx < pw || dx >= bw - pw);
      for (let dy = 0; dy < (wing ? d + 2 : d); dy++) fp.set(cellKey(x0 + dx, y0 + dy), wing ? h - 1 : h);
    }
    for (const [k, hh] of fp) {
      const x = k % 4096, y = k / 4096 | 0, inner = [[1, 0], [-1, 0], [0, 1], [0, -1]].every(([a, b]) => fp.get(cellKey(x + a, y + b)) === hh);
      if (x === x0 + mid && y === y0 + d - 1) {
        w.add(x, y, 0, 3, door, door);
        w.add(x, y, 3, hh, Sd, Sd);
      } else w.add(x, y, 0, hh, inner ? R : Sd, Sd);
      if (!inner) w.add(x, y, hh, hh + 0.6, Lt, Lt);
    }
    const c0 = x0 + pw, cw = bw - 2 * pw;
    for (let i = 0; i < cw; i++) {
      const x = c0 + i, y = y0 + d;
      if (i % 2 === 0) w.add(x, y, 0, h - 1, Lt, Lt);
      else w.add(x, y, 0, 0.5, Lt, Lt);
      w.add(x, y, h - 1, h, Lt, Lt);
      for (let k = 1; k <= (L >= 4 ? 3 : 2); k++) if (i >= k * 2 - 1 && i <= cw - k * 2) w.add(x, y, h - 1 + k, h + k, Lt, Lt);
      w.add(x, y0 + d + 1, 0, 0.5, Lt, Lt);
    }
    if (L >= 4) {
      const cx = x0 + mid + 0.5, cy = y0 + d / 2, big = L >= 8;
      let z = h;
      for (const [r, th] of big ? [[2.6, 1], [2, 1], [1.3, 0.8]] : [[1.3, 1.2], [0.8, 0.8]]) {
        for (const k of discMask(cx, cy, r)) w.add(k % 4096, k / 4096 | 0, z, z + th, dome, shade(dome, 0.9));
        z += th;
      }
      if (big) w.add(x0 + mid, Math.floor(cy), z, z + 1.2, Lt, Lt);
    }
    if (L >= 10) volume(w, discMask(x0 - 2.4, y0 + d / 2, 2.3), 0, 2, { wall: "#e8ecef", glass: "#a9d6e6", ribbon: true, lo: 0.4, hi: 0.4, roof: "#cfd4d8", trim: "#9aa0a6" });
  }
  function carpark(w, ax, ay, L) {
    const T = "#56585d", Ln = "#e8e6df", Cn = "#b8b3a8", s = sizeFactor("carpark");
    const cols = ["#c8202f", "#f4f3ee", "#1f4fb8", "#1a1a1a", "#f2c230", "#1f8a4c", "#7fb8e6"];
    const bays = Math.min(Math.round(5 * s), [2, 3, 3, 4, 4, 4, 5, 5, 5, 5][L - 1]), rows = L === 1 ? 1 : 2, decks = [1, 1, 1, 2, 2, 3, 3, 3, 4, 4][L - 1];
    const bw = bays * 3 + 1, bd = rows === 1 ? 4 : 10, x0 = ax - bw + 1, y0 = ay - bd + 1;
    const top = (lx, ly) => (rows === 1 || ly <= 3 || ly >= 6) && lx % 3 === 0 ? Ln : T;
    const pillarX = [0, Math.floor(bays / 2) * 3, bw - 1], pillarY = [0, bd - 1];
    for (let k = 0; k < decks; k++) {
      const z = k * 3;
      for (let ly = 0; ly < bd; ly++) for (let lx = 0; lx < bw; lx++) {
        if (k === 0) w.ground(x0 + lx, y0 + ly, top(lx, ly), null);
        else {
          if (pillarX.includes(lx) && pillarY.includes(ly)) w.add(x0 + lx, y0 + ly, z - 3, z - 0.5, Cn, Cn);
          w.add(x0 + lx, y0 + ly, z - 0.5, z, top(lx, ly), Cn);
        }
      }
      for (let r = 0; r < rows; r++) for (let b = 0; b < bays; b++) {
        if ((b + r * 2 + k * 3) % 5 === 3) continue;
        const col = cols[(b + r * 3 + k * 2) % cols.length], bx = x0 + 1 + b * 3, by = y0 + (r === 0 ? 0 : bd - 3);
        for (let dx = 0; dx < 2; dx++) for (let dy = 0; dy < 3; dy++) {
          w.add(bx + dx, by + dy, z, z + 1, col, col);
          if (dy === 1) w.add(bx + dx, by + dy, z + 1, z + 1.5, shade(col, 1.08), "#2b3440");
        }
      }
    }
    if (L >= 6) {
      const tz = decks * 3 + 1.5;
      for (const dy of [0, 1]) {
        w.add(x0 - 1, y0 + dy, 0, tz, "#cfcac0", dy ? "#a9d6e6" : "#cfcac0", dy ? "win" : null);
        w.add(x0 - 1, y0 + dy, tz, tz + 0.5, "#9aa0a6");
      }
    }
    if (L >= 10) {
      const z = (decks - 1) * 3;
      for (const px of [0, bw - 1]) for (const py of [0, bd - 1]) w.add(x0 + px, y0 + py, z, z + 3, "#9aa0a6");
      for (let ly = 0; ly < bd; ly++) for (let lx = 0; lx < bw; lx++) w.add(x0 + lx, y0 + ly, z + 3, z + 3.3, lx % 3 === 0 ? "#5a6a8c" : "#2b3a5c", "#9aa0a6");
    }
  }
  var CORNERS = [["nw", -1, -1], ["ne", 1, -1], ["sw", -1, 1], ["se", 1, 1]];
  function cornerFill(c) {
    const live = (id) => !c[`${id}Construction`], D = (id) => standDepth(id, c[id]);
    const cf = (a, b) => live(a) && live(b) && c[a] >= 9 && c[b] >= 9 ? Math.min(D(a), D(b)) : 0;
    return { nw: cf("north", "west"), ne: cf("north", "east"), sw: cf("south", "west"), se: cf("south", "east") };
  }
  function buildStadium(c, o = {}) {
    var _a, _b, _c, _d;
    const rot = (_a = o.rotation) != null ? _a : 0;
    const { M, W, H, IW, IH } = S, w = makeWorld(W, H);
    const fx = M + 2, fy = M + 2;
    w.owner = "pitch";
    for (let y = M; y < M + IH; y++) for (let x = M; x < M + IW; x++) {
      const px = x - fx, py = y - fy;
      let col = "#4a8531";
      if (px >= 0 && py >= 0 && px < S.FW && py < S.FH) {
        if (isLine(px, py)) col = "#eef0e8";
        else {
          const a = Math.floor(px / 3), b = Math.floor(py / 3);
          const alt = c.pitch === "stripes" ? a % 2 : c.pitch === "checks" ? (a + b) % 2 : 0;
          col = alt ? "#529538" : "#5da53e";
        }
      }
      w.ground(x, y, col, "pitch");
    }
    for (const gx of [fx - 1, fx + S.FW]) for (let py = 10; py <= 14; py++) {
      if (py === 10 || py === 14) w.add(gx, fy + py, 0, 2, "#f4f3ee", "#f4f3ee");
      w.add(gx, fy + py, 2, 2.5, "#f4f3ee", "#f4f3ee");
    }
    const K = cornerFill(c);
    const ends = { north: [K.nw, K.ne], south: [K.sw, K.se], west: [K.nw, K.sw], east: [K.ne, K.se] };
    let maxL = 1;
    for (const id of STAND_SIDES) {
      w.owner = id;
      if (c[`${id}Construction`]) standConstruction(w, id);
      else stand(w, id, c[id], c, ends[id].map((v) => v > 0));
      maxL = Math.max(maxL, c[id]);
    }
    w.owner = "corners";
    for (const [k, sx, sy] of CORNERS) if (K[k]) corner(w, sx, sy, K[k], Math.min(c[sy < 0 ? "north" : "south"], c[sx < 0 ? "west" : "east"]), c);
    if (c.floodlights) {
      w.owner = "floodlights";
      const arch = ((_b = o.floodlightStyle) != null ? _b : "arch") === "arch", A = FLOODLIGHT_ARCH;
      const h = arch ? Math.round(A.base + maxL * A.perLevel) : Math.round((14 + maxL * 1.6) * 0.7);
      for (const [k, sx, sy] of CORNERS) {
        const off = K[k] ? Math.ceil(K[k] * 0.75) + 1 : 1, x = sx < 0 ? M - 1 - off : M + IW + off, y = sy < 0 ? M - 1 - off : M + IH + off;
        if (arch) archMast(w, x, y, -sx, -sy, h, A.reach);
        else mast(w, x, y, -sx, -sy, h);
      }
    }
    w.owner = "scoreboard";
    if (c.scoreboard) {
      if (((_c = o.scoreboardPlacement) != null ? _c : "stand") === "corner") scoreboard(w, 5, 1, c.home, c.away);
      else {
        const side = SCOREBOARD_STAND[rot], L = c[side], con = c[`${side}Construction`], alongY = rot % 2 === 1;
        const D = con ? 8 : standDepth(side, L), r = con ? D : D - 1, n = scoreboardWidth(c.home, c.away);
        const zb = con ? 0 : L >= 4 ? D + 3 : L >= 3 ? D + 2 : D, { pos } = standFrame(side), [x, y] = pos((alongY ? IH : IW) - n >> 1, r);
        scoreboard(w, x, y, c.home, c.away, zb, con ? 5 : 1, rot === 1 || rot === 2, alongY);
      }
    }
    w.owner = "museum";
    if (c.museum) museum(w, W - 2, 1, c.museumLevel);
    w.owner = "shop";
    if (c.shop) shop(w, 2, H - 2, c.seat, c.shopLevel);
    w.owner = "carpark";
    if (c.carpark) carpark(w, W - 2, H - 2, c.carparkLevel);
    w.owner = null;
    if (c.trees) drawSurrounds(w, (_d = o.surrounds) != null ? _d : "parkland");
    return finalize(w, c.base);
  }
  function buildStadiumFacility(id, c, o = {}) {
    var _a, _b;
    if (id === "trees") return buildSurroundThumb((_a = o.surrounds) != null ? _a : "parkland", c.base);
    const w = makeWorld(26, 24);
    switch (id) {
      case "floodlights":
        if (((_b = o.floodlightStyle) != null ? _b : "arch") === "arch") archMast(w, 18, 4, -1, 1, 12, FLOODLIGHT_ARCH.reach);
        else mast(w, 12, 11, 1, 1, 14);
        break;
      case "scoreboard":
        scoreboard(w, 1, 10, c.home, c.away);
        break;
      case "shop":
        shop(w, 1, 22, c.seat, c.shopLevel);
        break;
      case "museum":
        museum(w, 24, 1, c.museumLevel);
        break;
      case "carpark":
        carpark(w, 24, 22, c.carparkLevel);
        break;
    }
    return finalize(trimWorld(w), c.base);
  }
  function resolveStadiumSelected(s) {
    if (!s || s === "none") return null;
    return STADIUM_SLUGS[s] || s;
  }
  function renderStadium(c, opts = {}) {
    var _a;
    const rot = (_a = opts.rotation) != null ? _a : 0, night = opts.time === "night";
    if (opts.facility) return paint(buildStadiumFacility(opts.facility, c, opts), rot, S.THUMB_ZMAX, night, false, true);
    return paint(buildStadium(c, opts), rot, S.ZMAX, night, c.floodlights, false, { selected: resolveStadiumSelected(opts.selected), backdrop: opts.backdrop });
  }

  // plotSite.ts (from the shared assets, see header)
  var FACILITY_ZMAX = 30;
  var rec = (c) => c;
  function layoutPlots(def) {
    const [IW, IH] = def.site, m = def.margin, out = {};
    let rest = def.facilities.map((f) => ({ id: f.id, v: f.cost, a: 0 })).sort((a, b) => b.v - a.v || (a.id < b.id ? -1 : 1));
    const tot = rest.reduce((s, e) => s + e.v, 0);
    rest.forEach((e) => {
      e.a = e.v * IW * IH / tot;
    });
    const worst = (r, side) => {
      const s = r.reduce((q, e) => q + e.a, 0);
      let mx = 0, mn = Infinity;
      for (const e of r) {
        mx = Math.max(mx, e.a);
        mn = Math.min(mn, e.a);
      }
      return Math.max(side * side * mx / (s * s), s * s / (side * side * mn));
    };
    let x = 0, y = 0, W = IW, H = IH;
    while (rest.length) {
      const side = Math.min(W, H), row = [rest[0]];
      let i = 1;
      while (i < rest.length && worst([...row, rest[i]], side) <= worst(row, side)) row.push(rest[i++]);
      const s = row.reduce((q, e) => q + e.a, 0);
      if (W >= H) {
        const cw = s / H;
        let yy = y;
        for (const e of row) {
          const eh = e.a / cw;
          out[e.id] = [x, yy, x + cw, yy + eh];
          yy += eh;
        }
        x += cw;
        W -= cw;
      } else {
        const chh = s / W;
        let xx = x;
        for (const e of row) {
          const ew = e.a / chh;
          out[e.id] = [xx, y, xx + ew, y + chh];
          xx += ew;
        }
        y += chh;
        H -= chh;
      }
      rest = rest.slice(row.length);
    }
    const plots = {};
    for (const id in out) {
      const [a, b, c, d] = out[id].map(Math.round);
      plots[id] = { x: m + a + 1, y: m + b + 1, w: c - a - 2, d: d - b - 2 };
    }
    return plots;
  }
  var plotCache = /* @__PURE__ */ new WeakMap();
  function plotsFor(def) {
    let p = plotCache.get(def);
    if (!p) {
      p = layoutPlots(def);
      plotCache.set(def, p);
    }
    return p;
  }
  var siteSize = (def) => ({ W: def.site[0] + 2 * def.margin, H: def.site[1] + 2 * def.margin });
  var inset = (P, k = 1) => ({ x: P.x + k, y: P.y + k, w: P.w - 2 * k, d: P.d - 2 * k });
  function zones(P, yf) {
    const I = inset(P), yd = Math.max(3, Math.round(I.d * yf));
    return { I, B: { x: I.x, y: I.y, w: I.w, d: I.d - yd - 1 }, Y: { x: I.x, y: I.y + I.d - yd, w: I.w, d: yd } };
  }
  function pitch(w, P, L, c) {
    const I = inset(P), top = 4;
    let fw = Math.max(9, Math.min(I.w - 4, Math.round((I.d - top - 2) * 1.5))) | 1;
    if (L === 1) fw = Math.max(9, Math.round(fw * 0.62)) | 1;
    const fd = Math.max(7, Math.round(fw / 1.5)) | 1, fx = I.x + (I.w - fw >> 1), fy = I.y + top + Math.max(0, I.d - top - fd >> 1), my = fy + (fd >> 1);
    for (let dy = -1; dy <= fd; dy++) for (let dx = -2; dx <= fw + 1; dx++) w.ground(fx + dx, fy + dy, "#4a8531", "pitch");
    field(w, fx, fy, fw, fd, c.pitch, Math.max(1, Math.round(fd / 10)), L === 1 ? 1.5 : 2);
    if (L === 1) {
      for (let k = 0; k < 4; k++) w.add(fx + 2 + k * Math.floor((fw - 4) / 3), fy - 1, 0, 1, "#f07a1a");
      return;
    }
    for (let k = 0; k < 3; k++) {
      w.add(fx + Math.round(fw * 0.3), my - 1 + k, 0, 2.5, c.accent);
      w.add(fx + Math.round(fw * 0.7), my - 1 + k, 0, 2.5, c.accent);
    }
    if (L >= 4) {
      const n = Math.max(3, Math.round(fd * 0.3));
      nets(w, fx - 3, my - n, my + n);
      nets(w, fx + fw + 2, my - n, my + n);
    }
    if (L >= 5) for (const [a, b] of L >= 6 ? [[0, 0], [1, 0], [0, 1], [1, 1]] : [[0, 0], [1, 0]]) mast(w, a ? fx + fw : fx - 1, b ? fy + fd : fy - 1, a ? -1 : 1, b ? -1 : 1, 9 + L);
    if (L >= 7) {
      const half = L >= 9 ? 0.34 : 0.2, x0 = fx + Math.round(fw * (0.5 - half)), x1 = fx + Math.round(fw * (0.5 + half));
      bleacher(w, x0, x1, fy - 4, c.accent);
      if (L >= 10) for (let x = x0 - 1; x <= x1 + 1; x++) {
        for (let r = 0; r < 3; r++) w.add(x, fy - 4 + r, 7.5, 8, "#f4f3ee", "#d6d1c6");
        if (x === x0 - 1 || x === x1 + 1) w.add(x, fy - 4, 0, 7.5, "#9aa0a6");
      }
    }
    if (L >= 8) for (const sx of [fx + Math.round(fw * 0.32) - 2, fx + Math.round(fw * 0.68) - 1]) for (let i = 0; i < 4; i++) {
      w.add(sx + i, fy + fd + 1, 0, 2.2, "#dcdfe2", c.accent);
      w.add(sx + i, fy + fd + 1, 2.2, 2.5, "#cfe6ee", "#a9c9d4");
    }
  }
  function buildPlotSite(def, c) {
    const P = plotsFor(def), { W, H } = siteSize(def), m = def.margin, w = makeWorld(W, H), ps = Object.values(P), r = rec(c);
    const pc = c.base === "grass" ? "#d6cfbe" : "#dcd8ce";
    for (let y = m; y < m + def.site[1]; y++) for (let x = m; x < m + def.site[0]; x++)
      if (!ps.some((p) => x >= p.x && y >= p.y && x < p.x + p.w && y < p.y + p.d)) w.ground(x, y, pc, null);
    for (const f of def.facilities) {
      if (!r[f.id]) continue;
      const p = P[f.id];
      w.owner = f.id;
      if (r[f.id + "Construction"]) plotConstruction(w, p.x + 1, p.y + 1, p.w - 2, p.d - 2);
      else f.draw(w, p, r[f.id + "Level"], c);
      w.owner = null;
    }
    if (c.trees) scatterTrees(w);
    return finalize(w, c.base);
  }
  function buildPlotFacility(def, id, c) {
    if (id === "trees") {
      const w2 = makeWorld(14, 13);
      tree(w2, 3, 3, "oak");
      tree(w2, 9, 3, "conifer");
      tree(w2, 3, 9, "round");
      tree(w2, 9, 10, "bush");
      tree(w2, 11, 7, "poplar");
      return finalize(w2, c.base);
    }
    const f = def.facilities.find((x) => x.id === id), p = plotsFor(def)[id];
    if (!f || !p) return finalize(makeWorld(1, 1), c.base);
    const w = makeWorld(p.w, p.d);
    f.draw(w, { x: 0, y: 0, w: p.w, d: p.d }, rec(c)[id + "Level"], c);
    return finalize(trimWorld(w), c.base);
  }
  function resolveSelected(def, s) {
    if (!s || s === "none") return null;
    const f = def.facilities.find((x) => x.slug === s || x.id === s);
    return f ? f.id : null;
  }
  function renderPlotSite(def, c, opts = {}) {
    var _a;
    const rot = (_a = opts.rotation) != null ? _a : 0, night = opts.time === "night";
    if (opts.facility) return paint(buildPlotFacility(def, opts.facility, c), rot, FACILITY_ZMAX, night, false, true);
    const sel = resolveSelected(def, opts.selected);
    return paint(buildPlotSite(def, c), rot, def.zmax, night, def.lit(c), false, { selected: sel, selectedRect: sel ? plotsFor(def)[sel] : null, backdrop: opts.backdrop });
  }

  // trainingGroundConfig.ts (from the shared assets, see header)
  var DEFAULT_TRAINING_GROUND_CONFIG = {
    accent: "#c8202f",
    base: "grass",
    pitch: "stripes",
    trees: true,
    pitches: true,
    pitchesLevel: 7,
    pitchesConstruction: false,
    gym: true,
    gymLevel: 6,
    gymConstruction: false,
    techzone: true,
    techzoneLevel: 5,
    techzoneConstruction: false,
    tactics: true,
    tacticsLevel: 4,
    tacticsConstruction: false
  };
  function pitches(w, P, L, c) {
    pitch(w, P, L, c);
  }
  function gym(w, P, L, c) {
    const { B, Y } = zones(P, 0.26), a = c.accent;
    const M = massing(w, B, L, { wall: "#dfe3e6", roof: "#8f949a", trim: a, ribbon: L >= 2, fh: 4, floors: [1, 1, 1, 1, 2, 2, 2, 2, 3, 3] });
    if (L >= 6 && M.roof.w >= 4 && M.roof.d >= 3) for (let dy = 0; dy < M.roof.d; dy++) for (let dx = 0; dx < M.roof.w; dx++) {
      const ring = !dx || !dy || dx === M.roof.w - 1 || dy === M.roof.d - 1;
      w.add(M.roof.x + dx, M.roof.y + dy, M.roofZ, M.roofZ + 0.3, ring ? "#c4553a" : "#5da53e", "#8f949a");
    }
    const rigs = L >= 8 ? 2 : L >= 3 ? 1 : 0;
    for (let k = 0; k < rigs; k++) {
      const rx = Y.x + 1 + k * 7, ry = Y.y + 1;
      for (const px of [0, 4]) w.add(rx + px, ry, 0, 3, "#5a5f66");
      for (let dx = 0; dx <= 4; dx++) w.add(rx + dx, ry, 3, 3.3, "#5a5f66");
    }
    if (L >= 5) for (let dx = Math.min(Y.w, 15); dx < Y.w; dx++) for (let r = 0; r < 2 && r < Y.d; r++) w.ground(Y.x + dx, Y.y + Y.d - 1 - r, r ? "#3f9a4a" : "#4aa654", null);
  }
  function techzone(w, P, L, c) {
    const a = c.accent, I = inset(P), split = L >= 5 ? Math.round(I.w * 0.64) : I.w, D = { x: I.x, y: I.y, w: split - (L >= 5 ? 1 : 0), d: I.d };
    for (let dy = 0; dy < D.d; dy++) for (let dx = 0; dx < D.w; dx++) w.ground(D.x + dx, D.y + dy, "#4a8531", "pitch");
    const bw = 7, bh = 5, cols = Math.max(1, Math.floor((D.w - 1) / (bw + 1))), rows = Math.max(1, Math.floor((D.d - 5) / (bh + 1)));
    const nb = Math.min(cols * rows, [1, 2, 2, 3, 3, 4, 4, 5, 6, 6][L - 1]);
    for (let b = 0; b < nb; b++) {
      const bx = D.x + 1 + b % cols * (bw + 1), by = D.y + 5 + Math.floor(b / cols) * (bh + 1), k = b % 3;
      for (let dy = 0; dy < bh; dy++) for (let dx = 0; dx < bw; dx++) if (!dx || !dy || dx === bw - 1 || dy === bh - 1) w.ground(bx + dx, by + dy, "#eef0e8", "pitch");
      if (k === 0) for (const [cx, cy] of [[1, 1], [5, 1], [3, 2], [1, 3], [5, 3]]) w.add(bx + cx, by + cy, 0, 0.8, "#f07a1a");
      else if (k === 1) for (let i = 1; i < bw - 1; i += 2) w.add(bx + i, by + 2, 0, 2.4, a, shade(a, 0.8));
      else {
        for (let i = 1; i < bw - 1; i++) w.add(bx + i, by + 1, 0, 1.2, "#f4f3ee", "#c9c6bd");
        w.add(bx + 3, by + 3, 0, 0.6, "#f4f3ee");
      }
    }
    if (L >= 2) for (const f of [0.36, 0.76]) {
      const sx = D.x + Math.round(D.w * f) - 3;
      for (let i = 0; i < 6; i++) {
        const x = sx + i;
        if (L < 4) {
          w.add(x, D.y + 2, 0, 1.8, a, "#6b665c");
          w.add(x, D.y + 3, 0, 0.8, a, "#6b665c");
          continue;
        }
        w.add(x, D.y + 1, 0, 3, "#dcdfe2");
        if (i === 0 || i === 5) {
          w.add(x, D.y + 2, 0, 3, "#cfe6ee");
          w.add(x, D.y + 3, 0, 3, "#cfe6ee");
        } else w.add(x, D.y + 2, 0, 1, a, "#6b665c");
        for (let r = 1; r <= 3; r++) w.add(x, D.y + r, 3, 3.4, "#cfe6ee", "#a9c9d4");
      }
    }
    if (L >= 7) {
      const Pl = "#9aa0a6";
      for (let dy = 0; dy < 3; dy++) for (let dx = 0; dx < 3; dx++) {
        const x = D.x + dx, y = D.y + dy;
        if (dx !== 1 && dy !== 1) w.add(x, y, 0, 11, Pl);
        else if (dx !== 1 || dy !== 1) for (const z of [3.5, 7.5]) w.add(x, y, z, z + 0.4, Pl);
        w.add(x, y, 11, 11.5, "#c9a063");
        if (dy >= 1) {
          w.add(x, y, 11.5, 13.5, "#5a5f66", "#a9d6e6", "win");
          w.add(x, y, 13.5, 14, a);
        } else w.add(x, y, 11.5, 12.2, a);
      }
    }
    if (L >= 8) {
      mast(w, D.x, D.y + D.d - 1, 1, -1, 14);
      mast(w, D.x + D.w - 1, D.y + D.d - 1, -1, -1, 14);
    }
    if (L >= 5) massing(w, { x: I.x + split, y: I.y, w: I.w - split, d: Math.max(4, Math.round(I.d * 0.62)) }, Math.min(10, (L - 4) * 2 - 1), { wall: "#ece8df", trim: a, roof: "#a8a49b", ribbon: L >= 7 });
  }
  function tactics(w, P, L, c) {
    const { B, Y } = zones(P, 0.32), a = c.accent, cabin = L === 1;
    const M = massing(w, B, L, { wall: cabin ? "#6f8a9a" : "#ece8df", trim: cabin ? "#5a7282" : a, roof: "#a8a49b", ribbon: L >= 5 });
    if (L >= 4 && M.roof.w >= 3 && M.roof.d >= 3) dish(w, M.roof.x + M.roof.w - 3, M.roof.y, M.roofZ);
    if (L >= 9 && M.roof.w >= 7 && M.roof.d >= 3) dish(w, M.roof.x, M.roof.y, M.roofZ);
    if (L >= 7 && Y.d >= 2) {
      const sw = Math.min(Y.w - 2, 8 + (L - 7) * 2), x0 = Y.x + (Y.w - sw >> 1), y = Y.y + Y.d - 1;
      w.add(x0, y, 0, 3, "#5a5f66");
      w.add(x0 + sw - 1, y, 0, 3, "#5a5f66");
      for (let i = 1; i < sw - 1; i++) for (let z = 3; z < 8; z++) {
        const line = i === 1 || i === sw - 2 || z === 3 || z === 7 || i === sw >> 1;
        const col = line ? "#eef0e8" : (i * 5 + z * 3) % 7 === 0 ? a : (i * 3 + z * 5) % 8 === 0 ? "#f4f3ee" : "#2f8a45";
        w.add(x0 + i, y, z, z + 1, "#1d1c1a", col, "glow");
      }
    }
  }
  var TRAINING_SITE = {
    key: "training",
    site: [64, 48],
    margin: 3,
    zmax: 26,
    lit: (c) => c.pitches && c.pitchesLevel >= 5 && !c.pitchesConstruction,
    facilities: [
      { id: "pitches", slug: "training_pitch", cost: 55e5, draw: pitches },
      { id: "gym", slug: "strength_suite", cost: 46e5, draw: gym },
      { id: "techzone", slug: "technical_zone", cost: 56e5, draw: techzone },
      { id: "tactics", slug: "tactical_room", cost: 27e5, draw: tactics }
    ]
  };
  var TRAINING_FACILITIES = TRAINING_SITE.facilities.map((f) => ({ id: f.id, slug: f.slug, labelKey: `facilities.training.${f.slug}`, cost: f.cost }));
  var TRAINING_PLOTS = plotsFor(TRAINING_SITE);
  var renderTrainingGround = (c, opts = {}) => renderPlotSite(TRAINING_SITE, c, opts);

  // medicalCentreConfig.ts (from the shared assets, see header)
  var DEFAULT_MEDICAL_CENTRE_CONFIG = {
    accent: "#1f4fb8",
    base: "grass",
    trees: true,
    physio: true,
    physioLevel: 5,
    physioConstruction: false,
    science: true,
    scienceLevel: 8,
    scienceConstruction: false,
    imaging: true,
    imagingLevel: 6,
    imagingConstruction: false,
    rehab: true,
    rehabLevel: 4,
    rehabConstruction: false,
    nutrition: true,
    nutritionLevel: 7,
    nutritionConstruction: false,
    wellbeing: true,
    wellbeingLevel: 3,
    wellbeingConstruction: false
  };
  function physio(w, P, L, c) {
    const { B, Y } = zones(P, 0.3), M = massing(w, B, L, { wall: "#e3ece6", trim: c.accent, roof: "#a9b3ad", ribbon: L >= 4 });
    if (L >= 2) {
      const n = Math.min(Y.w, Math.round(Y.w * (0.36 + 0.064 * L))), x0 = Y.x + (Y.w - n >> 1), lanes = Math.min(Y.d, L >= 6 ? 3 : 2);
      for (let dx = 0; dx < n; dx++) for (let r = 0; r < lanes; r++) {
        w.ground(x0 + dx, Y.y + r, r % 2 ? "#b84d33" : "#c4553a", null);
        if (L >= 4 && dx % 4 === 2) w.add(x0 + dx, Y.y + r, 0.8, 1.1, "#f4f3ee", "#f4f3ee");
      }
    }
    if (L >= 7) roofGarden(w, M.roof, M.roofZ);
  }
  function science(w, P, L, c) {
    const { B, Y } = zones(P, 0.28), cabin = L === 1;
    const M = massing(w, B, L, { wall: cabin ? "#6f8a9a" : "#e4e7ea", trim: cabin ? "#5a7282" : c.accent, roof: "#9aa0a6", ribbon: L >= 2, glass: "#9fd3e8" });
    if (L >= 3) {
      const lanes = Math.min(Y.d, L >= 8 ? 3 : 2);
      for (let dx = 0; dx < Y.w; dx++) for (let r = 0; r < lanes; r++) w.ground(Y.x + dx, Y.y + r, r % 2 ? "#b84d33" : "#c4553a", null);
      if (L >= 4) {
        const gs = L >= 9 ? 5 : 3;
        for (let k = 0; k < gs; k++) {
          const gx = Y.x + Math.round(k * (Y.w - 1) / (gs - 1));
          for (const gy of [Y.y, Y.y + lanes - 1]) {
            w.add(gx, gy, 0, 1.6, "#3b3831");
            w.add(gx, gy, 1.6, 2, "#ff5a4a", "#ff5a4a", "glow");
          }
        }
      }
    }
    if (L >= 5 && M.roof.w >= 1 && M.roof.d >= 1) {
      w.add(M.roof.x, M.roof.y, M.roofZ, M.roofZ + 4, "#c9ccd0");
      w.add(M.roof.x, M.roof.y, M.roofZ + 4, M.roofZ + 4.5, "#ff5a4a", "#ff5a4a", "glow");
    }
    if (L >= 6) solar(w, { x: M.roof.x + 2, y: M.roof.y, w: M.roof.w - 2, d: M.roof.d }, M.roofZ);
  }
  function imaging(w, P, L, c) {
    const { B, Y } = zones(P, 0.32), a = c.accent;
    if (L === 1) {
      trailer(w, B.x + (B.w - 6 >> 1), B.y + B.d - 2, a);
      return;
    }
    const M = massing(w, B, L, { wall: "#f2f1ec", trim: a, roof: "#b5b1a7" });
    if (L <= 4 && Y.w >= 7) trailer(w, Y.x + 1, Y.y + 1, a);
    if (L >= 4 && M.roof.w >= 1 && M.roof.d >= 1) {
      const vx = M.roof.x + M.roof.w - 1, vy = M.roof.y;
      w.add(vx, vy, M.roofZ, M.roofZ + 3, "#c9ccd0");
      w.add(vx, vy, M.roofZ + 3, M.roofZ + 3.4, "#9aa0a6");
    }
    if (L >= 6 && M.east) {
      const E = M.east;
      for (let dy = 1; dy < E.d - 1; dy++) for (let dx = 1; dx < E.w - 1; dx++) {
        const hh = Math.round(Math.sin(Math.PI * dx / (E.w - 1)) * 4.4) / 2;
        if (hh > 0) w.add(E.x + dx, E.y + dy, M.eh, M.eh + hh, "#cfd4d8", "#e8ecef");
      }
    }
    if (L >= 5 && Y.w >= 8) {
      const bx = Y.x + Y.w - 6, by = Y.y + 1;
      for (let dy = -1; dy <= 2; dy++) for (let dx = -1; dx <= 4; dx++) w.ground(bx + dx, by + dy, dx + dy & 1 ? "#f2c230" : "#56585d", null);
      ambulance(w, bx, by);
    }
    if (L >= 8) {
      for (let k = 0; k < 2; k++) for (let dy = 0; dy < 2; dy++) for (let dx = 0; dx < 2; dx++) if (k * 3 + dx + 2 < M.roof.w) w.add(M.roof.x + k * 3 + dx, M.roof.y + 1 + dy, M.roofZ, M.roofZ + 1.2, "#b8bcc2", "#9aa0a6");
    }
  }
  function rehab(w, P, L, c) {
    const { B, Y } = zones(P, 0.4), a = c.accent, Wt = "#4f9fc4", Wl = "#8fd0ea", S2 = "#e3dfd3";
    if (L === 1) {
      const hx = B.x + (B.w - 4 >> 1), hy = B.y + B.d - 3;
      for (let dy = 0; dy < 3; dy++) for (let dx = 0; dx < 4; dx++) {
        w.add(hx + dx, hy + dy, 0, 2.4, "#c79a68", "#a8804f");
        w.add(hx + dx, hy + dy, 2.4, 2.8, "#8a6a45");
      }
    } else massing(w, B, L, { wall: "#dfe7ea", roof: "#8f949a", trim: a, ribbon: true, fh: 4, lo: 0.5, hi: 0.5, glass: "#bfe3ee", floors: [1, 1, 1, 1, 1, 2, 2, 2, 2, 3] });
    const pw = Math.max(4, Math.min(Y.w - 4, Math.round(Y.w * (0.3 + 0.06 * L)))), pd = Math.max(2, Math.min(Y.d - 3, 2 + Number(L >= 3) + Number(L >= 6))), px = Y.x + (Y.w - pw >> 1), py = Y.y + 1;
    for (let dy = -1; dy <= pd; dy++) for (let dx = -1; dx <= pw; dx++) {
      const edge = dx < 0 || dy < 0 || dx === pw || dy === pd;
      w.ground(px + dx, py + dy, edge ? S2 : L >= 4 && dy % 2 === 1 ? Wl : Wt, null);
    }
    if (L >= 3) for (let k = 0; k < Math.min(5, L >> 1); k++) {
      const lx = px + k * 3;
      if (lx + 1 < px + pw) for (let j = 0; j < 2; j++) w.add(lx + j, py + pd + 1, 0, 0.5, "#f4f3ee", "#c9c6bd");
    }
    if (L >= 8) {
      for (const [dx, dy] of [[-1, -1], [pw, -1], [-1, pd], [pw, pd]]) w.add(px + dx, py + dy, 0, 3.2, "#c9ccd0");
      for (let dy = -1; dy <= pd; dy++) for (let dx = -1; dx <= pw; dx++) w.add(px + dx, py + dy, 3.2, 3.5, "#d5eef5", "#a9c9d4");
    }
  }
  function nutrition(w, P, L, c) {
    const { B, Y } = zones(P, 0.36), a = c.accent;
    const M = massing(w, B, L, { wall: "#f3ede2", trim: a, roof: "#a8a49b", ribbon: L >= 5 });
    if (M.roof.w >= 1 && M.roof.d >= 1) w.add(M.roof.x + M.roof.w - 1, M.roof.y, M.roofZ, M.roofZ + 2, "#9aa0a6");
    const tw = Math.min(Y.w, Math.max(5, Math.round(Y.w * (0.4 + 0.035 * L)))), tx = Y.x;
    for (let dy = 0; dy < Y.d; dy++) for (let dx = 0; dx < tw; dx++) w.ground(tx + dx, Y.y + dy, (dx + dy) % 2 ? "#d9c7a6" : "#d2bf9c", null);
    const n = Math.min(Math.floor((tw - 1) / 3), 1 + (L >> 1));
    for (let k = 0; k < n; k++) parasol(w, tx + 1 + k * 3, Y.y + 1, a);
    if (L >= 4) for (let X = tx + tw + 1; X < Y.x + Y.w; X += 2) for (let dy = 0; dy < Y.d; dy++) w.add(X, Y.y + dy, 0, 0.6, (Y.y + dy) % 2 ? "#58a24b" : "#4a8a3a", "#8a6a45");
    if (L >= 7 && M.roof.w >= 4 && M.roof.d >= 3) for (let dy = 1; dy < M.roof.d; dy++) for (let dx = 0; dx < M.roof.w - 2; dx++) w.add(M.roof.x + dx, M.roof.y + dy, M.roofZ, M.roofZ + 2, "#d7efe6", "#bfe3d6");
  }
  function wellbeing(w, P, L, c) {
    const { B, Y } = zones(P, 0.42), T = "#c79a68", Td = "#8a6a45", S2 = "#b8b3a8";
    if (L === 1) {
      const x = B.x + (B.w - 4 >> 1), y = B.y + B.d - 4;
      for (const [dx, dy] of [[0, 0], [3, 0], [0, 3], [3, 3]]) w.add(x + dx, y + dy, 0, 2.5, T);
      for (let dy = 0; dy <= 3; dy++) for (let dx = 0; dx <= 3; dx++) w.add(x + dx, y + dy, 2.5, 3, Td);
      for (let dy = 1; dy <= 2; dy++) for (let dx = 1; dx <= 2; dx++) w.add(x + dx, y + dy, 3, 3.6, Td);
    } else massing(w, B, L, { wall: T, trim: Td, roof: L >= 6 ? "#6aa84f" : "#8f8a80", ribbon: L >= 4, floors: [1, 1, 1, 1, 2, 2, 2, 2, 2, 3] });
    const rx = Math.max(2, Y.w * (0.12 + 0.024 * L)), ry = Math.max(1.2, (Y.d - 1.5) / 2), cx = Y.x + Y.w * 0.36, cy = Y.y + Y.d / 2;
    for (let y = Y.y; y < Y.y + Y.d; y++) for (let x = Y.x; x < Y.x + Y.w; x++) {
      const q = ((x + 0.5 - cx) / rx) ** 2 + ((y + 0.5 - cy) / ry) ** 2;
      if (q <= 1) w.ground(x, y, q < 0.35 && L >= 3 ? "#438db0" : "#4f9fc4", null);
      else if (q <= 1.7 && hash(x, y) % 3 === 0) w.add(x, y, 0, 0.5, S2, "#9a958a");
    }
    if (L >= 5) {
      const bx = Math.round(cx + rx + 2);
      for (let k = 0; k < 2; k++) w.add(bx + k, Math.round(cy), 0, 0.6, T, Td);
    }
    if (L >= 8) {
      const gx = Math.round(cx + rx + 5), gw = Y.x + Y.w - gx;
      if (gw >= 4) for (let dy = 0; dy < Y.d; dy++) for (let dx = 0; dx < gw; dx++) {
        if (hash(gx + dx, Y.y + dy) % 11 === 0) w.add(gx + dx, Y.y + dy, 0, 0.9, "#8f8a80", "#77705f");
        else w.ground(gx + dx, Y.y + dy, dy % 2 ? "#e6e1d3" : "#d9d3c2", null);
      }
    }
  }
  var MEDICAL_SITE = {
    key: "medical",
    site: [64, 44],
    margin: 3,
    zmax: 26,
    lit: () => false,
    facilities: [
      { id: "physio", slug: "physio_clinic", cost: 25e5, draw: physio },
      { id: "science", slug: "biometric_hub", cost: 9e6, draw: science },
      { id: "imaging", slug: "imaging_suite", cost: 32e5, draw: imaging },
      { id: "rehab", slug: "hydro_pool", cost: 19e5, draw: rehab },
      { id: "nutrition", slug: "nutrition_centre", cost: 56e5, draw: nutrition },
      { id: "wellbeing", slug: "player_wellbeing", cost: 25e5, draw: wellbeing }
    ]
  };
  var MEDICAL_FACILITIES = MEDICAL_SITE.facilities.map((f) => ({ id: f.id, slug: f.slug, labelKey: `facilities.medical.${f.slug}`, cost: f.cost }));
  var MEDICAL_PLOTS = plotsFor(MEDICAL_SITE);
  var renderMedicalCentre = (c, opts = {}) => renderPlotSite(MEDICAL_SITE, c, opts);

  // scoutingConfig.ts (from the shared assets, see header)
  var DEFAULT_SCOUTING_CONFIG = {
    accent: "#1b2a4a",
    base: "concrete",
    pitch: "stripes",
    trees: true,
    hq: true,
    hqLevel: 6,
    hqConstruction: false,
    data: true,
    dataLevel: 7,
    dataConstruction: false,
    trial: true,
    trialLevel: 5,
    trialConstruction: false,
    fleet: true,
    fleetLevel: 5,
    fleetConstruction: false,
    academy: true,
    academyLevel: 6,
    academyConstruction: false
  };
  function hq(w, P, L, c) {
    const { B, Y } = zones(P, 0.26), cabin = L === 1, a = c.accent;
    const M = massing(w, B, L, { wall: cabin ? "#6f8a9a" : "#d9dde2", trim: cabin ? "#5a7282" : a, roof: "#9a9fa6", ribbon: L >= 5, floors: [1, 1, 2, 2, 2, 3, 3, 3, 4, 4] });
    if (L >= 4) {
      const fx = Y.x + Y.w - 2, fy = Y.y + 1, ph = Math.min(M.h + 4, 20);
      w.add(fx, fy, 0, ph, "#c9ccd0");
      w.add(fx - 1, fy, ph - 1.5, ph, a);
      w.add(fx - 2, fy, ph - 1.5, ph, a);
    }
    if (L >= 8) roofGarden(w, M.roof, M.roofZ);
  }
  function data(w, P, L, c) {
    const { B, Y } = zones(P, 0.34), D0 = "#5d6268", G = "#3dd6a0";
    if (L === 1) {
      const x = B.x + (B.w - 3 >> 1), y = B.y + B.d - 2;
      for (let dx = 0; dx < 3; dx++) for (let dy = 0; dy < 2; dy++) {
        w.add(x + dx, y + dy, 0, 1, D0);
        w.add(x + dx, y + dy, 1, 1.6, D0, G, "glow");
        w.add(x + dx, y + dy, 1.6, 3, D0);
      }
      return;
    }
    const M = massing(w, B, L, { wall: "#c9ccd0", roof: "#8f949a", trim: "#9aa0a6", glass: G, flag: "glow", ribbon: true, lo: 1.4, hi: 1, floors: [1, 1, 1, 1, 1, 2, 2, 2, 2, 3] });
    if (L >= 7) for (let ux = 0; ux + 1 < M.roof.w; ux += 3) for (let dy = 0; dy < 2 && dy < M.roof.d; dy++) for (let dx = 0; dx < 2; dx++) w.add(M.roof.x + ux + dx, M.roof.y + dy, M.roofZ, M.roofZ + 1, "#b8bcc2", "#9aa0a6");
    for (let k = 0; k < (L >= 6 ? 2 : L >= 4 ? 1 : 0); k++) dish(w, Y.x + 1 + k * 4, Y.y, 0);
    if (L >= 9) {
      const mx = Y.x + Y.w - 2, my = Y.y + 1;
      for (let z = 0; z < 18; z += 2) {
        const k = z / 2 % 2 ? "#f4f3ee" : "#c8202f";
        w.add(mx, my, z, z + 2, k);
      }
      w.add(mx, my, 18, 19, "#ff5a4a", "#ff5a4a", "glow");
    }
  }
  function trial(w, P, L, c) {
    pitch(w, P, L, c);
  }
  function fleet(w, P, L, c) {
    const I = inset(P), T = "#56585d", Ln = "#e8e6df", a = c.accent;
    const bays = Math.max(2, Math.floor((I.w - 1) / 3)), maxRows = Math.max(1, Math.floor((I.d + 1) / 4)), lw = bays * 3 + 1, x0 = I.x + (I.w - lw >> 1);
    const n = Math.min(bays * maxRows, [1, 2, 3, 4, 6, 8, 10, 12, 14, 16][L - 1]), rows = Math.min(maxRows, Math.ceil(n / bays) + (L >= 3 && L < 10 ? 1 : 0)), ld = rows * 4 - 1;
    for (let ly = 0; ly < ld; ly++) for (let lx = 0; lx < lw; lx++) w.ground(x0 + lx, I.y + ly, ly % 4 < 3 && lx % 3 === 0 ? Ln : T, null);
    const cols = [a, "#f4f3ee", a, "#1a1a1a", "#7fb8e6"];
    for (let k = 0; k < n; k++) car(w, x0 + 1 + k % bays * 3, I.y + Math.floor(k / bays) * 4, cols[k % cols.length]);
    if (L >= 6) {
      for (const px of [0, 3 * (bays >> 1), lw - 1]) for (const py of [0, 2]) w.add(x0 + px, I.y + py, 0, 3, "#9aa0a6");
      for (let ly = 0; ly < 3; ly++) for (let lx = 0; lx < lw; lx++) w.add(x0 + lx, I.y + ly, 3, 3.3, lx % 3 === 0 ? "#5a6a8c" : "#2b3a5c", "#9aa0a6");
    }
    if (L >= 8 && I.d - ld >= 3) for (let dx = 0; dx < Math.min(7, lw - 2); dx++) for (let dy = 0; dy < 2; dy++) {
      const X = x0 + 1 + dx, Y2 = I.y + ld + 1 + dy;
      w.add(X, Y2, 0, 0.4, "#2b2b2b");
      w.add(X, Y2, 0.4, 1.6, a);
      w.add(X, Y2, 1.6, 2.4, a, "#2b3440");
      w.add(X, Y2, 2.4, 2.8, a);
    }
    if (L >= 10 && I.d - ld >= 3 && lw >= 12) {
      const kx = x0 + lw - 3, ky = I.y + ld + 1;
      for (let dx = 0; dx < 2; dx++) for (let dy = 0; dy < 2; dy++) {
        w.add(kx + dx, ky + dy, 0, 2.4, "#ece8df", a);
        w.add(kx + dx, ky + dy, 2.4, 2.8, a);
      }
    }
  }
  function academy(w, P, L, c) {
    const { B, Y } = zones(P, 0.4), cabin = L === 1;
    const M = massing(w, B, L, { wall: cabin ? "#6f8a9a" : "#efe6d6", trim: cabin ? "#5a7282" : c.accent, roof: "#a8a49b", ribbon: L >= 5, floors: [1, 1, 1, 2, 2, 2, 3, 3, 3, 3] });
    const cw = 9, ch2 = 6, fit = Math.max(1, Math.floor((Y.w + 1) / (cw + 3))), n = Math.min(fit, [1, 1, 2, 2, 2, 3, 3, 3, 4, 4][L - 1]);
    const span = n * (cw + 3) - 3, x0 = Y.x + (Y.w - span >> 1) + 1;
    if (Y.d >= ch2 + 2) for (let k = 0; k < n; k++) cage(w, x0 + k * (cw + 3), Y.y + 1, cw, ch2, c.pitch, 0, L >= 6);
    if (L >= 9 && M.roof.w >= 7 && M.roof.d >= 5) cage(w, M.roof.x + 1, M.roof.y + 1, M.roof.w - 2, M.roof.d - 2, c.pitch, M.roofZ, false);
  }
  var SCOUTING_SITE = {
    key: "scouting",
    site: [60, 52],
    margin: 3,
    zmax: 26,
    lit: (c) => c.trial && c.trialLevel >= 5 && !c.trialConstruction || c.academy && c.academyLevel >= 6 && !c.academyConstruction,
    facilities: [
      { id: "hq", slug: "scouting_center", cost: 42e5, draw: hq },
      { id: "data", slug: "advanced_scouting", cost: 76e5, draw: data },
      { id: "trial", slug: "trial_pitch", cost: 52e5, draw: trial },
      { id: "fleet", slug: "scout_fleet", cost: 34e5, draw: fleet },
      { id: "academy", slug: "youth_academy", cost: 126e5, draw: academy }
    ]
  };
  var SCOUTING_FACILITIES = SCOUTING_SITE.facilities.map((f) => ({ id: f.id, slug: f.slug, labelKey: `facilities.scouting.${f.slug}`, cost: f.cost }));
  var SCOUTING_PLOTS = plotsFor(SCOUTING_SITE);
  var renderScouting = (c, opts = {}) => renderPlotSite(SCOUTING_SITE, c, opts);

  // scripts/facility-compositor/entry.ts
  function toSvg(image, scale) {
    const paths = image.paths.map((p) => `<path d="${p.d}" fill="${p.color}"></path>`).join("");
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${image.width} ${image.height}" width="${image.width * scale}" height="${image.height * scale}">${paths}</svg>`;
  }
  function merge(defaults, config) {
    return __spreadValues(__spreadValues({}, defaults), config != null ? config : {});
  }
  function composeStadiumSvg(config, opts, scale = 2) {
    const merged = merge(DEFAULT_STADIUM_CONFIG, config);
    const carried = merged.surrounds;
    delete merged.surrounds;
    const options = __spreadValues(__spreadValues({
      floodlightStyle: "arch",
      scoreboardPlacement: "stand"
    }, typeof carried === "string" ? { surrounds: carried } : {}), opts != null ? opts : {});
    return toSvg(renderStadium(merged, options), scale);
  }
  function composeTrainingGroundSvg(config, opts, scale = 2) {
    return toSvg(renderTrainingGround(merge(DEFAULT_TRAINING_GROUND_CONFIG, config), opts != null ? opts : {}), scale);
  }
  function composeMedicalCentreSvg(config, opts, scale = 2) {
    return toSvg(renderMedicalCentre(merge(DEFAULT_MEDICAL_CENTRE_CONFIG, config), opts != null ? opts : {}), scale);
  }
  function composeScoutingSvg(config, opts, scale = 2) {
    return toSvg(renderScouting(merge(DEFAULT_SCOUTING_CONFIG, config), opts != null ? opts : {}), scale);
  }
  var FACILITY_SITES = {
    training: {
      ids: TRAINING_FACILITIES.map((f) => f.id),
      labels: Object.fromEntries(TRAINING_FACILITIES.map((f) => [f.id, f.labelKey])),
      slugs: Object.fromEntries(TRAINING_FACILITIES.map((f) => [f.slug, f.id]))
    },
    medical: {
      ids: MEDICAL_FACILITIES.map((f) => f.id),
      labels: Object.fromEntries(MEDICAL_FACILITIES.map((f) => [f.id, f.labelKey])),
      slugs: Object.fromEntries(MEDICAL_FACILITIES.map((f) => [f.slug, f.id]))
    },
    scouting: {
      ids: SCOUTING_FACILITIES.map((f) => f.id),
      labels: Object.fromEntries(SCOUTING_FACILITIES.map((f) => [f.id, f.labelKey])),
      slugs: Object.fromEntries(SCOUTING_FACILITIES.map((f) => [f.slug, f.id]))
    },
    stadium: {
      ids: STADIUM_FACILITIES.map((f) => f.id),
      labels: Object.fromEntries(STADIUM_FACILITIES.map((f) => [f.id, f.labelKey])),
      slugs: __spreadValues({}, STADIUM_SLUGS)
    }
  };
  window.composeStadiumSvg = composeStadiumSvg;
  window.composeTrainingGroundSvg = composeTrainingGroundSvg;
  window.composeMedicalCentreSvg = composeMedicalCentreSvg;
  window.composeScoutingSvg = composeScoutingSvg;
  window.DEFAULT_STADIUM_CONFIG = DEFAULT_STADIUM_CONFIG;
  window.DEFAULT_TRAINING_GROUND_CONFIG = DEFAULT_TRAINING_GROUND_CONFIG;
  window.DEFAULT_MEDICAL_CENTRE_CONFIG = DEFAULT_MEDICAL_CENTRE_CONFIG;
  window.DEFAULT_SCOUTING_CONFIG = DEFAULT_SCOUTING_CONFIG;
  window.FACILITY_SITES = FACILITY_SITES;
})();
