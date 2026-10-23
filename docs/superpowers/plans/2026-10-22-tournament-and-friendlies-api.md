# Tournament / Competition API & Friendlies — Implementation Plan

**Date:** 2026-10-23 (Updated after Frontend Prototype Team Review — **Implemented & Verified**)
**Scope:** Backend (`wunderkind-backend`) — `/api/competitions/*`, `/api/club/competitions`, `/api/club/hub`, `/api/matches/*`, `/api/club/matches`, `/api/club/h2h/*`, `/api/inbox/*` (tournament & friendly rewards/notifications), and `/api/friendlies/*`.

---

## 1. Confirmed Product & Architecture Decisions

> [!IMPORTANT]
> **Locked Decisions (incorporating Frontend Review 2026-10-23):**
> 1. **Power-of-Two Capacities Only (`4, 8, 16, 32, 64`) & No Byes (§1.1A):**
>    - `CompetitionTemplate::ALLOWED_ENTRANT_CAPACITIES` and `BracketLabeler::labelsForCapacity()` strictly enforce power-of-two capacities (`[4, 8, 16, 32, 64]`). Non-power-of-two capacities (`6, 12, 24`) are rejected by `CompetitionTemplate::validate()`.
>    - Every competition therefore has `bracketSize === entrantCapacity`, `byeCount === 0`, `totalRounds = log2(entrantCapacity)`, and `fixtures = entrantCapacity / 2^roundIndex`.
> 2. **No "Tournament Starts In" Registration Timer:**
>    - Tournaments only lock and start when all registration slots are filled (`registeredCount === entrantCapacity`).
>    - There is no `registrationClosesAt` countdown, no `REGISTRATION_CLOSING` notification, and no deadline-based cancellation.
> 3. **Round 1 Lead-Time Preserved (`scheduled` State Timestamps — §2.2):**
>    - Once the final registration slot fills, `CompetitionLockService::lock()` transitions the competition to `status = "scheduled"` and persists Round 1 (`status = "draw_pending"`) with `scheduledAt = now + firstRoundLeadTimeSeconds`.
>    - While `status === "scheduled"`, `InstanceSummary.nextRoundAt` and Round 1's `drawAt` (canonical; `scheduledAt` kept as deprecated alias) are populated with that timestamp so the client can show `"Draw at HH:MM"`.
> 4. **Reuse Existing Canonical Club Identity + `reputation` / `reputationTier` (§1.3, §3.1):**
>    - Attach the canonical `{ clubId, clubName, name, abbreviation, country, ovr, reputation, reputationTier, homeKitConfig, awayKitConfig, badgeConfig }` block across competitions, matches, and friendlies while keeping legacy keys on `GET /api/competitions/{id}` intact.
>    - `reputation` is `Club.reputation` (`int`, 0–100 scale), and `reputationTier` (`"local" | "regional" | "national" | "elite"`) is derived via the existing `Tier::fromScore($reputation)->value` (`0..14 → "local"`, `15..39 → "regional"`, `40..74 → "national"`, `75+ → "elite"`).
>    - `ovr` is the rounded integer mean of `currentAbility` across the 11 starting-XI players in the snapshot (`SnapshotValidator::REQUIRED_PLAYERS = 11`, no bench), computed and stored in `snapshotJson['club']['ovr']` at snapshot acceptance time so it never drifts on read.
>    - `CompetitionSpoofEntrantService` is updated to populate `homeKitConfig`, `awayKitConfig`, and `badgeConfig` on spoof clubs using `NpcClubGenerationService::generateIdentity()` (eliminating invalid `"circle"` / `"badge"` values).
> 5. **One-Off Entry Fee (`entryFeePence`) Charged Atomically on Register, Refunded on Withdraw & Cancel (§1.2, §2.5):**
>    - Emit `entryFeePence` as the canonical key on competition summaries/details; keep `entryFeePerRound` and `entryFeePerRoundPence` as deprecated aliases with the same integer value. Add `CompetitionTemplate::getEntryFeePence(): int`.
>    - Fee deduction happens in the **same DB transaction** as the entrant insert in `CompetitionRegistrationService::register()`, covered by `Idempotency-Key`.
>    - On `POST /api/competitions/{id}/withdraw` (allowed only while `status === "registering"`, otherwise `409 withdrawal_closed`), the `CompetitionEntrant` row is **kept** with `status = "withdrawn"` (`CompetitionEntrantStatus::WITHDRAWN`) so `viewer.relation === "withdrawn"` works on subsequent reads, `registeredCount` excludes withdrawn rows, and `entryFeePence` is refunded to `Club.balance`. Re-registering while still `"registering"` transitions the row back to `"registered"` and re-charges the fee.
>    - Whenever an `ActiveCompetition` is **cancelled** (admin cancel or any cancel path), every active/registered entrant (`status != "withdrawn"`) is automatically refunded `entryFeePence` to `Club.balance` in the same transaction.
> 6. **Friendlies Use Reputation Tier + Venue (§1.3, §2.1):**
>    - Challenge board filters by `?stake=none|low|high` and `?reputation=local,regional,national,elite` (comma-separated, any-of; omitted = all). `ovrDiff` and `?strength` are removed.
>    - `POST /api/friendlies/challenges` accepts `venue: "home" | "away"` (poster's perspective); challenge reads emit both `venue` and `viewerVenue` (`"home" | "away" | null`, acceptor's perspective).
>    - **Simulator Home Advantage Confirmation:** `DeterministicEngine` (`ResultsEngine.ts` port) computes dominance symmetrically (`calculateDominance($home, $away)` vs `calculateDominance($away, $home)`) with **no home-advantage multiplier**. Venue controls `Match.home` / `Match.away` ordering, kit selection (away side uses `awayKitConfig ?? homeKitConfig`), and commentary framing without skewing match balance.
> 7. **Single Combined `InboxMessage` per Reward & Club-Scoped Financials (§2.3, §2.4):**
>    - All tournament and friendly financials apply to `Club.balance` (never `UserLedger`, which is strictly for cross-club owner dividend draws).
>    - `RewardApplierService::applyVictorPrize()` combines cash `victorPrize` (`ledger_delta`) and attached `RewardTemplate` effects (`reputation_delta`) into a **single** `InboxMessage` (`senderType: "competition"`, `type: "COMPETITION_WON"`, `requiresAccept: true`).
>    - Friendly match payouts (`2 * stakePence` for a win when `stakePence > 0`) are also delivered as a claimable `InboxMessage` (`senderType: "friendly"`, `type: "FRIENDLY_RESULT"`, `requiresAccept: true`) so the client reconciles `Club.balance` through the exact same `POST /api/inbox/{id}/accept` response (`{ status: "accepted", appliedEffects, club: { clubId, balance, reputation } }`). On a 90-minute friendly draw with `stakePence > 0`, each club's escrowed `stakePence` is refunded directly to `Club.balance` at match resolution and an informational `FRIENDLY_RESULT` inbox message (`requiresAccept: false`) is sent.

