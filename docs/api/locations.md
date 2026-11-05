# Locations — the land catalogue

`GET /api/locations`, `GET /api/locations/facets`, `GET /api/locations/{id}`,
`POST /api/locations/{id}/enrich`

**All four require a signed-in user** — a valid JWT, `IS_AUTHENTICATED_FULLY`. **No club is
needed**, and that distinction matters: land is bought before a club exists, so none of these is
gated on `ROLE_CLUB`.

> **This changed on 2026-11-04.** The three reads were previously `PUBLIC_ACCESS`. They are not
> any more. A client that browsed towns before sign-in now gets `401`. Nothing shipped consumed
> them, so no released build is affected — but see
> `.context/shared/api-backwards-compatibility.md`: this is a behaviour change to a deployed
> endpoint and is recorded as one.
>
> Two reasons, and the second is the real one. The catalogue is pre-release curated content, and
> the detail read is the doorway to a **billed** AI valuation — see rule 3. A public doorway to a
> metered external API is a budget anyone can spend.

This is the read side. Holding, buying and selling plots is `docs/api/land-plots.md`; the
balance you spend is `docs/api/user-ledger.md`; where these calls sit in the first-launch
sequence, and the auth level of every other call in it, is `docs/api/setup-flow.md`.

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

**3. Nothing a GET does triggers enrichment. Only `POST /{id}/enrich` does.**
Every read on this page is side-effect free and free of charge: the list, the facets and the
detail. Pricing an un-enriched town is an **explicit, authenticated POST**, because it spends one
billed external API call, and spending money is not something a GET should do.

> **This changed on 2026-11-04.** `GET /api/locations/{id}` used to queue enrichment as a side
> effect of being read. It no longer writes anything. A client that relied on opening a town to
> start its valuation must now send the POST. The GET's response shape is unchanged — only the
> side effect is gone, so polling code keeps working once something has queued the job.

