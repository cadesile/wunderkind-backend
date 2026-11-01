# `ROLE_CLUB` 403s (client integration)

Answers `wunderkind-app/docs/backend-notes/2026-11-01-role-club-403.md`. Two separate
causes, both fixed backend-side on `development`; **no client change is required for
either**, and no endpoint changes shape.

## 1. An authenticated account was refused everywhere

**Symptom:** login succeeds, then every `ROLE_CLUB` endpoint answers
`403 {"error":"forbidden","detail":"Access Denied. The user doesn't have ROLE_CLUB."}`.

**Cause:** the role was read out of `user.roles`, and that column has twice held something
else:

| Account registered | `user.roles` stored | Result |
|---|---|---|
| before 2026-03 | `[]` | 403 on every `ROLE_CLUB` route |
| 2026-03 → 2026-04 | `["ROLE_ACADEMY"]` (role since deleted) | 403 on every `ROLE_CLUB` route |
| since 2026-04 | `["ROLE_CLUB"]` | fine |

There is no `role_hierarchy` in `security.yaml`, so neither legacy value granted anything,
and `User::getRoles()` did not append a baseline role the way Symfony's own convention
does. Login is gated on `isVerified()`, not on roles, which is why such an account
authenticates and gets a valid JWT and then cannot use it.

**Fix:** `User::getRoles()` now guarantees `ROLE_CLUB` instead of reading it — the same way
`Admin::getRoles()` hardcodes `ROLE_ADMIN`. A `User` row *is* a game account (admins are a
separate entity behind a separate firewall), so the stored array never carried information
that could withdraw it. Additive: anything else stored is kept alongside.

Every affected account is fixed by deploying, with no backfill and no re-registration — the
role is no longer per-row data that can be stale. A JWT minted before the deploy carries the
old role list in its payload, but roles are resolved from the user provider on each request,
so an existing token starts working too; the client does not need to re-authenticate.

**Not yet answered:** how the specific guest account in the report came to be stored that
way. Guest registration has granted `ROLE_CLUB` since the domain was introduced
(2026-08), which is later than both legacy shapes above — so that account looks like it
predates its own email domain, or was written by something not in this repo's history. The
lockout no longer depends on the answer, but it is worth knowing; see the queries at the
bottom.

## 2. `GET /api/club/check` was gated on `ROLE_CLUB`

Agreed and fixed as suggested. The endpoint is now **authentication-only**:

```
GET /api/club/check
Authorization: Bearer <JWT>   (any authenticated account — no role required)
```

| Case | Response |
|---|---|
| account has a club | `200 {"exists": true, "clubId": "0199..."}` |
| account has no club | `404 {"exists": false, "reason": "club_not_found"}` |
| `X-Club-Id` names a club this account does not own, or no club at all | `404 {"exists": false, "reason": "club_not_owned"}` |
| no / invalid token | `401` |

That third row is a second, separate fix, and worth knowing about because your fault #3
(`registerClub` building a world around a club the server never created) is what produces the
state that reaches it. `ClubResolver` throws `ClubMismatchException` — an
`AccessDeniedHttpException`, so **403** — for an `X-Club-Id` it cannot resolve to one of your
clubs. On every other endpoint that is right: it stops a write landing on a different save's
club. On `/check` it handed you the same unreadable 403 all over again, for a client holding
nothing worse than a stale id. So `/check` alone catches it and answers `404`.

`reason` is additive and you can keep ignoring it — status is still the whole contract — but
`club_not_owned` specifically means *the id you sent is not yours*, so it is the signal to
clear the stored club id rather than retry. It discloses nothing: the resolver throws
identically whether the id is unknown or belongs to someone else, so the two cannot be told
apart from the response either.

A `403` is no longer reachable on this endpoint at all. The 404 branch existed all along but was unreachable
for exactly the callers that needed it, since Symfony denies before the controller runs.
`src/__tests__/api/checkClub.test.ts`'s predicate — 404 means `{exists: false}`, anything
else rethrows — is now correct as written, for both 404 rows above.

Both gates had to be relaxed, which is worth knowing if this is ever revisited: the
`#[IsGranted]` attribute on the method **and** the `^/api/club/` entry in
`access_control`, which matches `/api/club/check` and would have kept denying on its own.
The new `^/api/club/check$` entry sits ahead of it (first match wins).

Scope is `/check` only. `AdminMessageController`, the tournament endpoints and the rest of
`/api/club/*` still require `ROLE_CLUB`, which is correct — they are about a club, not about
whether one exists. They answered 403 for cause 1, and cause 1 is fixed.

## Checking how widespread cause 1 was

Not reachable from this repo's test suite — it is a question about deployed data. Against a
deployed database:

```sql
-- Accounts that were locked out, by stored shape.
SELECT roles, count(*) FROM "user" GROUP BY roles ORDER BY 2 DESC;

-- The account in the report.
SELECT id, email, roles, is_verified, created_at FROM "user"
WHERE email = 'guest-01a19815-92b1-7d43-ad36-1676666c4db9@guest.buildmyclub.local';

-- Whether any of them is a guest account, which would mean a creation path
-- outside this repo's history.
SELECT roles, count(*) FROM "user"
WHERE email LIKE '%@guest.buildmyclub.local' AND roles::jsonb <> '["ROLE_CLUB"]'::jsonb
GROUP BY roles;
```

A row returned by the first query is now cosmetic rather than broken, so this is diagnosis,
not remediation. Leaving the column as-is is deliberate: an `UPDATE` would hide how the data
got that way, and the role no longer depends on it.
