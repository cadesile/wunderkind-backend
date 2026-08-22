# API Spec — Server-Driven Messaging

Operator-authored announcements: release notes, incident notices, and cohort-targeted
campaigns, composed in the backend admin and polled by the client. Delivery state is tracked
server-side, so a message is shown **once** and then retires for that club.

This is separate from the existing club Inbox (`/api/inbox`). Inbox messages are in-game
fiction from agents, sponsors and investors, written by game services. These are out-of-game
messages written by a human.

Client developers integrating these endpoints should start with
[admin-messages-client-integration.md](admin-messages-client-integration.md), which carries the
TS types, polling policy and rendering rules. This document is the full spec, including the
targeting model an operator uses when authoring a message.

## Endpoints

Both require the normal club JWT (`ROLE_CLUB`).

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/messages/pending` | Undelivered messages for the caller's club |
| `POST` | `/api/messages/{id}/ack` | Record that a message was displayed or dismissed |

### `GET /api/messages/pending`

```json
{
  "messages": [
    {
      "id": "01914eb1-9bc0-7111-9e23-28bf2281a8b1",
      "title": "Season 2 Update: New Youth Facilities",
      "bodyHtml": "<p>Academy upgrades now provide <strong>+15% XP</strong>.</p>",
      "priority": 2,
      "displayType": "modal_blocking",
      "createdAt": "2026-08-21T23:00:00+00:00"
    }
  ]
}
```

`messages` is `[]` when there is nothing pending — that is the normal case, not an error.

| Key | Type | Notes |
|---|---|---|
| `id` | uuid string | Pass back to the ack endpoint |
| `title` | string | ≤ 150 chars |
| `bodyHtml` | string | Sanitised server-side — see [HTML safety](#html-safety) |
| `priority` | int | `1` low / patch note, `2` standard, `3` urgent |
| `displayType` | string | `modal_blocking`, `inbox_item`, or `bottom_sheet` |
| `createdAt` | ISO 8601 | Publication time, not the poll time |

> **Key casing.** This endpoint uses camelCase (`bodyHtml`, `displayType`, `createdAt`), matching
> every other endpoint in this API and the client's existing TS types. Earlier drafts of the
> spec showed snake_case; that is not what ships.

Errors: `401` if unauthenticated, `404 {"error": "Club not found"}` if the authenticated user
has no club yet.

**Not cached.** Unlike `/api/archetypes`, there is no ETag — the response is per-club and
changes on every acknowledgement, so a 304 would suppress a message the client never saw.

#### How many messages come back

Per response, the server returns **at most one `modal_blocking` message plus at most five
non-blocking ones** — six maximum. The blocking message, if any, is always first in the array.
This cap is enforced server-side, so a misconfigured campaign cannot stack modals on a player;
the client does not need to defend against it, but should still only present one blocking modal
per session.

Ordering is `priority` descending, then oldest-first within a priority.

### `POST /api/messages/{id}/ack`

```json
{ "status": "displayed" }
```

`status` must be `"displayed"` or `"dismissed"`. Response is `200 {"success": true}`.

| Status | Meaning |
|---|---|
| `400` | Malformed body |
| `404` | Unknown message id, or the caller has no club |
| `422` | `status` was `"pending"` — that value is internal and not accepted from a client |

**Acknowledge on render, and again on dismiss.** Both calls are safe: acks are idempotent, and
repeating one never creates a duplicate record. `dismissed` is terminal — once a message is
dismissed, a late-arriving `displayed` ack (the two can race) will not downgrade it.

**Acknowledgements are per account, not per club.** A player who starts a new club does not
see announcements they already dismissed. Acking also does not require a club at all, so a
message can be retired after the club is deleted or replaced. Another account still receives
the same broadcast.

## HTML safety

`bodyHtml` is sanitised **when the admin saves it**, so what the API returns is already clean.
Only these tags survive: `p`, `strong`, `em`, `ul`, `ol`, `li`, `br`, `h3`, and `a` with an
`href` — `https:` and the app's own `buildmyclub:` deep-link scheme only, with every link
forced to `rel="noopener noreferrer"`.

Everything else is dropped, **including `style` and `class` attributes** — the client owns its
retro theme and admin-supplied CSS must never bleed into it. Scripts and iframes cannot be
saved in the first place.

The client should still render through a strict allowlist of its own (`react-native-render-html`
with `tagsStyles`/`allowedStyles`) rather than trusting the server — defence in depth, and the
allowlist above is the exact set worth styling.

## Client integration notes

**Polling.** Do **not** poll on the local week tick. An offline-first sim advances weeks
rapidly and would cause connection storms. Poll on:

1. app cold start, after SQLite hydration completes;
2. foreground resume via the `AppState` listener, debounced to at most once every 15 minutes.

**Offline.** A failed poll is not an error state — run normally and pick the messages up on the
next successful sync. Nothing is lost: the server keeps returning a message until it is acked.

**Routing.** Show `modal_blocking` as a modal (one per session). Route `inbox_item` and
`bottom_sheet` to the club Inbox under a `system` category rather than interrupting play.

## Targeting (backend reference)

Client developers do not need this; it is here so the API contract and the admin UI describe
the same rules.

A message targets either everyone (`broadcast`), a single club (`direct_club`), or one or more
audience groups (`group_segmented`, matching if **any** group matches). Groups are either
`manual` (an explicit membership list) or `dynamic` (evaluated live at poll time — no refresh
job, never stale).

Dynamic criteria are a closed whitelist; all keys present must match.

| Key | Type | Meaning |
|---|---|---|
| `minReputation` / `maxReputation` | int | Club reputation, inclusive |
| `country` | string or list | 2-letter club country code |
| `leagueTier` | int or list | Club's current league tier |
| `minWeek` / `maxWeek` | int | Last synced week, inclusive |
| `tutorialCompleted` | bool | Whether the tutorial is finished |

```json
{ "minReputation": 500, "leagueTier": [7, 8], "tutorialCompleted": true }
```

Two things that are easy to get backwards:

- **`leagueTier` is inverted.** Tier `1` is the top division and `8` is where new clubs start,
  so `{"leagueTier": 8}` targets beginners, not elite clubs.
- **An unrecognised key makes the group match nothing.** Criteria fail closed, so a typo
  under-delivers rather than broadcasting to the entire player base. Check the delivery counts
  on the message's admin detail page after publishing.

Clubs with no country have no league and never match a `leagueTier` or `country` criterion.

## Accounts: guests and registered users

Guest (device-bound) and registered accounts are both plain `User` rows and are treated
**identically**. Nothing in this system inspects verification status or the synthetic
`@guest.buildmyclub.local` email domain — if an account can authenticate, it polls and acks
like any other.

Targeting is evaluated against the caller's club, because that is where the cohort fields live,
but delivery state is recorded against the account. That split is what makes the two guarantees
above hold: cohort accuracy on one side, one-showing-per-person on the other.

**One caveat that is not solved here.** `POST /api/register` always creates a new `User`, so a
guest who registers a real email becomes a different account and may re-see a message they had
dismissed as a guest. That is an account-linking gap in registration, not a messaging one — no
choice of delivery key inside this feature can bridge two separate `User` rows. Once the
backend gains a guest-upgrade path, the fix is either to reuse the existing `User` row (in
which case messaging needs no change at all) or a single transfer query over
`message_delivery`.