---

## 2. Route Surface Overview

| Method & Path | Status | Auth / Club Resolution | Summary of Change |
|---|---|---|---|
| `GET /api/competitions/available` | **Modify** | Public (optional JWT + `X-Club-Id`) | Enriched `InstanceSummary` + canonical club identity (`reputation`, `reputationTier`, `homeKitConfig`, `awayKitConfig`, `badgeConfig`, `ovr`) + `viewer` block (`alreadyRegistered` & `eligibility` kept as deprecated aliases) |
| `GET /api/competitions/active` | **Modify** | Public (optional JWT + `X-Club-Id`) | Enriched `InstanceSummary` + `viewer` block |
| `GET /api/competitions` | **[NEW]** | Public (optional JWT + `X-Club-Id`) | Consolidated list with `?status=`, `?capacity=`, `?templateId=`, `?sort=startsAt\|completedAt`, `?cursor=`, `?limit=` + `serverTime` |
| `GET /api/club/competitions` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | All tournament instances the club has entered (any status, excluding `withdrawn`), cursor-paginated |
| `GET /api/competitions/champions` | **[NEW]** | Public | Latest completed instance + `champion` per active template |
| `GET /api/competitions/{id}` | **Modify** | Public (optional JWT + `X-Club-Id`) | Adds `viewer`, `serverTime`, `ETag` (`304`), full `totalRounds` (with `future`/`tbd` placeholders for undrawn rounds), canonical `drawAt`/`kickoffAt` (with `scheduledAt`/`matchesResolveAt` deprecated aliases), and fixture `homeEntrantId`/`awayEntrantId`/`winnerEntrantId`/`score`/`matchId` |
| `POST /api/competitions/{id}/register` | **Modify** | `ROLE_CLUB`, **required** `X-Club-Id` | Requires `X-Club-Id`; supports `Idempotency-Key`; checks & deducts `entryFeePence` in same transaction (`403 insufficient_funds`); splits `409` (`registration_closed` vs `competition_full`); stores `ovr` on snapshot; returns `{ entrantId, instanceId, seed, status, entrant, competition }` |
| `POST /api/competitions/{id}/withdraw` | **[NEW]** | `ROLE_CLUB`, **required** `X-Club-Id` | Allowed only while `status == "registering"` (`409 withdrawal_closed` once locked); sets entrant `status = "withdrawn"`, refunds `entryFeePence` to `Club.balance`, returns `{ competition: InstanceSummary }` (with `viewer.relation: "withdrawn"`) |
| `POST /api/competitions/{id}/resubmit` | **Modify** | `ROLE_CLUB`, **required** `X-Club-Id` | Resolves club via `X-Club-Id` (body `clubId` still accepted if matching); enforces `clientSnapshotAt` last-writer-wins (`409 stale_snapshot`); recomputes stored `ovr`; returns `locksAt` |
| `GET /api/matches/{matchId}` | **[NEW]** | Public (optional JWT + `X-Club-Id`) | First-class match detail (`kind: "cup"\|"friendly"`, `home`, `away`, `score`, `homeLineup`, `awayLineup`, `narrativePayload`) |
| `GET /api/club/matches` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | `?state=upcoming\|recent` — club's upcoming fixtures (`scheduled`, `awaiting_draw`, `awaiting_entrants`) and recent results with inline `h2h` |
| `GET /api/club/h2h/{opponentClubId}` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | `{ won, drawn, lost, recent: MatchSummary[] }` between caller's club and `opponentClubId` |
| `GET /api/inbox` | **Modify** | `ROLE_CLUB`, `X-Club-Id` | Emits `type`, `link: { kind, id }`, and `requiresAccept: bool` on each message; excludes `dismissed` messages |
| `POST /api/inbox/{id}/accept` | **Modify** | `ROLE_CLUB`, `X-Club-Id` | Returns `{ status: "accepted", appliedEffects, club: { clubId, balance, reputation } }`; returns `409 already_processed` on repeat call |
| `POST /api/inbox/{id}/dismiss` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | Marks notification/message as `dismissed` (`200 { status: "dismissed" }`) |
| `GET /api/friendlies/challenges` | **[NEW]** | Public (optional JWT + `X-Club-Id`) | Open challenges filtered by `?stake=none\|low\|high&reputation=local,regional,national,elite&limit=20&cursor=...` |
| `GET /api/club/friendlies/challenges` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | Caller's challenges grouped as `{ open: [...], accepted: [...] }` |
| `POST /api/friendlies/challenges` | **[NEW]** | `ROLE_CLUB`, **required** `X-Club-Id` | Post a challenge (`stakePence`, `venue`, `expiresInHours`, `snapshot`); escrows `stakePence` from `Club.balance` |
| `POST /api/friendlies/challenges/{id}/cancel` | **[NEW]** | `ROLE_CLUB`, **required** `X-Club-Id` | Cancel an open challenge (refunds `stakePence` to `Club.balance`) |
| `POST /api/friendlies/challenges/{id}/accept` | **[NEW]** | `ROLE_CLUB`, **required** `X-Club-Id` | Accept a challenge (anti-collusion check across same `User`, escrows `stakePence`, schedules kickoff at `acceptedAt + 15m`, returns `{ match: MatchSummary }`) |
| `GET /api/club/hub` | **[NEW]** | `ROLE_CLUB`, `X-Club-Id` | Aggregate landing payload (`{ nextMatch, inbox, myChallenges, topChallenges, upcoming, recent, open, champions, serverTime }`) |

