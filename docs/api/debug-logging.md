# Debug log panel flag (`debugLoggingEnabled`)

Controls whether the app shows the **cog icon** in the bottom navigation that opens
the in-app debug log panel (which records every API request and response).

Nothing about the field's name, type or meaning changed when per-account overrides
were added — it is still a single boolean. What changed is that its value can now
differ **per account**, and therefore *where* you should read it.

## 1. The two switches behind it

| Switch | Set in | Effect |
|---|---|---|
| Global | Admin → Game Config → Developer / Debug | Panel on for **every** account |
| Per-account | Admin → Users → edit → Developer / Debug | Panel on for **that one account** |

The server sends you `global || per-account`. The per-account flag is **additive
only**: it can turn the panel on while the global switch is off, and can never turn
it off for an account the global switch covers. One definition serves both endpoints
below (`App\Service\DebugLoggingResolver`), so they cannot disagree.

## 2. Where to read it

| Endpoint | Auth | Value you get |
|---|---|---|
| `GET /api/club/status` | JWT (`ROLE_CLUB`) | **Global or per-account — always correct.** Read it here. |
| `GET /api/game-config` | Public | Global only when called anonymously; global or per-account when the request carries the Bearer token |

**Read it from `GET /api/club/status`.** `GET /api/game-config` is a public endpoint,
so if you fetch it on a cold boot before sign-in (or without attaching the token),
the server has no account to check and can only answer with the global switch — a
per-account override will look like it did nothing. `club/status` is authenticated,
so the override always applies there, and you are likely polling it already.

If you'd rather keep reading the flag from `game-config`, that works too — just send
the `Authorization: Bearer …` header on that request and refetch after sign-in.

```
GET /api/club/status
Authorization: Bearer <jwt>
```
```json
200 OK
{
  "id": "0193f2c6-...",
  "name": "Northgate Athletic",
  "balance": 1250000,
  "debugLoggingEnabled": true
}
```
(Other fields omitted — see the endpoint's full response for the rest.)

## 3. Timing

The flag is read live on each request, so it takes effect on the account's **next**
call to either endpoint after an admin changes it. There is nothing to invalidate and
no token to re-issue — a signed-in player picks it up on their next poll.

## 4. Caching

`GET /api/game-config` is sent with `Cache-Control: private` because one of its keys
now varies per account. Don't store its response in a cache shared between accounts
(or between signed-in and signed-out states). `club/status` is authenticated and
per-club already, so nothing changes there.
