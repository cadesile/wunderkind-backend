# Periodic Leaderboard Awards + Immutable Period Archives

**Status:** planned, not started. Written 2026-10-28, parked while other work is in flight.

---

## Context

The leaderboard works and the data it collects is fine. What's missing is a reason to care
about it *this month*. Today `leaderboard_entry` holds a perpetually-recomputed cumulative
standing: nothing closes a period, nothing rewards topping a chart, and nothing records who
was top last month. Weekly rows accumulate forever and are never read again.

This adds, on top of the existing system:

- **Admin-configured award definitions** — an admin picks which charts award, at what cadence
  (weekly/monthly/quarterly), to how many places, with a distinct reward per place.
- **Growth scoring** for period boards, so each period is a true league table.
- **Immutable period snapshots** — "October 2026 Golden Boot" archived forever, read by the
  landing page and admin.
- **Rewards delivered through the existing inbox-accept path.** No new claim mechanism and no
  new client endpoint.

### The one non-obvious constraint driving the whole design

`LeaderboardEntry.score` is **an absolute snapshot of a cumulative total, re-read on every
write** — not an accumulator. `SyncService.php:204-231` writes `$club->getReputation()` into
both the `all-time` row and the ISO-week row (the same number), and
`LeaderboardCalculationService::computeAggregateScores()` writes career totals from
`PlayerCareerStat` / `UserLedger` / `SeasonRecord`.

So **deleting rows at a period boundary resets nothing.** The next sync and the next `*/5`
cron tick repopulate them with identical cumulative numbers. The existing weekly board proves
it: it gets a fresh `period` key every ISO week and still shows all-time career goals.

The only thing that resets a score is a **baseline**: `score = cumulative_now −
cumulative_at_period_start`. That is what this implements, and it is what makes a period
genuinely winnable by a club that isn't the all-time leader.

**The baseline lives on the live period row itself, not in a separate table and not in the
archive.** `leaderboard_entry` already holds exactly one row per `(club, category, period)`,
so two extra columns on it add *zero rows* — the baseline is free. (Using the archive as the
baseline would be wrong: the archive is bounded to a top-N for display, while a baseline is
needed for every eligible club. Using a separate baseline table would be redundant with a
table that already has the right grain.)

### Decisions already taken

| Decision | Choice |
|---|---|
| Which charts award | Admin-configurable subset; nothing awards until explicitly enabled |
| Depth | Top N per definition, distinct reward per place |
| Recipient | Club wins; `user_id` frozen on the archive row so "awards won by this account" is one indexed query |
| `UserLedger` row | **No.** Its before/after chain is a dividend-draw (club→user transfer) ledger; a prize paid into a club balance is not a user-level balance change, and inserting one would corrupt `UserLedgerRepository::getCurrentBalance()` and the landing page's Rich List |
| Archive consumers | Landing page + admin only. No new client endpoint — the reward still arrives via the existing `GET /api/inbox` |
| Interval | Per definition, not one global setting |
| Period scoring | Growth everywhere |

---

## 1. Period keys and cadence

### `src/Enum/Leaderboard/AwardCadence.php` (new)

`WEEKLY = 'weekly'`, `MONTHLY = 'monthly'`, `QUARTERLY = 'quarterly'`. Plus `label()`.

### `src/Service/Leaderboard/LeaderboardPeriod.php` (new)

`final class`, **all-static and dependency-free** — the `TrophyConfigFactory` /
`CountryContentRegistry` precedent, because entities, commands and services all need it and
an entity cannot be injected.

```php
const ALL_TIME = 'all-time';

currentWeekKey(?\DateTimeImmutable $at = null): string   // format('o-\WW') — THE one implementation
keyFor(AwardCadence $c, \DateTimeImmutable $at): string
cadenceOf(string $key): ?AwardCadence
boundsOf(string $key): ?array                            // [start inclusive, end EXCLUSIVE], UTC
nextKey(string $key): ?string
previousKey(string $key): ?string
isValidKey(string $key): bool
label(string $key): string                               // "Week 44, 2026" / "October 2026" / "Q4 2026"
```

Key formats, all inside the existing `varchar(20)`:

| Cadence | Format | Example | Len |
|---|---|---|---|
| weekly | `o-\WW` | `2026-W44` | 8 |
| monthly | `Y-\Mm` | `2026-M10` | 8 |
| quarterly | `Y-\Q` + `ceil(month/3)` | `2026-Q4` | 7 |

The `W` / `M` / `Q` discriminator makes a key parseable back to its cadence without being
told, which is what `cadenceOf()` needs. **Zero-padding is load-bearing:** within one cadence
these keys sort lexicographically (`2026-M09 < 2026-M10`, `2026-W09 < 2026-W44`), and the
close job's monotonic claim guard (`last_closed_period_key < :dueKey`) depends on it. A test
pins it.

The weekly key is **byte-identical to today's ISO-week key**, so a weekly definition awards
on the board the game already maintains — one less period to populate.

`boundsOf()` is the **first thing in the repo that parses a period string back to dates**
(weekly via `setISODate()` then `setTime(0,0)`; monthly/quarterly via `createFromFormat` on
the derived first day). It is what lets the archive freeze `period_starts_at` /
`period_ends_at` so no later reader ever re-derives them.

### The four duplicated call sites

`SyncService.php:208`, `GenerateLeaderboardsCommand.php:57` and `:58`,
`DecayReputationCommand.php:148` all become `LeaderboardPeriod::currentWeekKey()`. **The
returned string is byte-identical** — same `format('o-\WW')`, just in one place.
`LeaderboardPeriodTest` pins it against the raw expression for an ISO-week-53 year and a
"Jan 1 belongs to the previous ISO year" date, so the de-duplication is provably
non-regressive. No migration touches `leaderboard_entry.period`.

Deliberately **not** reusing `src/Enum/StatsPeriod.php` / `PeriodResolver`: that is a
*rolling-window* vocabulary for a different board family (`/api/stats/*`), where `month`
means `-30 days`, not a calendar month, and which carries cases (`last_6_hours`, `season`,
`all`) that are meaningless here. Sharing a type whose two consumers disagree on what `month`
means is how `PeriodResolver`-shaped bugs get written.

### Which periods get populated

New `src/Service/Leaderboard/ActivePeriodService.php` returns
`['all-time', LeaderboardPeriod::currentWeekKey($now)]` ∪ `{cadence->keyFor($now)}` for every
**enabled** definition. `SyncService` and `GenerateLeaderboardsCommand` ask it instead of
hardcoding their period lists — so `all-time` and weekly stay on unconditionally (no
regression) and monthly/quarterly rows start being written only once an admin enables such a
definition. Memoised per request; the definition table holds a handful of rows.

### One contract-safe hardening in `LeaderboardController`

`?period=` stays permissive (validating it strictly would be a contract change and could 400
a client that today gets an empty 200). But the controller short-circuits: only
`LeaderboardPeriod::isValidKey($period)` reaches `getLeaderboard()`; anything else returns the
**same empty-200 shape** without touching the DB. Visible behaviour is identical; what
disappears is the ability for any string on the internet to mint a cache key and trigger a
`recalculate()` **write**. Lands in its own step and stands alone.

---

## 2. Growth scoring

Two new nullable columns on `src/Entity/LeaderboardEntry.php`:

```php
/** Cumulative value when this period first observed this club. Null on 'all-time' rows, which are never growth-scored. */
#[ORM\Column(name: 'baseline_score', type: 'bigint', nullable: true, options: ['unsigned' => true])]
private ?int $baselineScore = null;

/** Raw cumulative at the last observation — the truthful value, before the clamp below. */
#[ORM\Column(name: 'cumulative_score', type: 'bigint', nullable: true, options: ['unsigned' => true])]
private ?int $cumulativeScore = null;
```

and one new mutator, which is the entire mechanism:

