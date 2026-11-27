# Personal Assistant: personalities and advice

Added 2026-11-27. The owner picks one Personal Assistant (PA) from a backend roster, and the PA puts the
device's own findings about the club into words. The PA belongs to the **account**, not a club or a save.

**What the server does and doesn't do:** the device computes every fact and figure. The server only asks Claude to
reword those facts in the PA's voice. The reply is display text; nothing here touches club, ledger or money state.

## Roster: `GET /api/game-config` → `personalAssistants`

Only active PAs are listed, in picker order (`sortOrder`, then `slug`). The list is open-ended, so don't assume
there are three.

```json
"personalAssistants": [
  {
    "id": "0193…",
    "slug": "pa-marcus",
    "name": "Marcus Hale",
    "gender": "male",
    "pronoun": "he",
    "appearanceSeed": "pa-marcus",
    "appearanceOverrides": { "hair": "crop", "facial": "stubble", "glasses": false, "face": "cool" },
    "description": "A dry-humoured fixer who has seen every boardroom crisis twice. …",
    "tone": "dry",
    "outOfOfficeMessage": "Marcus is out fixing someone else's mess. …",
    "sortOrder": 10
  }
]
```

- **`slug`** is the stable key the app stores as the owner's choice. It can't be changed after creation.
- **`appearanceSeed`** feeds the app's `generateExecutiveAppearance(seed)`. **`appearanceOverrides`** is an optional
  partial `SpriteConfig` laid over that result.
- **`tone`** is one of `dry`, `upbeat` or `precise`. It picks the app's offline template family. A tone the app doesn't
  know falls back to the neutral templates.
- **The personality prompt is never served.** Each PA's `personalityPrompt` stays on the server and is only sent to
  the AI.
- **Removing a PA:** admins switch PAs off instead of deleting them. A switched-off slug drops out of this list, and
  the app falls back to the first PA in the list.

Admins edit the roster at **World › Personal assistants**. Migration `Version20261127100000` seeds the launch
three: `pa-marcus`, `pa-theo` and `pa-priya`. The seed only inserts slugs that are missing.

## Advice: `POST /api/pa/advice`

Sign-in is required (`IS_AUTHENTICATED_FULLY`). The request is owner-scoped, so it takes no `X-Club-Id`.

```json
{
  "personalityId": "pa-marcus",
  "topic": "finances",
  "locale": "en",
  "context": { "clubName": "Harbour Town", "tier": 4, "gameDate": "2027-03-14", "currency": "GBP" },
  "findings": [
    { "code": "FINANCIAL_BLEED", "severity": "high", "facts": { "weeksOfRunway": 6, "weeklyNet": -4200000 } }
  ]
}
```

| Field | Rule |
|---|---|
| `topic` | `finances`, `squad`, `transfers` or `general`. Any other value returns 422. |
| `locale` | Only `en` is supported. Any other language code returns `unavailable` / `unsupported_locale`. |
| `findings[].code` | `SUPPORTER_CRISIS`, `FINANCIAL_BLEED`, `5_STAR_UNDERPERFORMANCE`, `DRESSING_ROOM_SCHISM`, `STAFF_MORALE_SLUMP`, `PHYSICAL_BURNOUT`, `ACADEMY_OVER_SATURATION`, `GAFFER_IDEOLOGY_DEADLOCK`, `SCOUTING_PRIMER`, `FINANCE_SUMMARY`, `SQUAD_SUMMARY`, `TRANSFER_SUMMARY`, `GENERAL_SUMMARY`. Unknown codes are dropped. |
| `findings[].severity` | `low`, `medium` or `high`. Anything else becomes `medium`. The list is sorted highest severity first and capped at 6. |
| `findings[].facts` | Keys are camelCase identifiers, up to 40 characters. Values are numbers, booleans, ISO dates, or lowercase tokens `[a-z0-9_-]{1,24}`. Anything else is dropped, and each finding keeps at most 10 facts. **Money is in minor units** of `context.currency`. |
| `context` | `clubName` (control characters and brackets are stripped, then it's cut to 60 characters), `tier` (1 to 20), `gameDate` (ISO date), `currency` (ISO 4217). |
| Body | Up to 8 KB. Anything larger returns 413. |

**Responses**

```json
200 { "status": "ok", "personalityId": "pa-marcus", "message": "Boss, six weeks of runway. …" }
200 { "status": "unavailable", "reason": "rate_limited", "retryAfterSeconds": 3600 }
200 { "status": "unavailable", "reason": "provider_error | timeout | not_configured | unsupported_locale" }
404 { "error": "unknown_personality" }
413 { "error": "payload_too_large" }
422 { "error": "invalid_topic | invalid_personality | invalid_locale | invalid_json" }
```

**`unavailable` is always a 200, including `rate_limited`.** It's an expected state: the app keeps showing its own
template advice and adds its out-of-office strip. `retryAfterSeconds` is sent only with `rate_limited`, and only
when the daily cap was actually reached.

## Reply cache (added 2026-11-27)

The endpoint is a paid proxy to the AI, so the server caches each `ok` reply (`PaAdviceCache`, in `cache.app`,
kept 30 days). An identical request from **any** owner gets the stored reply without a provider call.

- **The key** is a SHA-256 of the model (`PA_ADVICE_MODEL`), the full system prompt as built for the PA (so an admin
  edit to its personality prompt, or a rule change in code, starts fresh), and the sanitised request: slug, topic,
  locale, context and findings, with object keys sorted. `context.gameDate` is left out, so the same figures a week
  later reuse the reply. The app keeps its own on-device cache with the same rule.
- **Cache hits are free.** They're served before the daily cap is checked, so they never count toward
  `paAdviceDailyCallLimit`, and an owner who has hit the cap still gets cached replies. `0` still turns AI wording
  off entirely, cached replies included.
- Only `ok` replies are cached. Failures (`timeout`, `provider_error`, empty or overlong replies) are retried on the
  next request.

## Limits (admin › Game config › Events & club life › Personal Assistant advice)

- **`paAdviceDailyCallLimit`** (default 20): paid AI calls one owner may make per day, in a fixed one-day window.
  The value is read on every request, so an admin change applies at once. `0` turns AI wording off. A call only
  counts when it reaches the provider (a cache hit never does); a 4xx, an unknown PA, an unsupported locale or `not_configured` cost
  nothing. The app never receives this value.
- **`paAdviceTimeoutSeconds`** (default 8, range 1 to 30): how long the server waits for the provider before
  answering `timeout`.

## Configuration

- **API key:** the PA uses the same key as location enrichment (`LOCATION_ENRICHER_API_KEY`). With no key set, the
  endpoint answers `not_configured`.
- **Model:** `PA_ADVICE_MODEL` (default `claude-haiku-5-5`). It's a deploy variable, so changing the model takes a
  redeploy but no code change.

## Prompt (`PaAdvicePromptBuilder`)

The prompt is built in three layers:

1. **Fixed rules in code:** use only the given figures, never invent anything, write 2 or 3 short sentences, no
   real-world advice.
2. **The PA's `personalityPrompt`,** wrapped in a `<personality>` block.
3. **The rules again,** restated after the personality block so an admin's prompt can shape the voice but can't
   override them.

The findings go in the user turn inside `<club_data>`, which is marked as data. The output is held to a
`{ "message": string }` schema. An empty reply, or one over 500 characters, counts as `provider_error`.
