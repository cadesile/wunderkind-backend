# docs/superpowers/plans/2026-06-21-remove-player-staff-club-fk.md

> Title: Remove Player/Staff Club FK Implementation Plan · 6209 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Global Constraints
-   Task 1: Entity layer — Player, Staff, Club, PlayerGenerationService
-   Task 2: Repository cleanup — PlayerRepository, StaffRepository
-   Task 3: StarterPackService — consume pool entities on assign
-   Task 4: MarketPoolService — assignToClub() deletes Player/Staff, returns snapshot
-   Task 5: EconomicService + SyncService cleanup
-   Task 6: Controller cleanup — delete SquadController, StaffController; update ClubController and MarketController
-   Task 7: Admin dashboard + commands cleanup
-   Final verification

## Summary
This plan describes removing the `club_id` FK from the Player and Staff entities in a Symfony/Doctrine PHP project, replacing club assignment with an "assign-and-remove" pattern: pool entities are deleted from the DB on consumption (via `POST /api/market/assign` or starter pack init), with a snapshot array returned to the frontend instead of maintaining a persistent Club→Player/Staff relationship. This eliminates Doctrine cascade issues by removing the `Club.players`/`Club.staff` OneToMany collections entirely.

An agent should read this file when implementing or reviewing work on Player/Staff/Club entity relationships, market assignment logic, or Doctrine cascade bugs in this codebase — it's a task-by-task TDD plan (starting with a failing PHPUnit test in `tests/Entity/PoolEntityTest.php`) meant to be executed via the `superpowers:subagent-driven-development` or `superpowers:executing-plans` skill, on branch `feat/remove-player-staff-club-fk`. Note Sponsor/Investor/Scout/Transfer entities are explicitly out of scope.
