# Backend-Owned Appearance (Avatar) — Design

**Date:** 2026-07-13
**Status:** Approved (design phase)

## Goal

Move avatar/appearance ownership from the frontend into the backend. Every pool
entity that renders an avatar (`Player`, `Staff`, `Scout`, `Agent`) gets a stored
`appearance` object that is:

1. **Generated on the backend** (a faithful port of the frontend
   `generateAppearance()`), so it exists the moment an entity is created.
2. **Editable in the admin** with per-attribute dropdowns and a **live SVG
   preview**.
3. **Returned on every player/staff/market payload** in a shape that is a
   **drop-in replacement** for the frontend's existing `player.appearance`.

### The overriding constraint: trivial frontend transition

The emitted `appearance` object MUST match the frontend `Appearance` type
(`wunderkind-app/src/types/player.ts:74-101`) **field-for-field, value-for-value**.
The frontend change is then only:

- **Before:** `generateAppearance(id, role, age, personality)` is called
  client-side (`useInitFlow.ts:96,132,157`) and the result stored on the entity.
- **After:** read `payload.appearance` straight off the incoming object and store
  it. `parseAppearance` in `playerMapper.ts` already accepts this shape. The
  client-side generator becomes dead code (can be deleted in a later app PR).

No compositor changes, no mapper reshaping, no coordination on field names. If the
backend ever omits `appearance` (e.g. legacy row not yet backfilled), the frontend
keeps its existing fallback (`composeAvatarSvg(null, ...)`), so the rollout is safe
either direction.

## Storage

Add a **nullable `json` column `appearance`** to `player`, `staff`, `scout`,
`agent`. Mapped as `?array $appearance` with a plain getter/setter on each entity.

Rationale for a single `json` column over a Doctrine Embeddable:

- Avoids ~10 columns × 4 tables ≈ 40 new columns.
- Snapshot builders become a one-line passthrough (`'appearance' => $e->getAppearance()`).
- Validation lives in the enums + custom form type instead of column types.

The backend DB representation is **independent** of the frontend's
`players.appearance_data` TEXT column. The snapshot JSON is the contract; the
storage shape only needs to round-trip cleanly.

### Stored shape (the 10 rendered fields only)

```json
{
  "skinTone":      "#dfaa80",
  "hairStyle":     "messy",
  "hairColor":     "dark_brown",
  "accessory":     null,
  "kitTrim":       "#3a8fd4",
  "facialHair":    "none",
  "faceShape":     "oval",
  "eyeShape":      "narrow",
  "noseType":      "normal",
  "jerseyVariant": 2
}
```

The three dead fields (`expression`, `earSize`, `eyebrowStyle`) are **omitted** —
the frontend compositor never reads them and accepts their absence.

## Enums — single source of truth

New backed enums under `src/Enum/Appearance/`, used by **both** the generator's
random picks and the admin dropdowns so they cannot drift:

| Enum | Cases |
|---|---|
| `SkinTone` (string, hex) | `#f5dcc8 #e8c49a #dfaa80 #c47d4a #8b4c1e #5c2d0a` |
| `HairStyle` | `bald classic messy round smart spike usual` |
| `HairColor` | `black dark_brown brown light_brown blonde` |
| `AvatarAccessory` | `glasses sunglasses whistle headset beanie face_tattoo neck_tattoo` (nullable → "none" in UI) |
| `FacialHair` | `none stubble moustache goatee beard fench_2 french_smile` |
| `FaceShape` | `oval round square` |
| `EyeShape` | `narrow round` |
| `NoseType` | `normal small downside_large medium upside_large` |

Non-enum fields:

- `jerseyVariant` — `int` 1–12 (only 1–3 have art; 4–12 fall back to 1 on the
  frontend, so no backend clamp needed).
- `kitTrim` — free hex string. Palette suggestions surfaced in the admin UI:
  - PLAYER (vibrant): `#f5c842 #e8852a #3a8fd4 #d94040 #2eab5a #9b59b6`
  - COACH/SCOUT/AGENT (muted): `#4a5568 #2d3748 #374151 #1e3a5f`

## `AppearanceGeneratorService`

A faithful PHP port of `wunderkind-app/src/engine/appearance.ts`.

- **`hashId(string): int`** — djb2 variant, kept to 32-bit unsigned
  (`& 0xFFFFFFFF`).
- **`SeededRng`** — LCG (`1664525`, `1013904223`), 32-bit unsigned. `Math.imul`
  emulated as `($a * $b) & 0xFFFFFFFF` (safe on 64-bit PHP ints). `next()` returns
  a float in `[0,1)`; `pick()` / `chance()` mirror the JS helpers.
