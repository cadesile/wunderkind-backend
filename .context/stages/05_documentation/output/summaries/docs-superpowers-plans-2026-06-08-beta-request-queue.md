# docs/superpowers/plans/2026-06-08-beta-request-queue.md

> Title: Beta Request Queue Implementation Plan · 3081 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- File Map
- Task 1: BetaRequest Entity + Repository
- Task 2: Migration
- Task 3: Email sending for beta verification
- Task 4: Replace BetaRequestController
- Task 5: Security — expose /api/beta-request/verify publicly
- Task 6: Admin CRUD — BetaRequestCrudController
- Task 7: Landing page — two-step beta modal
- Self-Review
-   Spec Coverage
-   Type Consistency Check
-   No Placeholders

## Summary
This is a plan for adding a two-step verified opt-in beta request queue (email → 6-digit code → verification), replacing a one-shot email flow, backed by a new standalone `BetaRequest` Doctrine entity and a read-only EasyAdmin CRUD view. It covers new entity/repository/controller files, mailer service changes, security config, a DB migration, and a two-step JS modal on the landing page.

An agent should read this before implementing beta-signup or admin-panel work in this Symfony app — it's meant to be executed task-by-task via the `superpowers:subagent-driven-development` or `superpowers:executing-plans` skill, following the checkbox steps in order.
