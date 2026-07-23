# docs/superpowers/specs/2026-07-16-account-deletion-endpoint-design.md

> Title: Account Deletion Endpoint — Design · 783 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Goal
- Endpoint
- `AccountDeletionService::deleteAccount(User $user): void`
-   Why a service (not a bare `em->remove($user)`)
-   Teardown order (per user)
-   Completeness guard — verified against the live schema
- Token behaviour
- Out of scope
- Testing
- Files

## Summary
This design doc specifies the `POST /api/account/delete` endpoint (JWT-only auth, no password re-confirmation) and its supporting `AccountDeletionService::deleteAccount()`, which explicitly deletes non-cascaded club dependents (`Investor`, `Sponsor`, `MatchResult`, `SeasonRecord`, `SeasonSnapshot`, `SeasonRatingsSnapshot`) before removing the user/clubs in one transaction, since several FKs lack cascade rules. An agent should read it before implementing account deletion, adding new `Club`-referencing entities (to update the teardown/completeness table), or writing the corresponding service/controller tests, as it documents the verified FK cascade map and why transfer history is intentionally retained via `SET NULL`.