- **`generate(string $id, AppearanceRole $role, int $age): array`** — reproduces
  the JS distributions exactly:
  - Hair style pool skews to `smart`/`bald` for older staff (`age>45`, `age>35`).
  - Hair color skews `dark_brown` for older coaches/scouts.
  - Accessories gated by role (whistle/headset staff-only; rare tattoos for
    players `age>=20`).
  - Kit trim from player-vibrant vs staff-muted palette.
  - Facial hair always `none` for players; staff `age>=20` skew.
  - Nose from `[normal, normal, small]`; jersey 1–3.
- `personality` is **not** a parameter — it only ever fed the dead `expression`
  field.

Bit-exact parity with the JS output is **not required** (the backend is now
authoritative); determinism from `(id, role, age)` is. The algorithm is ported
straight across regardless.

`AppearanceRole` is a backend enum `PLAYER | COACH | SCOUT | AGENT`. Age is derived
from `dateOfBirth`/`dob`; when null (staff/scout/agent may have null `dob`) a
sensible default age is used so the distributions still resolve.

### Generation hook points

- `PlayerGenerationService::generate()` → role `PLAYER`.
- Staff/Scout/Agent construction in `GenerateMarketDataCommand` and
  `MarketPoolService` → respective role.

Every newly-created entity is persisted with a populated `appearance`.

## Serialization — appearance flows out everywhere

| Site | Change |
|---|---|
| `WorldInitializationService::buildPlayerSnapshot()` | add `'appearance' => $player->getAppearance()` |
| `WorldInitializationService::buildStaffSnapshot()` | add `'appearance' => $staff->getAppearance()` |
| `MarketDataService::serializeCoach()` | add `'appearance'` |
| `MarketDataService::serializeScout()` | add `'appearance'` |
| `MarketDataService::serializeAgent()` | add `'appearance'` |

`buildPlayerSnapshot` covers the worldpack cache, tier packs, starter pack, and
`/api/market/assign` snapshot paths in one edit.

## Admin editor with live preview

A custom Symfony compound form type **`AppearanceType`** (`src/Form/Type/`):

- Child fields: `skinTone`, `hairStyle`, `hairColor`, `accessory`, `facialHair`,
  `faceShape`, `eyeShape`, `noseType` as `ChoiceType` (choices from the enums —
  `skinTone` is a dropdown of the 6 fixed swatches); `kitTrim` as a free hex color
  input with the role palette shown as suggestions; `jerseyVariant` as an
  integer/choice (1–12).
- Mapped to the entity's `appearance` array via a model transformer
  (child-field ↔ associative array). Nullable `accessory` maps `"none" ↔ null`.
- Wired into the CRUD controllers with
  `Field::new('appearance')->setFormType(AppearanceType::class)` — following the
  existing `ScoutCrudController` `setFormType(TextareaType::class)` precedent.

**Live preview:** a custom form-theme template renders the dropdowns plus a
preview `<div>` (placed in the red-boxed area of the edit form). The **actual
frontend compositor** is reused:

- `wunderkind-app/src/utils/avatarCompositor.ts` +
  `wunderkind-app/src/assets/avatarSvgLayers.ts` are copied to
  `public/admin/avatar-compositor.js` as a plain browser script (they are pure
  string manipulation — no React-Native dependencies).
- An inline script reads the child-field values, re-runs `composeAvatarSvg` on
  every `change`/`input`, and injects the resulting SVG into the preview. Fixed
  morale `70` and a neutral default kit color are used for the preview.

Added to the `Player`, `Staff`, and `Scout` edit forms.

**Agent edit form is out of scope.** `AgentCrudController` currently disables
`NEW`/`EDIT`/`DELETE`. Agents still receive generated + serialized appearance;
wiring the editor there would require enabling the edit form and is deferred as an
optional follow-up.

## Migration + backfill

- One Doctrine migration adding the four nullable `appearance` json columns, using
  the Schema API / Postgres-safe SQL (no `AUTO_INCREMENT`, no `ENGINE`).
- A `app:backfill-appearances` console command generates appearances for existing
  pool rows through `AppearanceGeneratorService`, so pre-existing data isn't stuck
  with null appearances.

## Out of scope

- Agent admin **edit** form (generation + serialization for Agent still included).
- Deleting the now-redundant frontend `generateAppearance()` call sites — a
  follow-up change in the app repo once the backend ships.
- Any change to the frontend compositor or mapper.

## Testing

- `AppearanceGeneratorService`: determinism (same `(id, role, age)` → identical
  output), role gating (players never get whistle/headset/facial-hair; staff-only
  accessories), all emitted values are members of the enums / allowed ranges.
- `AppearanceType`: round-trips array ↔ form model, including `accessory`
  none↔null.
- Snapshot builders / market serializers include a correctly-shaped `appearance`
  key.
- Migration up/down.