---

## 3. Payload Contracts

### 3.1 Entrant / Club Identity Shape

Reuses the canonical `{ clubId, clubName, country, homeKitConfig, awayKitConfig, badgeConfig }` block from `LeaderboardItemDto` / `CommunityStatsService`, enriched with `name`, `abbreviation`, `ovr`, `reputation`, and `reputationTier`, while keeping legacy snapshot colour keys intact:

```json
{
  "entrantId": "0195f3a0-1234-7000-8000-000000000001",
  "clubId": "0195f390-abcd-7000-8000-000000000099",
  "clubName": "Kit FC",
  "name": "Kit FC",
  "abbreviation": "KIT",
  "country": "EN",
  "ovr": 68,
  "reputation": 42,
  "reputationTier": "national",
  "seed": 1,
  "status": "registered",
  "registeredAt": "2026-10-22T20:00:00+00:00",
  "homeKitConfig": {
    "kit": "stripes",
    "primary": "#c8202f",
    "secondary": "#f4f3ee",
    "shorts": "black",
    "socks": "primary"
  },
  "awayKitConfig": null,
  "badgeConfig": {
    "badgeShape": "round",
    "badgePattern": "plain",
    "badgeCentre": "initials",
    "initials": "KIT",
    "badgeFill": "#c8202f",
    "badgeTrim": "#f4f3ee",
    "badgeSymbol": "#f2c230"
  },
  "badgeShape": "round",
  "homePrimary": "#c8202f",
  "homeSecondary": "#f4f3ee",
  "awayPrimary": "#000000",
  "awaySecondary": "#cccccc"
}
```

- **Reputation Tier Thresholds (`App\Enum\Tier::fromScore()`):**
  - `"local"`: `0 – 14`
  - `"regional"`: `15 – 39`
  - `"national"`: `40 – 74`
  - `"elite"`: `75+`
- **Kit Fallback Rule:** When rendering an away side, use `awayKitConfig ?? homeKitConfig`.
- **OVR Calculation:** Rounded integer mean of `currentAbility` across the 11 starting-XI players in `snapshot.players` (`SnapshotValidator::REQUIRED_PLAYERS = 11`), persisted into `snapshotJson['club']['ovr']` when the snapshot is accepted.
- **Seeding Rule:** `CompetitionLockService::lock()` assigns `seed = 1..N` in registration order (`registered_at ASC, id ASC`), and `CompetitionDrawService::seedFirstRoundFixtures()` pairs entrants sequentially (`seed 1 vs seed 2`, `seed 3 vs seed 4`, etc.).

### 3.2 `InstanceSummary` & `viewer` Block

