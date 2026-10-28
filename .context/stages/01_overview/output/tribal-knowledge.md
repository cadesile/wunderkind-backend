# Tribal Knowledge

> From `setup/questionnaire.md`, questions 4 and 5.

## Non-obvious knowledge (Q4)

### The device is the master for club data (stated by the human, 2026-10-28)

**The backend never owns a club's state. The device does.** Everything the
backend holds about an individual club arrives through `POST /api/sync` and is
a **mirror** of what the device reported — not a value the backend computes,
owns, or can be trusted to have correct between syncs.

`SyncService::process()` says so at the point of assignment, and the assignment
is an absolute overwrite rather than a delta:

```php
// The client is the game engine. balance, totalCareerEarnings, and
// reputation are accepted as authoritative snapshots from the device.
$club->setBalance($request->balance);
```

**Why this is written down here rather than left to the code:** a reader who
finds `club.balance` in the schema will reasonably assume the backend owns that
number, and act on it. That assumption was made during the 2026-10-28
tournament work — a code review flagged that a migration truncating competition
entrants destroyed entry fees "already deducted from `club.balance`" with no
refund, and the agent carried it as a release blocker across several exchanges
before the human pointed out that club balance only matters on the device. It
was never a blocker: there was no authoritative money in the backend to lose.

Practical consequences worth knowing before touching anything club-scoped:

- **A server-side write to a mirrored field does not persist.** It survives
  until the next sync, then the device's value replaces it. `register()`
  deducts `entryFeePence` and `withdraw()`/`cancel()` refund it, and all three
  are transient for this reason. The `balance >= :fee` affordability check is
  still meaningful — it tests the last-synced balance — but the deduction is
  not durable, so enforcement has to exist on the client too.
- **Deleting backend club-scoped rows does not destroy player state** in the
  way deleting an authoritative record would. It costs history and derived
  server-side artefacts, not the player's game.
- **The inverse also holds, and is the genuine constraint**: the backend cannot
  correct a club's state. There is no server-side authority to repair a wrong
  balance from, so a bug there has to be fixed on the device.

What the backend *does* own: world data (leagues, NPC clubs, market pools),
cross-club aggregates derived from sync (`LeaderboardEntry`, `UserLedger`,
`SeasonRecord`), and anything no single device could know. See the Hybrid Model
section of the root `CLAUDE.md`, and `03_data/output/entities.md`'s `Club`
entry.

## Canonical existing docs (Q5)

`README.md` and everything under `docs/` are canonical — index them as-is
in stage `06_documentation` rather than treating them as equal to every
other markdown file found.
