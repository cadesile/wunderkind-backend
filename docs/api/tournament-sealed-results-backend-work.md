# Tournament sealed results — what the backend has to change

**Date:** 2026-10-31
**Status:** requested, none of it started. Item 1 **blocks the client feature from shipping at
all** and cannot be worked around client-side. Every backend claim below was re-verified
against `development` on 2026-10-31, and the app-repo references were checked against
`wunderkind-app` @ `development` the same day rather than taken on trust.

The app is integrating a "sealed results" drop
(`assets/Components/design_handoff_tournament_sealed_results`): a played cup tie of the
player's own shows `? – ?` on every surface — dashboard, bracket, inbox, notifications — until
the player reveals it once, in MatchUX, by watching the match or pressing REVEAL RESULT.
Anything that would give the result away is held behind a lock row rather than hidden.

Three items, reviewed against this repo @ `development`.

| # | Item | File | Size | Blocks the feature? |
|---|---|---|---|---|
| 1 | Outcome-neutral push copy | `CompetitionResultsService.php` | ~10 lines + a comment rewrite + a stage-doc update | **Yes** |
| 2 | `roundId` on bracket rounds | `CompetitionResponseBuilder.php` | 1 line, **plus a fingerprint component, a format-specifier fix and a test** — without which no client ever sees it | Partly — two of the three notification screens render the wrong round |
| 3 | `ROUND_RESOLVING_SOON` name mismatch | client-side rename | 3 call sites | No, but the third screen never fires today |

Items 1 and 2 are independent of each other and of the client work, so they can land first and
in any order. Item 3 is app-side and needs only a decision.

---

## 1. The push gives the result away before the app can intervene

**This is the blocker.** `SendPushNotificationMessageHandler.php:57` sends every push with
`withNotification(...)` — a display notification — so when the app is backgrounded or killed
the OS renders the title and body itself. The client can suppress only the *foreground* banner
(`NotificationService.ts:95-105` in the app repo), and it keeps `shouldShowList: true`, so the
text reaches the notification shade even in the foreground. No client change can stop this.

`MATCH_RESULT` and `COMPETITION_COMPLETED` are the only two pushes in the competition domain
that carry an outcome. The rest of that surface is `CompetitionDrawService.php:311`
(`ROUND_DRAWN`), `CompetitionRoundReminderService.php:85` (`ROUND_RESOLVING_SOON`),
`CompetitionRegistrationService.php:1108` (`COMPETITION_CANCELLED`), plus `NEW_REGISTRANT` and
`NEW_COMPETITION_OPEN` — none of which reveal a result. Nothing else needs touching.

### `MATCH_RESULT` — `src/Service/Competition/CompetitionResultsService.php:550-561`

Two calls, identical `data`, different titles:

```php
$this->pushNotificationService->notifyUsers([...winner...], 'Victory!',   $scoreline, $resultData);
$this->pushNotificationService->notifyUsers([...loser...],  'Eliminated', $scoreline, $resultData);
```

`$scoreline` (line 500) is `"Home FC 2-1 Away FC"`. The title and the body each give the result
away, independently of one another.

**Change:** one call to both user lists, with copy that reveals nothing — for example title
`Full time`, body `Your <round> result is in — tap to reveal.` (`$round->getLabel()` is already
in scope at that point). The `data` payload is unchanged; the client routes on `fixtureId` and
re-fetches `GET /api/matches/{fixtureId}` as it does today. Merging the two calls is safe here
precisely because `$resultData` is already identical on both sides.

### `COMPETITION_COMPLETED` — lines 310-323

Same spoiler, but **not** the same fix. Either string gives away a sealed final:

- winners: `'Champions!'` / `'You won the tournament! Check your inbox for your prize.'`
- runners-up: `'Tournament complete'` / `'The final has been played — the tournament has ended.'`

**Do not collapse these into one call.** Unlike `MATCH_RESULT`, the two `data` payloads differ —
`'result' => 'WON'` vs `'result' => 'RUNNER_UP'` — and that key is a documented part of the
contract (`.context/stages/04_interfaces/output/push-notifications.md:68`), so one merged call
would have to drop it. Keep **two calls with identical neutral title and body**; the
discriminator stays in the `data` payload, which the OS never renders.

