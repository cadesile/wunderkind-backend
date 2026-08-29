# Global Leaderboard API

Twelve public, cached leaderboard categories ranking clubs. Backed by
`GET /api/leaderboard/{category}` — **no authentication required** (public
endpoint, same as `/api/stats/*` and `/api/leaderboard/transfers`).

Unlike `/api/stats/*` (computed fresh on every request), these are **cached**
(5-minute TTL) and periodically recomputed server-side — see Caching &
freshness below.

## Categories

| Slug | Label | Score meaning | `displayLabel` |
|---|---|---|---|
| `hall_of_fame` | Hall of Fame | Tier-weighted league titles — derived server-side (see below) | — |
| `club_reputation` | Reputation | Club's current reputation score | — |
| `career_earnings` | Career Earnings | Lifetime earnings, **in pence/cents** | — |
| `golden_boot` | Golden Boot | Goals scored this season by the club's top scorer | Top scorer's name |
| `playmaker` | Playmaker | Assists this season by the club's top provider | Top provider's name |
| `empire_index` | Empire Index | Sum of the club's facility upgrade levels | — |
| `fanatics` | Fanatics | Total season attendance (accumulated across home fixtures) | — |
| `club_goals` | Club Goals | Goals scored by the club's **whole squad** combined | — |
| `club_assists` | Club Assists | Assists made by the club's **whole squad** combined | — |
| `iron_man` | Iron Man | Appearances made by the club's most-capped player | That player's name |
| `transfer_record` | Transfer Record | Biggest single fee **received** for a departing player, **in pence/cents** | Player sold |
| `transfer_spend` | Transfer Spend | Biggest single fee **paid** for a signing, **in pence/cents** | Player signed |

### How `hall_of_fame` is scored

Hall of Fame points are **derived on the server** — the client does not supply them. A club earns
points by winning leagues:

```
score = Σ  GameConfig.leagueWinPoints[league.tier]
        over SeasonRecord rows where finalPosition = 1
```

The per-tier weights are admin-editable and default to a 10× drop per tier
(T1 = 10,000,000 … T8 = 1), so a single top-flight title outweighs any number of lower-division
titles. Only titles count — a runner-up finish scores nothing, and a club with no titles scores 0.

The score is recomputed when a season is concluded (`POST /api/league/conclude-season`, which also
returns the new `hallOfFamePoints`) and by `app:leaderboards:generate`. The same value is mirrored
onto `Club.hallOfFamePoints`, which `/api/club/status` and the `POST /api/sync` response report.

`SyncRequest.hallOfFamePoints` is still accepted for backwards compatibility but is **ignored** —
it is only recorded in the `SyncRecord` audit payload.


### Squad totals vs. individual bests

`golden_boot`, `playmaker` and `iron_man` credit the club with its single best
individual performer's tally; `displayLabel` carries that player's name.
`club_goals` and `club_assists` are the combined squad totals over the same
underlying per-player stats, so a club always ranks on both scales.

All twelve boards are **club-level** — entries are clubs, never players.
`displayLabel` is populated for `golden_boot`, `playmaker`, `iron_man`,
`transfer_record` and `transfer_spend`, and `null` for every other category.

### How the transfer boards are scored

Both rank on the **gross** fee (before agent commission), since an incoming
signing never records a net figure. `transfer_record` looks at every non-signing
transfer the club has made; `transfer_spend` looks only at signings. They are
records, not totals — a club's score is its single biggest deal, not its
lifetime turnover. For lifetime sale volume use
`GET /api/leaderboard/transfers/top-sellers` instead.

## Request

```
GET /api/leaderboard/{category}?period=all-time&page=1&pageSize=20
```

| Param | Type | Default | Notes |
|---|---|---|---|
| `category` | path, string | — | One of the 12 slugs above |
| `period` | query, string | `all-time` | `all-time`, or a specific ISO week string e.g. `2026-W09` |
| `page` | query, int | `1` | 1-indexed |
| `pageSize` | query, int | `20` | Clamped to `[1, 100]` |

### Period semantics

- `all-time` — cumulative/lifetime, no time bound.
- An ISO week string (`YYYY-Www`, e.g. `2026-W30`) — that week's snapshot,
  computed server-side using the **server's** current week at generation time
  (not client-reported week numbers). In practice only `all-time` and the
  *current* server week actually have data populated — arbitrary past weeks
  are not backfilled. Default to `all-time` unless you specifically need the
  current week's board.

## Response

```json
{
  "category": "golden_boot",
  "period": "all-time",
  "entries": [
    { "rank": 1, "clubId": "019f3282-491b-75c7-9ea7-c7f84712b2b1", "clubName": "Fixture Club A", "score": 24, "displayLabel": "Zayden Sloane" },
    { "rank": 2, "clubId": "019f3282-491f-7479-847b-27624e80a662", "clubName": "Fixture Club B", "score": 15, "displayLabel": "Marcelo Pereira" }
  ],
  "total": 5,
  "page": 1,
  "pageSize": 20,
  "hasNextPage": false
}
```

