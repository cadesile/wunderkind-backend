# Tournament Concurrency Hardening & Queued Processing — Execution Plan

**Date:** 2026-10-28
**Scope:** Backend (`wunderkind-backend`) — the competition and friendly processing paths:
`CompetitionResponseCacheService`, `CompetitionResponseBuilder`, `CompetitionDrawService`,
`CompetitionResultsService`, `CompetitionProvisionInstancesCommand`,
`CompetitionRegistrationService`, `FriendlyChallengeService`, plus the Messenger transports
and their cron consumers.
**Goal:** run **multiple concurrent rolling tournaments** without stranded rounds, stale
reads, or unbounded work in a single cron tick.

**Origin:** a `/code-review ultra` pass over `dev → master` (the four commits `5508269..f103181`
that split the competition/friendly snapshots and added the response cache), read against the
brief "harden for race conditions and implement queue systems across all processing". The
review found 7 substantive issues; 6 were introduced by those four commits, and 3 of those
share one root cause.

---

## 0. The root cause worth naming

Three separate findings are the same mistake: **the response cache uses Doctrine's ORM in
ways that are unsound when two things touch one competition at once.**

> [!IMPORTANT]
> Doctrine's identity map returns an **already-managed** entity from a later query without
> re-hydrating it (there is no `HINT_REFRESH` on `findOneBy()`/DQL). And a failed `flush()`
> **closes the EntityManager** — a fact CLAUDE.md already warns about in the
> Server-Driven Messaging section, and which a `catch (\Throwable)` does not undo.
>
> Every Phase 0 item below is a consequence of one of those two facts.

---

## 1. Phase 0 — correctness blockers (before `development → master`)

### 1.1 A failed prewarm strands the next round permanently

`CompetitionResponseCacheService::store()` calls `$this->em->flush()` on the shared
EntityManager. `prewarmResponse()` wraps `rebuild()` in `catch (\Throwable)` and logs, on the
assumption that a cache failure cannot affect game state. It can:

1. The flush throws (FK violation if the competition was deleted mid-tick, unique violation
   on `uq_competition_response_cache_competition` after `withCompetitionLock()` falls through
   unlocked, a statement timeout on the large JSON write). The UnitOfWork **closes the EM**.
2. `prewarmResponse()` swallows it and returns. The `foreach` in `publishDueResults()` /
   `drawDueRounds()` continues.
3. The next due round is claimed by `claimResults()`, which commits
   `resolve_locked_at = now` through **raw SQL** and *then* calls `$this->em->refresh($round)`
   — which throws `EntityManagerClosed`. The tick aborts.
4. That round now has `resolve_locked_at` set and `status = DRAWN`. Every later tick's claim
   predicate (`AND resolve_locked_at IS NULL`) returns false. **The round is permanently
   stuck** and only recoverable by an admin force-resolving each fixture by hand.

**Fix:** write the cache row through DBAL (`INSERT … ON CONFLICT (active_competition_id) DO
UPDATE`) instead of the ORM unit of work, so a cache failure cannot touch the caller's
EntityManager at all. Structural, not defensive — the `catch` stays, but stops being the only
thing standing between a cache hiccup and a dead bracket.

### 1.2 `stateVersion()` is not a sound freshness key

`computeInstanceVersion()` returns `1 + registeredCount + roundState + sumSnapshotVersions`.
Two independent defects:

- **It collides.** `countForCompetition()` excludes `WITHDRAWN`; `sumSnapshotVersions()`
  includes it. So a withdrawal nets **−1** and another club's resubmission nets **+1** — they
  cancel, `isFreshFor()` still matches, and the stale document is served (with a 304, since
  the anonymous ETag is also unchanged). The existing docblock claims withdrawn rows are
  included *to prevent* exactly this cancellation; that reasoning is inverted.
- **It is incomplete.** It omits inputs the cached document renders: `status`,
  `cancelledAt`/`cancelReason`, `completedAt`, and every template field the summary embeds
  (`templateName`, `trophy`, `entryConditions`, `entryFeePence`, `prizes`/`bonusRewards`).
  Cancelling a `REGISTERING` competition changes no round and no entrant count, so the body
  stays stale indefinitely; an admin edit to a template never reaches a running competition,
  and never reaches a COMPLETED one at all (its row is the permanent archive).