```php
public function observeCumulative(int $cumulative): void
{
    $this->baselineScore ??= $cumulative;          // first sighting in this period
    $this->cumulativeScore = $cumulative;
    $this->score = max(0, $cumulative - $this->baselineScore);
    $this->updatedAt = new \DateTimeImmutable();
}
```

`setScore()` stays and is used only for `all-time` rows.

**Three numbers, not one, and only the cumulative chains.** The next period subtracts the
*cumulative*, never a previous growth value, so growth never compounds its own clamping.

**`max(0, …)` is a product rule, not a safety net.** `score` is `bigint` **unsigned**
(`LeaderboardEntry.php:26`) so a negative would be a DB error — and growth genuinely goes
negative, because `club_reputation` decays daily (`DecayReputationCommand`, cron `20 3 * * *`).
Storing `cumulativeScore` separately is exactly what stops the clamp from corrupting the next
period's baseline: clamped to 0, `baseline + score` would no longer equal the truth, and the
club would be permanently suppressed.

### Call sites to convert

Split on the existing `$isAllTime` / period check — `all-time` keeps `setScore()`, every other
period gets `observeCumulative()`:

- `src/Service/SyncService.php:204-231` — the per-sync `CLUB_REPUTATION` / `FANATICS` upserts.
- `src/Service/LeaderboardCalculationService.php:141-150` — the aggregate write loop, **and**
  the HOF / career-earnings stale-zeroing pass at `:166-179` (`observeCumulative(0)` is
  correct there: a club absent from `$rows` has cumulative 0).
- `src/Command/DecayReputationCommand.php` — `syncLeaderboardScore()`. **Easy to miss and a
  real bug if skipped:** it currently writes the decayed reputation into *every existing* row
  via `findAllForClubAndCategory()`, which on a growth board writes a standing value into a
  growth row.

One writer path, one mutator, everywhere. That is what keeps the two existing writers
(`SyncService` and the `*/5` cron) from fighting over the same row.

### Baselines are stamped lazily, and that is the simplification

Stamped at first observation *inside* the period — so up to one cron tick (5 min) or one sync
of drift at a boundary. Accepted and documented: it is ~0.01% of a month.

What it buys is large: **no pre-seeding pass, no baseline table, no seed archive, and no
"first period has no baseline" special case.** A definition's first period is growth-scored
like any other, measured from each club's first sighting in it. The same mechanism closes the
disable→re-enable hazard structurally rather than by a guard someone can delete: while a
definition is disabled, `ActivePeriodService` stops emitting its key, so no rows exist; on
re-enable, rows are created fresh and growth is measured from the re-enable. **A disabled-then-
re-enabled definition therefore cannot retro-award the all-time leader.**

### `computeAggregateScores()`'s `$isAllTime` branch is unchanged

Period rows keep reading live in-place `PlayerCareerStat`; `all-time` keeps its reset-aware
reconstruction from `PlayerCareerStatSnapshot`. Growth makes the distinction moot for period
boards — a delta of a live cumulative is the gain during the period either way.

### Ordering and ties

`score DESC, club_id ASC` — deterministic, so a reclaimed retry re-reading the same data
produces the same places. Documented: a tie goes to the lower UUID.

### Baseline-missing rule

| Case | Baseline | On the board? | Award-eligible? |
|---|---|---|---|
| Has a baseline | its own stamped value | yes | yes |
| No baseline, `club.createdAt >= period_starts_at` | 0 | yes | **yes** — it did all its accumulating inside the period |
| No baseline, `club.createdAt < period_starts_at` | 0 | yes (honest: it arrived on the board this period) | **no** — its cumulative is partly pre-period |

The third row covers a club crossing the `currentSeason > 1` gate mid-period. The archive
records `baseline_missing` and `award_eligible` per row, so **the archive explains its own
exclusions** rather than leaving a reader to re-derive the rule.

---

## 3. New entities — four tables

### 3.1 `LeaderboardAwardDefinition` → `leaderboard_award_definition`

Admin-managed config. Mutable, plain accessors (not constructor-only).

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid (UuidV7) | no | |
| `name` | varchar(100) | no | "Golden Boot of the Month" — frozen onto each archive, used in the inbox subject and the landing card |
| `category` | varchar, `enumType: LeaderboardCategory` | no | |
| `cadence` | varchar, `enumType: AwardCadence` | no | |
| `minimum_score` | bigint default 0 | no | don't award a growth of 0 — otherwise a dead chart pays an idle club |
| `is_enabled` | boolean default **false** | no | nothing awards by accident |
| `last_closed_period_key` | varchar(20) | **yes** | the monotonic anchor; NULL until the first close |
| `last_closed_at` | datetime_immutable | yes | |
| `created_at`, `updated_at` | | | |

- `#[ORM\UniqueConstraint(name: 'uq_leaderboard_award_definition_category_cadence', columns: ['category', 'cadence'])]`
  — **load-bearing:** it is what makes `(category, period_key)` a sound non-nullable claim key
  on the archive. Two definitions on the same category but different cadences produce
  different period keys, so they cannot collide.
- `#[ORM\Index(columns: ['is_enabled'], name: 'idx_lad_enabled')]` — read by the close tick and
  by `ActivePeriodService`.
