# CLAUDE.md

> Title: CLAUDE.md · 3030 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Project Context
- Dev Environment
- Common Commands
- Testing
- Git Workflow
- Architecture
-   The Hybrid Model
-   Pool Lifecycle (Player + Staff)
-   Request Flow (POST /api/sync)
-   World Initialization Flow
-   Avatar Appearance (backend-owned)
-   Player↔Agent Association (world pack)
-   Two Firewalls
-   EasyAdmin Custom Routes
-   Key Gotchas
- Source Layout
- API Endpoints
- Key Services
- Key Entities (non-obvious fields)

## Summary
This CLAUDE.md documents a Symfony/PHP backend for a football management game with a hybrid client-server architecture — gameplay runs on-device, while the server handles club sync, anti-cheat, leaderboards, and world data. An agent should read it before running any dev commands (all PHP work happens inside Lando containers), before writing or debugging tests (especially functional tests, which hit a separate `wunderkind_test` DB prone to schema drift), and before modifying entities like Player/Staff, since the pool lifecycle model (no club FK, deleted-on-assign) is non-obvious from code alone. It also sets git workflow conventions (feature branches off `master`, no direct commits).
