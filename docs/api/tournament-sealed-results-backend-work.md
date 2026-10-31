# Tournament sealed results — backend changes needed

**Date:** 2026-10-31
**Status: all four actioned 2026-10-31.** Full suite green (1707 tests). What shipped, and
where it differs from the brief:

- **1.** `MATCH_RESULT` is now one push to both sides, `Full time` / `Your <round> result is in
  — tap to reveal.`; `COMPETITION_COMPLETED` keeps its two calls and the `result` key but
  sends identical neutral copy to both. The `:488-499` comment is rewritten to say the seal is
  the reason, so the next reader does not restore the spoiler as a bug fix.
- **2.** All three friendly notes now share one outcome-free subject and body (constants, so
  they cannot drift apart), and the push body is neutral. Note this went **further than "remove
  the scoreline from the subjects"**: `Friendly Draw:` and `Friendly Victory:` give the outcome
  away with no score in them at all, so a per-outcome subject could not survive sealing.
- **3.** `roundId` added, `null` on a `future` placeholder. The ETag needed two fixes, not one:
  a `DOCUMENT_SCHEMA_VERSION` component in the fingerprint (a new key is not a state change, so
  nothing would have invalidated) **and** `%d` → `%s`. Contract: `docs/api/competition-round-id.md`.
- **4.** The draw refund now rides on the note's `effects`. Same bug exists at **one more call
  site the brief does not mention** — the expiry refund at `:573-600` writes `club.balance` by
  raw SQL too, and its note carries no `effects`, so an expired challenge's stake is lost the
  same way. Left alone deliberately: it is a second behaviour change, not in scope here.

Also fixed in `push-notifications.md` while updating it: four live push types were missing from
a table that claims none are, and its re-fetch instruction still named
`CompetitionResult::toClientSummary()`, deleted when the bracket stopped embedding results.

---

Four changes. Items 1 and 2 block the app release.

---

## 1. The match-result push must not reveal the result

`src/Service/Competition/CompetitionResultsService.php:550-561`

Today it sends two pushes — title `'Victory!'` to the winner, `'Eliminated'` to the loser, with
the scoreline as the body of both. The OS renders that on the lock screen.

Send one push to both user lists instead:

- title: `Full time`
- body: `Your <round> result is in — tap to reveal.`

Leave `$resultData` exactly as it is.

**Also `:310-322`** (`COMPETITION_COMPLETED`): same neutral title and body for winners and
runners-up. Keep it as **two calls** — the `result` => `WON` / `RUNNER_UP` key in `data` is part
of the contract and must stay.

**Also update these two, which both specify the old copy:** the comment at `:488-499`, and
`.context/stages/04_interfaces/output/push-notifications.md:67-68`.

---

## 2. The friendly-result push and notes must not reveal the result

`src/Service/Friendly/FriendlyChallengeService.php`

| Line | Change |
|---|---|
| `:531-537` | push body is the bare scoreline → `Your friendly result is in — tap to reveal.` |
| `:454` / `:456` | subject + body |
| `:489` / `:491` | subject + body |
| `:502` / `:503` | subject + body |

Remove the scoreline from all three subjects, and the outcome word from `'Friendly Victory: …'`.

---

## 3. Add `roundId` to each bracket round

`src/Service/Competition/CompetitionResponseBuilder.php:170`, and `:99` for the `future`
placeholder:

```php
'roundId' => (string) $round->getId(),
```

**This will not reach a single client until the ETag changes.** `computeEtag` at `:415` formats
the 32-character hex state fingerprint with `%d`, so the fingerprint contributes almost nothing
and a rebuilt document returns the same ETag. Add a schema-version component to
`computeStateFingerprint()`'s `$components` and change that `%d` to `%s`.

---

## 4. A drawn staked friendly loses the player's stake

`src/Service/Friendly/FriendlyChallengeService.php:436-446`

The raw `UPDATE club SET balance = balance + :stake` never reaches the app: the client is
authoritative for the balance and ignores the server's figure. The refund is lost.

Drop that `UPDATE` and put the refund on the note instead, exactly as the win payout already
does at `:479-482`:

```php
'effects' => [['type' => 'ledger_delta', 'amountPence' => $stakePence]],
```

Unrelated to sealed results. Worth fixing either way.

---

Nothing else is needed. `completedAt` on hub `recent` rows, syncing revealed match ids, and
`competition.trophy` on `MatchSummary` were all checked and need no change.
