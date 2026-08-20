# CLAUDE.md

> Title: CLAUDE.md · 3165 words · parsed 2026-08-20T22:28:10.476Z

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
This project's CLAUDE.md is a build/environment guide, not a task requiring skills — it's a Lando-based PHP/Symfony backend for a game called "Wunderkind." Direct summary:

This file documents the backend for a mobile game's hybrid client-server architecture: gameplay logic runs on-device, while the Symfony/PostgreSQL API only handles club sync, anti-cheat, leaderboards, and serving world/market data. It also notes a critical footgun — the functional-test database (`wunderkind_test`) is built via `schema:create` rather than migrations, so it drifts from the dev DB and needs manual reconciliation. An AI agent should read this before running any PHP command (all must go through `lando`), writing/running tests, or touching the Player/Staff "pool" model, where entities have no club FK and are deleted from the DB the moment they're assigned to a club.