**Fix:** replace the sum with a collision-free key — a hash over an explicit tuple:
`(non-withdrawn count, withdrawn count, snapshot-version sum, ordered round statuses, status,
cancelledAt, completedAt, template updatedAt-or-hash)`. A hash removes the arithmetic
coincidence class entirely rather than patching one instance of it.

### 1.3 Identity-map staleness defeats the stampede guard, and can cache stale as fresh

- **`getOrBuild()`'s double-check is a no-op.** The stale row is loaded before the lock; the
  re-read inside the lock calls `findForCompetition()`, which returns that *same managed
  instance* with its old `stateVersion`. So every request queued behind the advisory lock
  rebuilds the whole bracket in turn — N concurrent GETs after any state change become N
  serialized full builds, each pinning a PHP worker and a DB connection. The docblock's
  promise that a cold competition is "built once rather than once per request" only holds
  when no row exists at all.
- **The cron prewarm can stamp a stale document as fresh.** `stateVersion()` reads fresh SQL
  aggregates, but `sharedDocument()` builds from entrants the same tick already loaded. A
  resubmission committing mid-tick is counted in the version but absent from the document —
  which is then stored as fresh for the new version, so every later GET serves the club's old
  formation, style, OVR and kit until some unrelated event moves the version. Resubmission is
  open during a resolve tick by design (`hasUpcomingRound()` is true while round N+1 is
  `DRAW_PENDING`), so this is a reachable race, not a theoretical one.

**Fix:** read the row through DBAL inside the lock (or `em->refresh()` it), and build the
prewarm document from fresh state (`em->clear()` or refresh before building, or derive the
version from the same loaded data so the two cannot disagree).

### 1.4 `MAX_STAFF = 20` rejects without protecting

The 256KB `MAX_SNAPSHOT_BYTES` cap already bounds the payload. The count cap adds a new 422
on register, resubmit, friendly create/accept and spoof cloning, for a collection the same
docblock calls "open-ended". **Fix:** drop the count cap; keep the byte cap.

---

## 2. Phase 1 — race hardening beyond the reviewed diff

The review only saw the diff. These are pre-existing and matter more at scale.

### 2.1 Claims are acquired but never released — DONE

`claimDraw()` and `claimResults()` set `*_locked_at` and nothing ever cleared it. §1.1 was
one way to strand a round; *any* uncaught exception between the claim and the end of
processing did the same.

**Implementing this turned up something worse on the draw side, which this plan had not
spotted.** `claimDraw()` also committed `status = DRAWN` through raw SQL *before*
`drawRound()` created a single fixture. A failure in that flush therefore left a round marked
DRAWN with no fixtures and `matches_resolve_at` still NULL — skipped by `findDueForDraw()`
(which wants DRAW_PENDING) **and** by `findDueForResults()` (`matches_resolve_at <= now` is
never true for NULL). The round was **orphaned from both ticks**, permanently, with no lock
involved at all. A lease alone would not have fixed it.

**Shipped:**
1. `claimDraw()` takes the lease only. `drawRound()` sets `DRAWN` in the same flush as the
   fixtures, `startedAt` and `matchesResolveAt`, so the status and the work it describes
   commit together. A crash leaves the round DRAW_PENDING and reclaimable.
2. Both claims are leases, reclaimable after `CompetitionRound::CLAIM_LEASE_SECONDS` (15
   minutes), and **released on success** in the same flush as the terminal status — so
   `*_locked_at IS NOT NULL AND status = <pre-work status>` is a precise, monitorable "in
   progress or died mid-round" signal.

Retrying is safe for reasons that already held: `publishResults()` skips fixtures carrying
`processedAt`, and a round's work lands in one flush, so a reclaimed round either continues
from where the last attempt stopped or redoes work that was never committed.

Covered by `tests/Service/Competition/CompetitionRoundClaimLeaseTest.php`, which drives the
private claims by reflection on purpose: the property under test is the state a claim leaves
*before* any work runs, which no public entry point exposes.

### 2.2 The advisory lock falls through unlocked