The old behaviour was wrong in both directions. It meant a town could be priced by a crawler, a
prefetch, or a double-tap that nobody intended; and it meant the client had no way to say "price
this one" without also navigating to it. Batch enrichment remains an admin action.

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
      "plotCount": 4,
      "plotsAvailable": 1,
      "clubCount": 3,
      "claimable": true,
      "claimableReason": null,
      "plotId": "0193...",
      "isClaimed": false,
      "claimedBy": null
    }
  ],
  "nextCursor": "dG93bnxTYWxmb3JkfDAxOTM..."
}
```

### How many plots does a town have? It depends on its size

**Plot count scales with the town.** A big city holds several clubs; a village holds one:

| City size | Plots |
|---|---|
| `BIG` | 4 |
| `MEDIUM` | 2 |
| `SMALL` | 1 |

An **unpriced** town has no population yet, so it defaults to `SMALL` and one plot. It gains
plots if enrichment later reveals a bigger place — and never loses them, because a plot can
already carry somebody's claim.

City size is derived from population, and population comes from enrichment — so **enrichment is
what decides how much football a place can hold.** Read `plotCount`; never hardcode a number.

### Most towns arrive unpriced — this is the normal state, not an error

**The catalogue is far larger than the priced set.** England has **280 towns, of which ~42 are
priced**. The rest — Prestwich, Telford, Doncaster — exist as real, selectable places but have
never been valued, because valuing a town costs a billed AI call and we only pay for towns
players actually look at.

An unpriced town looks like this in the list:

```json
{
  "town": "Prestwich",
  "lat": null,
  "lng": null,
  "population": null,
  "landPence": null,
  "enrichmentStatus": "pending",
  "citySize": "SMALL",
  "claimable": true,
  "plotsAvailable": 1
}
```

**All five nulls are expected.** Render it as a town that exists but has no price yet — not as
an error, not as a loading failure, and not filtered out.

#### The flow, end to end

| Step | What the client does | What happens |
|---|---|---|
| 1 | `GET /api/locations?country=EN` | 280 towns. ~42 priced, the rest `pending`. **Free, and nothing is queued.** |
| 2 | Player opens Prestwich → `GET /api/locations/{id}` | Still free, still nothing queued. `enrichmentStatus: "pending"`, prices null. Show a "get a valuation" affordance. |
| 3 | Player asks for it → `POST /api/locations/{id}/enrich` | **One** billed AI call is queued. `202` with `enrichmentStatus: "queued"` and a `pollUrl`. |
| 4 | Client polls `pollUrl` (the detail GET) | Status becomes `enriched` and `lat`/`lng`/`population`/`landPence` are populated — **and the town gains its plots** (see below). |
| 5 | Player buys | Normal hold → claim flow. |

**Poll the detail endpoint; do not re-request the list.** The list carries the same
`enrichmentStatus`, but it is a page-sized query and is cached — the detail read is the cheap,
uncached one while a row is in flight.

**Enrichment is what gives a town its plots.** A town imported as a bare name has **zero** plots
until it is enriched: enrichment supplies the population, population gives the city size, and
city size decides the plot count (4 / 2 / 1). So step 4 is not only "a price appeared" — it is
also "something became buyable". Before that the town reports
`claimableReason: "awaiting_enrichment"`, which is **not** the same as `"town_sold_out"`; see
the reasons table under `GET /api/locations/{id}`.

**While `enrichmentStatus` is `pending` or `queued` the detail response is `private, max-age=0`.**
Once enriched it becomes `public, max-age=60`. So polling works; don't add your own cache layer
in front of it.

#### Statuses you will see

| `enrichmentStatus` | Meaning | What to show |
|---|---|---|
| `pending` | Never valued. **Reading it does nothing** — `POST /{id}/enrich` is what starts it. | The town, no price, and an affordance to ask for a valuation. Normal. |
| `queued` | Enrichment is in flight. | A loading state. Poll. |
| `enriched` | Priced and placed. | Everything. |
| `needs_review` | The AI answered, but something failed validation and a human must look. | Treat as unpriced; it will not self-resolve. |
| `failed` | Could not be enriched, repeatedly. | Treat as unpriced. Will not retry on its own. |

**`needs_review` and `failed` do not resolve by polling.** They need an admin. If a player is
sitting on one, stop polling after a few attempts and show it as unavailable rather than
spinning forever.

#### Buying an unpriced town

A town with no price **cannot be bought yet** — `POST /api/plots/{id}/hold` returns
`422 plot_not_sellable` with *"the land has not been valued yet"* or *"the town has not been
located yet"*.

That is not a dead end: it means the town has never been valued. The correct client behaviour is
to `POST /api/locations/{id}/enrich`, poll until `enriched`, then offer the buy button. Don't
offer it against a null `landPence`, and don't expect opening the town to be enough — the GET no
longer starts anything.

#### What is NOT affected by an unpriced town

- **It cannot host an NPC club.** Generation ignores towns with no population, so an unpriced
  town is never somebody else's home ground.
- **It does not affect other towns' `citySize`.** City size is a percentile across the country,
  and unpriced towns are excluded from that calculation until they have a population — so
  importing thousands of them cannot re-band the priced ones.
- **It still gets a plot.** Unpriced towns default to `SMALL`, so one plot. If enrichment later
  reveals a large population the town re-bands and gains plots; it never loses them.

### A town can be sold out, and `claimable` is what to gate on

A town's plots are shared between established (NPC) clubs and players:

```
available = plotCount − (clubs here, capped at 2) − (plots already bought)
```

**`claimable: false` means there is nothing left to sell.** Hold and claim both return
`409 town_sold_out` carrying the same arithmetic, so the list and the refusal can never
disagree.

The cap matters: a town can have eight or ten established clubs, but they only ever consume
**two** plots between them. Without that, every town would be full regardless of size, and the
plot count — and therefore enrichment — would decide nothing.

**Gate "buy" on `claimable`, not on `isClaimed`.** `isClaimed` only describes the primary plot;
`claimable` is the whole town's capacity, and it is the exact check the purchase endpoints run.

`GET /api/locations/{id}` returns the working:

```json
"capacity": { "plots": 4, "clubs": 3, "takenByClubs": 2, "claimed": 1, "available": 1 },
"claimable": true,
"claimableReason": null
```

#### `claimableReason` — two "no"s that mean opposite things

When `claimable` is false, `claimableReason` says why, and the two values need different UI:

| `claimableReason` | Means | What to do |
|---|---|---|
| `null` | `claimable` is true. | Offer the buy button. |
| `awaiting_enrichment` | The town has **no plots at all**, because it has never been valued. `capacity.plots` is 0. | Offer **"get a valuation"** → `POST /{id}/enrich`. This is a door, not a wall. |
| `town_sold_out` | The town has plots and every one is taken. | Offer nothing. Send the player elsewhere. This one is final. |

These used to both report `town_sold_out`, which sent players away from every bare-name town in
the catalogue — the large majority of it. Do not treat an empty `capacity.plots` as sold out.

### Which clubs already play here

Detail responses carry the established clubs at this town, and at its rival towns:

```json
"clubs":      [ { "id": "…", "name": "Salford Rovers", "tier": 3, "reputation": 42 } ],
"rivalClubs": [ { "locationId": "…", "town": "Manchester", "distanceKm": 4.2, "clubs": [ … ] } ]
```

Strongest club first (lowest tier, then reputation). Both are **detail-only** — the list never
carries them, for the same reason it never carries `rivals`.

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
stadium is a separate, later purchase (`land-plots.md`).

There is no longer a signed-out case to reason about: the endpoint requires a token, so
`balancePence` is always the real caller's credit. (It previously computed against zero for an
anonymous caller. That branch is gone with the auth change.)

`?affordable=1` does the same and additionally filters the page. The filter is applied **after**
the page is assembled, because it depends on a balance rather than a column — so an
`affordable=1` page may be shorter than `limit` while `nextCursor` is still set. Keep paging
until `nextCursor` is `null`.

### Caching

| Request | `Cache-Control` |
|---|---|
| plain list | `public, max-age=60` |
| `?affordability=1` or `?affordable=1` | `private, max-age=0` |

Note that `public, max-age=60` on the plain list is a **shared**-cache directive on a response
that now requires a token. That is deliberate and safe: the plain list carries no per-account
field whatsoever, so two players' plain pages for the same query are byte-identical. The moment a
balance enters the response the directive flips to `private`, which is the whole point of the
split.

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
  "capacity": { "plots": 4, "clubs": 3, "takenByClubs": 2, "claimed": 1, "available": 1 },
  "claimable": true,
  "claimableReason": null,
  "clubs": [
    { "id": "0193...", "name": "Salford Rovers", "tier": 3, "reputation": 42 }
  ],
  "rivalClubs": [
    { "locationId": "0193...", "town": "Manchester", "distanceKm": 4.2, "clubs": [] }
  ],
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

### This GET is read-only, and not cacheable while pending

It writes nothing and costs nothing. It used to take an enrichment lease and queue a job; that
moved to `POST /{id}/enrich` on 2026-11-04.

While the status is `pending` or `queued` the response is still `private, max-age=0`, because a
cached `"queued"` would be polled for ever and the client would never see the result. Once
enriched it becomes `public, max-age=60`.

---

## `POST /api/locations/{id}/enrich`

Ask for one town to be valued. **Requires a signed-in user; no club needed.** It takes no body.

This is the only client-callable thing in the system that spends money — one AI call per town —
which is why it is a POST, why it is authenticated, and why it is explicit rather than a side
effect of navigation.

### Responses

| Status | Body | When |
|---|---|---|
| `202` | `{enrichmentStatus: "queued", queued: true, pollUrl, message}` | Accepted. **This caller's request is what dispatched the job.** |
| `202` | `{enrichmentStatus: "queued", queued: false, pollUrl, message}` | Already in flight — somebody else (or an earlier tap) queued it. Nothing extra was spent. Poll anyway. |
| `200` | `{enrichmentStatus: "enriched", queued: false, pollUrl, message}` | Already valued. A no-op, not an error: show the town. |
| `409` | `{error: "enrichment_needs_admin", enrichmentStatus: "needs_review"\|"failed"}` | Terminal. Polling will not change it. Show the town as unavailable. |
| `429` | `{error: "enrichment_quota_exhausted", code: "enrichment_limited", message, quota}` | This account has used its allowance. Nothing was queued or billed. |
| `404` | `{error: "location_not_found"}` | Unknown id. |
| `401` | — | No token. |

Every `2xx` also carries a `quota` block — the same shape as the quota endpoint below — so a
client never has to make a second call to find out what it has left.

**`429 enrichment_quota_exhausted`** is the other refusal — see the quota section below. The
body carries **both** `error: "enrichment_quota_exhausted"` and `code: "enrichment_limited"` —
two spellings of one refusal, not two meanings. `error` is canonical and will not change.

**A client should accept either string.** The `code` field alone does not help a client that
reads its error code from `error`: treat the two as equivalent rather than branching on one.

**`queued: false` with `202` is a success, not a conflict.** It is how a double-tap, a retry after
a timeout, and two players opening the same town all resolve: the first one dispatches, the rest
are told the same thing, and exactly **one** API call is billed. Treat `202` as "poll `pollUrl`"
regardless of the flag; the flag is only there if you want to distinguish "I started this" for
analytics or copy.

### Polling

`pollUrl` is the detail GET for the same id. Suggested cadence, which matches what the worker
actually does:

- Poll every **3s for the first 30s**, then back off to **10s**.
- **Give up at ~3 minutes** and show the town as unpriced with a retry affordance. Do not spin
  forever: `needs_review` and `failed` are terminal, and a `queued` row whose worker died is only
  reclaimed when its lease expires.
- Stop immediately on `enriched`, `needs_review` or `failed`.

### Each account has a limited number of valuations

A valuation is a billed API call, so an account gets a finite number of them. The ceiling is
admin-set; the account's spend against it is on the account.

**`GET /api/account/enrichment-quota`** — requires a signed-in user, no club.

```json
{ "used": 7, "max": 25, "remaining": 18, "unlimited": false, "exhausted": false,
  "enrichmentCount": 7, "enrichmentCountMax": 25 }
