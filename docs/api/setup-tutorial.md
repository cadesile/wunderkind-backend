# New-game setup tutorial — backend work needed

**This is a pending work brief, not a contract for something that exists.** Nothing described
here is implemented server-side yet.

Client side is shipped: `wunderkind-app@development` `b41beac0`, merged to `dev` as `54bc1c3a`
on 2026-11-02, so it is live on the `dev` OTA channel and already parses this payload.

Adds an optional `tutorial` block to `GET /api/starter-config` that drives first-run guidance
inside the app's new-game setup flow.

> ⚠️ **On this file's location and expiry.** `docs/api/` is otherwise contracts for endpoints
> that exist, and is load-bearing because ~20 of its files are cited by name from source
> docblocks. The 2026-11-02 prune deleted
> `docs/api/tournament-sealed-results-backend-work.md` for being exactly what this file is — "a
> work log misfiled as an API contract" — once its items were actioned. So this file is here only
> because the request has to live somewhere a backend session will find it. **When the work
> lands, it should either be rewritten as a contract describing what IS (and cited from
> `StarterConfigController`'s docblock, which is what earns a place in this directory) or
> deleted.** It is not currently registered in
> `.context/stages/06_documentation/output/index.md`, so a staleness pass will not see it.

## Why this exists, and what it is not

The app's setup flow (owner → club → identity → staff → review) has no explanation in it. The
permanent in-game Personal Assistant covers ongoing guidance, but it only exists *after* a club
does, so the first ten minutes are unguided. Rather than hardcode copy into a release, the copy
and its placement are server-driven so they can be reworded, re-anchored or switched off without
an app build.

**This is not the old tutorial walkthrough.** A 13-step spotlight tour was deleted from the app in
`213489f2` (2026-09-29), when the permanent in-game Personal Assistant replaced it. This feature
replaces nothing and resurrects nothing: it is two kinds of card rendered inside one flow.

- **note** — a dismissible card at the top of a step. The step is fully usable underneath it.
- **briefing** — a panel rendered *in place of* a step's body until the player taps its CTA.
  It is **not a step**: it does not affect the step counter, BACK, FOUND CLUB, or the payload the
  client finally submits.

### `tutorial` also means something else here — half fixed 2026-11-02, half still open

Unrelated to the feature above, but adjacent enough to trip over:

- `Club::$tutorialCompletedAt` (`src/Entity/Club.php:118`), written set-once from
  `SyncRequest::$tutorialCompletedAt` (`SyncService.php:183`) and echoed back by both the sync
  response and `GET /api/club/status`.
- Read by `AudienceCriteriaEvaluator` as the `tutorialCompleted` audience criterion
  (`src/Service/AudienceCriteriaEvaluator.php:31,77`).

**The app stopped sending it in `213489f2` (2026-09-29)**, the same commit that deleted the
13-step walkthrough whose `skipAll()` was the only thing that ever wrote it. So the column went
null for every club created after that date, and `tutorialCompleted` as a criterion was silently
false for all of them — while remaining the worked example in the admin help text
(`AudienceGroupCrudController.php:85`). An admin following the documentation got a message that
reached nobody.

#### Fixed on the client (2026-11-02)

The app sends `tutorialCompletedAt` again, from `clubStore.club.tutorialCompletedAt`, written in
`useSetupHost`'s `onComplete`. **No backend change was needed** — the field, the set-once write
and the echo were all still there.

What it means now is necessarily different, and the new meaning is narrower but still useful:
**new-game SETUP completed.** There is no walkthrough left to finish, so that is the only
completion event available. It is not a tautology: the server club is created at FOUND CLUB, but
setup continues through badge, kits, stadium, eight hire/role steps and the overview, so **a club
row can exist whose setup was abandoned partway**. Those are the clubs the criterion now
distinguishes, and they are a reasonable population to message.

Deliberately *not* sourced from the app's account-scoped onboarding flag, which survives a new
game and is therefore already true from an earlier club — that would have a second club report
completion the moment it was founded.

#### Still open, and it is yours: backfill, and the stale copy

1. **Backfill the clubs created 2026-09-29 → 2026-11-02.** Their setup completed, but nothing
   local records when, and the client deliberately does not invent a value — a `?? now` would also
   fire for a club whose setup is genuinely unfinished, which is the one distinction the field
   exists to make. The server can do it accurately and in one statement, because
   `Club::$lastSyncedWeek` defaults to 0 and **only ever advances through a sync, which cannot
   happen until setup has completed**:

   ```sql
   UPDATE club SET tutorial_completed_at = <marker>
   WHERE tutorial_completed_at IS NULL AND last_synced_week >= 1;
   ```

   `<marker>` only has to be non-null — the criterion is boolean. Prefer an existing date on the
   row over `NOW()` if one is closer to the truth.

2. **Decide whether the criterion keeps the name.** `tutorialCompleted` now reads as a promise the
   product no longer makes. Renaming it is a breaking change for any saved `AudienceGroup`
   criteria JSON, so it may not be worth it — but the admin help text should at least say what it
   now measures, and `Club::$tutorialCompletedAt`'s docblock still says "completes the first-launch
   tutorial", which is no longer a thing that exists.

3. **Consider whether `minWeek` already serves the intent better.** The help text's example pairs
   it with `leagueTier: [7, 8]` to mean "beginners, but past the start". `minWeek` reads that
   directly off `lastSyncedWeek` and has none of this history.

## The whole feature is optional, and failure is silent

Three properties worth understanding before implementing, because they decide how much validation
you owe:

1. **Absent means "no tutorial".** If the key is missing, null, or not an object, the flow behaves
   exactly as it does today. Shipping nothing is a valid state and is the current state.
2. **The client never throws on this data.** `parseTutorial`
   (`wunderkind-app/src/components/new-game-setup/tutorial.ts`) validates item by item and drops
   anything it does not like. A malformed block cannot produce an error screen or block the setup
   route — that is deliberate, because setup is the one route a new account cannot skip.
3. **Because of (2), a mistake is INVISIBLE rather than loud.** A typo'd anchor, an unknown kind,
   a missing copy key — the card just does not appear. There is no error, no log, and nothing in
   the UI. So the payload needs to be right by construction; you will not be told it was wrong.

## Shape

```json
{
  "tutorial": {
    "version": 1,
    "items": [
      {
        "id": "brief-owner",
        "kind": "briefing",
        "anchor": { "before": "avatar" },
        "modes": ["first"],
        "titleKey": "ngs.tut.owner.title",
        "bodyKey": "ngs.tut.owner.body",
        "pointKeys": ["ngs.tut.owner.p1", "ngs.tut.owner.p2"],
        "ctaKey": "ngs.tut.cta.gotIt"
      },
      {
        "id": "note-hire",
        "kind": "note",
        "anchor": { "on": "hire" },
        "bodyKey": "ngs.tut.note.hire",
        "dismissible": true
      }
    ]
  }
}
```

The canonical example is the client fixture
`wunderkind-app/src/components/new-game-setup/__fixtures__/tutorial.mock.json` — 5 briefings and
10 notes. It is the shape the client's tests assert against, so **matching it exactly is the
safest target.**

### Field rules, as the client actually enforces them

| Field | Rule |
|---|---|
| `version` | Optional number. Absent, non-numeric or non-finite is **coerced to 0** — a type check, not rounding. See version semantics below |
| `items` | Array. Anything else is treated as empty |
| `id` | Non-empty string, **unique**. On a duplicate id the **first** item wins, the rest are dropped |
| `kind` | Exactly `"note"` or `"briefing"`. Any other value drops the item |
| `anchor` | Object. `{ "on": … }` for a note, `{ "before": … }` for a briefing. Must be a valid anchor (below) |
| `modes` | Optional array of `"first"`, `"newClub"`, `"profile"`. Unknown values are filtered out; **if nothing valid remains the item shows in ALL modes**, so a typo here widens rather than narrows |
| `bodyKey` | Required for both kinds. Non-empty string |
| `titleKey` | Required for a briefing |
| `pointKeys` | Optional array of strings for a briefing. Non-strings are filtered out |
| `ctaKey` | Optional for a briefing. Omit it and the CTA reads "GOT IT" |
| `dismissible` | Notes only. Defaults to **true**; only an explicit `false` makes a note permanent |

**If the whole payload yields zero valid items, the client treats it as no tutorial at all.**

### Valid anchors

An anchor is a step kind, optionally `kind:role`, and **at most one colon**.

- Step kinds: `welcome`, `avatar`, `owner`, `country`, `club`, `badge`, `kits`, `stadium`,
  `hire`, `role`, `overview`
- A role suffix is allowed **only** on `hire` and `role`, and must be one of
  `chairman`, `dof`, `manager`, `facilities`

So `hire:dof` and `role:chairman` are valid; `badge:dof` and `hire:physio` are not and drop the
item. `hire:dof:extra` is not (two colons).

**A bare kind matches every role of that kind.** `{ "on": "hire" }` shows on all four hire steps.
That is how the fixture gets one note across the whole staff chapter, and it is why
`ngs.tut.note.hire` is written with a `{role}` placeholder.

The flow steps, in order, are: `welcome, avatar, owner, country, club, badge, kits, stadium,`
`hire:chairman, role:chairman, hire:dof, role:dof, hire:manager, role:manager, hire:facilities,`
`role:facilities, overview`. `newClub` mode starts at `country`; `profile` mode is only
`avatar, owner`.

### The five briefings and where they anchor

A briefing's anchor cannot be inferred from its copy key. The copy namespaces are *chapter*
names, not step kinds — `identity` and `review` are not steps at all — and a briefing does not
anchor on the step sharing its name: the `owner` briefing anchors `before: "avatar"`, because it
introduces the avatar+owner chapter rather than the owner step.

| Briefing | Copy namespace | Anchor | Chapter it introduces |
|---|---|---|---|
| owner | `ngs.tut.owner.*` | `{ "before": "avatar" }` | avatar, owner |
| club | `ngs.tut.club.*` | `{ "before": "country" }` | country, club |
| identity | `ngs.tut.identity.*` | `{ "before": "badge" }` | badge, kits, stadium |
| staff | `ngs.tut.staff.*` | `{ "before": "hire:chairman" }` | the eight hire/role steps |
| review | `ngs.tut.review.*` | `{ "before": "overview" }` | overview |

The staff row needs its `:chairman` suffix. A bare `{ "before": "hire" }` matches every role of
that kind, so the briefing would be re-shown before all four hire steps.

✅ **All five verified against the fixture 2026-11-02** (`tutorial.mock.json`), so this table is
authoritative rather than implied. The one thing it does not show is `modes`, which differs by row
and matters when seeding: the **owner** briefing is `["first"]` only, and the other four are
`["first", "newClub"]`. That asymmetry is deliberate — someone founding a second club already has
an owner profile, so re-introducing the avatar chapter to them would be wrong. Every note is
`dismissible: true`; `avatar` and `owner` are `["first"]`, the other eight carry no `modes` and so
show in all three.

## Copy keys — the part most likely to go wrong

**Copy is NOT in the payload. The payload carries translation KEYS.** The client resolves them
against the translation catalogue.

Contrast [server-driven-messaging.md](server-driven-messaging.md), the other server-driven-copy
feature in this API, where an operator types literal `bodyHtml` straight into the admin and the
client renders it. This one carries keys instead, because the translation catalogue is the
mechanism that gets localised and the client already resolves keys against it. The cost of that
choice is the subject of the next two sections: nothing an admin edits on the Starter Config
screen can change a word of copy.

### The keys must be `ngs.tut.*`

The app shipped with 34 of them merged into `locales/en/translations.json`:

```
ngs.tut.cta.gotIt
ngs.tut.owner.{title,body,p1,p2}
ngs.tut.club.{title,body,p1,p2}
ngs.tut.identity.{title,body,p1,p2,p3}
ngs.tut.staff.{title,body,p1,p2,p3,p4}
ngs.tut.review.{title,body,p1,p2}
ngs.tut.note.{avatar,owner,country,club,badge,kits,stadium,hire,role,overview}
```

These were authored as a bare `tut.*` domain and **renamed to `ngs.tut.*` at integration**, to
keep them inside the domain new-game setup already owns rather than opening a 24th top-level
catalogue domain that could never be renamed. If you send the old `tut.*` form, every item is
dropped and nothing renders.

### English RENDERS without catalogue entries; REWORDING needs them

Two different questions with two different answers, and conflating them is the easiest way to ship
half of this feature.

**Rendering English: no backend translation change needed.** The app's `translationStore` seeds
from the **bundled** `locales/en/translations.json` and merges any API payload *on top* of it
(`{ ...baseline.entries, ...payload.entries }`). All 34 keys are in that bundle, so the English
copy appears whether or not the backend catalogue has ever heard of these keys.

**Rewording anything: the catalogue is the only lever there is.** Because copy is not in the
`tutorial` payload, the JSON blob can re-anchor, reorder, add, remove and switch off cards, but it
cannot change a single word. The only way to reword is a `Translation` row against a
`TranslationKey` named `ngs.tut.*`, edited through
`src/Controller/Admin/TranslationKeyCrudController.php` — and because the API payload merges *on
top of* the bundle, a stored `en` value does override the bundled copy.

So the 34 `en` catalogue rows are **not** deferrable localisation work: they are the prerequisite
for "reworded without an app build", which is the reason given at the top of this document for
doing any of this server-side. Ship the JSON column without them and you get a tutorial whose
placement is editable and whose words still need an app release. Treat them as part of the
implementation, not a follow-up — see step 5 below.

Localisation proper — the same 34 keys in languages other than English — genuinely *can* follow
later. A `GET /api/translations/{code}` payload that lacks them falls back to the English baseline,
which means a French player reads English tutorial copy. That is correct, safe behaviour, not a
bug.

### A key the catalogue does not have drops the item

The client checks each key before accepting an item, and a briefing is dropped if **any** of its
keys is missing — title, body, any point, or the CTA. So inventing a new copy key server-side
without adding it to the catalogue makes the card silently vanish. New copy means a catalogue
entry first.

### ⚠️ Placeholders: only `{role}`, and only on notes

The client passes exactly one interpolation token, `{ role }`, and **only when rendering a note
body**. Briefing titles, bodies and points are resolved with **no tokens at all**.

A `{something}` in any other value renders to the player as the literal text `{something}`. If
new copy needs a value interpolated, the client has to be changed to pass it — it is not something
the payload can introduce on its own.

## Version semantics

`version` controls replay. Each account stores the version its dismissals belong to, in
`setupBriefingStore` (account-scoped on the device: it survives starting a second club, and is
cleared on logout).

- The client clears every dismissal **only when the incoming version is strictly greater** than
  the stored one. Stored starts at 0.
- So: **raise `version` to show everything again to everyone.** Use this after rewording.
- A lower or equal version changes nothing. A non-numeric version is coerced to 0 — this is a
  type check, not rounding, so `"version": "2"` as a string lands on 0 and replays nothing. Omitting
  `version` lands on 0 too, which equals the starting stored value, so a payload carrying no
  `version` at all never replays anything either.
- Dismissals are per-item by `id`, so **changing an item's `id` also replays that one item**
  without touching anyone's other dismissals. That is the finer-grained tool; `version` is the
  blunt one.

## Suggested implementation

`StarterConfig` is a singleton entity (`id = 1`) already edited through the admin screen at
`/admin/starter-config` (`DashboardController::starterConfig()`), and it already carries five JSON
columns, so the storage half of this is routine.

**The editing half is not, and the obvious precedent is a mirage.** No JSON column on this entity
is edited as a free-form blob today. `npcSquadConfig` is reassembled field by field from per-tier
number inputs (`DashboardController::saveStarterConfig()`, around lines 628-647, against the
`npcConfig[tier][...]` inputs in `templates/admin/starter_config.html.twig`). The blob accessors
that would make it a textarea — `StarterConfig::getNpcSquadConfigJson()` and
`setNpcSquadConfigJson()` — are dead code, referenced nowhere in `src/`, `templates/` or
`tests/`. A textarea editor for a nested array of objects is new work on that screen, not a copy
of an existing section; the closest real precedent is the JSON textareas in
`templates/admin/game_config.html.twig`.

1. **A JSON column on `StarterConfig`.** ⚠️ **Name it `setupTutorialConfig`, not
   `tutorialConfig`** — `tutorial*` is already taken on `Club` by a different, live, and
   differently-scoped concept (`Club::$tutorialCompletedAt`: per-club "setup finished", see the
   section near the top). Two unrelated `tutorial` things in one domain is precisely how that one
   went unnoticed for five weeks while it was dead, and the export/import service in the traps
   below is reflection-driven over property names, so the collision would sit in one more place.
   This one is per-club state on `Club`; yours is global config on `StarterConfig`. Keep the
   names as far apart as the scopes. The API key stays exactly `tutorial` regardless — that
   is fixed by the shipped client and is the one name here that cannot change.

   Decide nullable-or-seeded first, because they are different features, and this document has
   previously implied both:
   - *nullable, defaulting to null* — ships dark; the tutorial exists only once an admin fills it
     in. Null is the no-op state.
   - *non-nullable `array`, defaulting to the fixture contents* — ships with the tutorial on, and
     matches all five existing JSON columns on the entity, every one of which is non-nullable with
     a PHP default.

   Two mechanical traps on the way, neither of which announces itself:
   - Config export/import is reflection-driven
     (`ConfigImportExportService::exportableProperties()`), so the new column is picked up for
     free — but `tests/Service/ConfigImportExportCoverageTest.php` fails the build on a persisted
     column with no `get*`/`set*` pair.
   - `ConfigImportExportService::coerce()` throws `null is not allowed` unless the setter's
     parameter is typed `?array`. A nullable column with an `array` setter migrates cleanly and then
     fails the export/import round-trip assertion in that same test.

2. **Exposed by `StarterConfigController::index()`** as
   `'tutorial' => $config->getSetupTutorialConfig()`. Null is fine and is the no-op state.

3. **An admin screen section**, if the copy is to be editable without a deploy — which is the main
   reason for doing this server-side at all. Per the note above, this is a new JSON textarea rather
   than another NPC Squad Config-style grid of inputs.

   ⚠️ **`items` must survive the round trip as a JSON array.** If the save handler rebuilds it
   into a PHP array with non-sequential keys, `json_encode` emits an object, the client's
   "anything else is treated as empty" rule fires, and the whole tutorial silently disappears.
   `array_values()` on save.

4. **Validate server-side, and report it where an admin will look.** This is the answer to "failure
   is silent" at the top of this document. `src/Controller/Admin/StarterPackCheckController.php`
   (`/admin/starter-pack-check`) and its CLI twin `CheckStarterPackCommand` already exist as the "is
   the starter config coherent?" surface. A server-side mirror of the client's drop rules reported
   there — unknown anchor, a role suffix on a kind other than `hire`/`role`, two colons, duplicate
   `id`, a bare `tut.*` prefix, a non-numeric `version`, a copy key with no `TranslationKey` row —
   turns every invisible drop into a visible warning. Without it, nobody ever finds out the payload
   was wrong.

5. **The 34 `ngs.tut.*` keys as `TranslationKey` rows with `en` values.** Not optional, and not
   localisation work — see "English RENDERS without catalogue entries; REWORDING needs them"
   above. This is what makes the copy editable without an app build.

Seed value, if step 1 takes the seeded option: the contents of the client fixture named above.

### Migration note

`starter_config` was one of the four tables in `dbe3644` ("Clear the ORM/database schema drift",
2026-10-11) whose column defaults existed in the database but were undeclared in the mapping.
That commit's whole point was that `doctrine:migrations:diff` now emits only what you actually
changed, instead of ~90 unrelated statements that had to be hand-trimmed. **Declare the new
column's default in the mapping** — `options: ['default' => …]` for the seeded option, or plain
`nullable: true` with no default — so the diff stays one statement and the drift does not come
back. If `diff` emits more than the one `ALTER TABLE`, something is undeclared; do not trim it by
hand without finding out what.

### Auth, and why there is no per-account variation to design

The endpoint is public — `StarterConfigController`'s docblock: "Public endpoint — no JWT required.
Called by the client before the user has credentials." So nothing here can depend on an
authenticated user, and **the payload is necessarily identical for everyone**. Per-account state —
which items this player has dismissed — lives on the device in `setupBriefingStore`, never here.
The only lever the server has over an individual is `version`, and it is all-or-nothing.

## Client-side references

- Parser and all validation rules: `wunderkind-app/src/components/new-game-setup/tutorial.ts`
- Canonical payload: `wunderkind-app/src/components/new-game-setup/__fixtures__/tutorial.mock.json`
- Tests, incl. every drop rule above:
  `wunderkind-app/src/__tests__/components/new-game-setup/tutorial.test.ts`
- Dismissal storage: `wunderkind-app/src/stores/setupBriefingStore.ts`
- Integration report: `wunderkind-app/docs/design-briefs/newgame-ui-integration.md`
- App-side route notes: `wunderkind-app/.context/stages/04_interfaces/output/routes.md`

## Backend-side references

- `src/Controller/Api/StarterConfigController.php` — the endpoint and its current field list
- `src/Entity/StarterConfig.php` — the singleton and its five existing JSON columns
- `src/Controller/Admin/DashboardController.php:576,598` — the admin screen routes
- `templates/admin/game_config.html.twig` — the closest real JSON-textarea precedent
- `docs/api/translations.md` — `GET /api/translations/{code}`, for the rewording half
- `src/Service/AudienceCriteriaEvaluator.php:31,77` — the stale `tutorialCompleted` criterion
