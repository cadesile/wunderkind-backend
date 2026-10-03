# Translations API (client integration)

Two independent systems, each with its own delivery mechanism — don't mix them up:

1. **Generic UI copy** — a flat key→value catalogue (button labels, screen
   text, etc.), fetched as a whole file per language. This is the
   client-facing equivalent of the bundled `locales/<code>/translations.json`
   files, now served live from the backend.
2. **Narrative content** (event/facility/excursion text) — **not** part of
   the catalogue above. It's localized **in place** on the existing
   `/api/events/templates`, `/api/excursions`, and `/api/game-config`
   endpoints via a `?lang=` query param. Nothing about those endpoints'
   response shape changes — the `title`/`body`/`label`/`description` fields
   you already read just come back in the requested language, falling back
   to English for anything untranslated.

This split is deliberate: it means none of your existing rendering code for
events/facilities/excursions needs new lookup logic — it only needs to pass
the current language code through. Only the brand-new generic-string
screens need the catalogue-fetch pattern.

## 1. Enabled languages

```
GET /api/languages
```
No auth required. Cacheable (`max-age=3600`).

```json
200 OK
{
  "languages": [
    { "code": "en", "name": "English", "isDefault": true },
    { "code": "fr", "name": "French", "isDefault": false }
  ]
}
```
Only **enabled** languages are listed — a language an admin has disabled
disappears from here immediately, even if its translations still exist in
the backend. `isDefault` marks the fallback language (always exactly one);
treat it as the language to show pre-login or before you've resolved a
device locale.

## 2. Generic UI-copy catalogue

```
GET /api/translations/{code}
GET /api/translations/{code}/version
```
No auth required. `{code}` is one of the codes from §1. An unknown or
disabled code is a hard **404** here — unlike the narrative `?lang=` params
below, this endpoint's whole contract is "give me exactly this language's
file," so it never silently substitutes another language.

### `GET /api/translations/{code}` — full file

```json
200 OK
{
  "_meta": {
    "generatedAt": "2026-10-03T14:16:19+00:00",
    "generatorVersion": "2",
    "entryCount": 2534,
    "versionHash": "8c61a6ebcda1e7db8b3977557ef5dc59",
    "pluralSensitiveKeys": ["component.coachPickerOverlay.assignmentStatus"],
    "bandedReferences": {
      "engine.assistant.supporterCrisisBody": {
        "ticketNote": "engine.assistant.supporterCrisis.ticketNoteFragment"
      }
    }
  },
  "entries": {
    "ui.common.cancel": "CANCEL",
    "component.excursionsPane.text.4": "CLOSE SEASON"
  }
}
```
Shape matches the bundled locale files field-for-field — `entries` is a flat
map, dot-notation keys, values may contain `{placeholder}` tokens (opaque to
the backend, render them exactly as before). `pluralSensitiveKeys` and
`bandedReferences` are **structural** metadata about which *keys* need
special handling — identical across every language, not translated values.
`entryCount`/`versionHash`/`generatedAt` are computed fresh per request.

A missing translation for the requested language silently falls back to the
default language's value — the client never has to handle a missing key.

### `GET /api/translations/{code}/version` — cheap polling

```json
200 OK
{ "code": "fr", "versionHash": "8c61a6ebcda1e7db8b3977557ef5dc59" }
```
Same `versionHash` as the full file's `_meta`. Poll this instead of the full
file to cheaply detect "has anything changed" before deciding to refetch.

## 3. Narrative content — localize in place via `?lang=`

Add `?lang={code}` to any of these **existing, unchanged** endpoints:

```
GET /api/events/templates?lang=fr       (ROLE_CLUB auth, unchanged)
GET /api/excursions?lang=fr             (no auth, unchanged)
GET /api/game-config?lang=fr            (no auth, unchanged)
```

- `events/templates`: `title`/`bodyTemplate` per template are localized.
- `excursions`: `title`/`body` per excursion are localized; `versionHash`
  in the response is computed over the **localized** values, so a client
  switching `lang` gets a different hash and won't get a false cache hit
  against English-computed content.
- `game-config`: each entry in `facilityTemplates[]` gets `label`/
  `description` localized.

In every case: an untranslated field falls back to English, and an
**unknown or disabled `lang` code silently falls back to the default
language** rather than erroring — these are core gameplay-data endpoints,
so a bad code must never break them. `lang` omitted behaves exactly as
today (English, unchanged).

### Example

```
GET /api/excursions?lang=fr
```
```json
200 OK
{
  "excursions": [
    {
      "slug": "local-community-initiative",
      "title": "Initiative communautaire locale",
      "body": "Organisez une activité communautaire à faible coût...",
      "costPerPersonPence": 0,
      "...": "... all other fields unchanged ..."
    }
  ],
  "versionHash": "8c61a6ebcda1e7db8b3977557ef5dc59"
}
```

## Summary for integration

- Pre-login / locale picker: `GET /api/languages`.
- New generic UI strings: fetch `GET /api/translations/{code}` once per
  language (cache by `versionHash`), look up by flat key — same pattern as
  your existing bundled locale files.
- Anything you already read from `/api/events/templates`, `/api/excursions`,
  `/api/game-config`: just add `?lang={code}` to the request you already
  make. No new parsing logic.
