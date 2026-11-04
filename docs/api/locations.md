# Locations — the land catalogue

`GET /api/locations`, `GET /api/locations/facets`, `GET /api/locations/{id}`

**Public.** A player browses towns before signing in, and the default list carries no
per-account data. The one exception is the affordability variant, which is opt-in precisely
because it changes that.

This is the read side. Holding, buying and selling plots is `docs/api/land-plots.md`; the
balance you spend is `docs/api/user-ledger.md`.

---

## Three rules that shape every response

**1. `country` is required on the list.** Thousands of rows span 70-odd countries and there is
no caller for an unfiltered list. Omitting it is a `400 country_required`, not a silent "all
countries".

**2. `lat`, `lng` and `landPence` are nullable, and `enrichmentStatus` tells you why.**
Geography is filled in lazily by an AI enrichment pass, so "not known yet" is a normal,
common state — not an error and not a 404. Treat `enrichmentStatus` as the loading signal and
render an un-enriched town as pending rather than crashing on a missing number. The keys are
always present; only their values are null.

**3. Only the detail endpoint triggers enrichment.** A read of an un-enriched location queues
one background job. The **list never does**, however many pending rows it contains — one page
of 200 would otherwise queue 200 billed external API calls, so a crawler or a player scrolling
a region could drain the budget in a few requests. Batch enrichment is an admin action.

### A country that is not live serves no locations

`country_profile.status` gates a whole country. A country still being built returns `200` with
an empty `locations` array and its real `status` in the `country` block, so you can tell
"nothing here yet" from "wrong filter". An unknown country code is a `404 unknown_country`.

Note the granularity: a single pending row inside a **live** country is a transient state you
render as loading. A half-built country is kept out wholesale.

---

## `GET /api/locations`

### Query parameters

| Param | Default | Notes |
|---|---|---|
| `country` | — | **Required.** Our own 2-letter code (`EN`, not `GB` — see `countries.md`). |
| `region` | — | One region, or several comma-separated (`region=North,South`). |
| `maxLandPence` | — | Price ceiling. **Excludes un-enriched rows**, since an unknown price cannot be known to satisfy it. |
| `q` | — | Town search. Matched against the normalised name, so `munchen` finds `München`. |
| `status` | `active` | `active` or `retired`. Anything else is `400 invalid_status`. |
| `sort` | `town` | `town` (A–Z) or `price` (cheapest first, un-enriched last). |
| `limit` | `50` | Clamped to 1–200. |
| `cursor` | — | Opaque keyset cursor from the previous response's `nextCursor`. |
| `affordability` | `0` | `1` adds the affordability fields and makes the response uncacheable. |
| `affordable` | `0` | `1` does the same **and** drops what the caller cannot afford. |

### Response

```json
{
  "country": {
    "code": "EN",
    "name": "England",
    "isoAlpha2": "GB",
    "isIsoCode": false,
    "isoAlpha3": null,
    "continent": "Europe",
    "currencyCode": "GBP",
    "distanceUnit": "mi",
    "status": "live"
  },
  "locations": [
    {
      "id": "0193...",
      "slug": "en-salford",
      "town": "Salford",
      "localisedNames": { "de": "Salford" },
      "region": "North West",
      "lat": 53.4875,
      "lng": -2.2901,
      "population": 270000,
      "populationSource": "CountryContentRegistry (seeded)",
      "populationAsOf": "2026-11-03",
      "landPence": 180000000,
      "landValuedAt": "2026-11-03T12:00:00+00:00",
      "landSource": "provider:model:2026-11-03",
      "isCapital": false,
      "citySize": "MEDIUM",
      "status": "active",
      "enrichmentStatus": "enriched",
      "plotCount": 1,
      "plotId": "0193...",
      "isClaimed": false,
      "claimedBy": null
    }
  ],
  "nextCursor": "dG93bnxTYWxmb3JkfDAxOTM..."
}
```

### How many plots does a town have? One — today

**Every seeded location has exactly one plot** (865 locations, 865 plots). So one card per town
is correct, and `plotId` on the list row is the plot to hold and claim.

`plotId` and `locationId` are nonetheless separate fields because the schema permits several
plots per town, and that is where this is heading — a town that can host more than one club.
**Read `plotCount` rather than assuming 1.** While it is 1 the list row's own `plotId` is all
you need; if it is ever greater, `GET /api/locations/{id}` returns the full `plots` array and
the town needs more than one card (or a card with a plot picker).

Nothing about the hold/claim flow changes either way: holds and claims have always been
per-PLOT, never per-town.

### `isClaimed` and `claimedBy` are two different facts

**Gate "buy" on `isClaimed`, never on `claimedBy`.** Land is buyable before a club exists, so
a perfectly valid active claim can have no club attached and therefore no name to display —
`claimedBy` is `null` for it while `isClaimed` is `true`. A client inferring "free" from a null
`claimedBy` would offer a sold plot for sale, and the buyer would only find out at the till.

