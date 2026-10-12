# Reputation Decay

A club loses **5 reputation per whole day it goes without syncing**. Dormant clubs
slide down the reputation leaderboard; active ones don't move.

Owned by `App\Service\ReputationDecayService` (pure arithmetic, no DB) and
`app:reputation:decay` (iteration, batching, persistence). Runs nightly at 03:20 UTC —
see the crontab in the `Dockerfile`, which calls `docker/reputation-decay.sh`.

## The behaviour that surprises people

**Decay is transient. A club's next sync restores its reputation in full.**

`Club::$reputation` is client-authoritative: the device is the game engine, and
`SyncService::process()` overwrites the column from the sync payload on every sync. So
decay only has an effect while a club is silent. The moment the owner comes back, the
on-device figure wins and the penalty is gone.

That is the intended design, confirmed with the product owner — the penalty is for
being absent, not a permanent mark. **Do not "fix" it** by accumulating a decay total
and subtracting it on sync; that would permanently penalise a returning player with no
way to recover.

## How a day is counted

Dormancy is measured from the latest of three anchors:

| Anchor | Why it's in the set |
|---|---|
| `Club.lastSyncedAt` | The club is active up to this moment |
| `Club.reputationDecayedAt` | Decay already charged up to this moment |
| `Club.createdAt` | A club that has never synced still starts its clock at founding |

Only **whole** days are charged, and the part-day remainder carries: a club 1 day and
18 hours silent is charged one day now, and the second day lands 6 hours later, not 24.
`reputationDecayedAt` advances by exactly the days charged — never to "now" — which is
what makes that true.

Reputation floors at 0. `club.reputation` is an unsigned bigint, so a negative value
would not merely look odd, it would fail to persist.

## Running it

```bash
# See what would change, without writing (always do this before the first real run)
lando php bin/console app:reputation:decay --dry-run

# Apply
lando php bin/console app:reputation:decay

# Per-club detail
lando php bin/console app:reputation:decay --dry-run -v

# Sample a large backfill
lando php bin/console app:reputation:decay --dry-run --limit 100
```

### There is no separate backfill command — on purpose

The command charges *whole days since the club was last active*, so the first run on an
environment that has never decayed settles a club silent for eight months with exactly
the same arithmetic as a club silent for one day.

**Expect that first run to be much larger than a nightly one.** Abandoned clubs are the
common case, not the exception — on a representative dataset of 252 clubs, 67 had
reputation left to lose and 64 of those went straight to the zero floor. Dry-run it
first so the scale is not a surprise.

Re-running is a no-op, and a week of missed cron runs is settled in a single pass.

## Leaderboard write-through

`club_reputation` is a **non-aggregate** leaderboard category: its `LeaderboardEntry.score`
is upserted only by `SyncService` at sync time, and `app:leaderboards:generate` merely
re-ranks what is already stored (see `LeaderboardCalculationService::recalculate()`).

So the decay command writes the new score through to the club's existing
`club_reputation` entries itself, then invalidates the leaderboard cache and re-ranks.
Without that, a dormant club would sit on its last-synced score forever — precisely the
case decay exists to correct.

Only **existing** periods are updated, never created: a club that has not synced this
ISO week has no entry for that week, and adding one would put a dormant club onto a
weekly board it never appeared on.

## Scope

- **Spoof clubs are skipped.** They are admin-generated competition filler
  (`CompetitionSpoofEntrantService`) that never syncs by definition; decaying them would
  drive every one of them to zero for no gameplay reason.
- **Known pre-existing wrinkle, not introduced here:** editing `Club.reputation` directly
  in the admin panel changes the club but not its leaderboard entry, so the two can
  diverge until that club's next sync or decay.

## Tests

`tests/Service/ReputationDecayServiceTest.php` covers whole-day charging, catch-up across
missed runs, idempotency, remainder carry, the zero floor, clubs that have never synced,
and future-dated anchors (clock skew must read as "not yet dormant", never as a refund).
