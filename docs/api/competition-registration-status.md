# Competition registration status — pending vs confirmed

**Audience:** `wunderkind-app`.
**Status:** backend shipped 2026-10-28. **Additive only — no existing field changed, no
existing value changed.** An app that ignores everything below keeps working exactly as it
does today.

---

## What changed on the backend, and why you now have a second status

Registration used to do all of its work inside one database transaction holding a lock on the
competition row: capacity check, entry fee, entrant insert, *and* the ~1.4MB squad/submission
write. Every club registering for the same competition queued behind one club's payload.

It now splits in two:

1. **Reserve** — under the lock: capacity check, entry fee, entrant row. ~1ms.
2. **Store** — outside the lock, on a queue: the squad and the verbatim submission.

**Your request still returns the same synchronous `201` it always did**, with the same body.
Nothing was removed and nothing became asynchronous from your side. But for a short window
after that 201 — normally milliseconds — the entry exists and holds its slot while its squad
is still being written. `submissionStatus` is how you can see that window.

## The field

Two places expose it. Both are additive.

### `POST /api/competitions/{id}/register` and `/resubmit` → `entrant.submissionStatus`

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

### `GET /api/competitions/{id}` → `viewer.snapshot`

Only ever your own. Another club's `submissionStatus` is not exposed — you do not need to
watch someone else's write land.

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
| `failed` | The write exhausted its retries. The slot is still held; a human has to resolve it. | Tell the player their entry needs attention. **Do not auto-retry** — see below. |

`settled` is `true` for both `confirmed` and `failed`, and `false` only for `pending`. Use it
as the "stop polling" signal: `failed` is terminal without operator action, so a client that
kept polling it would poll forever.

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
on `failed` means the same payload failing the same way while the player is charged nothing
extra but gains nothing either. `failed` needs a backend operator; surface it, don't retry it.

**Treat an unknown value as `confirmed`.** If this list ever grows, the safe default for an
older build is to let the player through rather than block them out of a competition they are
actually in.

## Polling, if you want it

Only worth doing if you show a `pending` state at all. `GET /api/competitions/{id}` is served
from a prebuilt cache and supports `ETag`/`If-None-Match`, so a 304 is cheap — but
`viewer.snapshot.status` is composed fresh on every request and is therefore **not** covered by
the ETag. A 304 does not mean your status is unchanged.

Suggested: poll at most 3 times, ~1s apart, and stop on `settled: true`. Then stop. The window
this exists for is milliseconds; a client that polls hard for a minute is loading the server
for a state that resolved before the first poll returned.

## One thing worth knowing about draws

A competition will not be drawn while **any** entrant is still `pending` — the squad row is the
match engine's only input, so drawing early would resolve a real fixture against an empty XI.
In practice this is invisible. But if a competition ever sits full without drawing, an entrant
stuck on `pending` or `failed` is the first thing to suspect, and that is a backend problem,
not something the app can fix.

## Not changing

- `status` values and meanings.
- The `201`/`200` response shape and codes for register/resubmit.
- `seed` — still `0` until the competition fills, exactly as before.
- Every other field on `viewer` and on entrant summaries.
- Other clubs' entrant objects in the bracket carry no `submissionStatus`.
