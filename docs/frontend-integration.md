# Frontend ↔ Wunderkind Backend — Integration Prompt

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
Error `409`: email already registered.

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

### `POST /api/sync` — JWT required
Called once per Weekly Tick. Sends aggregate deltas from the current week; server updates leaderboards and persists academy state.

Request body:
```json
{
  "weekNumber": 42,
  "clientTimestamp": "2026-03-01T12:00:00+00:00",
  "earningsDelta": 500000,
  "reputationDelta": 10,
  "hallOfFamePoints": 250,
  "transfers": []
}
```
- `weekNumber` — current game week (positive integer, must never go backwards)
- `clientTimestamp` — ISO 8601 device time at tick
- `earningsDelta` — earnings this week in pence/cents (≥ 0)
- `reputationDelta` — reputation change this week (can be negative)
- `hallOfFamePoints` — all-time HoF total (server takes `max(current, incoming)`, never decreases)
- `transfers` — array, pass `[]` until transfer syncing is implemented

Response `200` (accepted):
```json
{
  "accepted": true,
  "weekNumber": 42,
  "syncedAt": "2026-03-01T12:00:05+00:00",
  "academy": {
    "reputation": 310,
    "totalCareerEarnings": 12500000,
    "hallOfFamePoints": 250
  }
}
```
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
Fetch a ranked leaderboard.

`{category}` — one of: `career_earnings` · `academy_reputation` · `hall_of_fame`

`?period` — `all-time` (default) or ISO week string e.g. `2026-W09`

Response `200`:
```json
[
  {
    "rank": 1,
    "academyName": "string",
    "score": 99999999
  }
]
```

---

## Auth flow

1. On app launch, check MMKV for `auth_token`
2. If absent → call `POST /api/register`, then `POST /api/login`, store token
3. If present → proceed; on any `401` response, call `POST /api/login` to refresh and retry once
4. On `403` → show error, do not retry

---

## CORS

The backend allows all origins in dev (`CORS_ALLOW_ORIGIN=*`). In production this will be locked to the app's domain. Allowed headers: `Content-Type`, `Authorization`.