`withCompetitionLock()` proceeds without the lock if `pg_advisory_lock` fails, relying on the
unique index to prevent duplicate rows. That turns a lock failure into a unique-violation on
`flush()` — which, until §1.1 lands, closes the EntityManager. Decide deliberately: either
fail the rebuild, or make the write idempotent (which §1.1's `ON CONFLICT` does).

### 2.3 Capacity-fill → lock transition — AUDITED, no bug

`register()` takes `SELECT … FOR UPDATE` on the ActiveCompetition row as its **first**
statement and calls `CompetitionLockService::lock()` inside that same transaction. Three
properties follow, all verified by reading the code:

- **Serialization.** A concurrent last-slot registration blocks on the row lock, then reads
  the now-SCHEDULED status and is rejected. Two clubs cannot fill the same final slot.
- **Atomicity.** `lock()` only persists — `InboxService::createMessage()` deliberately does
  not flush, and nothing pushes — so the seeds, the rounds and the per-entrant inbox messages
  all land on `register()`'s single flush inside the transaction, together with the entrant
  insert and the fee deduction. A failure anywhere rolls back all of it, fee included.
- **`withdraw()` serializes against it**, taking the same `FOR UPDATE`.

**One real gap found and closed: `registration_closed` had no test coverage at all.** That
matters more than it looks, because there are two paths to it — the controller's pre-flight
status check, whose read can be stale by the time the transaction opens, and the
in-transaction check against the `FOR UPDATE`-read row. The second is the one that actually
handles the race, and it was the untested one. Covered now by
`tests/Service/Competition/CompetitionRegistrationCapacityTest.php`, including that a
rejected registration does not charge the entry fee and does not re-lock the competition.

**One characteristic recorded, deliberately not fixed.** The final registrant's request
performs the whole bracket lock while holding that row lock: N seed updates, log2(N) round
inserts and N inbox messages (64 for a full bracket). Every other registration for *that*
competition blocks for the duration. It happens once per competition and does not
cross-contaminate tournaments, so it is acceptable — and queueing it would be worse: the
status transition has to stay transactional with the capacity check, and splitting it leaves
a window where a SCHEDULED competition has no rounds, which `findDueForDraw()` would silently
not find. That is the orphaning shape §2.1 just removed, and it is not worth reintroducing for
a latency win on one request per tournament.

---

## 3. Phase 2 — queue the processing

The house pattern is established and documented in `config/packages/messenger.yaml`: a
dedicated transport per workload class (so one cannot starve another), its own consumer
script, and the three guards in `StarterPoolAuditService` — never queue work generation
cannot finish, never stack a sweep on an undrained queue, and let each chunk re-check its own
target so a replay costs nothing.

### 3.1 New `competition` transport — DONE

`competition: 'doctrine://default?queue_name=competition'` plus
`docker/competition-consume.sh` (flock, `--time-limit=50`, `--limit=10`), a `Dockerfile`
COPY, chmod and a per-minute crontab entry. A fourth transport rather than a fourth user of
an existing one, because round processing is **latency-sensitive in a way the others are
not**: a tournament's next round is scheduled against wall-clock time, so a backlog here
reads to players as a late draw rather than as a slow background job, and must not queue
behind an overnight pool top-up or a country regenerate.

### 3.2 Draw and resolve become per-round messages — DONE

`DrawCompetitionRoundMessage` / `ResolveCompetitionRoundMessage`, carrying a round id and
nothing else. The cron commands call new `dispatchDueDraws()` / `dispatchDueResults()`, so a
tick is one indexed due-row scan plus one INSERT per due round.

**The claim is taken by the handler, not the dispatcher** — the design decision the rest
rests on. Claiming at dispatch would start the lease
(`CompetitionRound::CLAIM_LEASE_SECONDS`) counting down while the message sat in the queue,
so a backlog would silently expire its own claims, and a message lost before delivery would
leave a round claimed by nobody. Claiming in the handler keeps the claim adjacent to the work
it protects and makes a duplicate delivery free.

**Deliberate divergence from `StarterPoolAuditService`: dispatch is not gated on an undrained
queue.** That guard exists there because its queue depth scales with how far a pool has
drifted, so a second sweep genuinely stacks. Here a dispatch is one row per due round and a
duplicate is a no-op, so gating would stall *other* competitions' progress for no benefit.
Throughput is bounded at the consumer, where the expensive work is.

`drawDueRounds()` / `publishDueResults()` survive as the synchronous drivers over the same
extracted per-round methods (`drawRoundIfClaimable()` / `publishResultsIfClaimable()`), so
the queued and inline paths cannot drift, the existing tests keep exercising the real logic,
and there is a working fallback if the consumer is ever not running.

Covered by `tests/Service/Competition/CompetitionRoundQueueTest.php`.

### 3.3 Provisioning — NOT DONE

`CompetitionProvisionInstancesCommand` still provisions inline, with its wrapper pinned at
`memory_limit=128M`. Lower priority than 3.2: provisioning is driven by template schedules
rather than by how many tournaments are mid-flight, so it does not scale with concurrency the
way round processing does. Same treatment when it matters.

### 3.4 Prewarm becomes a queued message — DONE

`WarmCompetitionResponseMessage`, dispatched by the draw and resolve handlers once their work
has **committed**, on the same `competition` transport so a warm naturally orders behind the
round it describes.

A separate message rather than a tail call on those handlers, and that is the substance of
the fix rather than a packaging choice: a round handler has the round's entrants in its
identity map, and Doctrine will not re-hydrate them, so building the document there while
fingerprinting fresh SQL is exactly how Phase 0's stale-stamping bug happened. Symfony's
`DoctrineClearEntityManagerWorkerSubscriber` clears the EntityManager before each message, so
the warm handler reads clean — verified in the container, not assumed.

Not dispatched on register/withdraw/resubmit. Those happen while the bracket is still filling,
when no rounds or fixtures exist and the document is cheap to build; dispatching there would
queue one full-document rebuild per registration — 64 for a filling bracket — to warm
something that costs almost nothing on demand. The self-validating fingerprint already covers
it.

Warm failures are swallowed rather than sent to `failed`: a warm is pure optimisation, a miss
self-heals on the next read, and a retry storm on a genuinely broken competition would keep
rebuilding a 64-entrant document for nobody.

Covered by `tests/Service/Competition/CompetitionRoundQueueTest.php`, including that a
*skipped* (no-op) delivery queues no warm, and that the round handler does not write the
cache row itself.

---

## 4. Phase 3 — hygiene (the review's nits) — DONE

1. **`prewarmResponse()` duplication — resolved by §3.4.** No occurrence of the method
   remains in `src/`; the three copies collapsed into the queued
   `WarmCompetitionResponseMessage` path when prewarm became a message.

2. **The duplicated snapshot split — one shared method now.**
   `CompetitionRegistrationService::splitSnapshot()` is public and returns `ovr` alongside
   the rest; `FriendlyChallengeService` calls it from both `postChallenge()` and
   `acceptChallenge()` and its own copy is gone, as is the third copy in
   `tests/Support/FriendlyFixtureTrait`, which had been splitting by its own rules in a
   trait whose whole purpose is to seed what the real write path produces.

   **The duplication was hiding a behavioural divergence, which is the part worth
   recording.** The friendly copy called `computeStartingXiOvr()` unconditionally, so a
   device-supplied `club.ovr` was recomputed away; the competition copy honoured it. The
   same envelope therefore rated a club differently depending on which domain it was
   submitted to. Unified on the competition rule — a numeric `club.ovr` wins, else derive
   from the XI — because that is the one consistent with the device being the master for
   club data. Pinned by `tests/Service/Competition/SubmittedSnapshotSplitTest.php`, which
   asserts the rule rather than either call site.

   Also removed two now-dead `$display['ovr'] = $ovr` reassignments: the split already
   freezes OVR into the display block.

3. **Friendly squads are pruned at full-time.** `deleteForChallenge()` is called from
   `resolveSingleMatch()`, after the flush and guarded on the status actually being
   `COMPLETE` — the same ordering, and for the same reason, as the competition side's prune
   in `publishRoundResults()`: the call is raw SQL that commits on its own, so running it
   before the flush would turn a failed flush into an unplayable challenge rather than
   merely an unpruned one. Safe because nothing reads those rows afterwards —
   `MatchQueryService` skips the squad lookup entirely once both lineup blocks exist, which
   is exactly the condition `recordResult()` establishes.

   Two follow-ons: `FriendlyChallengeCrudController`'s two squad fields now carry help text
   saying the rows are pruned at full-time, because a bare `—` where a submitted XI used to
   be reads as a bug worth chasing rather than as the intended archive behaviour; and
   `FriendlyResolveSingleMatchTest` asserts both halves — that the rows go, and that the
   match detail the client fetches is unaffected. It was the absence of that assertion that
   let the method sit uncalled in the first place.

---

## 5. Entry fees and the truncate — resolved, no decision required

**RESOLVED — moot. No decision needed, and the concern was mine to have caught earlier.**

The review flagged that `Version20261028001500`'s truncate deletes entrants whose
`entryFeePence` was already deducted from `club.balance`, refunding nothing, and I carried
that as a blocker on `master` across several messages.

It does not hold, because of the hybrid model this codebase is built on. `club.balance` in
the backend is a **mirror, not the source of truth**. `SyncService` says so explicitly at the
point of assignment — *"The client is the game engine. balance, totalCareerEarnings, and
reputation are accepted as authoritative snapshots from the device"* — and the assignment is
an absolute overwrite, `$club->setBalance($request->balance)`, not a delta. So the backend
never held the authoritative balance, and truncating competitions cannot lose player money:
the next sync reasserts whatever the device says.

Two things worth keeping from the investigation anyway:

1. **The fee default is 0 and nothing in the codebase ever sets it.** `entryFeePerRound` is
   `default => 0`, and no seeder or fixture calls `setEntryFeePence()` — it is admin-set only.
   So even under the wrong model, the exposure was whatever an admin had configured.
2. **The server-side fee deduction is transient, and that may be worth a look on the client
   side.** `register()` deducts, `withdraw()` and `cancel()` refund, and the next sync
   overwrites all of it. The `balance >= :fee` guard is still meaningful as an affordability
   check against the last-synced balance, but the deduction does not persist. If the client
   does not also deduct locally, an entry fee is effectively free. That is in
   `wunderkind-app`'s domain, not this repo's, so it is recorded here rather than acted on.

---

## 6. Sequencing

| Phase | Blocks | Why in this order |
|---|---|---|
| 0 (§1) | `master` | Correctness. §1.1 can permanently stall a live bracket. |
| 1 (§2.1, §2.2, §2.3) | **done** | Claims-as-leases, plus making the draw's status transition atomic — which is what actually kept a crashed draw recoverable. |
| 2 (§3) | §3.1/§3.2/§3.4 **done**; §3.3 open | Bounds the work per tick, and restores prewarming in the form it should have had. |
| 3 (§4) | nothing | Falls out of Phase 2 cheaply. |
| §5 | **nothing** | Resolved as moot: the backend's `club.balance` is a sync mirror, not the source of truth. |

---

## 7. Phase 4 — measurement — DONE

`app:competition:benchmark` drives the real services — registration including the
capacity-fill lock, draw, resolve, and the bracket document build — and reports wall-clock,
peak memory and payload composition per stage. It creates and deletes its own data and
refuses to run with `APP_ENV=prod`.

Two measurement artefacts had to be removed before any number meant anything, and both would
have produced confidently wrong conclusions:

1. **Filling via `spoofAllEntrants()` conflated club creation with registration.** It mints a
   User and Club per entrant (name and appearance generation) before registering, reading as
   "~612ms per registration". Separated: club creation is ~15ms each, registration is **~35ms
   median**. A ~25x overstatement of the one path players actually wait on.
2. **Cold start dominated every "worst" figure** — the first draw measured 526ms against 59ms
   for identical work on the next round. Since the slowest-round figure drives the lease
   recommendation, the harness now runs a discarded warm-up pass (`--no-warmup` keeps the
   cold-start cost observable).

### Measured, capacity 64, three concurrent competitions

| Stage | Measured |
|---|---|
| registration | **~35ms median** (189 samples), worst 712ms |
| capacity-fill lock (final registrant) | **871ms** — seeding, 6 rounds, 64 inbox messages |
| draw per round | 333ms – 952ms |
| resolve, 32-fixture round | **3,185 – 7,451ms** |
| resolve, 16-fixture round | 1,762 – 4,518ms |
| peak memory per resolve | **14 – 16MB** |
| bracket document build | 127 – 2,959ms |
| **bracket payload** | **~805KB** |

### What it changed

- **`CLAIM_LEASE_SECONDS` 900 → 300.** The worst full round is ~8.4s, so 15 minutes was ~107x
  the worst case and cost 15 minutes to recover a crashed tick. 300s keeps ~36x headroom and
  recovers within about five cron periods. Headroom deliberately generous: one machine, three
  competitions, uncontended database.
- **`competition-consume.sh`: `--limit=10` removed, `--memory-limit=256M` added.** The count
  could never bind — ten worst-case resolves are ~75s against a 50s `--time-limit` — so it was
  documenting a bound it did not enforce. The bounds are now on the two things that actually
  vary, time and memory, with 256M sitting an order of magnitude above the measured 16MB peak.
- **The `--limit` conclusion from the capacity-8 run was wrong**, and only the capacity-64 run
  caught it: at capacity 8 the harness suggested ~267 messages would fit in the window, at
  capacity 64 it is ~6. Small-bracket measurement would have justified *raising* the limit.

### The payload finding, which corrected an assumption

I had assumed `narrativePayload` dominated the bracket response. It does not. At capacity 32:

| component | share |
|---|---|
| result lineups (all fixtures) | **41.5%** |
| `result.narrativePayload` | 32.6% |
| everything else | 21.7% |
| `entrants[]` | 4.3% |

So **~74% of the response is per-fixture match detail** — lineups and commentary — and
`GET /api/matches/{id}` already serves exactly that per match, with the bracket already
handing out `matchId` for every completed fixture. The recommendation was therefore stronger
and different from the one I had been making: the bracket should not embed `result` detail at
all, not merely drop the narrative.

**Done.** The `result` block is removed, and re-measuring the same capacity-32 bracket puts
the response at **59KB against 397KB — an 85% reduction**. Client contract and migration
table: `docs/api/competition-bracket-no-embedded-result.md`. **The app must be updated**: where
it read `fixtures[].result` it needs `GET /api/matches/{matchId}`, which the push payload
already carries the id for.

`CompetitionResult::toClientSummary()` is deleted with it — it had no callers once the bracket
stopped embedding it, since `MatchQueryService::serializeCupMatchDetail()` was already the
real client shape. The two were a duplicated payload that tests had to cross-check against
each other; that cross-check is gone because the duplication is.

### Still not measured

The harness is single-process. It bounds work per message, which is what the lease and the
consumer bounds need, but it demonstrates **nothing about contention**: nothing runs two
consumers against one competition, so the advisory lock and the claim lease remain argued for
rather than shown. There is also still no production instrumentation — no metric on build
time, cache hit rate, queue depth or consumer lag — so a mis-tuning in prod would still
surface first as a log entry.

---

## 8. Original Phase 4 notes (superseded by the measurements above)

Everything above rests on **reading the code and on unit tests that simulate contention** — a
stale lease, a duplicate delivery, a deleted round, a locked competition. Nothing has run two
tournaments in parallel against a real queue and a real consumer.

That is a genuine limit on how much the rest of this plan should be trusted, and it is worth
saying plainly rather than leaving implied by a green suite:

1. **Nothing here is load-tested.** The claim "this scales to concurrent rolling tournaments"
   is an argument, not a measurement.
2. **No metric exists for the thing that started this.** There is no instrumentation on
   bracket-document build time, cache hit rate, queue depth or consumer lag — so the first
   signal that any of it is wrong would again be a 500 in a log.
3. **The lease window (15 minutes) is a guess.** It was chosen to sit clear of observed
   per-round work, but per-round work has not been timed.

Worth doing before trusting this under real load: provision several concurrent competitions on
dev, let them roll through their rounds, and watch `messenger_messages` depth for the
`competition` queue, consumer wall-clock per message, and `competition_response_cache` hit
rate. The lease window and the consumer's `--limit`/`--time-limit` should then be set from
those numbers rather than from reasoning.
