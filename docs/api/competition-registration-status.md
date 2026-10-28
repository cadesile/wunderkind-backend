# Competition registration status — pending vs confirmed

**Audience:** `wunderkind-app`.
**Status:** backend shipped 2026-10-28.

**One breaking change, deliberately taken while tournaments are not yet player-facing:**
`POST /resubmit`'s `status` no longer returns the literal `"accepted"` — it carries the
entrant's standing, as `/register` and `/withdraw` already did. Everything else here is
additive, including the new `entrant` object on `/resubmit` and `/withdraw`.

All three write endpoints now answer in the same shape: `status` is the entrant's standing,
and the entry itself is nested under `entrant`.

---

## What changed on the backend, and why you now have a second status

Registration used to do all of its work inside one database transaction holding a lock on the
competition row: capacity check, entry fee, entrant insert, *and* the ~1.4MB squad/submission
write. Every club registering for the same competition queued behind one club's payload.

It now splits in two:

1. **Reserve** — under the lock: capacity check, entry fee, entrant row. ~1ms.
2. **Store** — outside the lock, on a queue: the squad and the verbatim submission.

**Your request still returns the same synchronous `201` it always did.** Nothing was removed,
nothing became asynchronous from your side, and no existing field changed value — the body
only gained `entrant.submissionStatus`. But for a short window
after that 201 — normally milliseconds — the entry exists and holds its slot while its squad
is still being written. `submissionStatus` is how you can see that window.

## The field

Four places expose it: the three write endpoints below, and `viewer.snapshot` on
`GET /api/competitions/{id}`. All additive except `/resubmit`'s `status`, flagged above.

The response examples are **abbreviated to the keys this document is about** — every endpoint
also returns the fields it always did (`instanceId`, `seed`, `competition`, `club`, …), none
of which changed.

### `POST /api/competitions/{id}/register` → `entrant.submissionStatus`

```jsonc
{
  "entrantId": "01a17fe9-…",
  "status": "registered",          // unchanged — the entry's standing in the competition
  "entrant": {
    "entrantId": "01a17fe9-…",
    "clubName": "Bochum VFL",
    "seed": 0,
    "status": "registered",
    "submissionStatus": "pending"  // NEW — the state of the squad you just submitted
  }
}
```

### `POST /api/competitions/{id}/resubmit` → `entrant.submissionStatus`

Same place as register, read the same way:

```jsonc
{
  "entrantId": "01a17fe9-…",
  "snapshotVersion": 2,
  "status": "active",              // CHANGED — see below
  "locksAt": "2031-10-11T18:00:00+00:00",
  "entrant": {                     // NEW — the same object register returns
    "entrantId": "01a17fe9-…",
    "status": "active",
    "submissionStatus": "confirmed"
  }
}
```

**⚠️ Breaking change to `status` on this endpoint.** It used to be the literal string
`"accepted"`, describing the resubmission rather than the entry — a second vocabulary under a
key that means something else on `/register`. It now carries the **entrant's standing**
(`registered` / `active` / …), identical in meaning to register's.

Nothing is lost: acceptance is already the `200`, and the refusals are `404 not_registered` /
`409 resubmission_window_closed`. **If your code checks `status === "accepted"`, it must
change** — check the HTTP status instead.

Resubmit writes inline rather than queueing, so `entrant.submissionStatus` is always
`confirmed` on success. It is stated rather than implied, because this is how a client
confirms a `failed` entry actually recovered.

### `POST /api/competitions/{id}/withdraw` → `entrant.submissionStatus`

Same again. Nothing changed in value here — `status` was already the string `"withdrawn"` and
still is; it is now read off the entrant rather than hardcoded, so all three endpoints mean
the same thing by that key.

```jsonc
{
  "status": "withdrawn",
  "entrant": {                     // NEW
    "entrantId": "01a17fe9-…",
    "status": "withdrawn",
    "submissionStatus": "failed"   // whatever it was — see below
  },
  "competition": { … },
  "club": { "clubId": "…", "balance": 4300000 }   // fee already refunded
}
```

`submissionStatus` is returned as it stood, not reset — so a club withdrawing a `failed` entry
gets `"failed"` back. That is deliberate: it confirms you withdrew the thing you meant to.

### All three, side by side

| Endpoint | `status` | `entrant.submissionStatus` |
|---|---|---|
| `/register` | `registered` | `pending` → becomes `confirmed` |
| `/resubmit` | `registered` \| `active` | `confirmed` |
| `/withdraw` | `withdrawn` | unchanged from before the withdrawal |

`status` is the entrant's standing on all three. `entrant` is nested on all three.

### `GET /api/competitions/{id}` → `viewer.snapshot`

Only ever your own. Another club's `submissionStatus` is not exposed — you do not need to
watch someone else's write land.

