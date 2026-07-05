# Community Stats Landing Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the four `/api/stats/*` community stats endpoints publicly readable, surface them as a live "Community, right now" section on the marketing landing page (`public/index.html`), and write a frontend API spec for the mobile app team.

**Architecture:** One Symfony `access_control` rule change opens the endpoints to unauthenticated requests. The landing page (a single static HTML file with vanilla JS, no build step, no framework) gets a new section that fetches all four endpoints in parallel via `fetch()` on load and on period-tab clicks, reusing the page's existing `.stats-band` component and adding a handful of new leaderboard-card classes that follow the same naming/structure conventions as the page's existing `.feature-card`/`.depth-item` patterns.

**Tech Stack:** Symfony 8 (`config/packages/security.yaml`), PHPUnit `WebTestCase`, static HTML/CSS/vanilla JS (`public/index.html`), Markdown (API spec doc).

## Global Constraints

- No caching, precompute, or scheduled command for the stats endpoints — they remain live-computed (per the original endpoint design).
- Do not modify `LeaderboardEntry`, `src/Controller/LeaderboardController.php`, or `src/Service/TransferLeaderboardService.php`.
- No polling/auto-refresh on the landing page — data is fetched once per page load and once per period-tab click, nothing more.
- No JS framework or build step — `public/index.html` is a single static file with inline `<style>`/`<script>`, and all new code must match that.
- Reuse existing CSS custom properties (`--gold`, `--mint`, `--card`, `--border`, `--text-muted`, etc.) and existing component classes (`.stats-band`, `.stat-block`, `.section-label`, `.section-title`, `.section-sub`) rather than inventing new colors or duplicating those patterns.
- The `period=season` semantics are per-club (each club's own most recent `SeasonRecord.createdAt` as its cutoff), not a global date — this must be documented explicitly in the API spec doc, not glossed over.

---

### Task 1: Make `/api/stats/*` publicly accessible

**Files:**
- Modify: `config/packages/security.yaml:72`
- Modify: `tests/Controller/Api/CommunityStatsControllerTest.php`

**Interfaces:**
- Consumes: nothing new (endpoints already exist from prior work — `src/Controller/Api/CommunityStatsController.php`)
- Produces: `/api/stats/*` now reachable without an `Authorization` header; later tasks (landing page JS) depend on this being true

- [ ] **Step 1: Add the public access rule**

Open `config/packages/security.yaml`. Find this exact block (around line 72):

```yaml
        - { path: ^/api/leaderboard/transfers, roles: PUBLIC_ACCESS }
        - { path: ^/api/app-links,             roles: PUBLIC_ACCESS }
```

Replace it with:

```yaml
        - { path: ^/api/leaderboard/transfers, roles: PUBLIC_ACCESS }
        - { path: ^/api/stats,                 roles: PUBLIC_ACCESS }
        - { path: ^/api/app-links,             roles: PUBLIC_ACCESS }
```

This must appear before the catch-all `- { path: ^/api, roles: IS_AUTHENTICATED_FULLY }` rule at the bottom of the list (Symfony's `access_control` is first-match-wins, and it already is — that rule is last in the file).

- [ ] **Step 2: Rewrite the controller test to assert public access instead of 401**

Replace the entire contents of `tests/Controller/Api/CommunityStatsControllerTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CommunityStatsControllerTest extends WebTestCase
{
    public function testMostTransfersIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-transfers');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testMostDevelopmentIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-development');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testMostSeasonsIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-seasons');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testMostTrophiesIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-trophies');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testInvalidPeriodReturns400(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/stats/most-transfers?period=bogus');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
```

Note this is a strict improvement over the previous version of this file: the old `testInvalidPeriodReturns400OrUnauthorized` could only assert `[400, 401]` because there was no way to get a real JWT in a `WebTestCase`. Now that the endpoint is public, `period=bogus` deterministically returns `400` — no more ambiguity.

- [ ] **Step 3: Run the updated test file**

Run: `lando php vendor/bin/phpunit tests/Controller/Api/CommunityStatsControllerTest.php --no-coverage`
Expected: `OK (5 tests, ...)` — all passing, all asserting `200`/`400` (no `401` anywhere in this file anymore).

- [ ] **Step 4: Manually verify no other endpoint was accidentally widened**

The `^/api/stats` regex is anchored and specific, but confirm a sibling `ROLE_CLUB`-protected endpoint is still locked down:

```bash
lando php bin/console cache:clear
curl -s -o /dev/null -w "%{http_code}\n" http://wunderkind-backend.lndo.site/api/sync
curl -s -o /dev/null -w "%{http_code}\n" http://wunderkind-backend.lndo.site/api/stats/most-transfers
```

Expected: first line `401` (still protected), second line `200` (now public).

- [ ] **Step 5: Run the full test suite to confirm no other regressions**

Run: `lando php vendor/bin/phpunit --no-coverage`
Expected: same pre-existing baseline as before this change (38 errors / 1 failure, all unrelated to `CommunityStats*` or `security.yaml` — see `CLAUDE.md`'s note on stale test stubs). No *new* failures.

- [ ] **Step 6: Commit**

```bash
git add config/packages/security.yaml tests/Controller/Api/CommunityStatsControllerTest.php
git commit -m "Make /api/stats/* publicly accessible for the landing page"
```

---

### Task 2: Add the "Community, right now" section to the landing page

**Files:**
- Modify: `public/index.html` (CSS in the `<style>` block, HTML section, and a new `<script>` block)

**Interfaces:**
- Consumes: `GET /api/stats/most-transfers|most-development|most-seasons|most-trophies?period={week|month|season|all}&limit=10` → `{ period: string, results: [{ clubId: string, clubName: string, value: int, rank: int }] }` (from Task 1; already public). `limit=10` matches the stat-band's "(top 10 clubs)" label and the approved design's "sum of top-10 results" contract — this must stay in sync with the labels in Step 2.
- Produces: DOM element IDs `cs-stat-transfers|development|seasons|trophies` (stat band numbers) and `cs-list-transfers|development|seasons|trophies` (leaderboard `<ul>` elements) — used only within this task's own JS, no other task depends on them

- [ ] **Step 1: Add the new CSS rules**

In `public/index.html`, find this exact block (the end of the `<style>` element):

```css
        @media (max-width: 600px) {
            .bento-phones { grid-template-columns: 1fr; }
            .bento-phone-frame:nth-child(n+2) { display: none; }
        }
    </style>
```

Replace it with:

```css
        @media (max-width: 600px) {
            .bento-phones { grid-template-columns: 1fr; }
            .bento-phone-frame:nth-child(n+2) { display: none; }
        }

        /* ════════════════════════════════
           COMMUNITY STATS SECTION
        ════════════════════════════════ */
        .period-tabs {
            display: flex;
            gap: 8px;
            margin: 32px 0 8px;
            flex-wrap: wrap;
        }
        .period-tab {
            font-family: 'Space Mono', monospace;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 8px 16px;
            border: 1px solid var(--border);
            color: var(--text-muted);
            cursor: pointer;
            background: var(--card);
            transition: border-color 0.15s, color 0.15s, background 0.15s;
        }
        .period-tab:hover { border-color: var(--mint); color: var(--text); }
        .period-tab.active { border-color: var(--gold); color: var(--gold); background: var(--card-hi); }

        .lb-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            margin-top: 32px;
        }
        .lb-card { background: var(--card); padding: 22px 20px; }
        .lb-card-title {
            font-family: 'Press Start 2P', monospace;
            font-size: 8px;
            color: var(--gold);
            margin-bottom: 4px;
            line-height: 1.6;
        }
        .lb-card-sub {
            font-family: 'Space Mono', monospace;
            font-size: 9px;
            color: var(--text-muted);
            margin-bottom: 14px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .lb-list { list-style: none; display: flex; flex-direction: column; min-height: 40px; }
        .lb-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 0;
            border-top: 1px solid var(--border);
        }
        .lb-item:first-child { border-top: none; }
        .lb-rank {
            font-family: 'Press Start 2P', monospace;
            font-size: 8px;
            color: var(--gold);
            min-width: 18px;
        }
        .lb-name {
            font-family: 'VT323', monospace;
            font-size: 17px;
            color: var(--text);
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .lb-value {
            font-family: 'Space Mono', monospace;
            font-weight: 700;
            font-size: 13px;
            color: var(--mint);
        }
        .lb-empty, .lb-loading, .lb-error {
            font-family: 'VT323', monospace;
            font-size: 17px;
            color: var(--text-muted);
            padding: 9px 0;
        }
        .lb-error { color: var(--red); }

        @media (max-width: 900px) {
            .lb-grid-2 { grid-template-columns: 1fr; }
        }
    </style>
```

- [ ] **Step 2: Add the HTML section**

Find this exact block (the boundary between the leagues section and the CTA section):

```html
                <p class="pyramid-label">176 NPC clubs · Brazil launch · more countries coming</p>
            </div>
        </div>
    </div>
</section>

<hr class="divider">

<!-- ═══════════════════════════════ CTA ═══════════════════════════════ -->
<section class="cta-section" id="download">
```

Replace it with:

```html
                <p class="pyramid-label">176 NPC clubs · Brazil launch · more countries coming</p>
            </div>
        </div>
    </div>
</section>

<hr class="divider">

<!-- ═══════════════════════════════ COMMUNITY STATS ═══════════════════════════════ -->
<section id="community-stats">
    <div class="wrap" style="padding-bottom:0;">
        <div class="section-label">Community</div>
        <h2 class="section-title">The community, right now.</h2>
        <p class="section-sub">Live activity across every club in the pyramid — pulled straight from the server.</p>
    </div>
</section>

<div class="stats-band">
    <div class="wrap stats-inner">
        <div class="stat-block">
            <span class="stat-num" id="cs-stat-transfers">--</span>
            <span class="stat-lbl">Transfers (top 10 clubs)</span>
        </div>
        <div class="stat-block">
            <span class="stat-num" id="cs-stat-development">--</span>
            <span class="stat-lbl">Development points (top 10 clubs)</span>
        </div>
        <div class="stat-block">
            <span class="stat-num" id="cs-stat-seasons">--</span>
            <span class="stat-lbl">Seasons completed (top 10 clubs)</span>
        </div>
        <div class="stat-block">
            <span class="stat-num" id="cs-stat-trophies">--</span>
            <span class="stat-lbl">Titles won (top 10 clubs)</span>
        </div>
    </div>
</div>

<section>
    <div class="wrap">
        <div class="period-tabs" id="cs-period-tabs" role="tablist" aria-label="Leaderboard period">
            <div class="period-tab active" data-period="week" role="tab" aria-selected="true">Week</div>
            <div class="period-tab" data-period="month" role="tab" aria-selected="false">Month</div>
            <div class="period-tab" data-period="season" role="tab" aria-selected="false">Season</div>
            <div class="period-tab" data-period="all" role="tab" aria-selected="false">All Time</div>
        </div>

        <div class="lb-grid-2">
            <div class="lb-card">
                <div class="lb-card-title">MOST TRANSFERS</div>
                <div class="lb-card-sub">clubs</div>
                <ul class="lb-list" id="cs-list-transfers"><li class="lb-loading">Loading…</li></ul>
            </div>
            <div class="lb-card">
                <div class="lb-card-title">MOST DEVELOPMENT</div>
                <div class="lb-card-sub">CA points gained</div>
                <ul class="lb-list" id="cs-list-development"><li class="lb-loading">Loading…</li></ul>
            </div>
            <div class="lb-card">
                <div class="lb-card-title">MOST SEASONS</div>
                <div class="lb-card-sub">seasons completed</div>
                <ul class="lb-list" id="cs-list-seasons"><li class="lb-loading">Loading…</li></ul>
            </div>
            <div class="lb-card">
                <div class="lb-card-title">MOST TROPHIES</div>
                <div class="lb-card-sub">titles won</div>
                <ul class="lb-list" id="cs-list-trophies"><li class="lb-loading">Loading…</li></ul>
            </div>
        </div>
    </div>
</section>

<hr class="divider">

<!-- ═══════════════════════════════ CTA ═══════════════════════════════ -->
<section class="cta-section" id="download">
```

- [ ] **Step 3: Add the JS fetch/render logic**

Find the end of the existing beta-request `<script>` block (the last `</script>` before `</body>`):

```html
        );
    });
}());
</script>
</body>
</html>
```

Replace it with (adding a new, separate `<script>` block — do not merge into the existing IIFE):

```html
        );
    });
}());
</script>

<script>
(function () {
    var ENDPOINTS = {
        transfers:   '/api/stats/most-transfers',
        development: '/api/stats/most-development',
        seasons:     '/api/stats/most-seasons',
        trophies:    '/api/stats/most-trophies',
    };

    var tabs = document.querySelectorAll('#cs-period-tabs .period-tab');
    if (!tabs.length) return;

    function renderList(listEl, results) {
        listEl.innerHTML = '';
        if (!results.length) {
            listEl.innerHTML = '<li class="lb-empty">No data yet — be the first</li>';
            return;
        }
        results.forEach(function (row) {
            var li = document.createElement('li');
            li.className = 'lb-item';
            var rank = String(row.rank).padStart(2, '0');
            li.innerHTML =
                '<span class="lb-rank">' + rank + '</span>' +
                '<span class="lb-name"></span>' +
                '<span class="lb-value"></span>';
            li.querySelector('.lb-name').textContent = row.clubName;
            li.querySelector('.lb-value').textContent = row.value;
            listEl.appendChild(li);
        });
    }

    function sumValues(results) {
        return results.reduce(function (total, row) { return total + row.value; }, 0);
    }

    function loadPeriod(period) {
        Object.keys(ENDPOINTS).forEach(function (key) {
            var listEl = document.getElementById('cs-list-' + key);
            var statEl = document.getElementById('cs-stat-' + key);
            listEl.innerHTML = '<li class="lb-loading">Loading…</li>';

            fetch(ENDPOINTS[key] + '?period=' + encodeURIComponent(period) + '&limit=10')
                .then(function (res) {
                    if (!res.ok) throw new Error('bad response');
                    return res.json();
                })
                .then(function (data) {
                    renderList(listEl, data.results);
                    if (statEl) statEl.textContent = sumValues(data.results);
                })
                .catch(function () {
                    listEl.innerHTML = '<li class="lb-error">Unavailable</li>';
                    if (statEl) statEl.textContent = '--';
                });
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            loadPeriod(tab.dataset.period);
        });
    });

    loadPeriod('week');
}());
</script>
</body>
</html>
```

- [ ] **Step 4: Verify the page loads and the section renders with live data**

With the fixture data already seeded (`lando php bin/console app:seed-community-stats-fixtures` — see `src/Command/SeedCommunityStatsFixturesCommand.php`), load `http://wunderkind-backend.lndo.site/` in a browser and scroll to the new "Community" section (right after the League System section, right before "Claim your spot in the beta").

Expected:
- The 4 stat-band numbers show non-zero values matching the sum of the top-10 `most-transfers`/`most-development`/`most-seasons`/`most-trophies` values for `period=week`.
- Each of the 4 leaderboard cards shows ranked rows with club names and values.
- Clicking "Month", "Season", "All Time" re-fetches and re-renders all 4 cards and the stat band with different numbers (verified earlier via direct API calls — see the fixture data's documented per-period breakdown in `SeedCommunityStatsFixturesCommand::execute()`'s summary table).

- [ ] **Step 5: Verify graceful degradation on a broken endpoint**

Temporarily break one endpoint to confirm isolated failure handling (do not commit this change — revert immediately after checking):

```bash
# Temporarily rename one route to simulate a 404, e.g. edit
# src/Controller/Api/CommunityStatsController.php and typo '/most-transfers' to '/most-transfers-x'
# then: lando php bin/console cache:clear
```

Reload the page. Expected: the "Most Transfers" card shows "Unavailable" and its stat-band number shows `--`, while the other 3 cards and stat numbers still populate normally. Revert the typo and clear cache again before moving on.

- [ ] **Step 6: Verify the empty-data case**

Run the app against a period where fixture data doesn't cover a club (e.g. any period where a query legitimately returns `results: []` — or temporarily point at a fresh/empty DB). Expected: the card shows "No data yet — be the first" instead of a blank list.

- [ ] **Step 7: Commit**

```bash
git add public/index.html
git commit -m "Add live community stats section to the landing page"
```

---

### Task 3: Write the frontend API spec for the mobile app team

**Files:**
- Create: `docs/api/community-stats.md`

**Interfaces:**
- Consumes: the same 4 endpoints and response shape as Task 2
- Produces: a standalone reference document; nothing else depends on it

- [ ] **Step 1: Write the spec document**

Create `docs/api/community-stats.md` with this exact content:

```markdown
# Community Stats API

Four read-only, live-computed leaderboard endpoints ranking clubs by activity. No authentication required (public endpoints). No caching — every request is computed fresh against the current database state.

## Common parameters

All four endpoints accept the same two query parameters:

| Param | Type | Default | Notes |
|---|---|---|---|
| `period` | string | `all` | One of `week`, `month`, `season`, `all` |
| `limit` | int | `10` | Capped at `50` regardless of requested value |

### Period semantics

- `week` — last 7 days
- `month` — last 30 days
- `all` — no lower bound, all-time
- `season` — **per-club**, not global. Each club's own most recent `SeasonRecord.createdAt` (i.e. when that club last concluded a season) is used as its individual cutoff. A club that has never concluded a season behaves the same as `all` for that club specifically. There is no single "current season start date" shared across all clubs — clubs conclude seasons independently, whenever their player finishes a season client-side. Don't build UI that assumes `season` maps to a fixed calendar window; it varies per club in the results.

## Common response shape

```json
{
  "period": "week",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 4, "rank": 1 }
  ]
}
```

- `results` is already sorted descending by `value`; `rank` is 1-indexed and matches array position.
- Clubs with zero matching activity for the period are **omitted entirely** — they never appear with `value: 0`.
- If no club has any matching activity, `results` is an empty array: `{"period": "week", "results": []}`. This is not an error condition — render an empty/zero state, not an error state.

## Errors

- `400 Bad Request` if `period` is not one of `week`/`month`/`season`/`all`:
  ```json
  { "error": "Invalid period. Valid values: week, month, season, all" }
  ```
- `limit` is silently clamped to `[1, 50]` rather than erroring — passing `limit=0`, a negative number, or `limit=999` will not fail, it will just be treated as `1` or `50` respectively.

---

## `GET /api/stats/most-transfers`

Ranks clubs by number of transfers completed, filtered by `Transfer.occurredAt` within the period window.

**Example request**
```
GET /api/stats/most-transfers?period=week&limit=10
```

**Example response (200)**
```json
{
  "period": "week",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 4, "rank": 1 },
    { "clubId": "019f3239-ad1a-7dea-a050-0676d0d7b9cb", "clubName": "Another FC", "value": 1, "rank": 2 }
  ]
}
```

`value` is a transfer count (integer).

---

## `GET /api/stats/most-development`

Ranks clubs by total player development points gained via transfers (sum of `Transfer.developmentPoints`), filtered by `Transfer.occurredAt` within the period window.

**Example request**
```
GET /api/stats/most-development?period=month&limit=10
```

**Example response (200)**
```json
{
  "period": "month",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 70, "rank": 1 }
  ]
}
```

`value` is a sum of development points (integer). A club that had transfers but zero net development points would show `value: 0` — but a club with *no* transfers in the period is omitted, not shown as `0`.

---

## `GET /api/stats/most-seasons`

Ranks clubs by number of completed seasons (`SeasonRecord` rows), filtered by `SeasonRecord.createdAt` within the period window.

**Example request**
```
GET /api/stats/most-seasons?period=all&limit=10
```

**Example response (200)**
```json
{
  "period": "all",
  "results": [
    { "clubId": "019f3239-ad1a-7e1a-a050-0676d13f2750", "clubName": "Example FC", "value": 2, "rank": 1 }
  ]
}
```

`value` is a season count (integer). Note: with `period=season`, this endpoint's own filter compares each `SeasonRecord.createdAt` against that same club's most recent `SeasonRecord.createdAt` — in practice this means `period=season` on this specific endpoint will only ever show a club's single most recent season record (see the Period semantics section above).

---

## `GET /api/stats/most-trophies`

Ranks clubs by number of season titles won (`SeasonRecord` rows where `finalPosition = 1`), filtered by `SeasonRecord.createdAt` within the period window.

**Example request**
```
GET /api/stats/most-trophies?period=season&limit=10
```

**Example response (200)**
```json
{
  "period": "season",
  "results": [
    { "clubId": "019f3239-ad18-7ba6-87f7-5fb50e9f8a1f", "clubName": "Example FC", "value": 1, "rank": 1 }
  ]
}
```

`value` is a trophy count (integer). A club with season records but none at `finalPosition = 1` is omitted entirely, not shown with `value: 0`.
```