```

| Field | Notes |
|---|---|
| `used` | Valuations this account has spent. A lifetime total — it does not reset. |
| `max` | The admin-set ceiling. **`0` means unlimited.** |
| `remaining` | `max - used`, floored at zero. **`null` when `unlimited`** — deliberately not a large sentinel, because a client doing arithmetic on `PHP_INT_MAX` will eventually render it. |
| `unlimited` | No ceiling is set. `exhausted` is then always `false`. |
| `exhausted` | The next `POST /{id}/enrich` will be refused with `429`. **Gate the "get a valuation" button on this.** |
| `enrichmentCount` | Alias of `used` — added 2026-11-05 for the shipped client. |
| `enrichmentCountMax` | Alias of `max`, **except that it is `null` when unlimited, never `0`.** |

### Two sets of field names, and the one place they differ

`enrichmentCount` / `enrichmentCountMax` are **aliases** added for an app build already in the
wild: its `allowance()` bails out when `enrichmentCount` is null, so against the documented
shape alone the VALUATIONS LEFT strip never rendered and VALUE TOWN stayed permanently enabled.
Both sets are sent, and both will keep being sent — dropping either breaks one of the two
readers.

**Where they appear matters, so they appear in both places.** On this endpoint `quotaFor()` *is*
the whole body, so the aliases are top-level. On `POST /{id}/enrich` the same array is nested
under `quota` — and the aliases are **also repeated at the top level there**, on both the `202`
and the `429`, because a client reading `res.enrichmentCount` would otherwise get `undefined`
on exactly the responses that follow a tap. A reader using `res.quota.used`, one using
`res.quota ?? res`, and one using `res.enrichmentCount` are all correct.

**The one thing the alias cannot express is "unlimited".** The ceiling is stored as `0` meaning
unlimited, and `enrichmentCountMax` sends **`null`** for that rather than passing `0` through,
because `0` reads as "none allowed" and would disable the button for every account — and `0` is
the deployed default on dev today. **A client must treat a null ceiling as "no limit, always
enabled".** Two ways to get this wrong, both seen: treating it as zero disables the button for
everyone, and applying a `?? DEFAULT_ALLOWANCE` fallback to it silently inverts "unlimited" into
the smallest cap the client knows. Check for null explicitly.

**Dev carries a real ceiling of `25`**, set by an operator on 2026-11-05 and verified against
`https://api.dev.buildmyclub.co.uk/api/starter-config`. So `enrichmentCountMax` is always a
number there and the null case is not what the app meets day to day.

