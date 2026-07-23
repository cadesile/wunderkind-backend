# docs/superpowers/plans/2026-04-14-npc-club-generation.md

> Title: NPC Club Generation — Implementation Plan · 6051 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- File Map
- Task 1: StaffRole + RecruitmentSource enum additions
- Task 2: PoolConfig senior player fields
- Task 3: NpcClub entity + repository
- Task 4: Database migration
- Task 5: PlayerRepository senior pool helpers
- Task 6: MarketPoolService — generateSeniorPlayers + replenishment
- Task 7: NpcClubGenerationService
- Task 8: POST /api/market/consume endpoint
- Task 9: NpcClubCrudController
- Task 10: DashboardController — NPC club routes + menu
- Task 11: pool_config.html.twig — senior player fields
- Task 12: npc_clubs_content.html.twig
- Task 13: Full test suite run + verification
- Self-Review Against Spec

## Summary
This documentation file is an implementation plan for "NPC Club Generation" (Spec A of a Club Sim expansion) in a Symfony/Doctrine/PostgreSQL backend, covering NPC club persistence as pure metadata, a shared senior/youth player pool, and a new `POST /api/market/consume` endpoint for frontend hard-deletion of claimed entities. It's a task-by-task TDD checklist (write failing test → verify failure → implement → repeat) intended to be executed via the `superpowers:subagent-driven-development` or `superpowers:executing-plans` skill, with a file map enumerating every file to create/modify (enums, entities, repositories, services, controllers, migrations, templates, tests). An agent should read this file when asked to implement, resume, or review progress on the NPC club/senior-pool/consume-endpoint feature, since it contains the exact code snippets and step ordering to follow.
