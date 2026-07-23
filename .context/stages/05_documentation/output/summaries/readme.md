# README.md

> Title: The Wunderkind Factory — Backend · 1286 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Project Overview
- Tech Stack
- Architecture: The Hybrid Model
-   Request Flow — `POST /api/sync`
- Local Development
-   Prerequisites
-   Setup
-   Useful Commands
-   Environment Variables
- API Endpoints
- Admin Panel
- Source Layout
- Entities
- Security
- Key Gotchas
- Repositories

## Summary
This README describes the backend for "The Wunderkind Factory," a Symfony/API Platform mobile game server using a client-authoritative, async sync architecture (game logic runs on-device, server handles legacy stats sync, anti-cheat validation, and leaderboards). An agent should read it when working on backend code — it covers the tech stack (Symfony 8/PHP 8.4/PostgreSQL 16/Doctrine), the `/api/sync` request flow, Lando-based local dev setup commands, the full API endpoint table, EasyAdmin panel features, and the `src/` directory layout — making it the primary orientation doc before touching entities, controllers, services, or admin config in this repo.