```json
{
  "instanceId": "0195f3a0-0000-7000-8000-000000000010",
  "templateId": "0195f3a0-0000-7000-8000-000000000001",
  "templateName": "Midweek Floodlit Cup",
  "status": "registering",
  "registrationOpensAt": "2026-10-22T09:00:00+00:00",
  "startsAt": null,
  "endsAt": null,
  "completedAt": null,
  "cancelledAt": null,
  "cancelReason": null,
  "trophy": {
    "style": "classic",
    "finish": "gold",
    "ribbons": true,
    "ribbonPrimary": "#c8202f",
    "ribbonSecondary": "#f4f3ee"
  },
  "entrantCapacity": 8,
  "registeredCount": 5,
  "slotsRemaining": 3,
  "bracketSize": 8,
  "byeCount": 0,
  "totalRounds": 3,
  "durationOption": "24h",
  "durationHours": 24,
  "entryConditions": {
    "minClubReputation": 10,
    "minClubAgeSeasons": 0,
    "allowedTiers": [4, 5, 6, 7, 8]
  },
  "entryFeePence": 25000,
  "entryFeePerRound": 25000,
  "entryFeePerRoundPence": 25000,
  "prizes": {
    "victorPrize": 250000,
    "victorPrizePence": 250000,
    "bonusRewards": []
  },
  "champion": null,
  "runnerUp": null,
  "nextRoundLabel": null,
  "nextRoundAt": null,
  "version": 6,
  "viewer": {
    "relation": "registered",
    "entrantId": "0195f3a0-1234-7000-8000-000000000001",
    "eligibility": {
      "eligible": true,
      "reasons": []
    },
    "nextMatchId": null,
    "snapshot": {
      "version": 1,
      "acceptedAt": "2026-10-22T18:30:00+00:00",
      "locksAt": null
    }
  }
}
```

- **`entryFeePence`** is canonical; `entryFeePerRound` and `entryFeePerRoundPence` are deprecated aliases with the same value.
- **`viewer.relation`**: `"none" | "registered" | "withdrawn" | "active" | "eliminated" | "winner"`.
- **When `status === "scheduled"`** (between registration fill and Round 1 draw): `nextRoundLabel` is Round 1's label (e.g. `"QF"`), `nextRoundAt` is Round 1's `drawAt` timestamp (`now + firstRoundLeadTimeSeconds`), and `viewer.snapshot.locksAt` equals `nextRoundAt`.

### 3.3 `GET /api/competitions/{id}` Bracket Structure

- Emits all `InstanceSummary` fields + `serverTime` + `entrants` (excluding `withdrawn` rows) + `rounds`.
- `rounds` is **always** `totalRounds` long (`log2(entrantCapacity)`), even while `registering` (`status: "future"`).
- Every round carries:
  - `roundIndex` (1-based)
  - `key` (`"R64" | "R32" | "R16" | "QF" | "SF" | "F"`)
  - `label` (`"R64" | "R32" | "R16" | "QF" | "SF" | "FINAL"`)
  - `status` (`"future" | "draw_pending" | "drawn" | "results_published" | "cancelled"`)
  - `drawAt` (canonical; `scheduledAt` kept as deprecated alias)
  - `startedAt`
  - `kickoffAt` (canonical; `matchesResolveAt` kept as deprecated alias)
  - `fixtures`: always `entrantCapacity / 2^roundIndex` items (`status: "tbd"` placeholders when undrawn; populated with `homeEntrantId`, `awayEntrantId`, `winnerEntrantId`, `score`, `matchId`, plus legacy `home`, `away`, `result` when drawn/complete).

### 3.4 `GET /api/matches/{matchId}` & `GET /api/club/matches`

#### `GET /api/matches/{matchId}` (Full Match Detail)
```json
{
  "matchId": "0195f3a0-9999-7000-8000-000000000055",
  "kind": "cup",
  "competition": {
    "instanceId": "0195f3a0-0000-7000-8000-000000000010",
    "templateId": "0195f3a0-0000-7000-8000-000000000001",
    "templateName": "Midweek Floodlit Cup",
    "roundIndex": 2,
    "roundKey": "SF",
    "roundLabel": "SF"
  },
  "friendly": null,
  "kickoffAt": "2026-10-22T16:00:00+00:00",
  "status": "complete",
  "home": { "clubId": "...", "clubName": "Alpha FC", "name": "Alpha FC", "abbreviation": "ALP", "country": "EN", "ovr": 71, "reputation": 55, "reputationTier": "national", "homeKitConfig": {}, "awayKitConfig": null, "badgeConfig": {} },
  "away": { "clubId": "...", "clubName": "Bravo FC", "name": "Bravo FC", "abbreviation": "BRA", "country": "EN", "ovr": 68, "reputation": 42, "reputationTier": "national", "homeKitConfig": {}, "awayKitConfig": null, "badgeConfig": {} },
  "score": {
    "home": 2,
    "away": 1,
    "extraTime": false,
    "penalties": null
  },
  "homeLineup": [...],
  "awayLineup": [...],
  "narrativePayload": [...]
}
```

#### `GET /api/club/matches?state=upcoming` Item States (§2.2)
1. **Drawn fixture (`status: "scheduled"`)** — Cup round is `drawn` (or friendly challenge is `accepted`):
   - `matchId`: fixture/challenge UUID
   - `drawAt`: `round.scheduledAt` (for cup) or `null` (for friendly)
   - `kickoffAt`: `round.matchesResolveAt` (for cup) or `challenge.kickoffAt` (`acceptedAt + 15m` for friendly)
   - `home` & `away`: populated club identity blocks
2. **Awaiting draw (`status: "awaiting_draw"`)** — Competition is `scheduled` (between fill and Round 1 draw) or `running` (between rounds, next round `draw_pending`):
   - `matchId`: `null`
   - `drawAt`: `round.scheduledAt` (ATOM — always non-null so client can show `"Draw at HH:MM"`)
   - `kickoffAt`: `round.matchesResolveAt` (nullable until drawn)
   - `home`: caller's club identity; `away`: `null`
