# Owner Avatar API (client integration)

Branch: `development`/`dev` (merged). Sets/updates the real account holder's
own identity — name, nationality, gender, date of birth, and avatar — shown
for every club that account creates. This is a **single profile per account**,
not per club: it replaces the old per-club "manager" fields some clients may
still be sending at club creation (those are now silently ignored server-side,
see §5).

## Endpoints

```
GET  /api/owner-avatar
POST /api/owner-avatar
Authorization: Bearer <JWT>   (ROLE_CLUB required, both)
Content-Type: application/json   (POST only)
```

`GET` takes no body/params and returns the same shape as the `POST` response
(§ below) — use it to prefill your avatar editor / profile screen on load,
before the user has changed anything this session.

### Example — read the current owner identity

```
GET /api/owner-avatar
```
```json
200 OK
{
  "name": "Alex Owner",
  "nationality": "English",
  "gender": null,
  "dob": "1990-05-14",
  "avatar": { "hair": "...", "...": "..." }
}
```

## Request body (`POST` only) — all fields optional, partial update

Only the keys you actually send are changed. Omit a key entirely to leave
that field untouched — this is not the same as sending it as `null`, which
explicitly clears it (see the `name`/`gender` behavior below).

| Key | Type | Validation | Notes |
|---|---|---|---|
| `name` | `string \| null` | max 100 chars | display name |
| `dob` | `string \| null` | `YYYY-MM-DD` | date of birth |
| `gender` | `"male" \| "female" \| null` | fixed choice | — |
| `nationality` | `string \| null` | max 60 chars | free text, not an enum — e.g. `"English"`, `"Brazilian"` (matches the demonym strings the rest of this API already uses for players/staff) |
| `avatar` | `object \| null` | none (stored verbatim) | full sprite `Appearance` config — see §3 |

### Example — set name and DOB only

```json
POST /api/owner-avatar
{
  "name": "Alex Owner",
  "dob": "1990-05-14"
}
```

### Example — set a custom avatar from your own avatar builder

```json
POST /api/owner-avatar
{
  "avatar": {
    "hair": "bun", "hairColor": "#e6bd55", "headband": true, "skin": "s2",
    "face": "cool", "facial": "none", "lip": "#c9575e",
    "primary": "#1f4fb8", "secondary": "#f4f3ee",
    "kit": "stripes", "shorts": "black", "socks": "primary",
    "outfit": "suit", "trousers": "black", "glasses": true
  }
}
```

## `POST` Response — `200 OK`

Always echoes the **full current state** of all 5 fields (same shape `GET`
returns), regardless of which ones you sent:

```json
{
  "name": "Alex Owner",
  "nationality": null,
  "gender": null,
  "dob": "1990-05-14",
  "avatar": { "hair": "...", "...": "..." }
}
```

## 1. Avatar auto-generation — when it happens and when it doesn't

You don't have to supply `avatar` at all. The server will generate a
reasonable one automatically, but **only under these exact conditions**:

- No `avatar` key was sent in this request, **and**
- The account's stored avatar is currently `null` (i.e. this is the first
  time `nationality`/`dob` is being set), **and**
- This request changed `nationality` and/or `dob`.

Once an avatar exists (whether server-generated or client-supplied), it is
**never** silently regenerated or overwritten by a later call that only
touches `name`/`gender`/`nationality`/`dob` — only an explicit `avatar` key
in the request body changes it after that point. This means: if your app
lets the user set their name first and pick an avatar later, calling this
endpoint twice in that order is always safe.

Every new account also already gets a *default* avatar automatically at
registration (age defaults to 40, nationality unknown → random skin tone)
before you ever call this endpoint — so `avatar` in the response is never
actually `null` in practice, even before the user has customized anything.

## 2. `gender` and `name` — full clear vs. partial update

- Omit the key → unchanged.
- Send the key with a real value → set to that value.
- Send the key as JSON `null` → explicitly cleared to `null`.

The server tells these three apart by checking whether the key is present in
your JSON body at all, not just by checking for `null` — so don't send
`"name": null` unless you actually mean "clear the name."

## 3. Avatar shape

Same 15-key pixel-sprite `Appearance` object used for players/staff/scouts/
agents — full field reference, enum value tables, and hex/color palettes:
see `docs/api/sprite-appearance-schema.md` (§1 and §3 in that doc). The
account holder's avatar always renders with the **staff body shape**
(kit/shorts/socks fields are ignored; outfit/trousers/glasses apply), same as
Staff/Scout/Agent.

## 4. Error responses

| Status | Cause |
|---|---|
| `401` | No/invalid JWT |
| `422` | Validation failure — e.g. `gender` not `"male"`/`"female"`, `dob` not a valid date, `name`/`nationality` over the length limit |

## 5. Migration note for existing club-creation flows

`POST /api/club/initialize` no longer reads or stores a `manager` object —
if your app currently sends one there, it's now silently ignored (harmless,
not an error). Owner identity is set exactly once per account via this new
endpoint instead, independent of which/how many clubs that account creates.
