# New-game setup tutorial (client integration)

**Contract for `GET /api/starter-config`'s `tutorial` block. Implemented 2026-11-02.** This file
was a work brief until the work landed; it now describes what exists, per its own instruction at
the time.

- **Server:** `starter_config.setup_tutorial_config` (`Version20261102121403`), served verbatim as
  `tutorial` by `StarterConfigController::index()`. Editable at `/admin/starter-config` →
  **Setup Tutorial**. Validated by `App\Service\SetupTutorialValidator` on save and by
  `php bin/console app:check-setup-tutorial`.
- **Client:** `wunderkind-app@b41beac0`, on the `dev` OTA channel since `54bc1c3a`.
- **Seeded on**, with the 5 briefings and 10 notes the client already shipped as its fixture, so
  the feature arrived working rather than dark. An empty `{}` is the valid "off" state.

Still owed, and tracked at the bottom of this file: the `ngs.tut.*` translation rows (the copy
renders from the app's bundled catalogue without them — they are what makes it *reworded* without
an app release), and the `Club::$tutorialCompletedAt` backfill, which is a different thing that
happens to share the word "tutorial".

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
- Read by `AudienceCriteriaEvaluator` as the `setupCompleted` audience criterion — named
  `tutorialCompleted` until 2026-11-02 (`src/Service/AudienceCriteriaEvaluator.php`).

**The app stopped sending it in `213489f2` (2026-09-29)**, the same commit that deleted the
13-step walkthrough whose `skipAll()` was the only thing that ever wrote it. So the column went
null for every club created after that date, and the criterion was silently
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

2. ~~**Decide whether the criterion keeps the name.**~~ **Done 2026-11-02.** Renamed to
   `setupCompleted`, with `Version20261102120000` rewriting stored payloads and
   `AudienceCriteriaEvaluator::LEGACY_KEYS` still accepting the old spelling — necessary
   because that whitelist fails CLOSED, so a bare rename would have made any unmigrated group
   match **nobody**. Retire the alias and the migration's `down()` together; `down()` is only
   non-destructive while the alias exists. The admin help text and
   `Club::$tutorialCompletedAt`'s docblock now both say what the field measures.

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

## How it is built

| Piece | Where |
|---|---|
| Column | `StarterConfig::$setupTutorialConfig` — `json`, NOT NULL, PHP-default seeded with the 15 items |
| Migrations | `Version20261102121403` (the column), `Version20261102130000` (owner briefing → `ngs.tut.owner.bodyRole`) |
| API | `StarterConfigController::index()` → `'tutorial' => $config->getSetupTutorialConfig()` |
| Admin | `/admin/starter-config` → **Setup Tutorial** (CodeMirror JSON textarea, lint + submit guard) |
| Validation | `App\Service\SetupTutorialValidator::problems()` — pure, static, no DB |
| CLI | `php bin/console app:check-setup-tutorial` — structure **plus** translation-row coverage |
| Tests | `tests/Service/SetupTutorialValidatorTest.php` (20), `tests/Controller/Api/StarterConfigTutorialTest.php` (6) |

Four decisions in there are worth the explanation, because each had a wrong-looking easy option.

**Named `setupTutorialConfig`, not `tutorialConfig`.** `Club::$tutorialCompletedAt` is a different,
differently-scoped thing (per-club "setup finished"); this is global config. Two unrelated
`tutorial` names in one domain is exactly how that column went stale unnoticed for five weeks, and
`ConfigImportExportService` is reflection-driven over property names, so a near-collision would
have landed in one more place.

**Non-nullable with a seeded PHP default, matching all five other JSON columns on the entity.**
The nullable alternative also trips `ConfigImportExportService::coerce()`, whose null branch throws
unless the setter is typed `?array` — and `ConfigImportExportCoverageTest` fails the build on a
persisted column with no `get*`/`set*` pair, so both accessors are mandatory regardless.