3. **Awaiting entrants (`status: "awaiting_entrants"`)** — Competition is still `registering` and caller is registered:
   - `matchId`: `null`
   - `drawAt`: `null`
   - `kickoffAt`: `null`
   - `competition`: includes `registeredCount` and `entrantCapacity` so client can show `"Waiting for 3 more clubs (5/8)"`

### 3.5 Inbox Read (`GET /api/inbox`) & Reward Claim (`POST /api/inbox/{id}/accept`) (§2.3)

#### `GET /api/inbox` Item Shape
```json
{
  "id": "0195f3b0-1111-7000-8000-000000000001",
  "senderType": "competition",
  "senderName": "Tournament Association",
  "subject": "Champions: Midweek Floodlit Cup",
  "body": "Congratulations! Your club won the Midweek Floodlit Cup.",
  "type": "COMPETITION_WON",
  "link": {
    "kind": "competition",
    "id": "0195f3a0-0000-7000-8000-000000000010"
  },
  "requiresAccept": true,
  "offerData": {
    "type": "COMPETITION_WON",
    "link": {
      "kind": "competition",
      "id": "0195f3a0-0000-7000-8000-000000000010"
    },
    "effects": [
      { "type": "ledger_delta", "amountPence": 250000 },
      { "type": "reputation_delta", "amount": 10 }
    ]
  },
  "status": "unread",
  "relatedEntityType": "active_competition",
  "relatedEntityId": "0195f3a0-0000-7000-8000-000000000010",
  "createdAt": "2026-10-22T20:00:00+00:00",
  "respondedAt": null
}
```
- `requiresAccept` is `true` iff `offerData.effects` is non-empty and `status` is `"unread"` or `"read"`. Informational notifications have `requiresAccept: false` and are cleared via `POST /api/inbox/{id}/dismiss` (or marked read via `POST /api/inbox/{id}/read`).

#### `POST /api/inbox/{id}/accept` Response
```json
{
  "status": "accepted",
  "appliedEffects": [
    { "type": "ledger_delta", "amountPence": 250000 },
    { "type": "reputation_delta", "amount": 10 }
  ],
  "club": {
    "clubId": "0195f390-abcd-7000-8000-000000000099",
    "balance": 1250000,
    "reputation": 52
  }
}
```

### 3.6 Friendlies / Challenge Board Contract (§1.3, §2.1)

#### `GET /api/friendlies/challenges`
- **Query params:**
  - `stake`: `none | low | high` (omitted = all)
    - `none`: `stakePence === 0`
    - `low`: `0 < stakePence <= 5_000_000` (up to £50K; also covers `1`–`50_000` if prototype sends unscaled values)
    - `high`: `stakePence > 5_000_000` (£100K / £250K)
  - `reputation`: comma-separated subset of `local,regional,national,elite` (any-of match on `challenger.reputationTier`; omitted = all)
  - `limit`: `1..50` (default `20`), `cursor`: opaque pagination cursor
- **Response (`200 OK`):**
```json
{
  "challenges": [
    {
      "challengeId": "0195f3c0-2222-7000-8000-000000000001",
      "challenger": {
        "clubId": "0195f390-abcd-7000-8000-000000000099",
        "clubName": "Kit FC",
        "name": "Kit FC",
        "abbreviation": "KIT",
        "country": "EN",
        "ovr": 68,
        "reputation": 42,
        "reputationTier": "national",
        "homeKitConfig": {},
        "awayKitConfig": null,
        "badgeConfig": {}
      },
      "venue": "home",
      "viewerVenue": "away",
      "stakePence": 5000000,
      "createdAt": "2026-10-23T10:00:00+00:00",
      "expiresAt": "2026-10-23T16:00:00+00:00",
      "status": "open",
      "h2h": { "won": 1, "drawn": 0, "lost": 2 }
    }
  ],
  "nextCursor": null,
  "serverTime": "2026-10-23T11:00:00+00:00"
}
```
- Note: When called with `X-Club-Id`, challenges posted by any club belonging to the caller's `User` are excluded from the public board (or flagged), `viewerVenue` is the opposite of `venue` (`"home" ↔ "away"`), and `h2h` is computed against the caller's club (`null` when called without club context).

#### `GET /api/club/friendlies/challenges` (`ROLE_CLUB`, `X-Club-Id`)
Returns the caller's own challenges (both posted and accepted):
```json
{
  "open": [ /* ChallengeItem[] posted by this club with status === "open" */ ],
  "accepted": [ /* ChallengeItem[] involving this club with status === "accepted" (awaiting 15m kickoff resolution), including matchId & kickoffAt */ ],
  "serverTime": "2026-10-23T11:00:00+00:00"
}
```