**It is still worth handling**, because the ceiling is one admin field away from `0` at any
time, and a fresh environment starts there: the migration deliberately sets `0` on the existing
row (enrichment was already deployed and working, and a migration must not start refusing
requests as a side effect). Setting a real number is therefore a **one-time operator action per
environment** — `/admin?routeName=admin_starter_config` → **"Land Valuations Per Account"** —
that no deploy performs for you.

`private, max-age=0` — it is one account's spend and changes on that account's own next request.

**Only billed calls are charged.** A `202` with `queued: false` costs nothing and does not touch
the allowance, so a double tap, a retry after a timeout, and two players opening the same town
are all free. Likewise opening an **already-priced** town: that is a `200` no-op and never
charged. An exhausted account can still browse the whole catalogue and open every priced town in
it — only a *new* valuation is refused.

`max` is also published on `GET /api/starter-config` as `maxEnrichmentRequestsPerUser`, but the
ceiling alone cannot tell you whether to offer the button. Use the quota endpoint, which has
both.

### Throughput ceiling — read this before designing the UI

The worker is a per-minute cron running `app:locations:enrich --limit=10`, so the whole system
drains at about **10 towns per minute**. That is fine for one player valuing one town, and it is
not fine for a screen that fans out across a list. A queue of 50 is a five-minute wait with no
feedback beyond `queued`.

So: **one town at a time, on an explicit user action.** Do not pre-warm, do not enrich on scroll,
and do not offer "value all of these".

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

Purchase endpoints (`docs/api/land-plots.md`) add one more that originates here:

| Status | `error` | Meaning |
|---|---|---|
| `409` | `town_sold_out` | The town has no plots left. Carries `plots`, `npcOccupied` and `claimed` so the client can explain why. |