`claimedBy` comes from the claim's frozen `club_name_at_claim`, so an owner still renders after
the club has been deleted.

### Pagination is keyset, not offset

Pass back `nextCursor` verbatim; `null` means that was the last page. There is no `total` — a
count over a filtered set is a second query nobody reads.

Two reasons it is not an offset. A deep page with a region filter makes `OFFSET` scan and
discard everything before it; and, more importantly, an admin correcting a land valuation
mid-browse reorders a price-sorted set, so an offset-paged client silently skips or repeats
towns. A cursor names a position rather than a distance.

**The cursor is bound to its `sort`.** Paging a `price` cursor with `sort=town` is a
`400 cursor_sort_mismatch` rather than a quietly wrong page. A malformed cursor is
`400 invalid_cursor`.

### Affordability is opt-in, and against land only

`?affordability=1` adds three fields per location:

| Field | Notes |
|---|---|
| `landAffordable` | `landPence <= balance`. **`false` for an un-enriched town**, whose price is unknown — reporting it affordable would promise a price we do not have. |
| `shortfallPence` | `max(0, landPence - balance)`, or `null` when unpriced. |
| `balancePence` | The caller's account credit, repeated per row for convenience. |

**Against land only**, because that is what `POST /api/plots/{id}/claim` actually charges — the
stadium is a separate, later purchase (`land-plots.md`). A signed-out caller gets the flags
computed against a zero balance, which is honest: nothing is affordable without credit.

`?affordable=1` does the same and additionally filters the page. The filter is applied **after**
the page is assembled, because it depends on a balance rather than a column — so an
`affordable=1` page may be shorter than `limit` while `nextCursor` is still set. Keep paging
until `nextCursor` is `null`.

### Caching

| Request | `Cache-Control` |
|---|---|
| plain list | `public, max-age=60` |
| `?affordability=1` or `?affordable=1` | `private, max-age=0` |

The split is load-bearing: a shared cache would serve one player's affordability to another.

---

## `GET /api/locations/facets?country=XX`

Everything needed to build the filter UI in one call. `public, max-age=300`.

```json
{
  "country": { "code": "EN", "...": "...", "locationCount": 42 },
  "regions": [ { "region": "North West", "count": 7 } ],
  "landPenceMin": 12000000,
  "landPenceMax": 980000000,
  "pricedCount": 38,
  "totalCount": 42
}
```

Region counts are over **active** rows only, so a facet never offers a filter that returns
nothing. `landPenceMin`/`Max` are `null` until at least one location in the country has been
enriched — an empty price range is a real pre-enrichment state, not a missing value.
`pricedCount` versus `totalCount` is how far enrichment has got.

---

## `GET /api/locations/{id}`

Everything the list returns for one town, plus `rivals`, `stadiumEstimate`, the full `plots`
array, and the `country` block. Unknown id is `404 location_not_found`.

```json
{
  "id": "0193...",
  "town": "Salford",
  "countryCode": "EN",
  "enrichmentStatus": "queued",
  "lat": null,
  "lng": null,
  "landPence": null,
  "plots": [
    { "plotId": "0193...", "plotNumber": 1, "landPence": null, "isClaimed": false, "claimedBy": null }
  ],
  "stadiumEstimate": null,
  "rivals": [
    { "locationId": "0193...", "town": "Manchester", "rank": 1, "distanceKm": 4.2 }
  ]
}
```

**`rivals` is detail-only, by contract.** Four rows per location across a 200-row page is 800
rows nobody reads them from. `distanceKm` is always kilometres; convert for display using
`country.distanceUnit`.

**`stadiumEstimate` is `null` when the town cannot be priced yet** — a stadium cost needs a
population and a land value, so this is a normal pre-enrichment state rather than an error.

### This GET can write, and is therefore not cacheable while pending

Reading an un-enriched location takes an enrichment lease and queues a job, so the endpoint is
not idempotent in a small, deliberate way. Two consequences:

- The response says `enrichmentStatus: "queued"` on the very read that queued it — not
  `"pending"`. Poll the same URL; it becomes `enriched` (or `needs_review` / `failed`) once the
  worker finishes.
- While the status is `pending` or `queued`, the response is `private, max-age=0`. A cached
  `"queued"` would be polled for ever and the client would never see the result. Once enriched,
  it is `public, max-age=60`.

A herd of simultaneous readers costs **one** API call, not one each — only the reader that wins
the lease dispatches, and everyone else is told the same `queued`.

---

## Errors

| Status | `error` | Meaning |
|---|---|---|
| `400` | `country_required` | No `?country=`. |
| `400` | `invalid_status` | `status` was not `active` or `retired`. |
| `400` | `invalid_cursor` | Cursor was not one this endpoint issued. |
| `400` | `cursor_sort_mismatch` | Cursor was issued for a different `sort`. |
| `404` | `unknown_country` | No country profile for that code. |
| `404` | `location_not_found` | No such location id. |
