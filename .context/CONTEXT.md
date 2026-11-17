# Project Context

`wunderkind-backend` — Symfony 8.1 / PHP 8.5 / PostgreSQL 16 API + admin
back office. Full plain-language summary:
`stages/07_synthesis/output/overview.md`.

| If you need to know... | Read |
|---|---|
| Stack, language, dev environment (Lando/Docker), database | `shared/stack.md`, `stages/01_overview/output/environment.md` |
| Human says "pull prod DB" (or similar) — **run it now**, don't just describe it | `stages/01_overview/output/environment.md`'s "Developing against realistic data" section (`scripts/pull-prod-db.sh`) |
| Anything a human said up front that isn't in code | `stages/01_overview/output/tribal-knowledge.md` |
| Module boundaries, deployment wiring, config layout | `stages/02_architecture/output/structure.md` |
| Which areas change together / current hotspots | `stages/02_architecture/output/git-activity.md` |
| Tables & repository query patterns | `stages/03_data/output/schema.md` |
| Entities, fields, relationships | `stages/03_data/output/entities.md` |
| Migration history | `stages/03_data/output/migrations.md` |
| Client/in-memory state (there mostly isn't any — backend-only) | `stages/03_data/output/state.md` |
| **Changing ANY API response, request DTO or generated payload — read before writing the change** | `shared/api-backwards-compatibility.md` |
| **World packs, starter packs, `/api/initialize/*`, anything named `WorldPack*` or `StarterPack*` — read before touching any of it** | `stages/07_synthesis/output/architecture-notes.md`'s "World pack sets" section (the end-to-end account: what a set is, the TWO different generation paths and why, the claim, the buffer, and five traps) |
| How a player, staff member or scout gets a WAGE — and which population is priced when | `stages/01_overview/output/tribal-knowledge.md`'s "A shared-pool row is priced at SERVE time" ruling, then `stages/04_interfaces/output/services.md`'s "TWO POPULATIONS, TWO TREATMENTS" |
| Routes/endpoints (`/api/*` and `/admin/*`) | `stages/04_interfaces/output/routes.md` |
| What each controller does | `stages/04_interfaces/output/controllers.md` |
| What each service does | `stages/04_interfaces/output/services.md` |
| Push notification spec (FCM device tokens, payload contract per type) | `stages/04_interfaces/output/push-notifications.md` |
| Admin-panel design system (colors/typography/components) — read before writing any admin UI code | `stages/05_ui/output/design-system.md` |
| Index of existing markdown docs in this repo | `stages/06_documentation/output/index.md` |
| Cross-stage architectural connections | `stages/07_synthesis/output/architecture-notes.md` |
| What's actively being worked on | `stages/07_synthesis/output/current-focus.md` |

See `.agents/skills/icm-codebase-context/SKILL.md`'s Triggers table for
when to update a stage instead of just reading it, and for the staleness
check against `shared/last-sync.md`.
