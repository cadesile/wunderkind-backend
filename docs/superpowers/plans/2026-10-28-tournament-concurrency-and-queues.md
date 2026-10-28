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

## 4. Phase 3 — hygiene (the review's nits)

1. `prewarmResponse()` is byte-identical in `CompetitionDrawService` and
   `CompetitionResultsService`, with a third variant in the warm command. Folds naturally
   into `CompetitionResponseCacheService::prewarm()` once §3.4 lands.
2. `FriendlyChallengeService::splitSnapshot()` duplicates
   `CompetitionRegistrationService::splitSnapshot()` (private static). Share one.
3. `FriendlyChallengeSquadRepository::deleteForChallenge()` has no production caller, so
   friendly squads are never pruned after resolution — unlike competition squads. Wire it
   after `recordResult()`, or delete the method.

---

## 5. Decision required before `development → master`

> [!WARNING]
> `Version20261028001500` truncates the ten competition/friendly **instance** tables. The
> clear was explicitly authorised and `dev` has already taken it. Production has **not**.
>
> The review surfaced a consequence that was not weighed when it was authorised:
> **`register()` deducts `entryFeePence` from `club.balance`, and the truncate refunds
> nothing.** `entrant_reward_claim` rows go too, and `InboxMessage` rows that link to a
> competition are left pointing at deleted rows. `down()` restores none of it.
>
> Options, in increasing effort: accept it (tournaments were never player-facing, so the fees
> may be test data only); refund `entryFeePence` to every non-withdrawn entrant in the
> migration before truncating; or gate the truncate on the environment genuinely having no
> live instances and backfill instead, as `Version20261027230000` does for entrants.

---

## 6. Sequencing

| Phase | Blocks | Why in this order |
|---|---|---|
| 0 (§1) | `master` | Correctness. §1.1 can permanently stall a live bracket. |
| 1 (§2.1, §2.2, §2.3) | **done** | Claims-as-leases, plus making the draw's status transition atomic — which is what actually kept a crashed draw recoverable. |
| 2 (§3) | §3.1/§3.2/§3.4 **done**; §3.3 open | Bounds the work per tick, and restores prewarming in the form it should have had. |
| 3 (§4) | nothing | Falls out of Phase 2 cheaply. |
| §5 | `master` | A data decision, not an engineering one. |

---

## 7. Phase 4 — measurement (not started, and the real remaining gap)

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