**`viewer.snapshot` is `null`** when you have no entry in this competition, and when your
entry is withdrawn. `viewer` itself is `null` for an unauthenticated read. So reach it
defensively — `viewer?.snapshot?.status` — rather than assuming it is there whenever
`viewer.relation` is set.

```jsonc
"viewer": {
  "relation": "registered",
  "snapshot": {
    "version": 1,
    "acceptedAt": "2031-10-11T00:00:00.000Z",
    "locksAt": "2031-10-11T18:00:00+00:00",
    "status": "confirmed",   // NEW
    "settled": true          // NEW — convenience: false only while "pending"
  }
}
```

## Values

| Value | Meaning | What the app should do |
|---|---|---|
| `pending` | Slot is held and the fee is paid, but the squad is still being written. | Show the entry as in-progress. Expect it to flip within a second. |
| `confirmed` | Squad stored. This is the steady state for every healthy registration. | Normal "you're in" UI. |
| `failed` | The write exhausted its retries. The slot is still held and the fee is still paid. | Offer **Withdraw** (full refund) or **Resubmit**. Don't auto-retry. |

`settled` is `true` for both `confirmed` and `failed`, and `false` only for `pending`. Use it
as the **stop-polling** signal, not as a success signal: polling will never move a `failed`
entry, because only an action changes it — see below. A client that kept polling `failed`
would poll forever.

## Rules

**`status` and `submissionStatus` are different axes — don't collapse them.** `status` is the
entry's standing in the competition (`registered` / `active` / `eliminated` / `withdrawn`).
`submissionStatus` is whether the payload that came with it has been stored. A club can be
`registered` + `pending`, and that is a normal state, not an error.

**`pending` is not a failure, and usually not even visible.** The queue is drained by an
always-on worker, so the realistic lifetime of `pending` is milliseconds. Treat a brief
`pending` as success, not as something to warn about. If you want a spinner, give it a delay
before it appears or it will flicker on every registration.

**Do not re-POST `/register` to resolve a `pending` or `failed` entry.** Re-registering does
not re-run the stored write — it creates a fresh reservation and a fresh queued write, which
on `failed` means the same payload failing the same way. Use one of the two actions below
instead.

**Treat an unknown value as `confirmed`.** If this list ever grows, the safe default for an
older build is to let the player through rather than block them out of a competition they are
actually in.

## What the player can do about `failed`

**The panel can offer actions, not just an explanation.** Both of these work on a `failed`
entry, and neither needs a backend operator:

| Action | Endpoint | Result |
|---|---|---|
| **Withdraw** | `POST /api/competitions/{id}/withdraw` | Slot released, **entry fee refunded in full**. Ordinary refund path — `failed` is not special-cased. |
| **Resubmit** | `POST /api/competitions/{id}/resubmit` | Writes the squad synchronously and clears the failure. On success `entrant.submissionStatus` is `confirmed` in the same response. |

Resubmit is the better first offer — it keeps the player in the competition. Withdraw is the
exit if resubmitting fails too.

**Why withdrawal is always available here:** withdrawal needs the competition to still be
open and the entry to still be `registered`. Locking a competition ends both at once — it
moves the competition on and flips every entry to `active` — so a competition that locked
while one entry was still unstored would trap that player with no way out.

The backend therefore will not lock while **any** entrant is unstored, even when every slot is
taken. A `failed` entry consequently *always* leaves its competition in a state where
withdrawal works, by construction rather than by luck.

The visible consequence: a competition can sit full without starting for a moment. That is the
mechanism working, not a stall. Don't show it as an error.

## Polling, if you want it

Only worth doing if you show a `pending` state at all. `GET /api/competitions/{id}` is served
from a prebuilt cache and supports `ETag`/`If-None-Match`, so a 304 is cheap — but
`viewer.snapshot.status` is composed fresh on every request and is therefore **not** covered by
the ETag. A 304 does not mean your status is unchanged.

Suggested: poll at most 3 times, ~1s apart, and stop on `settled: true`. Then stop. The window
this exists for is milliseconds; a client that polls hard for a minute is loading the server
for a state that resolved before the first poll returned.

## One thing worth knowing about draws

A competition will not **lock or draw** while any entrant is `pending` or `failed` — the squad
row is the match engine's only input, so starting early would resolve a real fixture against an
empty XI. In practice this is invisible, because storing takes milliseconds.

If a competition does sit full without starting, one entrant is unstored. If it's yours, the
two actions above resolve it. If it's someone else's, there is nothing for your client to do
and nothing to show as an error — it clears when they act or when the backend operator does.

## Not changing

- `status` on `/register`, `GET /{id}` and everywhere else — only `/resubmit`'s changed.
- The `201`/`200` response shape and codes for register/resubmit.
- `seed` — still `0` until the competition fills, exactly as before.
- Every other field on `viewer` and on entrant summaries.
- Other clubs' entrant objects in the bracket carry no `submissionStatus`.
