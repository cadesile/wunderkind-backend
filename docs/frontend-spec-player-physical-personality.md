# Frontend Spec — Player Physical & Personality Matrix

## Overview

Every player object returned by the API includes `height`, `weight`, and a `personality` sub-object.
These values are generated server-side and sent to the client — the client never calculates them.

---

## 1. Physical — Height & Weight

### API Shape

```json
{
  "height": 158,
  "weight": 47
}
```

### Ranges (from PoolConfig defaults)

| Field    | Unit | Min | Max | Notes                              |
|----------|------|-----|-----|------------------------------------|
| `height` | cm   | 145 | 160 | Youth academy range — grows on-device |
| `weight` | kg   | 38  | 55  | Youth academy range — grows on-device |

> These ranges reflect youth players (ages ~13–17). Display as-is; do not convert unless the user's locale preference is imperial, in which case apply:
> - Height: `cm × 0.0328` → feet (e.g. 158 cm → 5'2")
> - Weight: `kg × 2.205` → lbs (e.g. 47 kg → 104 lbs)

### Display Guidelines

- Always show both together in a **Physical** section or card row
- Use abbreviated units: `158 cm`, `47 kg`
- Both are integers — no decimal display needed
- Neither value changes via sync; growth/aging is handled on-device

---

## 2. Personality Matrix

### API Shape

```json
{
  "personality": {
    "determination":   14,
    "professionalism": 11,
    "ambition":        8,
    "loyalty":         17,
    "adaptability":    12,
    "pressure":        9,
    "temperament":     15,
    "consistency":     13
  }
}
```

### Scale

| Property | Min | Max | Default | Generation range (pool) |
|---|---|---|---|---|
| `determination`   | 1 | 20 | 10 | 6–18 |
| `professionalism` | 1 | 20 | 10 | 6–18 |
| `ambition`        | 1 | 20 | 10 | 4–17 |
| `loyalty`         | 1 | 20 | 10 | 6–18 |
| `adaptability`    | 1 | 20 | 10 | 5–17 |
| `pressure`        | 1 | 20 | 10 | 4–16 |
| `temperament`     | 1 | 20 | 10 | 5–17 |
| `consistency`     | 1 | 20 | 10 | 6–18 |

- Scale is **1–20** (integer, never 0, never >20)
- Server clamps writes to [1, 20] — treat any value outside that range as a data error
- Traits are **not** synced back from the client — they are read-only from the frontend's perspective (set on generation, mutated server-side only)

### Trait Descriptions

| Trait | What it represents |
|---|---|
| `determination`   | Drive to improve and push through setbacks |
| `professionalism` | Attitude in training, punctuality, diet discipline |
| `ambition`        | Desire to reach the highest level |
| `loyalty`         | Resistance to leaving for rival clubs or better offers |
| `adaptability`    | How quickly the player adjusts to new roles, teams, cultures |
| `pressure`        | Performance under high-stakes situations |
| `temperament`     | Emotional control — low = volatile, high = composed |
| `consistency`     | Ability to replicate peak performance week-to-week |

### Recommended Display — Radar / Spider Chart

The 8 traits are designed to be rendered as an **octagonal spider/radar chart**.

```
Axes (clockwise from top):
  determination → professionalism → ambition → loyalty →
  adaptability → pressure → temperament → consistency
```

**Chart config:**

```ts
const PERSONALITY_AXES = [
  { key: 'determination',   label: 'DET' },
  { key: 'professionalism', label: 'PRO' },
  { key: 'ambition',        label: 'AMB' },
  { key: 'loyalty',         label: 'LOY' },
  { key: 'adaptability',    label: 'ADP' },
  { key: 'pressure',        label: 'PRS' },
  { key: 'temperament',     label: 'TMP' },
  { key: 'consistency',     label: 'CON' },
] as const;

const MIN = 1;
const MAX = 20;
```

**Normalise for rendering:**

```ts
// Map [1, 20] → [0, 1] for chart libraries that expect a 0–1 unit scale
const normalise = (v: number) => (v - 1) / (MAX - 1);
```

### Alternative Display — Stat Bar List

If a radar chart is not appropriate (e.g. compact card view), render as a vertical list of labelled bars:

```
Determination    ██████████████░░░░░░  14 / 20
Professionalism  ██████████░░░░░░░░░░  11 / 20
Ambition         ███████░░░░░░░░░░░░░   8 / 20
...
```

Bar fill: `(value / 20) * 100` as a percentage width.

### Colour Banding (optional)

Apply a subtle colour tint to the bar or radar fill based on value:

| Range | Colour suggestion | Meaning   |
|-------|------------------|-----------|
| 1–6   | Red / amber      | Weak      |
| 7–12  | Grey / neutral   | Average   |
| 13–17 | Blue / teal      | Good      |
| 18–20 | Gold / green     | Elite     |

---

## 3. Endpoints That Return These Fields

| Endpoint | Context |
|---|---|
| `POST /api/initialize` | AMP starter player + all NPC club players |
| `GET /api/market-data` | Pool players + prospects |
| `GET /api/squad` | Active squad players for the authenticated club |

All three return an identical shape for `height`, `weight`, and `personality`.
