# `GET /api/competitions/{id}`: every bracket round now carries `roundId`

**Date:** 2026-10-31
**Status:** shipped. Additive — nothing was removed or renamed.

## What changed

Each entry in `rounds[]` gained one key:

```
roundIndex, roundId, key, label, status, drawAt, scheduledAt, startedAt,
kickoffAt, matchesResolveAt, fixtures
```

`roundId` is the `CompetitionRound` UUID as a string, and **`null` on a `future` round** —
those are placeholders for rounds that have no row yet, so there is no id to give. The key is
always present either way, so a client can read it without checking `status` first.

## Why

`ROUND_DRAWN` and `ROUND_RESOLVING_SOON` pushes carry `competitionId` and `roundId` and **no
fixture id**, so `roundId` was the only handle that could map one of those notifications to a
round in the bracket — and the bracket did not expose it. A client resolving
`rounds.find(r => r.roundId === push.roundId)` therefore always missed, and anything falling
back to the last round rendered the **final** for every round notification.

There is no client-side workaround: neither push carries a `fixtureId` to work back from.

## Server-side note for whoever changes this document next

The response is prebuilt into `competition_response_cache` and ETag-validated, both keyed on
`CompetitionResponseBuilder::computeStateFingerprint()`. A new key is **not** a state change,
so adding one invalidates nothing on its own: every stored row and every client ETag keeps
validating, and a COMPLETED competition's state never changes again, so its clients would be
served the old shape forever.

`DOCUMENT_SCHEMA_VERSION` on that builder exists for exactly this, and is part of the
fingerprint. **Bump it in the same commit as any change to the document's shape.**

(The same change fixed `computeEtag()` formatting that 32-character hex fingerprint with
`%d`, which parsed only its leading decimal run — 42% of fingerprints collapsed to `0`, and
roughly one real state change in five did not move the ETag at all, so a polling client got a
`304` with the previous bracket behind it. It is `%s` now, and
`CompetitionResponseCacheTest::testTheEtagMovesWhenTheFingerprintDoes` pins it.)