- `OneToMany` → `placements`, `cascade: ['persist','remove']`, `orphanRemoval: true` (so
  EasyAdmin's `CollectionField` works).

**There is no `placements` count column.** Top N is the highest `place` among the child rows —
one source of truth instead of two that can disagree. "Top N configurable per definition" is
still satisfied: the admin adds N placement rows.

### 3.2 `LeaderboardAwardPlacement` → `leaderboard_award_placement`

One row per prize place.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | no | |
| `definition_id` | FK → definition | no | `onDelete: 'CASCADE'` — config, not archive; a placement is meaningless without its definition |
| `place` | smallint | no | 1-based |
| `prize_pence` | bigint default 0 | no | synthesized as a `ledger_delta` effect — the `CompetitionTemplate::$victorPrize` precedent: the common cash case needs no `RewardTemplate` row |
| `reputation_delta` | integer default 0 | no | synthesized as a `reputation_delta` effect |
| `reward_template_id` | FK → `reward_template` | **yes** | `onDelete: 'SET NULL'`; anything richer than cash + reputation |

`#[ORM\UniqueConstraint(name: 'uq_leaderboard_award_placement_def_place', columns: ['definition_id', 'place'])]`

**A real child entity with a real FK, not a json place→reward map on the definition.** Three
reasons: referential integrity to `RewardTemplate` (a slug in json dangles); `CollectionField`
plus a plain `FormType` is EasyAdmin's native idiom, whereas `Field::new('json')->setFormType()`
hits the documented injected-`CollectionType`-options trap (CLAUDE.md Key Gotchas) and needs a
form theme with a `{% block ..._widget %}`; and `CompetitionTemplate` already relates to
`RewardTemplate` by FK, so this is consistent. It also removes the need for a bespoke json
shape validator.

### 3.3 `LeaderboardPeriodArchive` → `leaderboard_period_archive`

Immutable header, one per closed period. **Carries the claim.** The `SeasonRecord` /
`SeasonSnapshot` shape.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | no | |
| `definition_id` | FK → definition | **yes** | `onDelete: 'SET NULL'` — the archive must outlive an admin deleting a definition (the `UserLedger::$sourceSyncRecord` nullable-FK precedent). Admin links only; never identity |
| `category` | `enumType: LeaderboardCategory` | no | **denormalised** so the archive is readable with the definition gone — and so the unique below can be plain |
| `cadence` | `enumType: AwardCadence` | no | denormalised, same reason |
| `definition_name` | varchar(100) | no | frozen admin label |
| `period_key` | varchar(20) | no | |
| `period_starts_at` | datetime_immutable | no | **frozen instants** — nothing downstream re-parses a key |
| `period_ends_at` | datetime_immutable | no | exclusive |
| `baseline_period_key` | varchar(20) | yes | which close last supplied the baselines this one consumed |
| `entry_count` | **integer** default 0 | no | **not smallint** — the eligible club count can exceed 32767 |
| `archived_entry_count` | smallint default 0 | no | how many rows actually landed in the bounded entry table |
| `placements_awarded` | smallint default 0 | no | |
| `status` | `enumType: PeriodArchiveStatus` (`pending`/`archived`/`skipped`) | no | |
| `claimed_at` | datetime_immutable | no | the lease clock |
| `closed_at` | datetime_immutable | yes | set with `archived` / `skipped` |

- `#[ORM\UniqueConstraint(name: 'uq_leaderboard_period_archive_category_period', columns: ['category', 'period_key'])]`
  — **both columns NOT NULL, so this is a plain unique, not a partial one.** No
  `options: ['where' => …]`, so the `pg_get_expr` predicate-string diff-churn trap and the
  `EntrantRewardClaim` two-partial-index dance both do not apply. That is the whole reason
  `category` is denormalised here instead of keying on the nullable `definition_id`.
- `#[ORM\Index(columns: ['status', 'period_ends_at'], name: 'idx_lpa_status_period')]`
- `#[ORM\Index(columns: ['cadence', 'closed_at'], name: 'idx_lpa_cadence_closed')]` — the
  "last month's winners" read.

Constructor-only for every identity field, plus exactly three mutators —
`markArchived(int $entryCount, int $archivedEntryCount, int $placementsAwarded, \DateTimeImmutable $at)`,
`markSkipped(...)`, `reclaim(\DateTimeImmutable $at)`. No general setters.

### 3.4 `LeaderboardPeriodArchiveEntry` → `leaderboard_period_archive_entry`

The frozen board, **bounded**: `LeaderboardPeriodArchive::ARCHIVED_ENTRY_LIMIT = 100`, plus
every awarded club regardless of rank (so an award always has its own row to point at). This
row doubles as the award record.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | uuid | no | |
| `archive_id` | FK → archive | no | `onDelete: 'CASCADE'` — a child of its header |
| `rank_position` | integer | no | **column name `rank_position`** — `rank` is reserved, same reason `LeaderboardEntry` does it |
| `club_id` | varchar(36) | no | **denormalised, no FK** — `SeasonRatingsSnapshot` / `ClubSpotlight` precedent: must survive club deletion |
| `club_name` | varchar(100) | no | frozen |
| `user_id` | varchar(36) | **yes** | the credited account, denormalised + indexed; nulled on account deletion (§8) |
| `score` | bigint unsigned | no | the growth value as ranked |
| `cumulative_score` | bigint unsigned | no | the absolute snapshot at close |
| `baseline_score` | bigint unsigned default 0 | no | what was subtracted |
| `baseline_missing` | boolean default false | no | explains award (in)eligibility |
| `award_eligible` | boolean default true | no | the archive explains its own exclusions |
| `display_label` | varchar(100) | yes | **frozen** — names the player who scored *in that period* for `golden_boot`/`playmaker`/`iron_man`; the live row overwrites it next week, which is the whole point of freezing |
| `home_kit_config` / `away_kit_config` / `badge_config` | json | yes | frozen club identity for the landing page |
| `country_code` | varchar(2) | yes | frozen, for the 2-letter chip |
| `awarded` | boolean default false | no | this rank received a prize |
| `place` | smallint | yes | the placement place, when awarded |
| `applied_effects_json` | json | yes | frozen effects (`EntrantRewardClaim::$appliedEffectsJson` precedent) |
| `reward_template_id` | FK → `reward_template` | yes | `onDelete: 'SET NULL'` |
| `inbox_message_id` | varchar(36) | yes | **plain string, not a FK** — inbox messages cascade-delete with the club, and an award must not vanish with its delivery receipt. Admin forensics only |

- `#[ORM\UniqueConstraint(name: 'uq_lpae_archive_club', columns: ['archive_id', 'club_id'])]`
- `#[ORM\UniqueConstraint(name: 'uq_lpae_archive_rank', columns: ['archive_id', 'rank_position'])]`
- `#[ORM\UniqueConstraint(name: 'uq_lpae_archive_place', columns: ['archive_id', 'place'])]` —
  **partial**, `options: ['where' => '(place IS NOT NULL)']`, because `place` is nullable and
  PostgreSQL treats NULLs as distinct. Declared on the entity with the predicate exactly as
  `pg_get_expr(indpred, indrelid)` reports it, per CLAUDE.md, so `schema:create` builds it and
  `migrations:diff` stops proposing to drop it. This is the one partial index in the feature.
- `#[ORM\Index(columns: ['club_id'], name: 'idx_lpae_club')]`,
  `#[ORM\Index(columns: ['user_id'], name: 'idx_lpae_user')]` — "has this club / account ever
  placed", which is the admin screen.

Constructor-only, getters only, plus one `markAwarded(...)` applied inside the close
transaction.

### 3.5 Why four tables, and where folding breaks

| Fold | Verdict |
|---|---|
| placement → definition (json) | **Rejected.** EasyAdmin json + custom-form-type trap; loses the `RewardTemplate` FK; needs a bespoke validator |
| award → archive entry | **Taken.** A separate `leaderboard_award` table would buy a `UNIQUE(archive, place)` idempotency key — but the archive header's own `UNIQUE(category, period_key)` is already the claim gate (§4), and the partial `uq_lpae_archive_place` gives the per-place guarantee anyway. Two tables describing the same fact is worse |
| baseline → its own table | **Rejected.** `leaderboard_entry` already has the exact `(club, category, period)` grain, so two columns there add zero rows |
| entries → archive header (json blob) | **Rejected.** The landing page's top-N read becomes a json scan, and `idx_lpae_user` / `idx_lpae_club` stop existing |
| archive header → definition | **Rejected.** The header is one immutable row *per close*; the definition is one mutable row. Folding loses the claim key |

---

## 4. The close job

### Command

`app:leaderboards:close-periods` → `src/Command/CloseLeaderboardPeriodsCommand.php`.
Options: `--definition=<uuid|category>`, `--dry-run`, `--force` (close the current open period
early, for ops/testing), `--at=<ISO8601>` (pin "now", for tests). A thin `SymfonyStyle`
wrapper over the service, returning `SUCCESS` even when nothing qualified — the
`GenerateClubSpotlightCommand` precedent.

Services: `src/Service/Leaderboard/LeaderboardPeriodCloseService.php` (driver + claim),
`LeaderboardAwardDeliveryService.php` (§5), `PeriodAwardsBoardService.php` (§7).

### Claim: `ON CONFLICT` **and** a lease

```sql
INSERT INTO leaderboard_period_archive
  (id, definition_id, category, cadence, definition_name, period_key,
   period_starts_at, period_ends_at, baseline_period_key, status, claimed_at)
VALUES (..., 'pending', :now)
ON CONFLICT DO NOTHING
```

Raw DBAL, un-targeted `ON CONFLICT`, matching `RewardApplierService::recordClaim()` — and raw
specifically because a `UniqueConstraintViolationException` out of `flush()` **closes the
EntityManager**, which in a cron tick would strand every remaining definition.

- `affected > 0` ⇒ this process owns the close.
- `affected === 0` ⇒ re-read the row:
  - terminal status ⇒ **skip**, already done;
  - `pending` and `claimed_at >= now − CLAIM_LEASE_SECONDS` ⇒ **skip**, someone is mid-close;
  - `pending` and the lease has expired ⇒ reclaim with a conditional
    `UPDATE … SET claimed_at = :now WHERE id = :id AND status = 'pending' AND claimed_at < :reclaimBefore`;
    if that affects 0 rows, skip. Then `$em->refresh()` and
    **`DELETE FROM leaderboard_period_archive_entry WHERE archive_id = :id`** — a pending
    archive's entries are by definition incomplete, and discarding them is what lets the entry
    write use the plain ORM without a unique violation on retry.

`LeaderboardPeriodArchive::CLAIM_LEASE_SECONDS = 300` — the same constant name and value as
`CompetitionRound`, so the two are recognisably one pattern.

**`last_closed_period_key` is deliberately NOT set in the claim.** Per CLAUDE.md's "never
commit a status ahead of the work it describes", it is set on the entity in the single flush
below. The claim row itself is the gate; the anchor is the record.

### Due-check — two parts, both required

1. `periodKeyToClose` = `nextKey(last_closed_period_key)` when that key's `period_ends_at <= now`
   (**catch-up, oldest first** — a cron outage settles each missed period in order, one per
   pass, rather than silently skipping two months), else
   `previousKey(keyFor($cadence, $now))`.
2. **And** `boundsOf($periodKeyToClose)[1] <= now` — the period has actually ended.

Two parts so clock skew or a stray `--force` cannot close a period that is still open.

### Order of operations

1. due-check
2. **claim** (raw, auto-committed on its own — deliberately outside any wrapping transaction,
   so the claim survives a later failure rather than silently re-opening the period)
3. **final score + rank** — `LeaderboardCalculationService::recalculate($category, $dueKey)`
   then `invalidate()`, then read the eligible ranked rows via a new
   `LeaderboardEntryRepository::findRankedForAward($category, $period, int $limit)`:
   `score DESC, club_id ASC`, filtering **`c.currentSeason > 1`** and **`c.isSpoof = false`**,
   with `addSelect` on club *and* user so the award's `user_id` and the inbox club arrive in
   one query
4. **archive entries** — top 100 plus every awarded club, freezing club identity and
   `display_label`. Streamed: hold the archive *id* (not the object) and `flush()` + `clear()`
   every 200 rows, per the mandatory `toIterable()` / clear rule
5. **award** — per place with a configured placement and an `award_eligible` club at that rank
   and `score >= minimum_score`: `markAwarded()` on the entry. Effects = synthesized
   `ledger_delta`(`prize_pence`) + `reputation_delta`(`reputation_delta`) + the attached
   `RewardTemplate`'s effects **when `isActive()`**, merged
6. **deliver** — one `InboxMessage` per awarded place via `InboxService::createMessage()`
   (persists, no flush), per §5
7. **one flush** — archive `markArchived(...)`, every entry, every inbox message,
   `definition.lastClosedPeriodKey` / `lastClosedAt`, and each entry's `inbox_message_id`.
   Mirrors `CompetitionResultsService::finalizeRound()`, where claims, statuses and inbox
   messages land together
8. **prune, after that flush commits** —
   `DELETE FROM leaderboard_entry WHERE category = :c AND period = :dueKey`, raw SQL. The
   `*_squad` precedent: committing a delete before a failed flush would clear the board with
   nothing archived. This is the visible "the table clears"
9. **invalidate** both the closed key and the new open key, so a cached page for the closed
   period cannot outlive its rows
10. **push** per winner, after the commit

`--dry-run` runs 1 and 3 and prints. It claims nothing.

### Inline, not queued

**All of it inline in the cron tick. No new Messenger transport, no new message class.** Per
due definition the work is one indexed read, ≤100 entry inserts and ≤N messages; the number of
due definitions per tick is bounded by the enabled count (≤12 categories × 3 cadences,
realistically a handful). That is nothing like a 32-fixture match-engine round, which is what
justified the `competition` transport. The only queued part is the push, which already goes to
`async` via `PushNotificationService`.

**What would change this:** if the archive write ever has to cover tens of thousands of rows
per definition per tick, split step 4 onto its own message with a re-read clamp (the
`TopUpStarterPoolMessageHandler` pattern).

### Idempotency guarantee, stated exactly

> For a given `(category, period_key)` at most one `leaderboard_period_archive` row can exist,
> and within one archive at most one entry can hold a given `place`. Therefore a re-run —
> concurrent, retried, or replayed after a crash — produces no second archive, no second award
> and no second inbox message. The only non-idempotent writes are the archive entry rows, which
> are deleted and rewritten wholesale on reclaim. A crash before step 7 leaves the archive
> `pending`, the live `leaderboard_entry` rows **unpruned**, and the period reclaimable after
> 300s. The monotonic `last_closed_period_key` anchor plus the lazy-baseline rule (§2) is what
> stops a long-disabled definition from retro-awarding on re-enable.

### Cron

`docker/leaderboards-close-periods.sh` — the `starter-pool-audit.sh` template verbatim:
`#!/bin/sh`, `CONSOLE=/var/www/html/bin/console`,
`PHP="su-exec www-data php -d memory_limit=256M"`, a UTC `log()` helper,
`=== leaderboards-close-periods start/done ===` bookends, exit status propagated, log redirect
in the crontab line not the script. **With `flock -n 9`** on
`/tmp/leaderboards-close-periods.lock` — sub-hourly, and a slow close on a large club table is
exactly what stacks (unlike `reputation-decay.sh`, whose own header documents why a *daily* job
needs none). The header comment notes the archive claim already makes re-entry harmless and
flock is the cheap outer guard.

**Schedule: `4,14,24,34,44,54 * * * *`.** Every 10 minutes, offset by 4 so it lands on no other
job's minute (`*/5` → 0,5,10…; `*/15` → 0,15,30,45; `*/10` → 0,10,20…; `0 */3`; `20 3`;
`35 *`). Ten minutes rather than one: a period boundary is a calendar instant and nothing
downstream is minute-sensitive (the prize is inbox mail the player reads whenever), and staying
off the 5-minute `leaderboards-generate` tick means the two never contend on the same rows in
the same minute.

