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

### A stored role column is not where a role comes from (found fixing the 2026-11-01 lockout)

**`user.roles` does not decide whether an account has `ROLE_CLUB`.**
`User::getRoles()` appends it unconditionally, the way `Admin::getRoles()` hardcodes
`ROLE_ADMIN`. The column is still written at registration and still readable, but
nothing authorises off it.

**Why this is written down here rather than left to the code:** the opposite assumption
is the natural one — a reader who finds `roles:array` in the schema will take it as the
authority, and the two obvious things to do with it are both now wrong. Backfilling it
"to fix" a stale row fixes nothing that is broken. Adding a new role case to it and
expecting a route to honour it will silently fail, because there is **no
`role_hierarchy`** in `config/packages/security.yaml` at all — every `roles:` entry in
`access_control` is an exact-match check.

What that assumption actually cost: the column had held three different things over the
project's life (`[]` before `6dc85ab`, the since-deleted `ROLE_ACADEMY` until `2a9e6cd`,
`ROLE_CLUB` after), and because login is gated on `isVerified()` rather than on roles, an
affected account **authenticated successfully and then got 403 from every `ROLE_CLUB`
route**. Client-side that is indistinguishable from a refused account, so it reached the
app team as "sign-in is broken" rather than as an authorisation bug, and three separate
app-side faults were written to handle it before anyone looked at the column.

Three related traps, each of which looks like a one-line fix and is not:

- **An endpoint's role gate lives in two places.** `#[IsGranted]` on the method *and* the
  matching prefix rule in `access_control`. `^/api/club/` matches `/api/club/check`, so
  relaxing only the attribute changes nothing observable — the request never reaches the
  controller either way. Any narrower exception must also be ordered **ahead** of the
  prefix rule it is carving out of; first match wins.
- **`ClubMismatchException` is a 403 on purpose, and that is wrong on exactly one
  endpoint.** It extends `AccessDeniedHttpException` so an `X-Club-Id` naming someone
  else's club cannot drive a write onto the wrong save. But on an endpoint whose whole job
  is to answer *whether* the account has a club, refusing is not an answer — `/check`
  catches it and returns 404. Any future "does X exist for me?" endpoint has the same
  shape: a role gate or a resolver refusal in front of a 404 makes the 404 unreachable for
  precisely the callers who need it.
- **The "EasyAdmin admin grant" instruction in the root pointer was wrong, and is
  corrected.** It told you to `UPDATE "user" SET roles = '["ROLE_ADMIN"]'`. The `admin`
  firewall's provider is `app_admin_provider` — the **`Admin`** entity — so a `User` row
  cannot authenticate against `^/admin` whatever its roles say; `app:admin:create` is the
  real path. Recorded here because the root `CLAUDE.md` is disposable and regenerable: the
  claim existed **only** there and in no stage output, so a regenerated pointer could
  reintroduce it from nothing. Noted under this heading because the two faults share a
  cause — treating `user.roles` as the authority for a role.

See `03_data/output/entities.md`'s `User` entry, `04_interfaces/output/routes.md`, and
`docs/api/role-club-403.md` for the client-facing contract.

## Canonical existing docs (Q5)

`README.md` and everything under `docs/` are canonical — index them as-is
in stage `06_documentation` rather than treating them as equal to every
other markdown file found.
