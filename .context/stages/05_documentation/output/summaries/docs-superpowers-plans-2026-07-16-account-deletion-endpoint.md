# docs/superpowers/plans/2026-07-16-account-deletion-endpoint.md

> Title: Account Deletion Endpoint Implementation Plan · 1435 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: AccountDeletionService
-   Task 2: AccountController endpoint
-   Task 3: Docs + full-suite check
- Self-Review Notes

## Summary
This documentation is a step-by-step implementation plan (with subskill directives, PHPUnit test code, and task checklists) for adding a `POST /api/account/delete` endpoint in a Symfony/Doctrine app, covering an `AccountController` + `AccountDeletionService` that deletes a user, their clubs, and five specific non-cascaded FK dependents (SeasonSnapshot, SeasonRecord, MatchResult, Investor, Sponsor) inside one transaction, with JWT-only auth and no schema migrations. An agent should read it when asked to implement, resume, or review the account-deletion feature, since it defines exact task order, file paths, required tests, and hard constraints (branch name, Lando command prefix, no password re-confirmation, response codes).
