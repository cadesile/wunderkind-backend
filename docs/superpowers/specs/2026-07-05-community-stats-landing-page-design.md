# Community Stats Landing Page Integration — Design

## Context

The `/api/stats/*` community stats endpoints (most-transfers, most-development, most-seasons, most-trophies — see [`src/Controller/Api/CommunityStatsController.php`](../../../src/Controller/Api/CommunityStatsController.php)) were built as JWT-protected, client-only endpoints. We now want to surface this live data on the public marketing landing page (`public/index.html`) as a "community, right now" section, and separately hand a written API spec to the mobile app team so they can build equivalent leaderboard sections in-app.

Surfacing this on a public, unauthenticated page requires making the endpoints publicly readable — they weren't designed with that consumer in mind originally.

## Scope

**In scope:**
- Make `/api/stats/*` publicly readable (security.yaml change)
- New landing page section: live stat band + period-filterable leaderboard grid (4 cards)
- Written frontend-facing API spec doc for the mobile app team

**Out of scope:**
- No changes to the mobile app / frontend repo itself
- No caching/precompute (endpoints remain live-computed, per the original design)
- No changes to `LeaderboardEntry`/`LeaderboardController`/`TransferLeaderboardService` (same guard as the original endpoint work)
- No polling/auto-refresh on the landing page — fetched once per page load / tab click

## 1. Backend: Public Access for `/api/stats`

Add one line to `config/packages/security.yaml`'s `access_control` list, mirroring the existing carve-out for `/api/leaderboard/transfers`:

```yaml
access_control:
    - { path: ^/api, roles: PUBLIC_ACCESS, methods: [OPTIONS] }
    - { path: ^/api/leaderboard/transfers, roles: PUBLIC_ACCESS }  # existing
    - { path: ^/api/stats, roles: PUBLIC_ACCESS }                  # new
    - { path: ^/api, roles: IS_AUTHENTICATED_FULLY }               # fallback
```

Rationale: these are read-only, club-name-and-count leaderboard rankings — no more sensitive than the transfer leaderboard that's already public. No controller code changes needed; `CommunityStatsController` has no `#[IsGranted]` attribute today and relies entirely on the firewall.

## 2. Landing Page Section

### Placement

New `<section id="community-stats">` inserted between the existing `#leagues` section and the `.cta-section` (`#download`) in `public/index.html`. The leagues section's closing copy ("your position across 6 global leaderboard categories updates instantly") sets up this section as proof-of-life immediately before the download call-to-action.

### Components

**Stat band** — reuses the existing `.stats-band` / `.stats-inner` / `.stat-block` / `.stat-num` / `.stat-lbl` classes verbatim (already used elsewhere in the page). Four blocks, one per endpoint, each showing the **sum of the `value` field across the top-10 results** for the currently-selected period:

| Block | Label |
|---|---|
| Sum of `most-transfers` values | "Transfers (top 10 clubs)" |
| Sum of `most-development` values | "Development points (top 10 clubs)" |
| Sum of `most-seasons` values | "Seasons completed (top 10 clubs)" |
| Sum of `most-trophies` values | "Titles won (top 10 clubs)" |

Labelled explicitly as "top 10 clubs" — this is an honest sum of what's displayed below, not a claim of a sitewide total (the API doesn't expose one).

**Period tabs** — new small component, 4 pill-style buttons (Week / Month / Season / All Time) mapping to `period=week|month|season|all`. Default active: Week. Clicking a tab re-fetches all 4 endpoints with the new period and re-renders the stat band + all 4 cards.

**Leaderboard grid — Option B (approved via mockup)** — a 2×2 grid of larger cards (`.lb-grid-2`), each showing up to 5 ranked rows. One card per endpoint: Most Transfers, Most Development, Most Seasons, Most Trophies. Each row: rank number (gold, Press Start 2P, matches `.depth-num` convention), club name (VT323, truncates with ellipsis if long), value (Space Mono bold, mint).

New CSS classes needed (added to the existing `<style>` block, following its naming and BEM-ish conventions): `.period-tab(.active)`, `.lb-grid-2`, `.lb-card`, `.lb-card-title`, `.lb-card-sub`, `.lb-list`, `.lb-item`, `.lb-rank`, `.lb-name`, `.lb-value`. Responsive: `.lb-grid-2` collapses to a single column under the existing `900px`/`600px` breakpoints, matching how `.features-grid`/`.archetype-grid` already collapse.

### Data flow

On page load (and on each period tab click), fire all 4 endpoints in parallel:

```js
Promise.all([
  fetch(`/api/stats/most-transfers?period=${period}&limit=10`),
  fetch(`/api/stats/most-development?period=${period}&limit=10`),
  fetch(`/api/stats/most-seasons?period=${period}&limit=10`),
  fetch(`/api/stats/most-trophies?period=${period}&limit=10`),
])
```

This mirrors the existing `fetch('/api/app-links')` pattern already in the page (plain `fetch`, no framework). Each response independently updates its own card and contributes its sum to the stat band — one endpoint failing doesn't block the others (each `fetch` promise is handled individually inside the `Promise.all`, e.g. via `.catch()` per call so one rejection doesn't reject the whole batch).

### Loading / error / empty states

- **Loading:** each `.lb-card` shows a single muted "loading…" row until its fetch resolves.
- **Error** (network failure, non-200): that card shows "unavailable" — degrades gracefully, doesn't block other cards or throw a visible JS error.
- **Empty** (`results: []`, realistic pre-launch case): card shows "No data yet — be the first" instead of blank space.

## 3. Frontend API Spec Document

New file: `docs/api/community-stats.md`. One section per endpoint, each containing: URL, query params table (`period`, `limit` with defaults/max), an example request, a full example response JSON body, error cases (`400` invalid period), and the empty-results shape. Includes one dedicated callout explaining the `period=season` per-club semantics (each club's own most recent `SeasonRecord.createdAt` as its cutoff, not a global date) — this is the one non-obvious behavior an app engineer needs before building UI that assumes a single global "season" window.

## Verification

- `lando php bin/console debug:router` shows the 4 routes; hit each with `curl` (no auth header) and confirm `200` instead of `401` after the security.yaml change.
- Load the landing page in a browser, confirm all 4 cards populate, confirm period tab switching re-fetches and re-renders correctly.
- Temporarily block one endpoint (e.g. typo the URL) to confirm the other 3 cards still render and the broken one shows "unavailable" rather than breaking the page.
- Confirm existing `/api/leaderboard/transfers/*` and admin-panel access rules are unaffected by the security.yaml change (existing PHPUnit suite + a manual check that `/api/leaderboard/{category}` still requires auth).