#### `POST /api/friendlies/challenges` (`ROLE_CLUB`, **required** `X-Club-Id`, supports `Idempotency-Key`)
- **Request body:**
```json
{
  "stakePence": 5000000,
  "venue": "home",
  "expiresInHours": 6,
  "snapshot": { "...same shape as POST /api/competitions/{id}/register..." }
}
```
- **Validation & Business Rules:**
  - `venue ∈ {"home", "away"}` (`422 validation_failed` otherwise).
  - `expiresInHours ∈ {1, 6, 24}` (`422 validation_failed` otherwise).
  - `stakePence`: allowed tiers in integer pence:
    - Real pence (£0 / £10K / £25K / £50K / £100K / £250K): `0, 1_000_000, 2_500_000, 5_000_000, 10_000_000, 25_000_000` (also accepts prototype-scaled `10_000, 25_000, 50_000, 100_000, 250_000` so prototype builds don't 422).
  - Max **3** open challenges per club → `409 { "error": "challenge_limit_reached", "message": "You already have 3 open challenges." }`.
  - Requires `Club.balance >= stakePence` → `403 { "error": "insufficient_funds", "required": 5000000, "actual": 1200000 }`.
  - Escrows `stakePence` from `Club.balance` immediately in the same transaction.
- **Response (`201 Created`):** `{ "challenge": ChallengeItem, "club": { "clubId": "...", "balance": ... } }`.

#### `POST /api/friendlies/challenges/{id}/cancel` (`ROLE_CLUB`, **required** `X-Club-Id`, supports `Idempotency-Key`)
- Only the poster club can cancel, and only while `status === "open"` (`409 challenge_taken` if already accepted, `409 challenge_expired` if already expired).
- Refunds `stakePence` to `Club.balance` in the same transaction.
- **Response (`200 OK`):** `{ "status": "cancelled", "club": { "clubId": "...", "balance": ... } }`.

#### `POST /api/friendlies/challenges/{id}/accept` (`ROLE_CLUB`, **required** `X-Club-Id`, supports `Idempotency-Key`)
- **Request body:** `{ "snapshot": { "...same shape as /register..." } }`.
- **Error codes:**
  - `409 own_challenge` — acceptor `clubId === challenger.clubId`.
  - `403 same_owner` — acceptor `club.user.id === challenger.user.id` (prevents multi-save stake transferring).
  - `409 challenge_taken` — status is no longer `"open"`.
  - `409 challenge_expired` — `expiresAt <= now`.
  - `403 insufficient_funds` — acceptor `Club.balance < stakePence`.
- **Execution:** Atomically claims the challenge (`SELECT ... FOR UPDATE`), deducts `stakePence` from acceptor `Club.balance`, assigns `home` and `away` according to `venue`, sets `acceptedAt = now` and `kickoffAt = now + 15 minutes`, and sends `CHALLENGE_ACCEPTED` (push + inbox) to the challenger.
- **Response (`200 OK`):** `{ "match": MatchSummary, "club": { "clubId": "...", "balance": ... } }`.
- **Resolution (`app:friendly:resolve-matches` every 1 min):**
  - Simulates a 90-minute match via `DeterministicEngine` (no extra time / penalties — friendlies can end in a draw).
  - **Win:** Winner receives an `InboxMessage` (`senderType: "friendly"`, `type: "FRIENDLY_RESULT"`, `requiresAccept: stakePence > 0`, `effects: [{ type: "ledger_delta", amountPence: 2 * stakePence }]`); loser receives an informational `FRIENDLY_RESULT` inbox message (`requiresAccept: false`).
  - **Draw:** If `stakePence > 0`, each club's escrowed `stakePence` is refunded directly to `Club.balance` on resolution, and both receive an informational `FRIENDLY_RESULT` inbox message (`requiresAccept: false`).

### 3.7 Unified Notification & Inbox Type Table (§2.4)

| Event | Push `data.type` | Inbox `type` | `senderType` | `requiresAccept` | `link` | Notes |
|---|---|---|---|---|---|---|
| New tournament instance opened | `NEW_COMPETITION_OPEN` | *(none)* | — | — | — | Existing push broadcast to eligible clubs |
| Another club registered in your cup | `NEW_REGISTRANT` | *(none)* | — | — | — | Existing push to already-registered entrants |
| Cup registration full & locked | `COMPETITION_LOCKED` | `COMPETITION_LOCKED` | `competition` | `false` | `{ kind: "competition", id: instanceId }` | Sent to all entrants when last slot fills (`status → scheduled`) |
| Cup cancelled by admin | `COMPETITION_CANCELLED` | `COMPETITION_CANCELLED` | `competition` | `false` | `{ kind: "competition", id: instanceId }` | Entry fee auto-refunded to `Club.balance` in cancel transaction |
| Round fixtures drawn | `ROUND_DRAWN` | `ROUND_DRAWN` | `competition` | `false` | `{ kind: "match", id: fixtureId }` | Sent to each entrant placed into the round |
| Round resolving in 15m | `ROUND_RESOLVING_SOON` | *(none)* | — | — | — | Existing reminder push |
| Cup match resolved | `MATCH_RESULT` | `MATCH_RESULT` | `competition` | `false` | `{ kind: "match", id: fixtureId }` | Sent to both sides of the fixture |
| Cup won (Champion) | `COMPETITION_COMPLETED` (`result: "WON"`) | `COMPETITION_WON` | `competition` | `true` | `{ kind: "competition", id: instanceId }` | Single combined reward (`victorPrize` + `RewardTemplate` effects) |
| Knocked out of cup (any round incl. Final) | `COMPETITION_COMPLETED` (`result: "RUNNER_UP"` on final) | `COMPETITION_ELIMINATED` | `competition` | `false` | `{ kind: "competition", id: instanceId }` | Created when an entrant is eliminated |
| Friendly challenge accepted | `CHALLENGE_ACCEPTED` | `CHALLENGE_ACCEPTED` | `friendly` | `false` | `{ kind: "match", id: challengeId }` | Sent to challenger; kickoff in 15m |
| Friendly challenge expired | `CHALLENGE_EXPIRED` | `CHALLENGE_EXPIRED` | `friendly` | `false` | `{ kind: "friendly", id: challengeId }` | Sent to challenger; `stakePence` auto-refunded to `Club.balance` |
| Friendly match resolved | `FRIENDLY_RESULT` | `FRIENDLY_RESULT` | `friendly` | `true` (winner if `stakePence > 0`) / `false` (otherwise) | `{ kind: "match", id: challengeId }` | Winner claims `2 * stakePence` via `/api/inbox/{id}/accept` |

---

## 4. Phased Implementation Milestones (Completed)

### Milestone 1: Club Identity (`badgeConfig` / `homeKitConfig` / `reputationTier`), Spoof Fix, & `viewer` Block (P0) — ✅ Completed
- **`src/Service/NpcClubGenerationService.php` & `src/Service/Competition/CompetitionSpoofEntrantService.php`**: Replaced `BADGE_SHAPES` (`'circle'`, `'badge'`) with `BadgeShape::cases()`; populated `homeKitConfig`, `awayKitConfig`, `badgeConfig`, `abbreviation`, and positive starting balance on spoof clubs via `NpcClubGenerationService::generateIdentity()`.
- **`src/Service/Competition/EligibilityEvaluator.php` & `EligibilityResult.php`**: Added `insufficient_funds` check against `CompetitionTemplate::getEntryFeePence()` and emitted structured reason objects (`{ code, required, actual }`) for `viewer.eligibility.reasons`.
- **`src/Controller/Api/CompetitionController.php`, `src/Service/Competition/CompetitionResultsService.php`, & `src/Entity/Competition/CompetitionResult.php`**: Attached `{ clubId, clubName, name, abbreviation, country, ovr, reputation, reputationTier, homeKitConfig, awayKitConfig, badgeConfig }` to entrants and result club blocks; normalized `trophy` through `TrophyConfigFactory::validateNullable()`; added `viewer` block (`relation: "none"|"registered"|"withdrawn"|"active"|"eliminated"|"winner"`) to all competition payloads.

### Milestone 2: Instance Summary Additions, Atomic Entry Fee, & Withdraw Endpoint (P0) — ✅ Completed
- **`src/Enum/Competition/CompetitionDuration.php` & `CompetitionEntrantStatus.php`**: Added `CompetitionDuration::toHours(): int` and `CompetitionEntrantStatus::WITHDRAWN = 'withdrawn'`.
- **`src/Repository/Competition/CompetitionEntrantRepository.php`**: Excluded `WITHDRAWN` rows from `countForCompetition()` and `findByCompetitionOrderedByRegistration()`; added `findByClub()`.
- **`src/Service/Competition/CompetitionRegistrationService.php`**:
  - Deducted `entryFeePence` in the same DB transaction as the entrant insert/update; stored `ovr` on `snapshotJson['club']['ovr']`; distinguished `registration_closed` vs `competition_full` (`409`); allowed re-registration of a `WITHDRAWN` row while `status === 'registering'`.
  - Implemented `withdraw(ActiveCompetition $activeCompetition, Club $club)` while `status === 'registering'` (`409 withdrawal_closed` once locked): marked entrant `status = WITHDRAWN` and refunded `entryFeePence` to `Club.balance`.
  - Added `cancel(ActiveCompetition $activeCompetition, ?string $reason)` to refund `entryFeePence` to all non-withdrawn entrants whenever an `ActiveCompetition` is cancelled.
- **`src/Controller/Api/CompetitionController.php`**: Added `POST /api/competitions/{id}/withdraw`; enriched `serializeInstanceSummary()` with `registrationOpensAt`, `slotsRemaining`, `bracketSize`, `byeCount`, `totalRounds`, `durationHours`, `entryFeePence` (plus deprecated aliases `entryFeePerRound` / `entryFeePerRoundPence`), `prizes.victorPrizePence`, `champion`, `runnerUp`, `cancelledAt`, `cancelReason`, and `version`.

### Milestone 3: Full Bracket Rounds (`GET /api/competitions/{id}`), First-Class Matches (`/api/matches/*`), & Head-to-Head (`/api/club/h2h/*`) (P1) — ✅ Completed
- **`src/Service/Competition/BracketLabeler.php`**: Added `keyForLabel()` (`'FINAL'` → `'F'`) and `slotCountForRound()`.
- **`src/Controller/Api/CompetitionController.php`**: Emitted all `totalRounds` in `GET /api/competitions/{id}` with canonical `drawAt`/`kickoffAt` (keeping `scheduledAt`/`matchesResolveAt` as deprecated aliases), placeholder `tbd` fixtures for undrawn rounds, and `homeEntrantId`/`awayEntrantId`/`winnerEntrantId`/`score`/`matchId` on fixtures.
- **`src/Service/Competition/MatchQueryService.php` & `src/Controller/Api/MatchController.php`**: Implemented `GET /api/matches/{matchId}`, `GET /api/club/matches?state=upcoming|recent` (supporting `scheduled`, `awaiting_draw`, and `awaiting_entrants` states), and `GET /api/club/h2h/{opponentClubId}`.

### Milestone 4: Required `X-Club-Id` on Writes, Register/Resubmit Enhancements, & `Idempotency-Key` (P1) — ✅ Completed
- **`src/Exception/ClubIdRequiredException.php` & `src/Service/ClubResolver.php`**: Added `resolveRequiredFromRequest(User $user, ?string $fallbackClubId = null): Club` (`400 club_id_required` when header/fallback is missing on competition/friendly writes).
- **`src/Entity/IdempotencyRecord.php`, `src/Repository/IdempotencyRecordRepository.php`, & `src/EventSubscriber/IdempotencySubscriber.php`**: Keyed on `(club_id, idempotency_key, endpoint)` with SHA-256 `request_hash` check (`422 idempotency_key_reused` if the same key is sent with a different body; replays stored status/body for 24h on match).
- **`src/Controller/Api/CompetitionController.php` & `src/Dto/Competition/CompetitionResubmitRequest.php`**: Returned `{ entrant, competition }` from `/register`; made `CompetitionResubmitRequest::$clubId` optional so `/resubmit` resolves via `X-Club-Id`, enforced stale snapshot lock check (`409 round_already_drawn` / `409 stale_snapshot`), recomputed `ovr`, and returned `locksAt`.

### Milestone 5: Inbox Notification & Reward System Hardening (P1) — ✅ Completed
- **`src/Service/Competition/RewardApplierService.php`**: Combined `victorPrize` and all attached `RewardTemplate` effects into one `InboxMessage` (`type: "COMPETITION_WON"`, `requiresAccept: true`) per tournament win, with `link` metadata.
- **`src/Service/Competition/{CompetitionLockService,CompetitionDrawService,CompetitionResultsService}.php`**: Emitted corresponding informational `InboxMessage` items (`COMPETITION_LOCKED`, `COMPETITION_CANCELLED`, `ROUND_DRAWN`, `MATCH_RESULT`, `COMPETITION_ELIMINATED`) with `requiresAccept: false` and `link`.
- **`src/Enum/MessageStatus.php` & `src/Enum/MessageSenderType.php`**: Added `MessageStatus::DISMISSED = 'dismissed'` and `MessageSenderType::FRIENDLY = 'friendly'`.
- **`src/Service/InboxService.php` & `src/Controller/Api/InboxController.php`**: Emitted `type`, `link`, and `requiresAccept` on `GET /api/inbox` items; excluded `DISMISSED` items from `findByClub()`; added `POST /api/inbox/{id}/dismiss`; updated `POST /api/inbox/{id}/accept` to return `{ status, appliedEffects, club: { clubId, balance, reputation } }` and `409 already_processed` on repeat calls.
- **`tests/Controller/Api/InboxControllerTest.php`, `tests/Service/InboxServiceTest.php`, `tests/Service/Competition/RewardApplierServiceTest.php`**: Full functional & integration test coverage.

### Milestone 6: Friendlies / Challenge Board (P1) — ✅ Completed
- **`src/Enum/Friendly/FriendlyChallengeStatus.php`, `src/Entity/Friendly/FriendlyChallenge.php`, `src/Repository/Friendly/FriendlyChallengeRepository.php`, `src/Service/Friendly/FriendlyChallengeService.php`, & `src/Controller/Api/FriendlyController.php`**:
  - Implemented `GET /api/friendlies/challenges?stake=none|low|high&reputation=local,regional,national,elite`, `GET /api/club/friendlies/challenges` (`{ open, accepted }`), `POST /api/friendlies/challenges` (`venue`, `stakePence`, `expiresInHours`, `snapshot`), `POST /api/friendlies/challenges/{id}/cancel`, and `POST /api/friendlies/challenges/{id}/accept` (`own_challenge`, `same_owner`, `challenge_taken`, `challenge_expired`, `insufficient_funds`).
  - Added `DeterministicEngine::resolveFriendly()` (`allowDraws: true` so 90-minute draws stand in friendlies) and 1-minute cron commands `app:friendly:resolve-matches` and `app:friendly:expire-challenges` (`src/Command/Friendly/`).
  - Added `FriendlyChallenge` cleanup to `AccountDeletionService::BLOCKING_CLUB_DEPENDENTS` and `DeleteAdminController::cleanClubForeignKeys()`, and created migration `migrations/Version20261023133500.php`.

### Milestone 7: List Consolidation, Champions, ETag, & Hub Aggregate (P1/P2) — ✅ Completed
- **`src/Controller/Api/CompetitionController.php` & `src/Controller/Api/ClubHubController.php`**: Added `GET /api/competitions`, `GET /api/club/competitions`, `GET /api/competitions/champions`, `ETag` (`304`) + `serverTime`, and `GET /api/club/hub`.

---

## 5. Verification Plan

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction --env=test
lando php bin/phpunit tests/Service/ClubResolverTest.php
lando php bin/phpunit tests/Service/InboxServiceTest.php
lando php bin/phpunit tests/Service/Competition/
lando php bin/phpunit tests/Controller/Api/InboxControllerTest.php
lando php bin/phpunit tests/Controller/Api/CompetitionControllerTest.php
lando php bin/phpunit tests/Controller/Api/MatchControllerTest.php
lando php bin/phpunit tests/Controller/Api/FriendlyControllerTest.php
```
