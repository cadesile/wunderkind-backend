// Builds public/images/world-pixel.png, the homepage plot map's land layer.
//
// Natural Earth land (public domain, via the world-atlas package) is sampled on a plain
// longitude/latitude grid: one pixel per CELL degrees, so the PNG is tiny and the page scales it
// up with `image-rendering: pixelated` to get the chunky pixel-art look. Because the grid IS an
// equirectangular projection, the page places a town at
//   x = (lng - WEST) / (EAST - WEST),  y = (NORTH - lat) / (NORTH - SOUTH)
// with no map library at all. Keep the bounds below in step with WORLD_MAP_BOUNDS in
// templates/landing/sections/_plot_map.html.twig.
//
// Run from this folder:  npm ci && node build-world-pixel.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { feature } from 'topojson-client';
import { PNG } from 'pngjs';

const require = createRequire(import.meta.url);
const topo = JSON.parse(readFileSync(require.resolve('world-atlas/land-50m.json'), 'utf8'));
const land = feature(topo, topo.objects.land);

const WEST = -180, EAST = 180, NORTH = 84, SOUTH = -58; // Antarctica cropped off
const CELL = 0.75;
const cols = Math.round((EAST - WEST) / CELL);
const rows = Math.round((NORTH - SOUTH) / CELL);

// Scanline fill in plain lng/lat, ring by ring, XOR-ing each ring's spans so lakes punch
// holes. A ring that crosses the antimeridian (the quantised topology has a few) is unwrapped
// first, so its longitudes run past +-180 and the spans wrap round the grid instead of
// striping the whole row. geoContains per cell is exact but takes minutes; this is instant.
const rings = [];
for (const f of land.features) {
  const polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
  for (const poly of polys) {
    for (const ring of poly) {
      const out = [ring[0].slice()];
      for (let i = 1; i < ring.length; i++) {
        let x = ring[i][0];
        const prev = out[i - 1][0];
        while (x - prev > 180) x -= 360;
        while (prev - x > 180) x += 360;
        out.push([x, ring[i][1]]);
      }
      rings.push(out);
    }
  }
}
const isLand = new Uint8Array(cols * rows);
for (let r = 0; r < rows; r++) {
  const lat = NORTH - (r + 0.5) * CELL;
  for (const ring of rings) {
    const xs = [];
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [x1, y1] = ring[j], [x2, y2] = ring[i];
      if ((y1 > lat) !== (y2 > lat)) xs.push(x1 + ((lat - y1) / (y2 - y1)) * (x2 - x1));
    }
    if (xs.length < 2) continue;
    xs.sort((a, b) => a - b);
    for (let k = 0; k + 1 < xs.length; k += 2) {
      const c0 = Math.ceil((xs[k] - WEST) / CELL - 0.5);
      const c1 = Math.floor((xs[k + 1] - WEST) / CELL - 0.5);
      for (let c = c0; c <= c1; c++) isLand[r * cols + (((c % cols) + cols) % cols)] ^= 1;
    }
  }
}

// Landing palette: --card for land, --mint-dim for the coast so the outline reads as a
// pixel-art edge. Sea stays transparent and takes the panel background from CSS.
const LAND = [0x1d, 0x5c, 0x52];
const COAST = [0x2d, 0x8a, 0x63];
const at = (r, c) => (r < 0 || r >= rows ? 0 : isLand[r * cols + ((c + cols) % cols)]);

const png = new PNG({ width: cols, height: rows });
for (let r = 0; r < rows; r++) {
  for (let c = 0; c < cols; c++) {
    const i = (r * cols + c) * 4;
    if (!at(r, c)) { png.data[i + 3] = 0; continue; }
    const coast = !at(r - 1, c) || !at(r + 1, c) || !at(r, c - 1) || !at(r, c + 1);
    const [R, G, B] = coast ? COAST : LAND;
    png.data[i] = R; png.data[i + 1] = G; png.data[i + 2] = B; png.data[i + 3] = 255;
  }
}

const out = new URL('../../public/images/world-pixel.png', import.meta.url);
writeFileSync(out, PNG.sync.write(png, { colorType: 6 }));
console.log(`world-pixel.png ${cols}x${rows}, bounds W${WEST} E${EAST} N${NORTH} S${SOUTH}`);
