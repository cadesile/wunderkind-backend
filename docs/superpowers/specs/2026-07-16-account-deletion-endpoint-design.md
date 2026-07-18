# Account Deletion Endpoint — Design

**Date:** 2026-07-16
**Status:** Approved (design phase)

## Goal

Provide a self-service account deletion endpoint: an authenticated user can
permanently delete their own account and every club they own, along with all
data that depends on those clubs.

`POST /api/account/delete` — JWT resolves the caller; returns success or failure.

## Endpoint

- Route: `#[Route('/api/account/delete', name: 'api_account_delete', methods: ['POST'])]`
  on a new `App\Controller\Api\AccountController`.
- Auth: `#[IsGranted('ROLE_CLUB')]` (the standard game-client role). No request
  body. The caller is resolved with `$this->getUser()` — **JWT alone**, no
  password re-confirmation (per decision).
- Responses:
  - Success → `200` `{"success": true}`.
  - Failure (teardown threw) → `500` `{"success": false}`.
  - Invalid/expired/missing token → `401` (handled by the `api` firewall, not the
    controller).
- The controller is thin: resolve user → call `AccountDeletionService` → map to the
  JSON response. Mirrors the existing `ClubController` pattern (`getUser()`,
  `IsGranted('ROLE_CLUB')`, `#[Route]` attributes).

## `AccountDeletionService::deleteAccount(User $user): void`

Performs the full teardown inside **one transaction** (roll back on any error, so a
partial delete never persists).

### Why a service (not a bare `em->remove($user)`)

`User.clubs` is `cascade: ['remove']` and some `Club` collections cascade
(`syncRecords`, `leaderboardEntries`, `inboxMessages`); `transfer.club_id` is
`ON DELETE SET NULL`. But several entities reference `Club` with **no cascade** and
(some) **`NOT NULL`** FKs, so a naive `em->remove($user)` hits FK violations. Those
must be torn down explicitly first.

### Teardown order (per user)

For **each** club the user owns (`$user->getClubs()` — a user may own several):

1. Bulk-delete (DQL, executes immediately) the non-cascaded / blocking dependents:
   - `SeasonSnapshot` (`club_id NOT NULL`)
   - `SeasonRecord` (`club_id NOT NULL`)
   - `MatchResult` (`club_id NOT NULL`)
   - `Investor` (nullable FK, RESTRICT)
   - `Sponsor` (nullable FK, RESTRICT)
   - `SeasonRatingsSnapshot` — denormalized `club_id` **string** column (no FK, so
     it doesn't block, but it is the user's data → delete `WHERE clubId = (string) $club->getId()`).

Then, once every club's blocking dependents are gone:

2. `$em->remove($user)` → cascades to the user's clubs → cascades `SyncRecord`,
   `LeaderboardEntry`, `InboxMessage`; the DB `SET NULL`s `transfer.club_id` /
   `transfer.player_id`. **Transfer history is intentionally retained** (its FK is
   already `SET NULL` by design — leaderboard/history data).

3. `$em->flush()`; commit.

Use `$em->wrapInTransaction(fn () => ...)` (or begin/commit/rollback) so any failure
rolls the whole thing back.

### Completeness guard (implementation step)

The blocking-dependent list above was derived by reading the entities. Before
finalizing the service, verify it against the **authoritative** schema — every FK
whose referenced table is `club` (and `user`) and its `delete_rule`:

```sql
SELECT tc.table_name, kcu.column_name, rc.delete_rule
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
JOIN information_schema.referential_constraints rc ON tc.constraint_name = rc.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY' AND kcu.column_name IN ('club_id','user_id');
```

Any FK with `delete_rule = NO ACTION`/`RESTRICT` that isn't ORM-cascaded must be
added to step 1. (Run once the command classifier is available.)

## Token behaviour

JWT is stateless — the issued token stays syntactically valid until it expires. But
the `admin`/`api` user provider (Doctrine `EntityUserProvider`) re-fetches the user
by email on every request, so once the row is gone, subsequent calls with the old
token 401 automatically. No token blacklist / revocation is added.

## Out of scope

- Password re-confirmation (JWT alone, per decision).
- Soft delete / anonymization — this is a hard, permanent delete.
- Deleting pooled market entities (players/staff/agents/scouts) — those are not
  owned by a club (no club FK; pool lifecycle), so they're unaffected.

## Testing

- **Service functional test** (`WebTestCase`/`KernelTestCase`, uses the `wunderkind_test` DB):
  seed a `User` + `Club` with **one of each** dependent — `Investor`, `Sponsor`,
  `MatchResult`, `SeasonRecord`, `SeasonSnapshot`, `SeasonRatingsSnapshot`,
  `SyncRecord`, `LeaderboardEntry`, `InboxMessage`, and a `Transfer`. Call
  `deleteAccount($user)`. Assert: user gone, club gone, every FK-dependent gone;
  the `Transfer` row survives with `club_id` nulled. Also cover a user with **two**
  clubs.
- **Controller functional test**: authenticated `POST /api/account/delete` → `200`
  `{"success": true}` and the user is gone from the DB; unauthenticated → `401`.
- Reconcile the test DB if any new column/constraint is involved (none expected —
  no schema change).

## Files

- Create: `src/Controller/Api/AccountController.php`, `src/Service/AccountDeletionService.php`
- Create: `tests/Service/AccountDeletionServiceTest.php`, `tests/Controller/Api/AccountControllerTest.php`
- No entity/migration changes (deletion-only; no schema change).