**Four Dockerfile edits — the last is the one that gets forgotten:**

1. `COPY docker/leaderboards-close-periods.sh /usr/local/bin/…` beside the other COPYs (~line 62).
2. A paragraph in the human-readable schedule comment block (starts `Dockerfile:64`), wording
   modelled on `post-community-stat-tick`: *"every 10 min at :04 — leaderboards-close-periods:
   closes any LeaderboardAwardDefinition whose configured period has ended … **this entry's own
   schedule is just the tick granularity, not the award cadence**, which is per-definition and
   configured at /admin."*
3. The crontab line in the `printf` block (`Dockerfile:159-177`):
   ```
   '4,14,24,34,44,54 * * * * /usr/local/bin/leaderboards-close-periods.sh >> /var/log/leaderboards-close-cron.log 2>&1'
   ```
4. The script path appended to the single long `RUN chmod +x /usr/local/bin/…` line
   (`Dockerfile:180`). Omitting it ships a non-executable script and a cron entry that silently
   never runs.

`docs/deploy/hetzner.md`'s Cron section (line ~332) needs a paragraph after the
starter-pool-audit one: the tick-granularity-vs-cadence split, the 300s claim lease, where the
log is, and that **a period stuck `pending` past its lease is the signal something is failing
mid-close**.

**No crontab under Lando** — local testing is
`lando php bin/console app:leaderboards:close-periods --force --dry-run`, then without
`--dry-run`.

---

## 5. Reward delivery

No new claim or apply mechanism. An award becomes exactly what a competition win already
becomes, following `src/Service/Competition/RewardApplierService.php:66-83`.

### `senderType: MessageSenderType::COMPETITION`. Naming the trap.

`InboxService::acceptMessage()` routes **only** `COMPETITION` and `FRIENDLY` to
`acceptCompetitionReward()`. A `SYSTEM` message carrying `offerData.effects` renders
`requiresAccept: true` in `InboxController::serializeMessage()` and then hits
`default => null` on accept — **the player taps Accept and gets nothing, with no error
anywhere.** "The system awarded you a leaderboard prize" reads like system mail, which is
precisely why someone will one day "tidy" this to `SYSTEM`. It must not be.

**And do not add a new `MessageSenderType` case.** `senderType` is serialized to a live client
(`InboxMessage.senderType` is `#[ORM\Column(type: 'string', enumType: …)]`, so a new case needs
no migration — the cost is not schema, it is contract). A case the client has never seen
changes how the message renders, and this repo is backend-only. `COMPETITION` already means
"you won something, accept to claim", and the discriminator clients are documented to key off
is `offerData.type`, which carries `LEADERBOARD_AWARD` either way. So the semantic distinction
is preserved in the field designed to carry it, at zero client risk.

