# Frontend ↔ Wunderkind Backend — Integration Guide

You are configuring a React Native app to communicate with the Wunderkind backend API. The app uses MMKV for local storage. Set up environment-based URL configuration and a typed API client layer for all backend endpoints.

---

## Environment configuration

The base URL must be configurable per environment. Use `react-native-dotenv` (or Expo's built-in `EXPO_PUBLIC_` env vars if on Expo) to inject it at build time.

Required env var:
```
API_BASE_URL=http://wunderkind-backend.lndo.site   # local dev (Lando)
# API_BASE_URL=https://api.wunderkind.app          # production (future)
```

All API requests must:
- Set `Content-Type: application/json`
- For protected endpoints, include `Authorization: Bearer <jwt_token>` — the token is stored in MMKV under the key `auth_token`

---

## Auth flow

1. On app launch, check MMKV for `auth_token`
2. If absent → call `POST /api/register`, then `POST /api/login`, store token
3. If present → proceed; on any `401` response, call `POST /api/login` to refresh and retry once
4. On `403` → show error, do not retry

---

## Balance model

The academy has a server-side liquid `balance` (integer, pence/cents). It is updated on every accepted sync:

| Event | Effect on balance |
|---|---|
| `earningsDelta` from sync | `+earningsDelta` |
| Monthly sponsor payment (if due) | `+monthlyPayment` |
| Weekly staff salaries | `−sum(staff.weeklySalary)` |
| Weekly player wages | `−sum(player.contractValue)` where status = active |
| Sponsor offer accepted (signing bonus) | `+signingBonus` (if present in offerData) |
| Investor offer accepted | `+investmentAmount` (capital injection) |
| Annual investor payout (every 52 weeks) | `−payout` (deducted per investor's equity share) |
| Facility upgrade | `−upgradeCost` |

Balance can go **negative** (debt). Use `hasDebt` from `/api/academy/status` or the sync response to surface a warning in the UI.

---

## Endpoints

### `POST /api/register` — Public
Create a new user and academy. Call once on first launch if no `auth_token` exists in MMKV.

Request body:
```json
{
  "email": "string",
  "password": "string",
  "academyName": "string"
}
```
Response `201`:
```json
{
  "id": "uuid",
  "email": "string",
  "academyName": "string"
}
```
Errors:
- `400` — missing required fields
- `409` — email already registered

---

### `POST /api/login` — Public
Exchange credentials for a JWT. Store the returned `token` in MMKV under `auth_token`.

Request body:
```json
{
  "username": "email@example.com",
  "password": "string"
}
```
Response `200`:
```json
{ "token": "eyJ..." }
```

---

### `POST /api/academy/initialize` — JWT required (`ROLE_CLUB`)
Called once after registration to set up the academy with a starter bundle of players, staff, and a sponsor. Do not call if the academy is already initialized.

Request body:
```json
{
  "academyName": "string"
}
```
Constraints: `academyName` must be 3–50 characters.

Response `201`:
```json
{
  "id": "uuid",
  "name": "string",
  "starterBundle": {
    "players": 10,
    "coaches": 2,
    "scouts": 1,
    "sponsors": 1,
    "investors": 0
  },
  "players": 10,
  "staff": 3
}
```
Errors:
- `409` — academy already initialized

Starting balance is £5,000 (500,000 pence). Four facilities are created at level 0 automatically. A random PA name and manager personality traits (temperament, discipline, ambition, each seeded between 40–60) are generated on initialization.

---

### `GET /api/academy/status` — JWT required (`ROLE_CLUB`)
Snapshot of the academy's current state. Poll after each sync to refresh UI totals.

Response `200`:
```json
{
  "id": "uuid",
  "name": "string",
  "balance": 487500,
  "hasDebt": false,
  "reputation": 310,
  "weekNumber": 42,
  "totalCareerEarnings": 12500000,
  "hallOfFamePoints": 250,
  "playerCount": 10,
  "staffCount": 3,
  "activeSponsors": 1,
  "activeInvestors": 0
}
```
- `balance` — liquid cash in pence/cents (can be negative)
- `hasDebt` — `true` when `balance < 0`
- `weekNumber` — last successfully synced game week

Errors:
- `404` — no academy found for the authenticated user

---

### `GET /api/squad` — JWT required (`ROLE_CLUB`)
Full player roster for the authenticated academy, including morale and personality traits. Personality traits are hidden from the player in-game but available for server-side processing.

Response `200`:
```json
{
  "players": [
    {
      "id": "uuid",
      "firstName": "string",
      "lastName": "string",
      "dateOfBirth": "YYYY-MM-DD",
      "nationality": "string",
      "position": "GK | DEF | MID | ATT",
      "status": "active | loaned_out | transferred | retired",
      "morale": 75,
      "contractValue": 1200,
      "personality": {
        "confidence": 70,
        "maturity": 65,
        "teamwork": 80,
        "leadership": 50,
        "ego": 40,
        "bravery": 75,
        "greed": 30,
        "loyalty": 85
      },
      "agentName": "Jorge Mendes"
    }
  ]
}
```
- `morale` — 0–100; affects training output and transfer negotiation
- `contractValue` — weekly wage in pence/cents; deducted from balance each sync
- `agentName` — `null` if the player has no agent
- Personality traits are all 0–100; intentionally hidden from the game UI

Errors:
- `404` — no academy found for the authenticated user

---

### `GET /api/staff` — JWT required (`ROLE_CLUB`)
Full staff roster for the authenticated academy.

Response `200`:
```json
{
  "staff": [
    {
      "id": "uuid",
      "firstName": "string",
      "lastName": "string",
      "role": "head_coach | assistant_coach | scout | fitness_coach | analyst",
      "specialty": "Technique | Physicality | Tactical | Mental | null",
      "specialisms": {"pace": 85, "technical": 70},
      "morale": 82,
      "coachingAbility": 75,
      "scoutingRange": 50,
      "weeklySalary": 15000
    }
  ]
}
```
- `specialty` — set for `head_coach` and `assistant_coach` only; drives which training pipeline they excel at
- `specialisms` — granular attribute strengths (keys: `pace`, `technical`, `vision`, `power`, `stamina`, `heart`; values 50–90); `null` if not set
- `coachingAbility` — 1–100; influences training effectiveness
- `scoutingRange` — 1–100; influences scouting network pipeline
- `weeklySalary` — deducted from academy balance each sync

Errors:
- `404` — no academy found for the authenticated user

---

### `POST /api/sync` — JWT required (`ROLE_CLUB`)
Called once per Weekly Tick. Sends aggregate deltas from the current week; server updates leaderboards, persists academy state, processes finances, and checks economic events.

After each accepted sync the server automatically:
1. **Credits** `earningsDelta` to the academy's liquid balance
2. **Processes sponsor payments** — any active sponsor whose monthly payment is due is credited to balance; `lastPaymentAt` is updated
3. **Deducts weekly salaries** — all active player wages and staff salaries subtracted from balance (balance can go negative)
4. **Applies manager shifts** — incremental changes to manager personality traits sent by the client
5. **Financial year-end** (every 52 weeks) — deducts investor profit-share payouts from balance; sends payout notifications to inbox
6. **Sponsor contract health check** — voids contracts below `reputationMinThreshold`; marks contracts as `completed` when end date passes
7. **Age-out checks** — sends inbox warning at ≤ 4 weeks before a player turns 21; hard-deletes the player at week 21

Any events generated by steps 5–7 appear as new messages in `GET /api/inbox`. Poll the inbox after each accepted sync.

Request body:
```json
{
  "weekNumber": 42,
  "clientTimestamp": "2026-03-01T12:00:00+00:00",
  "earningsDelta": 500000,
  "reputationDelta": 10,
  "hallOfFamePoints": 250,
  "transfers": [],
  "managerShifts": {
    "temperament": 2,
    "discipline": -1,
    "ambition": 0
  }
}
```
- `weekNumber` — current game week (positive integer, must never go backwards)
- `clientTimestamp` — ISO 8601 device time at tick; used to compute player age-out dates
- `earningsDelta` — earnings this week in pence/cents (≥ 0)
- `reputationDelta` — reputation change this week (can be negative)
- `hallOfFamePoints` — **ignored by the server.** Hall of Fame points are derived server-side from tier-weighted league titles (see `docs/api/leaderboard.md`); the field is still accepted for backwards compatibility and recorded in the sync audit payload. The value returned in the sync response is the derived one.
- `transfers` — array, pass `[]` until transfer syncing is implemented
- `managerShifts` — optional; signed integer deltas for manager personality traits (each clamped to 0–100 server-side); omit or pass `{}` if no changes this week

Response `200` (accepted):
```json
{
  "accepted": true,
  "weekNumber": 42,
  "syncedAt": "2026-03-01T12:00:05+00:00",
  "academy": {
    "reputation": 310,
    "totalCareerEarnings": 12500000,
    "hallOfFamePoints": 250,
    "balance": 487500,
    "hasDebt": false,
    "manager": {
      "temperament": 52,
      "discipline": 49,
      "ambition": 50
    }
  }
}
```
- `balance` reflects all financial processing that occurred during this sync
- `hasDebt: true` should surface a UI warning immediately
- `manager` — post-shift trait values; persist locally and apply as context to narrative event selection

Response `409` (anti-cheat rejection — week rolled back):
```json
{
  "accepted": false,
  "reason": "week_rollback",
  "currentWeek": 45
}
```

---

### `GET /api/leaderboard/{category}?period={period}` — JWT required
Fetch a ranked leaderboard including the caller's own rank.

`{category}` — one of: `career_earnings` · `club_reputation` · `hall_of_fame`

`?period` — `all-time` (default) or ISO week string e.g. `2026-W09`

Response `200`:
```json
{
  "category": "career_earnings",
  "period": "all-time",
  "entries": [
    {
      "rank": 1,
      "academyName": "string",
      "score": 99999999
    }
  ],
  "you": {
    "rank": 42,
    "academyName": "string",
    "score": 12500000
  }
}
```
- `you` is `null` if the authenticated user has no academy or no leaderboard entry for the given category/period.

Errors:
- `400` — invalid category value

---

### `GET /api/leaderboard/transfers/top-sellers?period={period}&limit={limit}` — Public
Transfer sale leaderboard. No JWT required.

`?period` — `week` (default) · `month` · `all-time`
`?limit` — max results, default 10, cap 50

Response `200`:
```json
{
  "period": "week",
  "topSellers": [
    {
      "academyName": "string",
      "totalSales": 4500000,
      "transferCount": 3,
      "averageSale": 1500000
    }
  ]
}
```

---

### `GET /api/leaderboard/transfers/most-valuable?period={period}` — Public
Single most valuable transfer sale. No JWT required.

`?period` — `week` (default) · `month` · `all-time`

Response `200`:
```json
{
  "period": "week",
  "sale": {
    "academyName": "string",
    "playerName": "string",
    "fee": 5000000,
    "occurredAt": "2026-03-01T00:00:00+00:00"
  }
}
```
- `sale` is `null` with a `message` field if no sales have been recorded for the period.

---

### `GET /api/market/data` — JWT required (`ROLE_CLUB`)
Fetch the current market pool snapshot. Returns all entities available to recruit/sign. The server sets a `Cache-Control: max-age=300` header — clients should honour this and avoid polling more frequently than every 5 minutes.

Response `200`:
```json
{
  "players": [
    {
      "id": "uuid",
      "firstName": "string",
      "lastName": "string",
      "dateOfBirth": "YYYY-MM-DD",
      "nationality": "string",
      "position": "GK | DEF | MID | ATT",
      "potential": 75,
      "currentAbility": 60,
      "contractValue": 0,
      "recruitmentSource": "scouting_network | coaching_find | agent_offer | youth_request",
      "agent": {
        "id": "uuid",
        "name": "string",
        "commissionRate": "10.00"
      }
    }
  ],
  "coaches": [
    {
      "id": "uuid",
      "firstName": "string",
      "lastName": "string",
      "role": "head_coach | assistant_coach | scout | fitness_coach | analyst",
      "coachingAbility": 70,
      "scoutingRange": 50,
      "weeklySalary": 1500
    }
  ],
  "agents": [
    {
      "id": "uuid",
      "name": "string",
      "nationality": "string",
      "experience": 5,
      "rating": 72,
      "commissionRate": "10.00",
      "isUniversal": true
    }
  ],
  "scouts": [
    {
      "id": "uuid",
      "name": "string",
      "nationality": "string",
      "experience": 3,
      "judgements": []
    }
  ],
  "investors": [
    {
      "id": "uuid",
      "company": "string",
      "nationality": "string",
      "size": "SMALL | MEDIUM | LARGE",
      "expectedReturnPercentage": 8.5
    }
  ],
  "sponsors": [
    {
      "id": "uuid",
      "company": "string",
      "nationality": "string",
      "size": "SMALL | MEDIUM | LARGE",
      "expectedReturnPercentage": 5.0
    }
  ]
}
```

Pool sizes returned per request:
| Entity | Limit |
|--------|-------|
| Players | 100 |
| Coaches | 20 |
| Scouts | 10 |
| Investors | 10 |
| Sponsors | 20 |
| Agents | all universal agents |

---

### `POST /api/market/assign` — JWT required (`ROLE_CLUB`)
Assign a market entity to the authenticated user's academy. Use the `id` values returned by `GET /api/market/data`.

Request body:
```json
{
  "entityType": "player | coach | scout | sponsor | investor",
  "entityId": "uuid"
}
```
- `entityType` must be one of the exact string values shown above
- `entityId` must be a valid UUID v7

Response `200`:
```json
{
  "success": true,
  "entityId": "uuid"
}
```
Errors:
- `400` — invalid `entityId` format
- `404` — entity not found, or no academy for the current user
- `409` — entity is already assigned to an academy (no longer in pool)

---

### `GET /api/events/templates` — JWT required (`ROLE_CLUB`)
Returns all active (weight > 0) game event templates for client-side narrative simulation. Response is cached server-side for 1 hour (`Cache-Control: max-age=3600`).

The client uses these templates to randomly trigger narrative events during the Weekly Tick. Events with higher `weight` are selected more frequently. The `impacts` array is consumed by the client engine to apply effects to player/academy state.

Response `200`:
```json
{
  "templates": [
    {
      "slug": "player_homesick",
      "category": "player",
      "weight": 3,
      "title": "Homesick",
      "bodyTemplate": "{player} has been struggling to settle in. They're missing home.",
      "impacts": [
        {"target": "player.morale", "delta": -10}
      ]
    }
  ]
}
```
- `slug` — unique identifier; use to reference templates in client logic
- `category` — `player` · `facility` · `staff` · `finance`
- `weight` — relative selection probability; 0 = inactive (never returned by this endpoint)
- `bodyTemplate` — replace `{player}`, `{staff}`, `{facility}`, `{amount}` before displaying
- `impacts` — array of effect descriptors; known `target` keys: `player.morale`, `player.confidence`, `player.energy`, `academy.reputation`, `academy.finances`, `staff.morale`

---

### `GET /api/archetypes` — Public (no JWT)
Returns the curated catalogue of 20 player archetypes — **10 positive and 10 negative** — with
their weighted trait formulas. The client resolves a **dual report** per player: one best-match
positive archetype and one best-match negative archetype. Use `versionHash` (also served as an
`ETag`) to detect catalogue changes and invalidate your local cache; a conditional request with
`If-None-Match` returns `304`.

Response `200`:
```json
{
  "archetypes": [
    {
      "id": 1,
      "slug": "standard_bearer",
      "name": "Standard Bearer",
      "description": "Sets the training standard and XP floor for the whole squad; anchors dressing-room morale during losing runs.",
      "polarity": "positive",
      "traitWeights": {
        "formula": {
          "professionalism": 0.5,
          "determination": 0.5
        },
        "threshold": 65
      }
    }
  ],
  "versionHash": "md5hex"
}
```
- `slug` — stable machine identity. **Key on this, never on `name` or `id`.**
- `polarity` — `"positive"` or `"negative"`. Resolve one of each per player.
- `traitWeights.formula` — map of personality trait keys to **signed** weights. A positive weight
  scores the trait directly ("High X"); a negative weight scores its inverse ("Low X"). Absolute
  values sum to 1.0.
- `traitWeights.threshold` — minimum weighted score (0–100) for a player to match this archetype.
- `versionHash` — MD5 over every archetype's slug, polarity, description and weights.

Available trait keys — these are exactly the eight fields of the player's `personality` object:
`determination`, `professionalism`, `ambition`, `loyalty`, `adaptability`, `pressure`,
`temperament`, `consistency`

**Scoring.** Personality traits are served on a **1–20** scale, but `threshold` is on 0–100.
Normalise each trait before applying weights:

```
norm(t)  = (personality[t] / 20) * 100
score    = SUM( w > 0 ?  w  * norm(t)
                      : |w| * (100 - norm(t)) )
matches  = score >= threshold
```

> **Changed 2026-08-21.** The previous catalogue had 30 archetypes, no `slug`, no `polarity`, and
> used the key `traitMapping`. It also weighted `bravery`, `ego` and `confidence`, which are not
> personality traits — they always scored 0, which is why archetypes resolved to `null`. Those
> three keys no longer exist.

---

### `GET /api/inbox` — JWT required (`ROLE_CLUB`)
List the 50 most recent inbox messages for the academy, ordered newest first.

Response `200`:
```json
{
  "unreadCount": 3,
  "messages": [
    {
      "id": "uuid",
      "senderType": "agent | sponsor | investor | system",
      "senderName": "string",
      "subject": "string",
      "body": "string",
      "status": "unread | read | accepted | rejected",
      "offerData": null,
      "relatedEntityType": "player | null",
      "relatedEntityId": "uuid | null",
      "createdAt": "2026-03-03T10:00:00+00:00",
      "respondedAt": null
    }
  ]
}
```

---

### `GET /api/inbox/{id}` — JWT required (`ROLE_CLUB`)
Fetch a single message. Automatically transitions status from `unread` → `read`.

Response `200`: same shape as a single entry in `messages` above.

Errors:
- `404` — message not found or belongs to a different academy

---

### `POST /api/inbox/{id}/accept` — JWT required (`ROLE_CLUB`)
Accept an offer message. Business logic applied server-side based on `senderType`:

- **Sponsor** — sets sponsor to `active`, populates contract dates, payment amount, reputation thresholds; if `offerData.signingBonus > 0`, that amount is immediately credited to the academy's balance
- **Investor** — sets investor to `active`, records `percentageOwned` and `investmentAmount`; the full `investmentAmount` is injected into the academy's balance as a capital investment

An idempotency guard prevents double-accepting: calling this on an already-`accepted` or `rejected` message returns `409`.

Response `200`:
```json
{ "status": "accepted" }
```
Errors:
- `404` — message not found
- `409` — message already accepted or rejected

---

### `POST /api/inbox/{id}/reject` — JWT required (`ROLE_CLUB`)
Reject an offer. Sets `status` to `rejected` and records `respondedAt`.

Response `200`:
```json
{ "status": "rejected" }
```

---

### `POST /api/inbox/{id}/read` — JWT required (`ROLE_CLUB`)
Mark a message as read without accepting or rejecting it. No-op if already read/accepted/rejected.

Response `200`:
```json
{ "status": "read" }
```

---

### `GET /api/finance/overview` — JWT required (`ROLE_CLUB`)
Snapshot of the academy's current financial position.

Response `200`:
```json
{
  "monthlyRevenue": 500000,
  "activeSponsors": 1,
  "totalOwnershipGiven": 15.5,
  "investors": [
    {
      "id": "uuid",
      "company": "string",
      "tier": "angel | vc | private_equity",
      "percentageOwned": 10.0,
      "investmentAmount": 10000000,
      "buybackPrice": 13000000,
      "investedAt": "2026-01-01T00:00:00+00:00",
      "lastPayoutAt": null
    }
  ]
}
```
- All monetary values in pence/cents
- `totalOwnershipGiven` — sum of all investor `percentageOwned` values; new investors cannot be accepted if this would reach or exceed 50 %
- `buybackPrice` — `investmentAmount × 1.3` (30 % premium to buy back equity)

---

### `GET /api/finance/investors` — JWT required (`ROLE_CLUB`)
Full investor list with ownership breakdown.

Response `200`:
```json
{
  "investors": [
    {
      "id": "uuid",
      "company": "string",
      "tier": "angel | vc | private_equity",
      "percentageOwned": 10.0,
      "investmentAmount": 10000000,
      "buybackPrice": 13000000,
      "investedAt": "2026-01-01T00:00:00+00:00",
      "lastPayoutAt": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

---

### `GET /api/finance/sponsors` — JWT required (`ROLE_CLUB`)
Full sponsor list with contract details and remaining value.

Response `200`:
```json
{
  "sponsors": [
    {
      "id": "uuid",
      "company": "string",
      "size": "SMALL | MEDIUM | LARGE",
      "status": "active | completed | voided | early_terminated",
      "monthlyPayment": 500000,
      "contractStartDate": "2026-01-01T00:00:00+00:00",
      "contractEndDate": "2027-01-01T00:00:00+00:00",
      "remainingMonths": 10,
      "remainingValue": 5000000,
      "reputationMinThreshold": 100,
      "reputationBonusThreshold": 300,
      "bonusMultiplier": 1.1,
      "earlyTerminationFee": null
    }
  ]
}
```
- `remainingValue` = `remainingMonths × monthlyPayment`
- `earlyTerminationFee` is `null` until a termination is requested — use `POST /api/finance/sponsors/{id}/terminate` to compute and apply it

---

### `POST /api/finance/sponsors/{id}/terminate` — JWT required (`ROLE_CLUB`)
Early-terminate an active sponsor contract. The server computes 50 % of remaining contract value as the fee, caches it on the record, and sets status to `early_terminated`.

Response `200`:
```json
{
  "status": "terminated",
  "earlyTerminationFee": 2500000
}
```
Errors:
- `404` — sponsor not found or belongs to a different academy
- `422` — sponsor contract is not currently active

---

### `GET /api/facilities` — JWT required (`ROLE_CLUB`)
List all four academy facilities and their current state. Facilities are created at level 0 when the academy is initialized.

Response `200`:
```json
{
  "facilities": {
    "training_pitch": {
      "type": "training_pitch",
      "level": 1,
      "canUpgrade": true,
      "upgradeCost": 150000,
      "currentEffect": "+5% coaching effectiveness",
      "lastUpgradedAt": "2026-03-01T10:00:00+00:00"
    },
    "medical_centre": {
      "type": "medical_centre",
      "level": 0,
      "canUpgrade": true,
      "upgradeCost": 50000,
      "currentEffect": "No bonus",
      "lastUpgradedAt": null
    },
    "medical_network": {
      "type": "medical_network",
      "level": 0,
      "canUpgrade": true,
      "upgradeCost": 50000,
      "currentEffect": "No bonus",
      "lastUpgradedAt": null
    },
    "scouting_network": {
      "type": "scouting_network",
      "level": 0,
      "canUpgrade": true,
      "upgradeCost": 50000,
      "currentEffect": "No bonus",
      "lastUpgradedAt": null
    }
  }
}
```

Facility levels run 0–5. Upgrade costs and effects per level (all costs in pence):

| Level | Upgrade cost | training_pitch | medical_centre | medical_network | scouting_network |
|------:|-------------:|----------------|----------------|-----------------|------------------|
| 0→1 | 50,000p (£500) | +5% coaching | +10% recovery | +5% prevention | +10 range |
| 1→2 | 150,000p (£1,500) | +10% coaching | +20% recovery | +10% prevention | +20 range |
| 2→3 | 300,000p (£3,000) | +15% coaching | +30% recovery | +15% prevention | +30 range |
| 3→4 | 500,000p (£5,000) | +20% coaching | +40% recovery | +20% prevention | +40 range |
| 4→5 | 1,000,000p (£10,000) | +25% coaching | +50% recovery | +25% prevention | +50 range |

Errors:
- `404` — no academy found for the authenticated user

---

### `POST /api/facilities/{type}/upgrade` — JWT required (`ROLE_CLUB`)
Upgrade a facility by one level. The upgrade cost is deducted from the academy's balance immediately.

`{type}` — one of: `training_pitch` · `medical_centre` · `medical_network` · `scouting_network`

Response `200`:
```json
{
  "type": "training_pitch",
  "level": 2,
  "canUpgrade": true,
  "upgradeCost": 300000,
  "currentEffect": "+10% coaching effectiveness",
  "balance": 337500
}
```
- `balance` — the academy's remaining balance after the upgrade cost was deducted

Errors:
- `400` — invalid facility type string
- `404` — facility or academy not found
- `409` — facility is already at max level (5), or insufficient funds

---

## CORS

The backend allows all origins in dev (`CORS_ALLOW_ORIGIN=*`). In production this will be locked to the app's domain. Allowed headers: `Content-Type`, `Authorization`.

---

## Wage & salary scale

All monetary values are stored and returned in **pence/cents** (integer). Current weekly salary ranges:

| Staff role | Weekly salary range |
|------------|---------------------|
| Head Coach | £80 – £200 |
| Assistant Coach | £40 – £100 |
| Scout | £25 – £70 |
| Fitness Coach | £30 – £80 |
| Analyst | £30 – £75 |

Player `contractValue` is `currentAbility × rand(10, 40)` pence/week.

---

## Enum reference

| Enum | Values |
|------|--------|
| `position` | `GK`, `DEF`, `MID`, `ATT` |
| `status` (player) | `active`, `loaned_out`, `transferred`, `retired` |
| `recruitmentSource` | `scouting_network`, `coaching_find`, `agent_offer`, `youth_request` |
| `role` (staff) | `head_coach`, `assistant_coach`, `scout`, `fitness_coach`, `analyst` |
| `size` (company) | `SMALL`, `MEDIUM`, `LARGE` |
| `entityType` (market assign) | `player`, `coach`, `scout`, `sponsor`, `investor` |
| `category` (leaderboard) | `career_earnings`, `club_reputation`, `hall_of_fame` |
| `transferType` | `sale`, `loan`, `free_release` |
| `tier` (investor) | `angel`, `vc`, `private_equity` |
| `status` (sponsor) | `active`, `completed`, `voided`, `early_terminated` |
| `senderType` (inbox) | `agent`, `sponsor`, `investor`, `system` |
| `status` (inbox) | `unread`, `read`, `accepted`, `rejected` |
| `type` (facility) | `training_pitch`, `medical_centre`, `medical_network`, `scouting_network` |
| `category` (event) | `player`, `facility`, `staff`, `finance` |
| `managerShifts` keys | `temperament`, `discipline`, `ambition` |
