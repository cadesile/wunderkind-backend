(function () {
/* ══════════════════════════════════════════════════════════════════════
   Stadium sprite compositor — mechanical plain-JS port of
   assets/Components/facilities/isoEngine.ts (the shared isometric voxel
   renderer) and stadiumSprite.ts (the stadium-specific builder), from the
   wunderkind-app sprite source. Port verbatim if the sprite's drawing logic
   ever changes — same convention as kit-compositor.js / avatar-compositor.js.

   Only the pieces stadiumSprite.ts actually imports from isoEngine.ts are
   included (shade, mix, makeWorld, finalize, paint, mast, tree) — the
   plot-based site builder (training/medical/scouting) is out of scope here.
   ══════════════════════════════════════════════════════════════════════ */

// ---------- isoEngine.ts: colour ----------

var isoColCache = new Map();
function isoHex2(n) { return Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0'); }
function isoCh(c, i) { return parseInt(c.slice(i, i + 2), 16); }

function shade(c, f) {
    var k = c + '*' + f;
    var v = isoColCache.get(k);
    if (v) return v;
    v = '#' + [1, 3, 5].map(function (i) { return isoHex2(isoCh(c, i) * f); }).join('');
    isoColCache.set(k, v);
    return v;
}

function mix(a, b, t) {
    var k = a + b + t;
    var v = isoColCache.get(k);
    if (v) return v;
    v = '#' + [1, 3, 5].map(function (i) { return isoHex2(isoCh(a, i) * (1 - t) + isoCh(b, i) * t); }).join('');
    isoColCache.set(k, v);
    return v;
}

// ---------- isoEngine.ts: world ----------

function makeWorld(W, H) {
    var cells = [];
    for (var i = 0; i < W * H; i++) cells.push([]);
    var occ = new Uint8Array(W * H);
    var gt = new Array(W * H).fill(null);
    function inb(x, y) { return x >= 0 && y >= 0 && x < W && y < H; }
    return {
        W: W, H: H, cells: cells, occ: occ, gt: gt,
        add: function (x, y, z0, z1, top, side, flag) {
            if (!inb(x, y)) return;
            cells[y * W + x].push([z0, z1, top, side || top, flag == null ? null : flag]);
            occ[y * W + x] = 1;
        },
        ground: function (x, y, c, flag) {
            if (!inb(x, y)) return;
            gt[y * W + x] = [c, flag == null ? null : flag];
            occ[y * W + x] = 1;
        },
        free: function (x0, y0, x1, y1) {
            for (var y = y0; y <= y1; y++) for (var x = x0; x <= x1; x++) if (!inb(x, y) || occ[y * W + x]) return false;
            return true;
        },
    };
}

function finalize(w, base) {
    var top = base === 'grass' ? '#7aa24a' : '#c9c4b8', earth = '#7d5f3f';
    for (var i = 0; i < w.cells.length; i++) {
        var g = w.gt[i];
        w.cells[i].push([-3, 0, g ? g[0] : top, earth, g ? g[1] : null]);
        w.cells[i].sort(function (a, b) { return a[0] - b[0]; });
    }
    return w;
}

// ---------- isoEngine.ts: paint ----------

var ISO_SEAM = 0.04;

function isoToImage(px, cw, x0, y0, w, h) {
    var byColor = new Map();
    for (var y = y0; y < y0 + h; y++) {
        var x = x0;
        while (x < x0 + w) {
            var c = px[y * cw + x];
            if (!c) { x++; continue; }
            var e = x + 1;
            while (e < x0 + w && px[y * cw + e] === c) e++;
            var rw = e - x + ISO_SEAM;
            var list = byColor.get(c);
            if (!list) { list = []; byColor.set(c, list); }
            list.push('M' + (x - x0) + ' ' + (y - y0) + 'h' + rw + 'v' + (1 + ISO_SEAM) + 'h' + (-rw) + 'z');
            x = e;
        }
    }
    var paths = [];
    byColor.forEach(function (parts, color) { paths.push({ color: color, d: parts.join('') }); });
    return { width: w, height: h, paths: paths };
}

function paint(w, rot, zmax, night, lit, crop) {
    var b = paintPixels(w, rot, zmax, night, lit, crop);
    return b.width ? isoToImage(b.px, b.stride, b.x0, b.y0, b.width, b.height) : { width: 0, height: 0, paths: [] };
}

function paintPixels(w, rot, zmax, night, lit, crop) {
    var W = w.W, H = w.H, Wr = rot % 2 ? H : W, Hr = rot % 2 ? W : H;
    var ox = (Hr - 1) * 2, oy = zmax * 2 + 2;
    var cw = (Wr + Hr - 2) * 2 + 4, chh = oy + (Wr + Hr - 2) + 10;
    var px = new Array(cw * chh).fill(null);
    function fill(x, y, rw, rh, c) {
        for (var yy = Math.max(0, y); yy < Math.min(chh, y + rh); yy++)
            for (var xx = Math.max(0, x); xx < Math.min(cw, x + rw); xx++) px[yy * cw + xx] = c;
    }
    function col(c, f) { return (!night || f === 'glow' || f === 'win') ? c : mix(c, '#141a33', (f === 'pitch' && lit) ? 0.25 : 0.62); }
    for (var s = 0; s <= Wr + Hr - 2; s++) for (var u = Math.max(0, s - Hr + 1); u <= Math.min(s, Wr - 1); u++) {
        var v = s - u;
        var x, y;
        if (rot === 0) { x = u; y = v; } else if (rot === 1) { x = v; y = H - 1 - u; } else if (rot === 2) { x = W - 1 - u; y = H - 1 - v; } else { x = W - 1 - v; y = u; }
        var segs = w.cells[y * W + x];
        if (!segs.length) continue;
        var sx = (u - v) * 2 + ox, sy = u + v + oy;
        for (var si = 0; si < segs.length; si++) {
            var seg = segs[si];
            var z0 = seg[0], z1 = seg[1], top = seg[2], side = seg[3], f = seg[4];
            if (night && f === 'win') { if ((x * 3 + y * 7 + Math.round(z0)) % 5) { top = side = '#ffd98a'; } else { f = null; } }
            var yt = Math.round(sy - z1 * 2), h = Math.round((z1 - z0) * 2), g = (f === 'glow' || f === 'win');
            if (h > 0) {
                fill(sx, yt + 2, 2, h, col(g ? side : shade(side, 0.84), f));
                fill(sx + 2, yt + 2, 2, h, col(shade(side, g ? 0.9 : 0.68), f));
            }
            fill(sx, yt, 4, 2, col(top, f));
        }
    }
    if (!crop) return { px: px, stride: cw, x0: 0, y0: 0, width: cw, height: chh };
    var x0 = cw, y0 = chh, x1 = -1, y1 = -1;
    for (var yy2 = 0; yy2 < chh; yy2++) for (var xx2 = 0; xx2 < cw; xx2++) if (px[yy2 * cw + xx2]) {
        if (xx2 < x0) x0 = xx2; if (xx2 > x1) x1 = xx2; if (yy2 < y0) y0 = yy2; if (yy2 > y1) y1 = yy2;
    }
    if (x1 < 0) return { px: px, stride: cw, x0: 0, y0: 0, width: 0, height: 0 };
    return { px: px, stride: cw, x0: x0, y0: y0, width: x1 - x0 + 1, height: y1 - y0 + 1 };
}

// ---------- isoEngine.ts: shared props used by the stadium builder ----------

function mast(w, x, y, dx, dy, h) {
    w.add(x, y, 0, h, '#a3a8ae', '#a3a8ae');
    [[0, 0], [dx, 0], [0, dy], [dx, dy]].forEach(function (p) { w.add(x + p[0], y + p[1], h, h + 2, '#d9dde2', '#fff3b0', 'glow'); });
}

function tree(w, x, y, big) {
    var leaf = '#3f8a3a', top = '#58a24b', h = big ? 5 : 4;
    w.add(x, y, 0, 2, '#6b4a2b', '#6b4a2b');
    for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) w.add(x + dx, y + dy, 2, h, top, leaf);
    w.add(x, y, h, h + 1, top, leaf);
}

// ---------- stadiumSprite.ts ----------

var STAND_SIDES = ['north', 'east', 'south', 'west'];

var DEFAULT_STADIUM_CONFIG = {
    north: 7, east: 3, south: 2, west: 5,
    northConstruction: false, eastConstruction: false, southConstruction: false, westConstruction: false,
    seat: '#c8202f', roof: '#f4f3ee',
    floodlights: true, scoreboard: true, shop: true, museum: false, carpark: true, trees: true,
    shopLevel: 2, museumLevel: 3, carparkLevel: 2, home: 2, away: 1,
    pitch: 'stripes', base: 'concrete',
};

var SS_M = 17, SS_FW = 37, SS_FH = 25, SS_IW = 41, SS_IH = 29, SS_W = 75, SS_H = 63, SS_ZMAX = 34;

var SS_GLYPHS = {
    0: '111101101101111', 1: '010110010010111', 2: '110001010100111', 3: '110001010001110', 4: '101101111001001',
    5: '111100110001110', 6: '011100111101111', 7: '111001010010010', 8: '111101111101111', 9: '111101111001110',
};

function ssIsLine(px, py) {
    if (px === 0 || px === SS_FW - 1 || py === 0 || py === SS_FH - 1 || px === 18) return true;
    if (Math.abs(Math.hypot(px - 18, py - 12) - 4) < 0.5) return true;
    var q = px < 18 ? px : SS_FW - 1 - px;
    if ((q === 6 && py >= 5 && py <= 19) || ((py === 5 || py === 19) && q <= 6)) return true;
    if ((q === 2 && py >= 9 && py <= 15) || ((py === 9 || py === 15) && q <= 2)) return true;
    return q === 4 && py === 12;
}

function ssStandFrame(side) {
    var len = (side === 'north' || side === 'south') ? SS_IW : SS_IH;
    function pos(i, r) {
        if (side === 'north') return [SS_M + i, SS_M - 1 - r];
        if (side === 'south') return [SS_M + i, SS_M + SS_IH + r];
        if (side === 'west') return [SS_M - 1 - r, SS_M + i];
        return [SS_M + SS_IW + r, SS_M + i];
    }
    return { len: len, pos: pos };
}

function ssStandConstruction(w, side) {
    var frame = ssStandFrame(side), len = frame.len, pos = frame.pos, D = 8, solid = new Set();
    function at(i, r, z0, z1, top, sd, flag) {
        if (i < 0 || i >= len || r < 0 || r >= D) return;
        var p = pos(i, r);
        w.add(p[0], p[1], z0, z1, top, sd, flag);
        if (z0 < 1) solid.add(i + ',' + r);
    }
    var P = '#9aa0a6', Wd = '#c9a063', Cn = '#b8b3a8', Y = '#f2c230', Yd = '#b88a12', Dk = '#3b3831', glass = '#a9d6e6';
    for (var i = 0; i < len; i++) for (var r = 0; r < D; r++) {
        var p2 = pos(i, r);
        w.ground(p2[0], p2[1], (i * 73 + r * 151) % 7 === 0 ? '#8a6c48' : '#9c7d56', null);
    }
    for (var i2 = 0; i2 < len; i2++) if (i2 % 6 !== 5) at(i2, 0, 0, 1.2, Math.floor(i2 / 2) % 2 ? '#f07a1a' : '#f4f3ee');
    var s0 = Math.round(len * 0.3), s1 = s0 + (len > 30 ? 14 : 11);
    for (var i3 = s0; i3 <= s1; i3++) {
        at(i3, 4, 0, 1, Cn, '#8f8a80'); at(i3, 5, 0, 4, Cn, '#8f8a80');
        [3, 6].forEach(function (r) {
            if ((i3 - s0) % 3 === 0) at(i3, r, 0, 9.5, P, P);
            else [3, 6, 9].forEach(function (z) { at(i3, r, z, z + 0.5, Wd, Wd); });
        });
    }
    var ic = Math.min(len - 2, s1 + 3), top = 22, hi = Math.min(len - 1, ic + 8);
    for (var z = 0; z < top; z += 2) at(ic, 5, z, z + 2, Y, (z / 2) % 2 ? Yd : Y);
    at(ic, 4, top - 2, top, Y, glass);
    for (var i4 = ic - 3; i4 <= Math.min(len - 1, ic + 9); i4++) at(i4, 5, top, top + 1, Y, Y);
    at(ic - 3, 5, top - 1.5, top, Cn, Cn); at(ic - 2, 5, top - 1.5, top, Cn, Cn);
    at(hi, 5, 12, top, Dk, Dk); at(hi, 5, 10.5, 12, Wd, Wd);
    var di = 2;
    for (var dx = 0; dx < 3; dx++) for (var r2 = 5; r2 <= 6; r2++) { at(di + dx, r2, 0, 0.5, Dk, Dk); at(di + dx, r2, 0.5, 2, Y, Y); }
    at(di, 6, 2, 3.5, Y, glass);
    at(di + 1, 4, 2, 2.5, Y, Y); at(di + 1, 3, 1.5, 2.5, Y, Y); at(di + 1, 2, 0.5, 1.5, Dk, Dk);
    for (var dx2 = 0; dx2 < 3; dx2++) for (var r3 = 2; r3 <= 3; r3++) at(len - 5 + dx2, r3, 0, 1.5, '#8f8a80', '#f07a1a');
    var piles = [[Math.round(len * 0.2), 2, 2.5], [Math.round(len * 0.55), 1.5, 2], [len - 8, 5, 2.2]];
    var rub = ['#8f8a80', '#a3927a', '#77705f'];
    for (var i5 = 0; i5 < len; i5++) for (var r4 = 1; r4 < D; r4++) {
        if (solid.has(i5 + ',' + r4)) continue;
        var h = 0;
        piles.forEach(function (pp) { h = Math.max(h, pp[2] - Math.hypot(i5 - pp[0], (r4 - pp[1]) * 1.3) * 0.7); });
        h = Math.round(h * 2) / 2;
        if (h > 0) at(i5, r4, 0, h, rub[(i5 * 31 + r4 * 17) % 3], '#6f675c');
    }
}

function ssStand(w, side, L, c) {
    var D = L + 1, frame = ssStandFrame(side), len = frame.len, pos = frame.pos;
    var roofed = L >= 4, rs = L >= 8 ? 1 : Math.floor(D * 0.4), hb = D + 2;
    var wall = '#e9e6df', riser = '#8f8a80', aisle = '#bdb8ac';
    for (var i = 0; i < len; i++) for (var r = 0; r < D; r++) {
        var p = pos(i, r), x = p[0], y = p[1];
        var covered = roofed && r >= rs;
        if ((covered && (i === 0 || i === len - 1)) || (r === D - 1 && L >= 3)) w.add(x, y, 0, hb, wall, wall);
        else {
            var top = i % 10 === 5 ? aisle : (r % 2 ? c.seat : shade(c.seat, 0.84));
            w.add(x, y, 0, 1 + r, top, riser);
        }
        if (covered) w.add(x, y, hb, hb + 1, c.roof, c.roof);
    }
}

function ssScoreboard(w, x0, y0, home, away) {
    var B = '#17181c', Y = '#ffd23a', G = '#8a9096';
    function digits(n) {
        var out = [];
        String(n).split('').forEach(function (chr, i) {
            if (i) out.push(null);
            out.push([chr, 0], [chr, 1], [chr, 2]);
        });
        return out;
    }
    var cols = [null].concat(digits(home), [null, 'dash', null], digits(away), [null]), n = cols.length;
    w.add(x0 + 1, y0, 0, 5, G, G); w.add(x0 + n - 2, y0, 0, 5, G, G);
    for (var c = 0; c < n; c++) for (var z = 5; z < 12; z++) {
        var j = 10 - z, cc = cols[c];
        var lit = false;
        if (j >= 0 && j < 5 && cc) lit = cc === 'dash' ? j === 2 : SS_GLYPHS[cc[0]][j * 3 + cc[1]] === '1';
        w.add(x0 + c, y0, z, z + 1, B, lit ? Y : B, lit ? 'glow' : null);
    }
}

function ssShop(w, ax, ay, seat, L) {
    var Wl = '#ece8df', glass = '#a9d6e6', door = '#3b3831';
    var bw = 3 + L * 2, d = 3 + L, h = [4, 4, 5, 6, 7][L - 1], mid = Math.floor(bw / 2);
    var x0 = ax, y0 = ay - d;
    for (var dx = 0; dx < bw; dx++) {
        for (var dy = 0; dy < d; dy++) {
            var x = x0 + dx, y = y0 + dy;
            if (dy === d - 1 && dx >= 1 && dx <= bw - 2) {
                if (dx === mid) w.add(x, y, 0, 3, door, door);
                else { w.add(x, y, 0, 1, Wl, Wl); w.add(x, y, 1, 3, glass, glass, 'glow'); }
                w.add(x, y, 3, 4, Wl, Wl);
                if (h >= 6) {
                    if (dx % 2) w.add(x, y, 4, 5.5, glass, glass, 'glow'); else w.add(x, y, 4, 5.5, Wl, Wl);
                    w.add(x, y, 5.5, h, Wl, Wl);
                } else if (h > 4) w.add(x, y, 4, h, Wl, Wl);
            } else w.add(x, y, 0, h, Wl, Wl);
            w.add(x, y, h, h + 0.5, seat, seat);
        }
        var s = dx % 2 ? seat : '#f4f3ee';
        w.add(x0 + dx, y0 + d, 3, 3.5, s, s);
    }
    if (L >= 3) for (var dx2 = mid - 1; dx2 <= mid + 1; dx2++) w.add(x0 + dx2, y0 + d - 1, h + 0.5, h + 2, seat, '#f4f3ee');
}

function ssMuseum(w, ax, ay, L) {
    var S = '#dcd3bd', Lt = '#efe9da', R = '#bfb49a', door = '#4a3f33', dome = '#8fb3a4';
    var bw = 3 + L * 2, d = 2 + L, h = 3 + L, mid = Math.floor(bw / 2);
    var x0 = ax - bw + 1, y0 = ay;
    for (var dx = 0; dx < bw; dx++) for (var dy = 0; dy < d; dy++) {
        var x = x0 + dx, y = y0 + dy;
        if (dy === d - 1 && dx === mid) { w.add(x, y, 0, 3, door, door); w.add(x, y, 3, h, S, S); }
        else w.add(x, y, 0, h, S, S);
        if (dx >= 1 && dx <= bw - 2 && dy >= 1 && dy <= d - 2) w.add(x, y, h, h + 1, R, R);
    }
    if (L >= 4) {
        var cy = y0 + Math.floor(d / 2);
        for (var dx2 = mid - 1; dx2 <= mid + 1; dx2++) for (var dy2 = -1; dy2 <= 1; dy2++) w.add(x0 + dx2, cy + dy2, h + 1, h + 2.5, dome, dome);
        w.add(x0 + mid, cy, h + 2.5, h + 3.5, dome, dome);
    }
    for (var dx3 = 0; dx3 < bw; dx3++) {
        var x2 = x0 + dx3, y2 = y0 + d;
        if (dx3 % 2 === 0) w.add(x2, y2, 0, h - 1, Lt, Lt); else w.add(x2, y2, 0, 0.5, Lt, Lt);
        w.add(x2, y2, h - 1, h, Lt, Lt);
        for (var k = 1; k <= 3; k++) if (dx3 >= k * 2 - 1 && dx3 <= bw - k * 2 && (k < 3 || L >= 4)) w.add(x2, y2, h - 1 + k, h + k, Lt, Lt);
        w.add(x2, y0 + d + 1, 0, 0.5, Lt, Lt);
    }
}

function ssCarpark(w, ax, ay, L) {
    var T = '#56585d', Ln = '#e8e6df', Cn = '#b8b3a8';
    var cols = ['#c8202f', '#f4f3ee', '#1f4fb8', '#1a1a1a', '#f2c230', '#1f8a4c', '#7fb8e6'];
    var bays = L >= 3 ? 4 : 3, rows = L === 1 ? 1 : 2, decks = L >= 5 ? 3 : (L >= 4 ? 2 : 1);
    var bw = bays * 3 + 1, bd = rows === 1 ? 4 : 10, x0 = ax - bw + 1, y0 = ay - bd + 1;
    function top(lx, ly) { return ((rows === 1 || ly <= 3 || ly >= 6) && lx % 3 === 0) ? Ln : T; }
    var pillarX = [0, Math.floor(bays / 2) * 3, bw - 1], pillarY = [0, bd - 1];
    for (var k = 0; k < decks; k++) {
        var z = k * 3;
        for (var ly = 0; ly < bd; ly++) for (var lx = 0; lx < bw; lx++) {
            if (k === 0) w.ground(x0 + lx, y0 + ly, top(lx, ly), null);
            else {
                if (pillarX.indexOf(lx) !== -1 && pillarY.indexOf(ly) !== -1) w.add(x0 + lx, y0 + ly, z - 3, z - 0.5, Cn, Cn);
                w.add(x0 + lx, y0 + ly, z - 0.5, z, top(lx, ly), Cn);
            }
        }
        for (var r = 0; r < rows; r++) for (var b = 0; b < bays; b++) {
            if ((b + r * 2 + k * 3) % 5 === 3) continue;
            var col = cols[(b + r * 3 + k * 2) % cols.length];
            var bx = x0 + 1 + b * 3, by = y0 + (r === 0 ? 0 : bd - 3);
            for (var dx = 0; dx < 2; dx++) for (var dy = 0; dy < 3; dy++) {
                w.add(bx + dx, by + dy, z, z + 1, col, col);
                if (dy === 1) w.add(bx + dx, by + dy, z + 1, z + 1.5, shade(col, 1.08), '#2b3440');
            }
        }
    }
}

function ssTrees(w) {
    var spots = [];
    for (var x = 3; x < w.W - 3; x += 6) { spots.push([x, 2]); spots.push([x, w.H - 3]); }
    for (var y = 8; y < w.H - 6; y += 6) { spots.push([2, y]); spots.push([w.W - 3, y]); }
    spots.forEach(function (p) {
        var x = p[0], y = p[1];
        if (w.free(x - 1, y - 1, x + 1, y + 1)) tree(w, x, y, (x * 7 + y * 13) % 3 === 0);
    });
}

function buildStadium(c) {
    var w = makeWorld(SS_W, SS_H), fx = SS_M + 2, fy = SS_M + 2;
    for (var y = SS_M; y < SS_M + SS_IH; y++) for (var x = SS_M; x < SS_M + SS_IW; x++) {
        var px = x - fx, py = y - fy;
        var col = '#4a8531';
        if (px >= 0 && py >= 0 && px < SS_FW && py < SS_FH) {
            if (ssIsLine(px, py)) col = '#eef0e8';
            else {
                var a = Math.floor(px / 3), b = Math.floor(py / 3);
                var alt = c.pitch === 'stripes' ? a % 2 : (c.pitch === 'checks' ? (a + b) % 2 : 0);
                col = alt ? '#529538' : '#5da53e';
            }
        }
        w.ground(x, y, col, 'pitch');
    }
    [fx - 1, fx + SS_FW].forEach(function (gx) {
        for (var py = 10; py <= 14; py++) {
            if (py === 10 || py === 14) w.add(gx, fy + py, 0, 2, '#f4f3ee', '#f4f3ee');
            w.add(gx, fy + py, 2, 2.5, '#f4f3ee', '#f4f3ee');
        }
    });
    var maxL = 1;
    STAND_SIDES.forEach(function (id) {
        if (c[id + 'Construction']) ssStandConstruction(w, id);
        else ssStand(w, id, c[id], c);
        maxL = Math.max(maxL, c[id]);
    });
    if (c.floodlights) {
        var h = Math.round((14 + maxL * 1.6) * 0.7);
        mast(w, SS_M - 2, SS_M - 2, 1, 1, h); mast(w, SS_M + SS_IW + 1, SS_M - 2, -1, 1, h);
        mast(w, SS_M - 2, SS_M + SS_IH + 1, 1, -1, h); mast(w, SS_M + SS_IW + 1, SS_M + SS_IH + 1, -1, -1, h);
    }
    if (c.scoreboard) ssScoreboard(w, 5, 1, c.home, c.away);
    if (c.museum) ssMuseum(w, SS_W - 2, 1, c.museumLevel);
    if (c.shop) ssShop(w, 2, SS_H - 2, c.seat, c.shopLevel);
    if (c.carpark) ssCarpark(w, SS_W - 2, SS_H - 2, c.carparkLevel);
    if (c.trees) ssTrees(w);
    return finalize(w, c.base);
}

function renderStadium(c, opts) {
    opts = opts || {};
    var rot = opts.rotation || 0, night = opts.time === 'night';
    return paint(buildStadium(c), rot, SS_ZMAX, night, !!c.floodlights, false);
}

/**
 * @param config Partial<StadiumConfig> — merged over DEFAULT_STADIUM_CONFIG
 * @param opts { rotation: 0|1|2|3, time: 'day'|'night' }
 * @param scale on-screen px per sprite pixel
 */
function composeStadiumSvg(config, opts, scale) {
    scale = scale || 2;
    var merged = Object.assign({}, DEFAULT_STADIUM_CONFIG, config || {});
    var image = renderStadium(merged, opts || {});
    var rects = image.paths.map(function (p) {
        return '<path d="' + p.d + '" fill="' + p.color + '"></path>';
    }).join('');
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + image.width + ' ' + image.height + '" width="' + (image.width * scale) + '" height="' + (image.height * scale) + '">' + rects + '</svg>';
}

window.composeStadiumSvg = composeStadiumSvg;
window.DEFAULT_STADIUM_CONFIG = DEFAULT_STADIUM_CONFIG;
})();
