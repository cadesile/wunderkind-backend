# Finding a town before it has been enriched

Notes for the app team, 2026-11-05. Written after Prestwich could not be found in the app while
being plainly visible in the admin.

**The short version:** 242 of England's 284 towns have **no region**, because a region is
something *enrichment produces*, not something the catalogue ships with. Any list request that
filters on region hides those 242 — and since a player has to find a town in order to request
its enrichment, filtering on region makes them permanently unreachable.

Nothing is missing from the catalogue, and nothing is broken in enrichment. The fix is on the
discovery side.

---

## The numbers (England, today)

| | Count |
|---|---|
| Active locations | 284 |
| …**curated** — seeded with a region and a population | **42** |
| …**bare names** — town string only, no region, no population, no price, no plots | **242** |
| Priced and placed so far | 10 locally / 42 on dev |

`Preston` is curated (`region: "North West"`). `Prestwich` is bare (`region: ""`). Both match a
search for `pres`; only Preston survives a region filter. That is the whole of the reported bug.

**England is the only country in this state right now** — every other country has 40 towns, all
curated. That changes the moment the bare-name import is run for them, so please don't treat
this as an England quirk.

## Why this is not an enrichment bug

Enrichment *does* fetch the region — it is a required field in the model's response schema, it
is validated, and it is persisted. We also confirmed it fills an **empty-string** region rather
than skipping it, which is the case that matters for a bare town:

```php
// EnrichmentValidator::validateRegion()
if (trim($location->getRegion()) !== '') {
    return;   // only fill it when it is genuinely absent — '' counts as absent
}
```

So enriching Prestwich gives it a region, a population, coordinates, a land value **and its
plots**, all in one pass.

The problem is the ordering:

> **region is an output of enrichment → the app needs region to show the town → nobody can find
> the town to trigger the enrichment that would give it a region.**

A deadlock, not a missing field. Break it by not requiring region for discovery.

## What a bare town looks like on the API

`GET /api/locations?country=EN&q=prestwich` returns it with a `200`. It is **not** hidden and
**not** an error:

```json
{
  "town": "Prestwich",
  "region": "",
  "lat": null,
  "lng": null,
  "landPence": null,
  "population": null,
  "enrichmentStatus": "pending",
  "claimableReason": "awaiting_enrichment"
}
```

Three fields to treat as nullable on every row: `lat`, `lng`, `landPence`. `enrichmentStatus` is
the loading signal.

**`claimableReason: "awaiting_enrichment"` is not a dead end** and must not render like one. It
means "ask for a valuation", whereas `town_sold_out` means "there is nothing here for you".
These were the same value once, which sent players away from most of the catalogue.

## What to do

**Don't**, during search or browse:

- send `region=` — `region IN (…)` excludes the 242, which is the reported bug;
- send `maxLandPence=` — it excludes unpriced rows **deliberately** (a town whose value is
  unknown cannot be promised as under £2m), so it hides every un-enriched town;
- filter client-side on `population`, `lat`/`lng` or `landPence` being present — same effect.

Those filters are all correct *once a town is priced*. They are wrong as a default for
discovery.

**Do:**

1. **Search by name** — `?q=` is an infix match on a normalised column, so `pres` finds both
   Preston and Prestwich, and `munchen` finds München.
2. **Page properly.** Default page size is **50**, max 200, sorted by town. England has 284
   towns, so the first page stops around the letter C — which is why Bury (position 44) looked
   present and Prestwich (position 186) looked missing. Follow `nextCursor`.
3. **Offer "get a valuation"** on a town with `claimableReason: "awaiting_enrichment"`, posting
   to `POST /api/locations/{id}/enrich`.
4. **Poll** the town's detail until `enrichmentStatus` is `enriched` — 3s for 30s, then 10s,
   give up at ~3 min. The worker drains ~10 towns/minute globally.
5. The town then has a region, a price and plots, and behaves like any curated one.

Full enrichment contract, including the quota and its error codes: [`locations.md`](locations.md).

## Browsing them: the `"Unassigned"` bucket — **built 2026-11-05**

`GET /api/locations/facets` reports the region-less towns under a usable label, and the list
endpoint accepts it back as a filter:

```json
{ "regions": [ { "region": "Unassigned", "count": 242 }, { "region": "North West", "count": 5 } ] }
```

```
GET /api/locations?country=EN&region=Unassigned      → the 242 un-enriched towns
GET /api/locations?country=EN&region=North West      → still excludes them (opt-in, not a widening)
GET /api/locations?country=EN&region=North West,Unassigned   → both
```

So the flow you want is now first-class: **browse the Unassigned bucket → tap a town → request
a valuation → it gains a region and leaves the bucket on its own.** Search still works and is
still the fastest route to a town by name.

It previously came back as `{"region": "", "count": 242}` — a blank entry a picker drops. That
is a **changed value under an unchanged key**, so it is recorded as a breaking change even
though nothing shipped consumed it.

**It is a label, not stored data.** The column holds `''` and keeps holding it; the filter
translates the label back. We deliberately did not invent regions for the 242: the source list
is names only, and enrichment's validator fills a region only when one is genuinely absent, so
a made-up value would block the real one from ever landing.

What we would **not** do is invent regions for the 242. The source list for bare towns is names
only; a made-up region would be wrong data that enrichment would then have to overwrite, and the
`trim(...) !== ''` guard above would stop it overwriting anything we had already filled in.