§9 pins this with an **end-to-end** test, not a senderType assertion — see below.

### `RewardTemplate` reused as-is, no schema change

Its own docblock already says *"reusable across competitions and future in-game milestones"*;
`LeaderboardAwardPlacement.rewardTemplate` is the second consumer that sentence anticipated.
Only `isActive()` templates contribute effects, so deactivating a template stops it paying out
without unwiring every definition. Effect types stay the same string discriminators in the
`effects` JSON (`ledger_delta`, `reputation_delta`, `unique_asset_grant` — still a
recorded-but-undelivered stub). `RewardTemplateCrudController::effectsHelp()` remains the
canonical list and gains one sentence noting leaderboard awards use it too.

### `offerData`

```json
{
  "type": "LEADERBOARD_AWARD",
  "link": {"kind": "leaderboard", "category": "club_goals", "period": "2026-W44"},
  "effects": [ /* merged */ ],
  "category": "club_goals",
  "categoryLabel": "Club Goals",
  "period": "2026-W44",
  "periodLabel": "Week 44, 2026",
  "cadence": "weekly",
  "place": 1,
  "score": 1234,
  "archiveEntryId": "<uuid>"
}
```

`effects` **at the top level** is load-bearing twice over: `acceptCompetitionReward()` reads
`$offerData['effects']`, and `serializeMessage()` derives `requiresAccept` from its
non-emptiness.

- `relatedEntityType = 'LeaderboardPeriodArchiveEntry'`,
  `relatedEntityId = (string) $entry->getId()` — mirroring `RewardApplierService`'s
  `'EntrantRewardClaim'` + claim id. `inbox_message_id` is written back in the same flush, so
  the link is navigable both ways in admin.
- Subject: `"You placed 1st — Club Goals, Week 44"`. Body names the score and that accepting
  claims the prize. A private `ordinal(int)` helper on the delivery service.
- **Never mutates `Club`.** Same docblock paragraph as `RewardApplierService`:
  `SyncService::process()` does an absolute `Club::setBalance($request->balance)`, so a
  server-side `addFunds()` on the cron's schedule would just be overwritten by the next sync.
  The accept path is what makes the subsequent overwrite correct.
- `GET /api/inbox` and `POST /api/inbox/{id}/accept` need **no change whatsoever.**

### Push

`type = 'LEADERBOARD_AWARD'` — uppercase, matching `ROUND_DRAWN` / `COMPETITION_COMPLETED` /
`MATCH_RESULT`. (Note `ClubSpotlightService` uses lowercase `club_spotlight`; follow the
uppercase majority and say so in the doc.) Payload
`['type' => 'LEADERBOARD_AWARD', 'category' => …, 'period' => …, 'place' => '1', 'archiveEntryId' => …]`.
One push per winner, after the commit, via
`PushNotificationService::notifyUsers([(string) $club->getUser()->getId()], …)` — the only
method a call site should touch. `ClubSpotlightService::notifyOwner()` is the inbox+push
precedent. An unrecognised `type` is contractually a client-side no-op that still displays, so
**no client release is needed**; add the row to
`.context/stages/04_interfaces/output/push-notifications.md`.

---

## 6. A closed period becomes read-only

This is the subtlest bug in the feature and it must ship **with** §4, not after.

`LeaderboardCalculationService::getLeaderboard()`'s cache callback calls `recalculate()`
(`:46-53`), so **a cold public GET writes to the database.** And `computeAggregateScores()`
will happily write rows for *any* period string. So after the close job prunes
`period = '2026-M10'`, a single public `GET /api/leaderboard/golden_boot?period=2026-M10`
would resurrect the entire pruned board with fresh lazy baselines — a near-empty board, plus
thousands of junk rows.

Fix: **a closed period is served from the archive, never recomputed.**

- New `LeaderboardPeriodArchiveRepository::findClosed(LeaderboardCategory, string $period): ?LeaderboardPeriodArchive`.
- `getLeaderboard()`'s cold callback checks it first; if closed, hydrate `LeaderboardItemDto`s
  from the archive entries — which already carry `club_name`, `country_code`, kit/badge
  configs, `score`, `display_label` and `rank_position` — and skip `recalculate()` entirely.
- `recalculate()` itself returns `0` early for a closed period, so neither the admin
  "generate leaderboards" button nor the `*/5` cron can resurrect one.

Pleasant side effect: past periods become permanently cacheable, and the archive is the single
source of truth for them.

---

## 7. Admin and landing surfaces

### Admin

| Controller | Mode | Notes |
|---|---|---|
| `LeaderboardAwardDefinitionCrudController` | **full CRUD** | the thing admins configure |
| `LeaderboardPeriodArchiveCrudController` | **read-only** (`disable(NEW, EDIT, DELETE)`) | the `LeaderboardEntryCrudController` / `UserLedgerCrudController` pattern; sorted `closedAt DESC` |
| `LeaderboardPeriodArchiveEntryCrudController` | **read-only** | **this is the "awards won by this account" screen** — filterable on `userId` and `awarded`, which is why those are a denormalised indexed string and a boolean |

