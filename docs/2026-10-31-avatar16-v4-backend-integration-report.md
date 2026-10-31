# AvatarSprite V4 — backend integration report

**From:** `wunderkind-backend` (PHP/Symfony API + admin + marketing site)
**Drop:** `rn/avatar16/` V4, 2026-10-31
**Status:** integrated, on `dev`

> **This is not the app's report.** `AvatarSprite-integration-prompt.md` is addressed to
> `wunderkind-app` and its `src/components/avatar-sprite/` destination. This repo is a
> second host: it renders the same sprite server-side-ish (as inlined SVG) for the admin
> avatar editor and the marketing site. It consumes `avatar16.ts` **read-only** from the
> app checkout and never writes to it.

---

## 1. Files changed

Nothing was copied into this repo by hand. `avatar16.ts` is pulled from
`wunderkind-app/src/components/avatar-sprite/avatar16.ts` at build time and passed through
esbuild (type-stripping only) into a browser bundle, so the "port it verbatim" rule is
mechanised rather than trusted.

| File | What |
|---|---|
| `public/assets/avatar-compositor.js` | **GENERATED**, rebuilt. The bundle the admin and landing page load. |
| `scripts/build-avatar-compositor.sh` | Unchanged — already pointed at the app's copy. |
| `scripts/avatar-compositor/entry.ts` | The only hand-written part of the bundle. Now splits the stored flat blob and forwards `extras`; also exposes `window.AVATAR_SPRITE_OPTIONS`. |
| `src/Enum/Appearance/{HairStyle,Face,Outfit}.php` | Extended 9→16, 9→15, 4→8 to mirror `HAIR_STYLES` / `FACES` / `OUTFITS` exactly, **in order**. |
| `src/Enum/Appearance/{Headwear,Neckwear,Tattoo}.php` | New, mirroring `HEADWEAR` / `NECKWEAR` / `TATTOOS`. `Tattoo::visibleFor()` is a port of `visibleTattoos()`. |
| `src/Service/Appearance/AppearanceGeneratorService.php` | Weighted `face`/`outfit` picks; rolls `AvatarExtras`. |
| `src/Form/Type/AppearanceType.php` | Seven new fields for the extras. |
| `templates/admin/field/appearance.html.twig`, `public/assets/appearance-widget.js`, `public/admin-appearance-widget.css` | Accessories / Headwear / Neckwear / Tattoos sections in the admin editor. |
| `tests/…` | Parity, generation and rendered-page assertions. |

## 2. Parity

**134/134.** Enforced inside the build script, which refuses to write the bundle on a
mismatch — so a drifted source cannot ship, rather than failing later in CI.

The 16 V3.1 cases are unchanged, which matched your "nothing moves by default" claim: no
existing stored avatar renders differently.

## 3. The `cropRect` difference

**Not applicable here**, and worth saying explicitly: this repo holds no copy of
`avatar16.ts` to patch. It takes the app's file as-is.

The two adaptations live in the hand-written `entry.ts` and are additive, not edits to your
drawing code:

- **Crop aliases.** `composePlayerSpriteSvg()` keeps a pre-16-bit signature in which
  `'small'`/`'large'` meant head/full. They are mapped to `'head'`/`'full'`; `arm` and `leg`
  pass straight through and are used for the tattoo picker tiles.
- **`SCALE_RATIO = 0.5`**, so pre-16-bit call sites keep their on-screen size now the grid
  is 2× per axis. Mirrors the app's own ratio in `PlayerSprite.tsx`.

Head anchoring is entirely yours — nothing here recomputes it.

## 4. Part B answers

**Persisting `AvatarExtras`: stored FLAT, in the existing `appearance` json column.** No
migration, no sibling field. The reasoning is your own API: the web builder's `onChange`
emits one flat object and you ship `splitAvatarConfig()` to take it apart, so the most
interoperable thing this backend can store is exactly that object. A stored blob can be
handed straight to `splitAvatarConfig()`, and the compositor seam does precisely that, so
the admin editor and the marketing site both got extras with no call-site change.

Verified, not assumed: a pre-V4 blob splits to empty extras and renders byte-identically.

**Morale source: deliberately not wired.** `morale` is never passed. Two reasons, and we'd
rather state them than leave you guessing:

1. These avatars are **stored identity sprites**, generated once and persisted. A face that
   changes with mood is a live-render concept, and this host has no live render.
2. The only 0–100 morale this backend holds is `Club.morale`, which is **fan** morale, not a
   person's. Feeding it to a face would be wrong data, confidently displayed.

A test asserts the omission so it reads as a decision rather than an oversight. If a
per-person morale ever lands here, the ladder is a one-line change at the seam.

**Builder UI: built, with your visibility rule.** The admin editor now offers accessories,
headwear/neckwear (staff) and tattoos. The tattoo grid hides ink the outfit would cover, via
the `visibleTattoos()` port, and rebuilds when the outfit changes — so a staff member shows
the forearm pair only in `polo` and `shirt`.

**Randomisers: yes, but AGE-GATED — and this is the one thing worth your attention.**

Your published rates (earring 25%, armband 10%, wristband 20%, snood 10%, headwear 30%,
neckwear 50%, each tattoo 15%) assume an adult subject. This is a **youth-academy game**:
the default pool config generates players at **12–13 years old**. Six independent 15% rolls
would have inked roughly **62% of twelve-year-olds**.

So tattoos and earrings are gated to 18+ here; kit accessories (armband, wristband, snood)
are not, since a wristband on a youth player is ordinary. Staff are adults and take your
rates as published.

**Not a complaint about the drop** — the rates are right for the builder. But if other hosts
have youth subjects, a note in the drop that the randomiser rates assume an adult would save
them finding it the way we did.
