# API Spec — Push Notifications (FCM device tokens)

OS-level push notifications for events the client can't reasonably poll for in
real time: a competition round being drawn, another club joining a competition,
and (optionally) admin-authored broadcasts. Delivered via Firebase Cloud
Messaging (FCM) — the backend sends, the client is responsible for requesting
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
| `ROUND_DRAWN` | `competitionId`, `roundId` | Fixtures for a round are seeded — either the initial round when a competition's capacity fills, or the next round once the prior one completes. Sent only to the entrants placed into that specific round. |
| `NEW_REGISTRANT` | `competitionId` | A new club registers into a competition you're already registered in. Sent to every other currently-registered entrant, not the new joiner. |
| `ADMIN_MESSAGE` | `adminMessageId` | An operator-authored broadcast (`/admin/admin-messages`) was published with its "Also send as push" option checked. `notification.body` is the message's `bodyHtml` with tags stripped to plain text — if you want to render rich text, poll `GET /api/messages/pending` for the full `bodyHtml` using `adminMessageId` (this push is a nudge to check the inbox, not a replacement for polling it). |

No other push types exist yet. Treat an unrecognized `type` as a no-op
(display generically from `notification.title`/`body`, don't attempt to
deep-link) rather than dropping the notification — new types may be added
without a client update if a graceful unknown-type fallback exists.

## What this does *not* cover

- This is a notify-only signal. None of these pushes carry the actual updated
  data (fixtures, round state, the new registrant's identity) — the payload
  is exactly the table above, nothing more. On tap (or on receipt, if you
  want to refresh proactively), re-fetch `GET /api/competitions/{id}` for the
  current bracket/round/result state, the same endpoint the client already
  polls.
- Foreground-only, low-latency data sync (e.g. live match commentary while a
  result is being generated) is out of scope for this document — FCM alone
  has enough latency/OS-throttling variance that it isn't the right tool for
  that; poll or use a persistent-connection approach if that's ever needed.
- Reference implementation server-side: `src/Service/Notification/
  PushNotificationService.php` (dispatch), `src/MessageHandler/
  SendPushNotificationMessageHandler.php` (the actual FCM send),
  `src/Service/Competition/CompetitionLockService.php` /
  `CompetitionRoundProcessorService.php` / `src/Controller/Api/
  CompetitionController.php` (trigger points).