**Definition CRUD fields:** `name`; `category` (`ChoiceField` over `LeaderboardCategory::cases()`,
with help text on what growth means per category — e.g. `transfer_record` growth is "biggest
fee beaten this period"); `cadence` (`ChoiceField` over `AwardCadence`); `minimumScore`;
`isEnabled` (`BooleanField->renderAsSwitch(true)`); `lastClosedPeriodKey` / `lastClosedAt`
**read-only on detail, `->hideOnForm()`** (runtime state — an editable anchor is a loaded gun);
and placements as a **`CollectionField`** of `src/Form/Admin/LeaderboardAwardPlacementType.php`
(`allowAdd` / `allowDelete`, `rewardTemplate` as an `EntityType` filtered to `isActive`). No
second CRUD controller for placements.

**EasyAdmin gotchas that apply:**

- The placement mapping is a child entity + `CollectionField`, deliberately *not* a json column
  with a custom form type, precisely to avoid the documented
  `Field::new('json')->setFormType()` → injected `CollectionType` options →
  `The options … do not exist` trap. If that is ever overridden, `configureOptions()` must
  `setDefined(['allow_add','allow_delete','delete_empty','entry_options','entry_type'])` and the
  widget needs `$crud->addFormTheme(...)` plus a `{% block …_widget %}`.
- Any computed index column (e.g. "next close due") needs a dummy `->setValue('')` placeholder
  or `formatValue()` is silently ignored — and it must be a `TextField` returning icon HTML,
  never a virtual `BooleanField`, whose template reads `field.value` directly.

**`persistEntity()` / `updateEntity()` on the definition CRUD:** refuse a duplicate
`(category, cadence)` with a flash **before** flushing, rather than letting the unique
constraint throw and close the EntityManager.

**Row action `Close now`** — `Action::new('closeNow')->linkToCrudAction('closeNow')`,
CSRF-guarded, redirecting **through EasyAdmin's entry point** per
`src/Controller/Admin/CLAUDE.md`. There is no crontab under Lando, so this is how the job gets
exercised by hand. A CSRF-guarded button beside the existing `admin_generate_leaderboards`
action (`DashboardController.php:1319`) is the alternative placement.

**Archive detail page** — `templates/admin/leaderboard_period_archive_detail.html.twig`,
extending `@EasyAdmin/crud/detail.html.twig` and overriding only `main` with `{{ parent() }}`
first (the `UserCrudController` / `TranslationKeyCrudController` "override one block"
technique). Renders the frozen top 20 and each award with its effects, so the archive is
browsable without a second screen.

**Menu:** extend the existing `Sync & Leaderboards` submenu (`DashboardController` ~line 1501)
with `Award Definitions` (`fa fa-medal`), `Period Archives` (`fa fa-box-archive`), `Awards`
(`fa fa-gift`). **Nothing belongs on the hand-rolled dashboard** —
`admin_game_config` / `admin_social_connections` exist because they edit *singletons* with no
row-per-thing; an award definition is a real entity collection, which is exactly EasyAdmin's
job.

### Landing page

**`src/Service/Leaderboard/PeriodAwardsBoardService.php`** — `getBoards(int $limit = 4): array`,
returning ready-to-render rows (no summing in Twig), from two indexed queries:

- `LeaderboardPeriodArchiveRepository::latestArchivedPerDefinition(int $limit = 4)` — one query
  on `idx_lpa_cadence_closed`
- `LeaderboardPeriodArchiveEntryRepository::findTopEntriesForArchives(array $archiveIds, int $perArchive = 5)`
  — **batched**, for the same reason `findByEntrants()` / `findBothSides()` exist: don't trade
  a snapshot for an N+1 of the same size

**No new snapshot entity and no cache layer**, and the reasoning is worth keeping: `ClubSpotlight`
is a snapshot row because its source is an aggregate over `sync_record` payloads, far too
expensive per request; `OwnerSpotlightService` is live-behind-a-short-cache because it is two
indexed queries and a snapshot would mean a migration and a cron entry to forget. **This case is
better than either, because the archive already *is* the cron-written denormalised row** — it
already carries frozen club name, kit, badge and country. Added to `LandingController::index()`
as `'periodAwards' => $this->periodAwardsBoard->getBoards()` with the same inline data-read
annotation the other three keys carry. (If the enabled-definition count grows, wrap it in the
same short cache `WorldOverviewService` uses — noted in the service docblock, not built now.)

**`templates/landing/sections/_period_awards.html.twig`** — `<section id="period-awards">`,
its own `.lbaw-*` prefix (the `.ospot-*`-vs-`.spotlight-*` precedent: borrow the gold-ringed
panel family, keep your own prefix). Tokens from `public/landing.css`'s `:root`,
`border-radius: 0 !important` global, `image-rendering: pixelated`.

**This is not a revival of the orphaned `_leaderboards.html.twig`.** That file is a 12-tab,
JS-fetched widget over `/api/leaderboard/{category}` — *current standings, client-fetched*.
This is *who won last period* — frozen, server-rendered, no API endpoint. Restoring the orphan
stays a one-line include whenever wanted, and the two don't conflict. Update
`index.html.twig`'s header comment, which lists `_leaderboards` among the deliberately-orphaned
sections: still true, but a sibling awards section now exists and someone restoring the orphan
expecting this feature must be headed off. Same for
`.context/stages/05_ui/output/design-system.md:170-176`.

- Include line in `index.html.twig`'s `{% block body %}`, **between `_owner_spotlight` and
  `_testers`** — the Rich List already establishes the leaderboard reading mode there, and two
  ranked boards back to back is the design's own grouping.
- `_nav.html.twig` gains a jump link gated on **the same flag the section uses**
  (`{% if periodAwards is not empty %}`), exactly as `.nav-spotlight-cta` is gated on
  `spotlight.isLive`. The section renders nothing when empty.
- **Client-side sprites only.** Each row emits `data-badge` / `data-home-kit`
  (`|json_encode|e('html_attr')`) and an empty `.lbaw-club-badge` slot; `public/landing.js`
  gains `mountPeriodAwards()`, mounted through the existing `mountWhenVisible()` /
  `IntersectionObserver`, composing identity via **`clubBadgeIconsHtml()`** — the single
  club-identity renderer. The rule *"any club listed anywhere on the page shows at least its
  badge"* is exactly why the archive froze `badgeConfig`: it still holds for a club that has
  since been deleted. Nationality via **`clubCountryChipHtml()`** as a 2-letter chip, never an
  emoji flag. Period label comes from the frozen `period_starts_at` / `period_ends_at`, never
  re-derived from the key.

---

## 8. Migrations, account deletion, import/export, docs

### Migrations

Two hand-written migrations (`migrations/Version<ts>.php`, `final class … extends
AbstractMigration`, Postgres syntax only, a multi-paragraph docblock explaining **why** —
including why no backfill is needed — plus a one-sentence `getDescription()`).

1. **`leaderboard_entry` growth columns.** `ADD baseline_score BIGINT DEFAULT NULL`,
   `ADD cumulative_score BIGINT DEFAULT NULL`, then an explicit one-off reset of in-flight
   period boards:
   ```sql
   UPDATE leaderboard_entry
      SET baseline_score = score, cumulative_score = score, score = 0
    WHERE period <> 'all-time'
   ```
   Without it, existing ISO-week rows sit at a stale cumulative `score` with a NULL baseline
   until each is next observed, and `assignRanks()` would rank on that stale value. This makes
   the discontinuity explicit and one-off: the current week's board resets to zero growth on
   deploy, which is the intended semantics arriving a few days early.
2. **The four new tables, in one migration** — they are one feature and a half-applied set is
   useless. Generate with `doctrine:migrations:diff`, then review: the only partial index is
   `uq_lpae_archive_place`, whose `where` predicate must match `pg_get_expr(indpred, indrelid)`
   exactly or the diff will churn.

Then the mandatory, easily-forgotten `wunderkind_test` reconciliation:
`doctrine:schema:update --dump-sql --env=test`, apply, then
`migrations:sync-metadata-storage --env=test` and
`migrations:version --add --all --no-interaction --env=test`, then
`migrations:up-to-date --env=test`. The symptom of skipping it is `column "x" does not exist`
in functional tests only.

### `AccountDeletionService` must be edited, and the edit is a decision

It carries a hardcoded entity-name list (`'LeaderboardEntry'`, line 49) and explicit raw
deletes for the no-FK snapshots (`DELETE FROM App\Entity\SeasonRatingsSnapshot e WHERE e.clubId IN (:ids)`,
lines 103/160). The new tables split two ways:

| Table | On account deletion |
|---|---|
| `leaderboard_entry` | already cascades via `Club` — unchanged |
| `leaderboard_period_archive_entry` | **retain the row; null `user_id`.** The rank happened; the account identity goes |

Add an explicit comment in that method naming the retained table as *deliberately* retained —
the role the `*_audit` "deliberately not pruned" note plays for competitions — so a future
"we missed this in deletion" report is answered in the file. Consequence to state: "awards won
by this account" stops returning rows for a deleted account, which is correct. `club_name` and
the kit configs are retained (an invented club name is not personal data, and the landing page
needs the badge). **`docs/api/account-delete.md` needs one sentence.**

### Import/export — no change, and that is deliberate

- **No new `GameConfig` column**, because cadence lives per-definition. So unlike
  `statPostSchedule` / `statPostLastRunAt` there is nothing to add or deny, and
  `ConfigImportExportService::DENIED_PROPERTIES` and `ConfigImportExportCoverageTest` are
  untouched. For the record: if a global kill-switch column were ever added to `GameConfig` it
  would be **exported** (a deliberate admin choice, like `statPostSchedule`), and anything
  `*LastRunAt`-shaped would be **denied** (runtime state — restoring it rewinds every clock).
- **The new entities join no import/export service.** `ConfigImportExportService` is reflection
  over three *singleton* rows (a collection entity needs a whole new code path);
  `NarrativeImportExportService` is player-facing content; `LeagueImportExportService` is world
  data. A definition is five fields plus a reward link — re-creating it in admin is less work
  than the round-trip code plus a coverage test. Archives are historical records, like
  `SeasonRecord` / `SeasonSnapshot` / `SeasonRatingsSnapshot`, which are in no service either.
  **Archives must never be importable.**
- **No coverage test goes red.** `ConfigImportExportCoverageTest` reflects only the three
  singletons; the narrative and league round-trip tests each reflect their own entity lists.
  **Verify by running the suite — don't assume.**

### Docs

| Doc | Change |
|---|---|
| `docs/api/leaderboard.md` | New "Periodic awards & period archives" section: the three period-key formats; **that a period board is a growth board, not a standing** (the single most important thing for a reading client); that `?period=` accepts `all-time` plus recognised keys and anything else is the same empty 200; that a closed period's rows are pruned, served from the archive, and the new board starts at zeros; that archive depth means sub-top-100 history is not retained; that the prize arrives through the unchanged inbox. **No new endpoint section** |
| **new** `docs/api/leaderboard-awards.md` | The client-facing contract: `offerData.type = 'LEADERBOARD_AWARD'` and every key, `requiresAccept` semantics, `relatedEntityType`/`Id`, the push `type` + payload, and an explicit *"no new endpoint — this reaches you through the existing `GET /api/inbox` + `POST /api/inbox/{id}/accept`, which need no change"*. Style: `docs/api/competition-registration-status.md` |
| `.context/stages/03_data/output/entities.md`, `schema.md` | the four tables, every index, the no-FK/denormalised rationale per column, the baseline-on-the-live-row rule |
| `.context/stages/03_data/output/migrations.md` | the two new versions |
| `.context/stages/04_interfaces/output/services.md` | `LeaderboardPeriod`, `ActivePeriodService`, the close and delivery services, `PeriodAwardsBoardService` |
| `.context/stages/04_interfaces/output/controllers.md` | the three new admin CRUDs + the `closeNow` action |
| `.context/stages/04_interfaces/output/routes.md` | **no new API route** — note that explicitly, plus the new admin routes |
| `.context/stages/04_interfaces/output/push-notifications.md` | the `LEADERBOARD_AWARD` row |
| `.context/stages/05_ui/output/design-system.md` | the `.lbaw-*` section, and amend lines 170-176 (see §7) |
| `docs/deploy/hetzner.md` (Cron, ~line 332) + `.context/stages/01_overview/output/deployment.md` | the new cron entry |
| `docs/api/account-delete.md` | the retained-row / nulled-`user_id` sentence |
| `CLAUDE.md` | a `### Periodic Leaderboard Awards` subsection under Architecture: growth-not-standing and the unsigned clamp; the baseline lives on the live period row and why; lazy stamping is what removes the first-period and re-enable special cases; the **`COMPETITION`-senderType trap**; "no `UserLedger` row" and why; the claim-lease / never-commit-status-early rule; the closed-period read-only rule. Plus `Key Entities` and `Key Services` rows, and a Key Gotcha for the `isValidKey()` short-circuit |

While in `.context/stages/03_data/output/entities.md`, fix three inaccuracies found during
exploration — they would actively mislead work in this area. Around line 360 it lists
`MessageSenderType` cases that **do not exist** (`GUARDIAN` / `STAFF` / `BOARD`) while omitting
the real `SPONSOR` / `INVESTOR` / `SYSTEM`; it lists `effects` as an `InboxMessage` column (it
lives nested in `offerData`); and it lists a `hall_of_fame_points` effect type that no code
handles.

---

## 9. Tests

| File | Cases |
|---|---|
| `tests/Service/Leaderboard/LeaderboardPeriodTest.php` (unit, no DB) | `currentWeekKey()` byte-equals the raw `format('o-\WW')` for an ISO-week-53 year and a "Jan 1 belongs to the previous ISO year" date — **the non-regression pin for the four de-duplicated call sites**. `keyFor()` for all three cadences; every key ≤ 20 chars (pins the column width); lexicographic monotonicity within a cadence. `boundsOf()` round-trips (`keyFor($c, boundsOf($k)[0]) === $k`) across a year per cadence; weekly starts Monday 00:00 UTC; `2026-Q4` = Oct 1 → Jan 1. `previousKey` across year boundaries (`2026-W01` ← `2025-W53`, `2026-Q1` ← `2025-Q4`, `2026-M01` ← `2025-M12`). `cadenceOf('all-time') === null`; `isValidKey()` rejects `''`, `'nonsense'`, `'2026-W99'` |
| `tests/Entity/LeaderboardEntryGrowthTest.php` | first `observeCumulative()` stamps the baseline and scores 0; a later gain scores the delta; **a decrease clamps `score` to 0 while `cumulativeScore` stays truthful** (the reputation-decay case, and the one that protects the next baseline); `setScore()` leaves the baseline null |
| `tests/Service/Leaderboard/LeaderboardGrowthScoringTest.php` | baseline 100 + cumulative 140 ⇒ period score 40. No baseline and `createdAt` inside the period ⇒ baseline 0, **award-eligible**. No baseline and `createdAt` before period start ⇒ on the board, `baseline_missing`, `award_eligible = false`, never awarded |
| `tests/Service/Leaderboard/LeaderboardPeriodCloseServiceTest.php` | **idempotent re-close:** close twice ⇒ exactly one archive, one entry per place, one `InboxMessage` per place (assert **counts**, not absence of exception). **Concurrent claim:** invoke the raw claim twice before commit ⇒ second skips. **Reclaim after lease:** hand-write a `pending` archive `claimed_at` 400s ago plus two partial entries ⇒ reclaimed, partials deleted, full board written, `archived`. **Terminal status not committed early:** force a failure during delivery ⇒ archive stays `pending` **and** the live `leaderboard_entry` rows stay unpruned. **Disable → re-enable:** the first close after the gap awards nobody who predates the re-enable. **Prune:** the closed key's rows are gone and the new open key's rows exist at score 0 with ranks. **Catch-up:** an anchor three periods stale ⇒ three archives, oldest first, correct bounds each. Growth below `minimum_score` is archived but not awarded. `entry_count` / `archived_entry_count` / `placements_awarded` correct |
| `tests/Service/Leaderboard/LeaderboardAwardDeliveryServiceTest.php` | the delivered message's `senderType` is one `InboxService::acceptMessage()` actually applies — assert `COMPETITION`, **then feed the message through the real `InboxService::acceptMessage()` and assert the club's balance / reputation actually moved**. A senderType assertion alone would pass a rename to `SYSTEM`; the end-to-end apply would not, and that is the only assertion that catches the trap. `requiresAccept` true; `offerData['type']`; `relatedEntityType === 'LeaderboardPeriodArchiveEntry'` and the id resolves. Effects merge `prize_pence` plus an **active** template's effects; an **inactive** template contributes nothing. **Zero `UserLedger` rows written** (count before/after) — pins the decision. One push per winner with `type = 'LEADERBOARD_AWARD'`, via the in-memory `async` transport |
| `tests/Service/Leaderboard/ClosedPeriodReadOnlyTest.php` | a cold `getLeaderboard()` on a closed period serves the archive and **writes no `leaderboard_entry` rows** (count before/after); `recalculate()` on a closed period returns 0; `?period=nonsense` through the controller ⇒ 200, `entries: []`, zero rows written |
| `tests/Service/LeaderboardEligibilityTest.php` | **spoof exclusion:** a top-scoring spoof club never appears in `findRankedForAward()` and never wins. **`currentSeason > 1`:** a season-1 club never wins. The live-board change: `findAllOrderedByScore()` now excludes spoof — **this test is the record of that behaviour change** |
| `tests/Command/CloseLeaderboardPeriodsCommandTest.php` | `--dry-run` claims nothing; no enabled definition ⇒ `SUCCESS` and nothing written |
| `tests/Controller/Admin/LeaderboardAwardAdminTest.php` | NEW / EDIT / DELETE absent on the read-only CRUDs. Row-click asserted via `role` / `data-default-action-url` and then following the URL |
| `tests/Service/Leaderboard/PeriodAwardsBoardServiceTest.php` | returns `[]` when nothing is archived (so section *and* nav both hide); frozen identity still renders after the club row is deleted; the entry fetch is batched for N archives, not N queries |

Also add to migration-1's coverage: after the one-off reset, every non-`all-time` row has
`score = 0` and `baseline_score = cumulative_score` — so the discontinuity is deliberate and
tested rather than discovered in production.

**Repo-specific caveats that bite here:**

- **Never name a close-job test helper `runCommand()`.** `KernelTestCase` pulls in
  `ConsoleCommandAssertionsTrait::runCommand()` (static), and a non-static override is a
  class-load fatal during PHPUnit's suite *building* — exit 255, zero tests executed, the whole
  run down, not just that file. Use `runCloseCommand()`, as
  `CompetitionProvisionInstancesCommandTest` uses `runProvisionCommand()`.
- **Never assert `ea-clickable-row`** in an admin functional test. EasyAdmin 5.x adds it at
  runtime in JS; DomCrawler executes none, so it can only ever fail — and its failure is not
  evidence row-click regressed.
- **Admin login** needs a persisted real `Admin` first, then `loginUser($admin, 'admin')` — the
  `admin` firewall re-fetches by email on every request.
- **API functional-test login is single-use** against the stateless JWT firewall, which is why
  the accept path is exercised through `InboxService` in a service test rather than two
  authenticated HTTP calls.
- **Seed commands never run against `wunderkind_test`** — a `RewardTemplate` fixture must be
  created in-test, never assumed.

### Pre-existing issues deliberately in scope / out of scope

- **Spoof clubs on the live board — fixed here, in its own commit.** `LeaderboardEntryRepository`
  has never filtered `isSpoof`; only `ClubRepository` does (`ClubRepository.php:60`). The award
  path must filter them (paying a bot real money is the actual bug), and once that predicate is
  hoisted into a shared `applyEligibility()`, leaving the public board inconsistent is a worse
  outcome than the change. **It is a visible behaviour change** — spoof clubs disappear from
  live public boards, shifting `total` and every rank below them, and shifting
  `GET /admin/stats/leaderboards` — so it lands alone, as build step 2.
- **Dead repository methods — deleted here.** `findTopByPeriod()` and `findWithRankForClub()`
  have zero call sites, and the latter's rank disagrees with the board because it omits the
  `currentSeason` gate. Leaving a wrong-answer method named "find rank for club" next to a new
  third ranked-read is a trap for whoever next needs that feature.
- **`findOrCreate()` has no `ON CONFLICT`** — a concurrent create surfaces as a
  `UniqueConstraintViolationException` from `flush()`, which closes the EntityManager. Adding a
  monthly cadence doesn't worsen the odds (weekly already has this), and the §6 guard removes
  the public-read racer entirely. Left alone, documented as "safe only under the single-writer
  rule".
- **`rank_position` on `leaderboard_entry` is written by `assignRanks()` and read by nothing** —
  the API's rank is positional at hydration. The close job likewise assigns archive ranks
  positionally rather than trusting the column, so the archive can't inherit a stale rank. The
  column stays as admin/debug visibility.
- **Weekly rows for non-awarding categories still accumulate forever.** Pruning only happens for
  a period a definition actually closed. See the follow-up in §10.

---

## 10. Build order — each step leaves the repo green

1. **Helper + dead-code removal.** `AwardCadence`, `LeaderboardPeriod`; repoint the four
   `format('o-\WW')` call sites. Delete `findTopByPeriod()` and `findWithRankForClub()`. Add
   `LeaderboardPeriodTest`. **No migration, no behaviour change.**
2. **Spoof + eligibility.** Hoist the predicate into `applyEligibility()`; add `isSpoof = false`
   to the board queries. **Own commit — a visible public behaviour change.** Add
   `LeaderboardEligibilityTest`.
3. **Controller hardening.** The `isValidKey()` short-circuit plus its half of the read-only
   test. Stands alone.
4. **Growth columns.** Migration 1, `observeCumulative()`, and the conversion of `SyncService`,
   `computeAggregateScores()` (including the zeroing pass) and `DecayReputationCommand`. Entity
   and growth tests. **Period boards become growth boards here.**
5. **Entities + migration 2.** All four tables in one migration; reconcile `wunderkind_test`.
   Register the definition CRUD, the two read-only CRUDs, the placement form type and the menu
   items.
6. **`ActivePeriodService`**, wired into `SyncService` and `GenerateLeaderboardsCommand`, so an
   enabled monthly definition starts getting rows written.
7. **Close service, no awards yet.** Claim, lease, score, archive, prune, anchor;
   `placements_awarded = 0` always. Close tests minus the award cases. **The archive is already
   useful here — this is the natural "ship the history, hold the money" checkpoint, and the
   landing page could ship off this step alone.**
8. **The closed-period read-only guard (§6) + test. Do not deploy step 7 anywhere without this** —
   the prune without the guard is what lets a cold public read refill the table with junk.
9. **Awards + delivery.** `LeaderboardAwardDeliveryService`, `markAwarded()`, the inbox message,
   the push. Delivery tests + the award close cases.
10. **Command + cron.** `app:leaderboards:close-periods`, `docker/leaderboards-close-periods.sh`,
    the four Dockerfile edits, `docs/deploy/hetzner.md`. Command test. Verify by hand locally.
11. **`closeNow` row action** + the admin test.
12. **Landing.** `PeriodAwardsBoardService`, `_period_awards.html.twig`, the include line,
    `_nav` gating, `.lbaw-*` CSS, `mountPeriodAwards()`. Board service test.
13. **Account deletion.** The `user_id` null-out, the retained-row comment,
    `docs/api/account-delete.md`.
14. **Docs.** `docs/api/leaderboard.md`, the new `docs/api/leaderboard-awards.md`, the seven
    `.context/` stage files (including the three `entities.md` corrections), `CLAUDE.md`.

Steps 1-3 are independently valuable even if the feature is shelved. Nothing after step 5
requires another migration.

**Optional follow-up**, cheap once the cadence machinery exists: a retention sweep for the
pre-existing ISO-week rows of non-awarding categories, which nothing has ever pruned.

---

## Verification

```bash
lando start

# 1. Schema
lando php bin/console doctrine:migrations:migrate --no-interaction
lando php bin/console doctrine:schema:update --dump-sql --env=test   # expect: nothing to update
lando php bin/console doctrine:migrations:up-to-date

# 2. Growth scoring is live
lando php bin/console app:leaderboards:generate --period=current-week
lando psql -c "SELECT category, period, score, baseline_score, cumulative_score
               FROM leaderboard_entry WHERE period <> 'all-time' LIMIT 10;"
#    expect score = max(0, cumulative - baseline); all-time rows still NULL/NULL

# 3. Configure an enabled definition in admin (Sync & Leaderboards -> Award Definitions):
#    monthly, golden_boot, three placements, a prize on place 1.
#    Then close the ended period by hand (no crontab under Lando):
lando php bin/console app:leaderboards:close-periods --force --dry-run -v
lando php bin/console app:leaderboards:close-periods --force -v
lando php bin/console app:leaderboards:close-periods --force -v   # expect: nothing due, no new rows

lando psql -c "SELECT period_key, status, entry_count, archived_entry_count, placements_awarded
               FROM leaderboard_period_archive;"
lando psql -c "SELECT rank_position, club_name, score, baseline_score, awarded, place
               FROM leaderboard_period_archive_entry ORDER BY rank_position LIMIT 10;"
lando psql -c "SELECT sender_type, subject, offer_data FROM inbox_message ORDER BY created_at DESC LIMIT 3;"
lando psql -c "SELECT count(*) FROM leaderboard_entry WHERE period = '<closed key>';"   # expect 0

# 4. The closed period must NOT resurrect
curl -s "http://wunderkind-backend.lndo.site/api/leaderboard/golden_boot?period=<closed key>" | jq '.total'
lando psql -c "SELECT count(*) FROM leaderboard_entry WHERE period = '<closed key>';"   # still 0

# 5. The reward actually applies (the senderType trap)
#    POST /api/inbox/{id}/accept as the winning club, then:
lando psql -c "SELECT balance, reputation FROM club WHERE id = '<winner>';"
lando psql -c "SELECT count(*) FROM user_ledger;"   # unchanged — awards never write a ledger row

# 6. Landing page
open http://wunderkind-backend.lndo.site/      # #period-awards renders, badges composited client-side

# 7. Suite
lando php vendor/bin/phpunit --no-coverage
```
