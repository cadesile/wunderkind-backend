# `packVersion` — which generation of the world a device holds

**Added 2026-11-15.** Additive on two surfaces. No key removed, renamed or re-meant.

## The problem it solves

The world pack's version existed only as a column on the server's cache row
(`country_world_pack_cache.payload_version`). The server used it to decide whether to rebuild;
**the client was never told.** A pack built before a calibration was indistinguishable from one
built after it.

That was not hypothetical. On 2026-11-15 the dev box held 60 packs with turnover wages,
re-authored TV money and parachutes, and 140 still carrying the pre-calibration economy — and
nothing in the payload said which you had. A player reporting "my TV money looks wrong" could
not be placed on either side of it.

## Where it appears

| Surface | Key | What the number means |
|---|---|---|
| `POST /api/initialize/league/{tier}` | `packVersion`, top level of the league pack | **The version this pack was built at.** A fact about the payload in your hand. |
| `GET /api/initialize/leagues` | `packVersion`, sibling of `leagues` | **The version the server builds at**, i.e. what the next tier fetch will stamp. |

```json
// POST /api/initialize/league/8
{ "packVersion": 5, "id": "…", "tier": 8, "country": "EN", "tvDeal": 495000, "clubs": [ … ] }

// GET /api/initialize/leagues
{ "leagues": [ … ], "packVersion": 5 }
```

### ⚠️ The two can legitimately disagree

They are not the same fact. During a regeneration, `/leagues` reports **5** while a tier pack
that has not yet been rebuilt still returns **4**. The honest reading is *"the packs you are
about to fetch will be rebuilt"* — not that anything is broken, and not that you already hold 5.

`/api/initialize/leagues` reads no cache, so its figure cannot describe anything you were
served. If you need to know what a specific world was built from, read the tier pack's own key.

## How to use it

1. **Store it with the save** and surface it in diagnostics and support output. It is the field
   that makes "my figures look wrong" answerable.
2. **Do not gate behaviour on it.** It identifies a pack, it does not describe a feature.
   Economy behaviour gates on `wageModel` — see `economy-config.md`.
3. **Compare for equality only.** Expect it to increment whenever the pack shape changes; do not
   infer ordering or assume consecutive values.
4. **Absent means an old pack.** A build that initialised before this shipped has no
   `packVersion`. Read `?? null` as "pre-5", not as an error.

## Not published anywhere else

Deliberately absent from `/api/game-config`, `/api/sync` and `/api/starter-config`. Those are
config surfaces; this describes a pack. Putting it on a config surface would invite exactly the
second-source confusion documented in `economy-config.md`.

## Server side

`WorldInitializationService::WORLD_PACK_VERSION` is the single source. `buildLeagueSnapshot()`
stamps it into every pack it builds, and `WorldPackCacheService::readFresh()` treats a row with
any other version as a miss — so a stale pack is rebuilt synchronously before it is returned, and
**no path serves a payload without the key.**

Adding a key to the pack is a shape change, which is why introducing this bumped the constant to
5 and invalidated every cached pack. Packs rebuild on read, one tier at a time, so the first
initialisation per country after that deploy is slower and the rest are normal.