### ⚠️ Read the comment at lines 488-499 before editing

The per-outcome titles are a **deliberate** decision from September — the comment says so:
"each with a title reflecting their own outcome (a distinct `Eliminated` signal rather than the
same neutral wording both sides used to get)". Sealed results reverse that call. **Rewrite that
comment in the same change**, or the next reader will restore the spoiler as a bug fix.

The same decision is documented in more detail, and more publicly, at **this repo's**
`.context/stages/04_interfaces/output/push-notifications.md:67-68` — down to the literal
`"Victory!"` / `"Eliminated"` titles and the example scoreline body. **That stage output has to
be updated in the same change too** (CLAUDE.md's finishing rule), or the context tree keeps
describing copy that no longer exists. (Note that the app repo has an `04_interfaces/output/`
of the same shape with no `push-notifications.md` in it — every `.context/` path in this brief
is this repo's unless it says otherwise.)

While that table is open, two things in that file are already wrong, both cheap to fix in the
same pass:

- **Four live push types are missing from it**, behind a closing claim that "No other push types
  exist yet" and with nothing scoping the table to competitions: `COMPETITION_CANCELLED`
  (`CompetitionRegistrationService.php:1108`), `FRIENDLY_RESULT`
  (`Friendly/FriendlyChallengeService.php:531`), `CHALLENGE_EXPIRED` (same file, `:605`) and
  `club_spotlight` (`ClubSpotlightService.php:297` — lower-cased, unlike every other type).
- **Its re-fetch instruction names a deleted method, and that is the contract item 1 relies on.**
  The "What this does *not* cover" section still tells clients to re-fetch
  `GET /api/competitions/{id}` and read "its per-fixture `result` field … exactly
  `CompetitionResult::toClientSummary()`'s shape". That method no longer exists anywhere in
  `src/`, and the bracket deliberately carries no `result` block at all
  (`docs/api/competition-bracket-no-embedded-result.md`). The inline comment at
  `CompetitionResultsService.php:496-499` was updated when that landed; this stage output was
  not. `GET /api/matches/{fixtureId}` is the handle — which is exactly what the `MATCH_RESULT`
  change above tells the client to keep doing.

### The inbox messages — three of them, lower priority

A resolved tie writes **three** inbox notes, not one, and each leaks the outcome by a different
route.

**1. `Match Result` — lines 511-512:**

```php
subject: sprintf('Match Result (%s): %s', $round->getLabel(), $scoreline),
body:    sprintf('Full-time in the %s of the %s: %s.', $round->getLabel(), $templateName, $scoreline),
```

`offerData.link` is `{kind: 'match', id: fixtureId}`, so a client-side rule keyed on a sealed
match id does reach it.

**2. `COMPETITION_ELIMINATED` — lines 525-540:**

```php
subject: sprintf('Eliminated from %s', $templateName),
body:    sprintf('Your club was knocked out in the %s of the %s (%s).', $round->getLabel(), $templateName, $scoreline),
```

The **subject alone reveals the outcome with no score in it**, and `offerData.link` here is
`{kind: 'competition', id: competitionId}` — a competition id, not a match id.

**The drop already holds this one**, though: `sealedResults.ts`'s `splitInbox` holds any note
whose `type` is in its `SPOILER_TYPES` (`ROUND_DRAWN`, `COMPETITION_WON`,
`COMPETITION_ELIMINATED`, `COMPETITION_COMPLETED`) **and** whose link is a sealed competition —
which is exactly this note's shape. So no rewrite is needed for it. The gap is note 1:
`MATCH_RESULT` is in neither that type set nor reachable by a competition-keyed rule, because
its link is a match. Add `MATCH_RESULT` to the type set and hold it on `fixtureId`, which both
notes carry.

**3. `You won the {template}!` — `src/Service/Competition/RewardApplierService.php:70`**, on the
final only, and the **claimable prize** message. The drop answers this one too, deliberately:
INTEGRATION.md §5 holds the `COMPETITION_WON` note until the reveal and **releases it on the
reveal**, after which LIFT THE TROPHY → RewardCeremony → cabinet runs exactly as today. The
prize is deferred, never withheld — the player controls when by revealing — and collection
stays a server accept on `WinNoteCard`, which is why MatchUX must not start the ceremony itself.

So all three are handled client-side, and that is still where I would handle them: the stored
text should stay honest once the tie has been revealed. Note 2 and note 3 already work; note 1
needs `MATCH_RESULT` added to the held types. Listed here only so the choice is explicit — if
the stored copy should never carry a score at all, these are the call sites.

---

## 2. Add `roundId` to each bracket round

`src/Service/Competition/CompetitionResponseBuilder.php`

The app's notification host resolves a push's round with
`comp.rounds.find(r => r.roundId === head.roundId)`
(`src/components/tournaments/TournamentNotificationHost.tsx:80`). **`Round` carries no
`roundId`.** Line 170 (a real round) and line 99 (the `future` placeholder) serialise:

```
roundIndex, key, label, status, drawAt, scheduledAt, startedAt, kickoffAt,
matchesResolveAt, fixtures
```

So the lookup always misses, and the host's `?? rounds[rounds.length - 1]` fallback renders the
**final** for every `ROUND_DRAWN` and `ROUND_RESOLVING_SOON` screen.

There is no client-side alternative: those pushes carry only `competitionId` and `roundId`, and
neither carries a `fixtureId` to work back from.

```php
'roundId' => (string) $round->getId(),   // line 170; null on the placeholder branch at line 99
```

### The field will not reach a single client until the ETag moves

`CompetitionResponseBuilder.php:415`:

```php
return sprintf('W/"%s-%s-%d-%s-%d"', $instance->getId(), $instance->getStatus()->value, $version, $clubPart, $entrantVersion);
```

`$version` is the 32-character hex state fingerprint (`substr(hash('sha256', …), 0, 32)`,
line 402), and **`%d` casts it to an int.** That does not reliably yield `0`: PHP parses the
leading numeric run, so `sprintf('%d', 'a3f9bc12')` is `0` but `sprintf('%d', '7f3a')` is `7`.
So the ETag keeps a few bits of accidental entropy and *usually* moves — which is worse than
never moving, because a single manual test will almost certainly appear to pass. Measured over
200k random fingerprints: **42% collapse to `0`** (a leading `0…` run counts too, which is why
this is higher than the 6-in-16 chance of starting with `a`-`f`), and **two fingerprints project
to the same int 18% of the time** — i.e. roughly **one state change in five does not move the
ETag at all**. Two consequences, and the first is what makes this part of item 2 rather than a
footnote:

- **Purging `competition_response_cache` is not sufficient.** `CompetitionController.php:428-431`
  compares `If-None-Match` and returns `304` *before* the cache is consulted at all. A rebuilt
  document is fingerprinted from unchanged state, so the recomputed ETag is byte-identical to
  the one the client already holds, and the client keeps its old `roundId`-less bracket. The
  change therefore needs a **builder/schema version constant inside
  `computeStateFingerprint()`'s `$components`**, *and* `%d` → `%s` so that constant survives
  into the ETag.
- **It is independently a live bug.** Within one `ActiveCompetitionStatus`, a draw or a
  resolve moves the ETag only when it changes the fingerprint's leading digit run — about four
  times in five — so roughly every fifth real bracket change is served to a polling client as
  a `304` with the previous bracket behind it. Intermittent, which is why it has not been
  reported as a bug: it looks like a slow client refresh rather than a cache fault. The
  `%d` was correct when the fingerprint was an additive integer, which is what `024e87f`
  (2026-10-27) introduced it against; **`e96f791` (2026-10-28) replaced that int with the
  sha256 fingerprint and left the format specifier** — a commit whose own subject is "stop the
  response cache from … serving stale brackets".

`CompetitionResponseCacheTest` asserts the *fingerprint* moves (lines 160, 253) but never the
ETag that consumes it, and `CompetitionControllerTest::testShowSupportsEtagAnd304NotModified`
only covers the matching-ETag happy path — which is how this survived. Add an assertion that a
state change changes `etagFor()`.

**Alternative:** add `roundIndex` to the `ROUND_DRAWN` and `ROUND_RESOLVING_SOON` push payloads
instead. Cheaper, avoids the cache and ETag question entirely, but it fixes only those two
pushes rather than any future push→bracket mapping.

---

## 3. One push type has two names

This repo sends **`ROUND_RESOLVING_SOON`** (`CompetitionRoundReminderService.php:91`, and the
`NotificationDebugController` route requirement). The app has only ever handled
**`ROUND_STARTING_SOON`** (`src/types/pushNotification.ts:56,70`,
`src/stores/competitionNotificationStore.ts:22`). That branch is dead today, and the drop's
third blocking screen would never fire.

**Recommendation: rename client-side.** This repo's name is the accurate one — results resolve,
there is no kick-off — and the reminder copy already says "Results incoming! … results land in
about 15 minutes." (`REMINDER_LEAD_MINUTES = 15`, line 24; the reminder cron itself runs every 5.)

Two consequences to take with it, both app-side:

- the notification screen should read *results land in ~N minutes*, not count down to a
  kick-off. Its countdown reads `round.kickoffAt`, which is `matchesResolveAt` — the resolve
  time — so the number is already right and only the words are wrong;
- `.context/stages/04_interfaces/output/services.md:540` in the app repo documents the client's
  name as though it were the wire's.

---

## Confirmed as needing nothing

| Asked for | Verdict | Evidence |
|---|---|---|
| `completedAt` on hub `recent` rows | **Not needed** | `MatchQueryService.php:509` (in `serializeCupMatchSummary()`, which is what `findRecentMatchesForClub()` builds every cup row from) already sets `kickoffAt = processedAt ?? matchesResolveAt ?? scheduledAt`, so on a COMPLETE fixture `kickoffAt` **is** the completion time. The drop's baseline comparison is correct as written |
| Syncing revealed match ids across devices | **Not now** | Needs a new account-scoped endpoint with nothing to hang it on (`/api/owner-avatar` is the only account-level record). Cost of skipping: a result revealed on one device shows sealed on another |
| `competition.trophy` on `MatchSummary` | **Not needed here** | The known "pending" field. Nothing in this drop reads it — the sealed screen and the notification host both take the trophy from `GET /api/competitions/{id}` |
| Sealing friendly results | **Scope contradiction — needs an owner decision** | See below |

The `completedAt` answer also settles a client-side bug: the app's `useSealedResults.isSealedId`
returns true for *any* id not yet revealed, ignoring the baseline, so the bracket would seal
every historical tie on first run. The fix is to use the round's own `kickoffAt` as the
fixture's finish proxy — still no backend field.

---

## Open: are friendlies in scope?

**The drop contradicts itself, and the answer changes whether there is a fourth backend item.**

- INTEGRATION.md §3 says the scope is "cup ties **and challenge friendlies**
  (`kind === 'cup' | 'friendly'`, `involves(me)`). Friendlies follow the same rule with
  `matchCompLabel` in place of a round."
- Its code is cup-only: `sealedResults.ts`'s `sealedMatches` filters `m.kind === 'cup'`, and so
  does `splitUpcoming`.

If friendlies stay out, nothing here changes. **If they are in scope, the friendly surface
leaks in three more places** and the fix is not "the body only":

| Where | What leaks |
|---|---|
| `Friendly/FriendlyChallengeService.php:531-537` | push title `'Friendly match full-time'` is already neutral ✅, but the **body is the bare scoreline** |
| same file, ~line 454 | inbox subject `'Friendly Draw: <scoreline>'` |
| same file, ~lines 489 and 502 | inbox subjects `'Friendly Victory: <scoreline>'` and `'Friendly Result: <scoreline>'` — the first reveals the outcome in the subject word alone, like `COMPETITION_ELIMINATED`. It is also a **claimable prize** note ("Accept to claim your friendly match winnings", line 491), so it needs the same defer-until-reveal treatment as `RewardApplierService.php:70`, not just a subject rewrite |

The client side would also need `'friendly'` added to those two `kind` filters. Worth settling
before item 1 is written, since the push fix is the same shape in both services.

---

## Sealing is a UI promise, not a server rule

`GET /api/competitions/{id}` and `GET /api/matches/{id}` are both deliberately `PUBLIC_ACCESS`
(`config/packages/security.yaml:97-98`), commented as non-sensitive so the web landing page can
read them. A player could read their own sealed result straight from the API.

That is fine for the feature. It only matters that no document claims the seal is enforced.
