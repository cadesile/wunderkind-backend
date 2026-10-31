# API Spec — Push Notifications (FCM device tokens)

> Moved here from `docs/api/push-notifications.md` (2026-09-20) — this repo's push-notification
> spec now lives in `.context/` rather than as a separate hand-written doc. Referenced from
> `.context/CONTEXT.md`'s router and `04_interfaces/output/routes.md`/`services.md`.

OS-level push notifications for events the client can't reasonably poll for in
real time: a competition round being drawn, another club joining a competition,
a single fixture's result, and (optionally) admin-authored broadcasts. Delivered via Firebase
Cloud Messaging (FCM) — the backend sends, the client is responsible for requesting
notification permission and registering its FCM token.

## Endpoints

Both require the normal club JWT (`ROLE_CLUB`).

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/device-tokens` | Register (or reassign) an FCM token for the authenticated user |
| `DELETE` | `/api/device-tokens/{deviceToken}` | Remove a token — call on logout |

### `POST /api/device-tokens`

```json
{
  "deviceToken": "<the FCM registration token>",
  "platform": "ios",
  "deviceId": "optional-client-generated-stable-id"
}
```

- `platform` — `"ios"` or `"android"`.
- `deviceId` — optional, not currently used server-side; reserved for
  future device-scoped actions.

Upserts by `deviceToken`: a token identifies one **app installation**, not an
account. Re-registering an already-known token under a different
authenticated user (e.g. logging into a different club on the same phone)
reassigns it to the new user rather than creating a duplicate row or erroring.
Call this every time the token is available — on first grant of notification
permission, and again whenever FCM's SDK fires its token-refresh callback.

Response: `201` `{"success": true}`. `422` on a missing/invalid `platform`.

### `DELETE /api/device-tokens/{deviceToken}`

Call on logout. The `api` firewall is stateless JWT — there is no server
session to invalidate this from automatically, so the client must call this
explicitly. Deleting a token that doesn't belong to the caller (or doesn't
exist) is a no-op, not an error — `200 {"success": true}` either way, so this
is always safe to call defensively.

## What you'll receive

A push notification's payload has two parts, same as any FCM message:

- **`notification.title` / `notification.body`** — plain text, safe to show
  directly in an OS notification banner.
- **`data`** — a flat `{string: string}` object (FCM requires every value to
  already be a string) carrying a `type` discriminator plus whatever ids are
  needed to deep-link when the notification is tapped:

| `type` | Additional `data` keys | Fired when |
|---|---|---|
| `ROUND_DRAWN` | `competitionId`, `roundId` | Fixtures for a round are seeded — round 1 or any later round, all drawn the same way now (`CompetitionDrawService::drawRound()`), decoupled from that round's own resolution. Sent only to the entrants placed into that specific round. |
| `NEW_REGISTRANT` | `competitionId` | A new club registers into a competition you're already registered in. Sent to every other currently-registered entrant, not the new joiner. |
| `MATCH_RESULT` | `competitionId`, `roundId`, `fixtureId` | A single fixture's result has just been generated (`CompetitionResultsService::resolveFixture()` — the scheduled cron path and the admin "Generate Result" force-resolve path both go through this). **One** push, addressed to both sides of that fixture, with **outcome-neutral** copy: `notification.title` `"Full time"` and `notification.body` `"Your <round label> result is in — tap to reveal."`. **The result is not in this push, by design** — a cup tie of the player's own is sealed until they reveal it in MatchUX, and every push is sent via `withNotification(...)`, so the OS renders title and body on a locked screen before any client code runs; a scoreline or a per-outcome title (this used to be two pushes titled `"Victory!"` / `"Eliminated"` with the scoreline as the body) would break the seal where no client-side suppression can reach. Fetch by `fixtureId` as before. Unlike `ROUND_DRAWN`, this fires per fixture, not per round. |
| `COMPETITION_COMPLETED` | `competitionId`, `result` (`"WON"` or `"RUNNER_UP"`) | The tournament's final round has just had its results published (`CompetitionResultsService::finalizeRound()`). Still **two** pushes — champions carry `result: "WON"`, the final's loser(s) `result: "RUNNER_UP"` — but their `notification.title` and `notification.body` are now **identical**: `"Tournament complete"` / `"The final has been played — tap to reveal the result."`. The old `"Champions!"` title gave a sealed final away on the lock screen exactly as a scoreline would; the outcome still reaches you in `data.result`, which the OS never renders. It stays two calls precisely so that key can differ per side. Distinct from — and in addition to — the `MATCH_RESULT` push both sides already got for that same final fixture; this one specifically marks the *tournament* ending, not just that one match. |
| `NEW_COMPETITION_OPEN` | `competitionId` | A new instance was just provisioned for a `CompetitionTemplate` (`app:competition:provision-instances`, run every 10 min — only fires when a **new** instance is actually created, not on every tick that finds one still open). Sent only to clubs the template's own entry constraints would actually let register (`EligibilityEvaluator`, the same check `POST /api/competitions/{id}/register` itself enforces) — a club outside the template's tier/country/etc. restrictions never sees this. |
| `ROUND_RESOLVING_SOON` | `competitionId`, `roundId` | A round you're still in (already drawn — `DRAWN` status) is due to have its results published within 15 minutes (`app:competition:send-round-reminders`, run every 5 min; `CompetitionRoundReminderService`). Sent once per round, only to that round's actual participants (the entrants with a fixture seeded into it) — a `DRAW_PENDING` round has no fixtures yet, so it's never eligible for this reminder. |
| `COMPETITION_CANCELLED` | `competitionId` | A competition instance was cancelled before it could run (`CompetitionRegistrationService`, e.g. an under-subscribed instance at its deadline). Sent to every registered entrant; entry fees are refunded through the inbox, not this push. |
| `FRIENDLY_RESULT` | `challengeId`, `matchId` (same value) | An accepted challenge friendly resolved (`FriendlyChallengeService::resolveSingleMatch()`). One push to both clubs, with **outcome-neutral** copy for the same reason as `MATCH_RESULT`: title `"Friendly match full-time"`, body `"Your friendly result is in — tap to reveal."`. The score is served by `GET /api/matches/{challengeId}`. |
| `CHALLENGE_EXPIRED` | `challengeId` | An open friendly challenge reached `expiresAt` without an opponent (`FriendlyChallengeService::expireStaleChallenges()`). Sent to the challenger only. |
| `club_spotlight` | — | The Club Spotlight rotated (`ClubSpotlightService`). Note the lower-cased `type`, unlike every other value here. |
| `ADMIN_MESSAGE` | `adminMessageId` | An operator-authored broadcast (`/admin/admin-messages`) was published with its "Also send as push" option checked. `notification.body` is the message's `bodyHtml` with tags stripped to plain text — if you want to render rich text, poll `GET /api/messages/pending` for the full `bodyHtml` using `adminMessageId` (this push is a nudge to check the inbox, not a replacement for polling it). |

| `LEADERBOARD_AWARD` | `category`, `period`, `place`, `archiveEntryId` | A leaderboard award period ended and this club placed in it (`LeaderboardAwardDeliveryService::notifyWinners()`, driven by `app:leaderboards:close-periods`). Sent once per winning club, **after the close has committed** — a push for an award that was rolled back cannot be unsent. `place` is a string (`"1"`), as FCM requires of every `data` value. The prize itself is an inbox message, not this push: it arrives through the unchanged `GET /api/inbox` with `offerData.type = 'LEADERBOARD_AWARD'`, and `archiveEntryId` is the handle that correlates the two. Nothing is claimed from the leaderboard screen. Full contract: `docs/api/leaderboard-awards.md`. |

No other push types exist yet. Treat an unrecognized `type` as a no-op
(display generically from `notification.title`/`body`, don't attempt to
deep-link) rather than dropping the notification — new types may be added
without a client update if a graceful unknown-type fallback exists.

## What this does *not* cover

- This is a notify-only signal. None of these pushes carry the actual updated
  data (fixtures, round state, the new registrant's identity, or — for
  `MATCH_RESULT` — the match itself) — the payload is exactly the table
  above, nothing more. On tap (or on receipt, if you want to refresh
  proactively), re-fetch **`GET /api/matches/{fixtureId}`** for the match
  itself — score, clubs, lineups, narrativePayload, tournament/round context
  (`MatchQueryService::serializeCupMatchDetail()`; the sibling
  `wunderkind-app` doc is `docs/api/tournament-match-result-payload.md`).
  `GET /api/competitions/{id}` is still the call for current
  bracket/round state, but **it carries no per-fixture `result` block** — that
  was removed along with `CompetitionResult::toClientSummary()`, because
  embedded match detail measured ~74% of a capacity-64 response and the match
  endpoint already served the same thing. `rounds[].fixtures[].matchId` is the
  handle. See `docs/api/competition-bracket-no-embedded-result.md`.
- **Why `MATCH_RESULT` doesn't embed the full result payload directly, even
  though it would be convenient:** FCM's `data` payload (a) requires every
  value to already be a string — the result payload is deeply nested
  (arrays of objects) — and (b) is capped at roughly 4KB total message
  size. A real result's `narrativePayload` (dozens of commentary lines) plus
  two 11-player lineups routinely exceeds that on its own; FCM rejects an
  oversized send outright rather than truncating it. Fetching by
  `fixtureId` after the notify is both the only reliable option and
  consistent with every other push type here.
- Foreground-only, low-latency data sync (e.g. live match commentary while a
  result is being generated) is out of scope for this document — FCM alone
  has enough latency/OS-throttling variance that it isn't the right tool for
  that; poll or use a persistent-connection approach if that's ever needed.
- Reference implementation server-side: `src/Service/Notification/
  PushNotificationService.php` (dispatch), `src/MessageHandler/
  SendPushNotificationMessageHandler.php` (the actual FCM send),
  `src/Service/Competition/CompetitionDrawService.php` (`ROUND_DRAWN`) /
  `CompetitionResultsService.php` (`MATCH_RESULT`/`COMPETITION_COMPLETED`)
  / `src/Controller/Api/CompetitionController.php` (`NEW_REGISTRANT`),
  `src/MessageHandler/ResolveNewCompetitionAudienceForPushMessageHandler.php`
  (`NEW_COMPETITION_OPEN`'s eligibility-filtered audience resolution, mirroring
  `ResolveAdminMessageAudienceForPushMessageHandler`'s pattern via the shared
  `App\Message\AudienceResolutionMessage` interface), `src/Service/Competition/
  CompetitionRoundReminderService.php` (`ROUND_RESOLVING_SOON`),
  `src/Service/Competition/CompetitionRegistrationService.php`
  (`COMPETITION_CANCELLED`), `src/Service/Friendly/FriendlyChallengeService.php`
  (`FRIENDLY_RESULT`/`CHALLENGE_EXPIRED`), `src/Service/ClubSpotlightService.php`
  (`club_spotlight`).
- **Sealed results.** `MATCH_RESULT`, `COMPETITION_COMPLETED` and
  `FRIENDLY_RESULT` are deliberately outcome-neutral in both `title` and
  `body`, because a played tie of the player's own stays sealed until they
  reveal it in MatchUX and these are display notifications the OS renders
  itself. The outcome travels in `data` only (`result` on
  `COMPETITION_COMPLETED`; otherwise fetch the match). Do not reintroduce
  per-outcome copy on any of the three.
