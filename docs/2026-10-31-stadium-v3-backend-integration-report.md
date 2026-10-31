# StadiumSprite v3 — backend integration report

**From:** `wunderkind-backend` (PHP/Symfony API + admin + marketing site)
**Drop:** `rn/stadium-v3/`, 2026-10-31
**Status:** integrated, on `dev`

> **This is not the app's report.** `StadiumSprite-integration-prompt.md` is addressed to
> `wunderkind-app` and its `src/components/facility-sprites/` destination. This repo is a
> second host: it renders the stadium (and the other three sites) as inlined SVG on the
> marketing site. It consumes the sprite modules **read-only** from the app checkout and
> never writes to it.

---

## 1. Files changed, and the destination path

There is no destination path in the sense the prompt means — nothing is copied here. The
modules are read from `wunderkind-app/src/components/facility-sprites/` at build time and
passed through esbuild (type-stripping only), so "verbatim" is mechanised.

| File | What |
|---|---|
| `public/assets/facility-compositor.js` | **GENERATED**, rebuilt. All four sites in one bundle (isoEngine is ~32KB and shared). |
| `src/Service/Facility/FacilitySpriteMap.php` | **GENERATED** by the same script. Source sha changed; the 22-slug mapping is byte-identical. |
| `scripts/build-facility-compositor.sh` | Source paths and the parity gate — see §5. |
| `scripts/facility-compositor/entry.ts` | The only hand-written part. The single seam (§3). |
| `src/Service/Facility/FacilityConfigBuilder.php` | Derives `surrounds` per club. |
| `templates/landing/base.html.twig` | Asset cache-buster bumped. |
| `tests/…` | The derivation rule and its tier inversion. |

**Deleted:** `scripts/build-stadium-compositor.sh`, `scripts/stadium-compositor/entry.ts`
and `public/assets/stadium-compositor.js` — a stadium-only bundle from before this host
rendered the other three sites. Nothing had loaded it for a while, the two emitted
byte-identical `composeStadiumSvg` output, and keeping it meant applying every drop twice.

## 2. Parity

**432/432**, and the gate refuses to write the bundle on a mismatch.

| Set | Result |
|---|---|
| `stadium-v3.parity.json` → `cases` | **131/131** |
| `stadium-v3.parity.json` → `v2Look` | **63/63** |
| `parity.json` → training / medical / scouting | **238/238** |
| `stadium-v3.geometry.json` | exported constants deep-checked |

Nothing drifted. `v2Look` passing is the result we cared about most — it is the evidence
that v3 left alone what the changelog said it would.

## 3. Where the render options are set (the single seam)

`composeStadiumSvg()` in `scripts/facility-compositor/entry.ts`. Every backend stadium goes
through it: the Club Spotlight, the Rich List leader card and the Facilities section.

```ts
const options = {
  floodlightStyle: 'arch',        // restated, not inherited
  scoreboardPlacement: 'stand',   // restated, not inherited
  ...(carriedSurrounds ? { surrounds: carriedSurrounds } : {}),
  ...(opts ?? {}),
};
```

Both look options are set **explicitly even though they are v3's own defaults**, per A2 —
an option left implicit lets a future drop change what this host renders without a line of
this repo changing. `STADIUM_V2_LOOK` remains spreadable at a call site to pin one surface.

## 4. Part B answers

**Where `surrounds` is persisted: nowhere — it is DERIVED, so there is no schema change.**
It comes from the club's **league tier**, the one signal this backend already holds that
says anything about a club's setting:

| Tier | Variant |
|---|---|
| 1–2 (top flight) | `city` |
| 3–4 | `suburban` |
| 5–6 | `terraces` |
| 7–8 (entry level) | `industrial` |
| no league | `parkland` |

Climbing the pyramid moves a club from an industrial fringe to a city centre. Note tier 1 is
the *top* division and 8 is where new clubs start, so the ladder reads downwards; a test
pins that direction, because inverting it would put brand-new clubs in a city centre.

`parkland` for an unplaced club is deliberate: it **is** your v2 trees-only look, so a club
we know nothing about renders as it did before v3 rather than as someone else's city.

One wrinkle worth flagging, since it is a consequence of A1 rather than a complaint:
`StadiumConfig` is frozen and `surrounds` is a render option, but this backend still has to
get a per-club value from PHP to the browser. It therefore travels **inside the stadium json
blob** (no column for a derived value) and the compositor **lifts it out into the options and
deletes it from the config**, so what reaches your renderer is exactly a `StadiumConfig`. An
explicit option always wins, and a payload without the key falls through to `parkland`.

**A picker for the five variants: not built.** The value is derived, not authored, so there
is nothing for an operator to choose. If clubs should own their surroundings, that becomes a
real column and an admin control, and we would want to agree the migration first.

**Which screens keep the v2 look: none.** All adopt v3.

**Randomisers: not applicable** — nothing here rolls a variant.

## 5. Two notes on the fixtures

Both cost us real debugging time, so they may be worth a line in the next drop.

**The two fixture generations shape render options differently.** The v2 four-site
`parity.json` puts `rotation` / `time` / `selected` / `facility` **flat on the case**, while
`stadium-v3.parity.json` nests everything under `options`. Our gate cherry-picked the four
known keys, which silently passed the flat cases and then rendered **193 cropped facility
tiles as whole sites** once pointed at the new shape — a loud failure, luckily, but only
because the expected sizes differed. Forwarding whatever is there wholesale fixes it. A
consistent shape across drops would remove the trap.

**`v2Look` is identical to the old fixture's `stadium` map.** We verified key-for-key and
hash-for-hash: all 63 match. So a host holding both fixtures can skip the old `stadium`
block entirely, which is what we now do. Worth stating in the drop, since it is not obvious
that the two are the same 63 cases rather than merely similar.

## 6. Also in this pass

The module rename (`*Sprite.ts` → `*Config.ts`, plus the new `stadiumSurrounds.ts`) and the
disappearance of the old standalone drop directory meant both our build scripts' default
source paths pointed at nothing. Not a problem — but a drop that renames files is worth a
line in the changelog, because a build script that silently reads a stale path is a worse
failure than one that errors.