**The migration adds the column WITH a DB default and then drops it.** `starter_config` always has
exactly one row, so `ADD ... JSON NOT NULL` with no default fails outright on any existing
database. The default populates that row; dropping it afterwards is what keeps the mapping and the
schema in agreement — every other JSON column here is NOT NULL with no DB default, and leaving one
behind makes the next `migrations:diff` emit `ALTER ... DROP DEFAULT`, which is the drift `dbe3644`
spent a commit clearing. The first draft left it in and did exactly that.

**The admin setter ignores un-decodable input rather than writing `[]`.** A typo in a 15-item blob
should not silently delete the tutorial. The parse failure is reported as a flash, and
`SetupTutorialValidator`'s findings alongside it — because the *client* reports nothing.

### Why this is NOT in the deploy pipeline

`app:check-setup-tutorial` exits 1 on structural problems, and `deploy-dev.yml` runs under
`set -e`. Wiring it in would mean an admin saving a bad payload breaks the next deploy of
everything else — a worse failure than the one being prevented, and one with no obvious cause for
whoever hits it. The save-time check is where an author sees it; the command is for CI on the
payload itself, or for a human with a suspicion.

### Validation: what it can and cannot tell you

It mirrors the client's drop rules, so an empty result means every item will *render*. It cannot
tell you an anchor is on the **wrong** step — `{"before": "kits"}` on the owner briefing is
structurally perfect and shows the wrong card in the wrong place. `StarterConfigTutorialTest`
pins the five chapter-opening anchors for that reason.

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
- `src/Entity/StarterConfig.php` — the column, its accessors and the seeded default
- `src/Service/SetupTutorialValidator.php` — the client's drop rules, mirrored
- `src/Command/CheckSetupTutorialCommand.php` — `app:check-setup-tutorial`
- `templates/admin/starter_config.html.twig` — the Setup Tutorial card
- `src/Service/AudienceCriteriaEvaluator.php` — the `setupCompleted` criterion and `LEGACY_KEYS`
- `migrations/Version20261102120000.php` — the stored-payload rename, tested against PG16

## Still owed

1. **The 34 `ngs.tut.*` translation rows.** Import the app's `locales/en/translations.json` at
   `/admin/translations/import`. **Leave "clear before import" UNTICKED** — ticking it calls
   `deleteGenericForLanguage()` and wipes every existing generic `en` translation (3,054 of them
   on dev as of 2026-11-02). Note also that dev's catalogue is ~1,300 entries behind the app's
   file generally, not just missing these 34; importing the whole file closes that too. **Deliberately not seeded from a PHP array here** — that file is
   the single source of those strings, and re-typing them on this side gives the same copy two
   homes and a drift problem. Without the rows the cards still render (the app's bundled catalogue
   carries them and API translations merge on top); what you cannot do is reword or localise
   without an app release, which is the whole reason the block lives server-side.
   `app:check-setup-tutorial` reports exactly which are missing.

2. **`Club::$tutorialCompletedAt` backfill** — unrelated to this feature beyond the word, and
   described under the `setupCompleted` section above: `UPDATE club SET tutorial_completed_at = …
   WHERE tutorial_completed_at IS NULL AND last_synced_week >= 1`.

3. **Retire `AudienceCriteriaEvaluator::LEGACY_KEYS` and `Version20261102120000`'s `down()`
   together** — that `down()` is only non-destructive while the alias accepts the old key.

## ⚠️ Changing a copy key is ordered, and the order is not obvious

`parseTutorial` **drops** an item whose copy key it cannot resolve. So serving a key an
installed app does not carry makes that card silently vanish — and the app's bundled catalogue
is only updated by a release.

Therefore: **ship the client key first, repoint the payload second.** That is why
`ngs.tut.owner.body` → `ngs.tut.owner.bodyRole` went out as `wunderkind-app@965aa5f3` before
`Version20261102130000` repointed the served payload, and not the other way round. The reverse
order breaks the owner briefing for everyone on the old bundle, with no error anywhere.

The same applies to any future reword: a catalogue value cannot be edited (the merge tool
refuses a differing value, and hand-editing is banned), so a reword is always a new key, and a
new key is always client-first.