- [ ] **Step 2: Verify the examples against the live API**

With fixtures seeded (`lando php bin/console app:seed-community-stats-fixtures`), spot-check at least one example per endpoint against the real response:

```bash
curl -s "http://wunderkind-backend.lndo.site/api/stats/most-transfers?period=week&limit=10" | python3 -m json.tool
curl -s "http://wunderkind-backend.lndo.site/api/stats/most-development?period=month&limit=10" | python3 -m json.tool
curl -s "http://wunderkind-backend.lndo.site/api/stats/most-seasons?period=all&limit=10" | python3 -m json.tool
curl -s "http://wunderkind-backend.lndo.site/api/stats/most-trophies?period=season&limit=10" | python3 -m json.tool
```

Expected: real response shapes match the documented shape (keys, types, sort order, rank indexing) — exact `clubId`/`value` numbers will differ from the doc's illustrative examples since fixture data is regenerated fresh each run, and that's fine.

- [ ] **Step 3: Commit**

```bash
git add docs/api/community-stats.md
git commit -m "Add frontend API spec for community stats endpoints"
```

---

## Final verification (after all 3 tasks)

- [ ] Run `lando php vendor/bin/phpunit --no-coverage` one more time — same pre-existing baseline, no new failures.
- [ ] Load the landing page end-to-end in a browser with fixture data seeded, click through all 4 period tabs, confirm the section looks right against the approved mockup (2×2 grid, larger cards).
- [ ] Confirm `git log` on this branch shows 3 clean, independently-revertable commits (one per task) on top of `feat/community-stats-landing-page`.