| Field | Type | Notes |
|---|---|---|
| `entries[].rank` | int | 1-indexed, matches sort order (descending by `score`) |
| `entries[].clubId` | string (UUID) | |
| `entries[].clubName` | string | |
| `entries[].score` | int | Raw value — see the per-category table above for units. `career_earnings`, `transfer_record` and `transfer_spend` are **pence/cents**, divide by 100 before formatting as currency. |
| `entries[].displayLabel` | string \| null | Only populated for `golden_boot`, `playmaker`, `iron_man`, `transfer_record`, `transfer_spend` |
| `total` | int | Total clubs ranked in this category/period (across all pages) |
| `hasNextPage` | bool | Whether `page + 1` has more results |

Clubs with no data yet for a category (e.g. no facility upgrades for
`empire_index`) simply don't appear — `entries` can be shorter than `total`
implies, or empty (`total: 0`) if nobody has any data yet. This is not an
error condition — render an empty/zero state.

## Errors

- `400 Bad Request` for an unknown category:
  ```json
  { "error": "Invalid category. Valid values: career_earnings, club_reputation, hall_of_fame, golden_boot, playmaker, empire_index, fanatics, club_goals, club_assists, iron_man, transfer_record, transfer_spend" }
  ```

## Caching & freshness

Responses are cached server-side per `(category, period)` for 5 minutes
(`LEADERBOARD_CACHE_TTL` env var). A cron job re-ranks and (for every category
except `club_reputation`, `career_earnings` and `fanatics`, whose scores are
upserted on each sync) recomputes scores every 5 minutes and invalidates
the cache; a cold cache falls back to computing fresh on that request. In
practice: **don't poll faster than every ~30–60s**, and expect the board to
lag live gameplay by up to ~5–10 minutes. Good candidates for polling on an
interval or refetch-on-focus, not a live/websocket feed.

## Example

```js
async function fetchLeaderboard(category, { period = 'all-time', page = 1, pageSize = 20 } = {}) {
  const params = new URLSearchParams({ period, page, pageSize });
  const res = await fetch(`/api/leaderboard/${category}?${params}`);
  if (!res.ok) {
    const { error } = await res.json().catch(() => ({}));
    throw new Error(error || `Leaderboard request failed (${res.status})`);
  }
  return res.json();
}

function formatScore(score, category) {
  if (category === 'career_earnings') {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(score / 100);
  }
  return score.toLocaleString('en-GB');
}
```

---

## Styling direction

The reference implementation lives in `public/index.html` (`#leaderboards`
section) — a retro/pixel arcade aesthetic. Match it, or reinterpret within the
React app's own design system using the same structure and behavior:

### Visual language (as built)

- **Category switcher**: a horizontal tab row, one tab per category (7 total),
  single-select — only one category's board is visible at a time. Active tab
  gets a distinct border/text color; inactive tabs are muted. Tabs wrap to
  multiple rows on narrow viewports rather than scrolling or collapsing into a
  dropdown.
- **Board panel**: one card containing a header (category name + a one-line
  unit caption, e.g. "all-time · HoF points") and a ranked list below it.
- **Row anatomy**, left to right:
  1. **Rank** — zero-padded 2-digit (`01`, `02`, …), visually distinct/bold.
     Ranks 1–3 get accent treatment (e.g. gold/silver/bronze tint) to read as
     a podium at a glance.
  2. **Main column** — club name (primary text), with the `displayLabel`
     (top scorer/provider name) rendered as a smaller secondary line directly
     beneath it *only* for `golden_boot`/`playmaker`. Don't reserve that
     line's space for other categories.
  3. **Score** — right-aligned, formatted per the table above (currency for
     earnings, plain grouped number otherwise), visually secondary to rank/name
     but still clearly legible (bold/monospace works well for scannability).
- **Empty state**: a single centered line, e.g. "No clubs ranked yet — be the
  first" — not a blank panel.
- **Error state**: distinct color (red/danger), short and non-technical, e.g.
  "Leaderboard unavailable — try again shortly."
- **Loading state**: skeleton rows (pulsing placeholder bars for rank/name/score
  shapes), not a spinner or "Loading…" text — reduces layout jump when real
  data lands. Respect `prefers-reduced-motion` (disable the pulse, just show a
  static dim placeholder).

### Interaction & accessibility

- Tabs are keyboard-navigable: arrow keys move focus between tabs and switch
  the active board; each tab is a single stop in the tab order (roving
  `tabindex`, not all 7 tabs individually tabbable).
- Guard against race conditions when switching tabs quickly — if a user
  taps through 3 categories before the first request resolves, only the
  **last** selected category's response should ever render.
- Defer the initial fetch until the leaderboard section is actually visible
  (e.g. on-scroll-into-view) rather than fetching all 7 categories eagerly on
  page load — only the active tab's category needs to load up front.
- Fetch `pageSize=10` for a compact "top 10" board (matches the reference
  implementation); use pagination controls only if you want a full
  drill-down view beyond the top page.
