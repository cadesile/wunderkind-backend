# wunderkind-backend — Project Context (.context)

> Generated: 2026-07-21T21:51:10.187Z · Stack: symfony 8.0 · PHP 8.4 · postgres:16 · Generator: v2.0.0 (514a55f)

This folder is an **ICM (Interpretable Context Methodology)** context structure
(https://arxiv.org/html/2603.16021v2): numbered stages, each with a CONTEXT.md
contract (Inputs / Process / Outputs) and an output/ folder of focused markdown.

## How to use this folder (for agents)

1. Read this router.
2. Pick the stages relevant to your task from the index below (numbering = recommended reading order).
3. Read each chosen stage's CONTEXT.md, then load only the output files you need.
4. Do not load every file — the structure exists so you can scope your context.
5. Check the extraction provenance table below before trusting a section — some
   outputs come from a static best-effort scan rather than a live, resolved
   source, and that changes how much weight to give them.

Unresolved: see `KNOWLEDGE_GAPS.md` for open questions this generation run couldn't answer from the code alone.

Regenerate with: `node generate_project_context.js`. Ignore rules live in
`_config/ignore`; the parse ledger and per-stage extraction provenance in
`_config/manifest.json`.

## Stage index

| Stage | Purpose | Output files |
|---|---|---|
| `stages/01_overview/` | Stack, environment, metrics | `output/stack.md` (1081b), `output/environment.md` (552b), `output/metrics.md` (126b) |
| `stages/02_architecture/` | Structure and git activity | `output/structure.md` (711b), `output/git-activity.md` (1688b) |
| `stages/03_data/` | Schema, entities, state, migrations | `output/schema.md` (5980b), `output/entities.md` (47396b), `output/migrations.md` (470b) |
| `stages/04_interfaces/` | Routes, controllers, services, API spec | `output/routes.md` (7856b), `output/controllers.md` (21563b), `output/services.md` (10277b) |
| `stages/05_documentation/` | Markdown docs index and digests | `output/index.md` (6908b), `output/summaries/claude-skills-ui-ux-pro-max-skill.md` (2332b), `output/summaries/claude.md` (1241b), `output/summaries/readme.md` (1090b), `output/summaries/docs-api-account-delete.md` (858b), `output/summaries/docs-api-community-stats.md` (1135b), `output/summaries/docs-event-guide.md` (1026b), `output/summaries/docs-frontend-integration.md` (2545b), `output/summaries/docs-frontend-spec-player-physical-personality.md` (1216b), `output/summaries/docs-superpowers-plans-2026-04-14-npc-club-generation.md` (1708b), `output/summaries/docs-superpowers-plans-2026-04-18-admin-starter-config-league-ability-ranges.md` (1394b), `output/summaries/docs-superpowers-plans-2026-05-12-initialize-endpoint-redesign.md` (1410b), `output/summaries/docs-superpowers-plans-2026-05-18-worldpack-cache-admin.md` (1320b), `output/summaries/docs-superpowers-plans-2026-06-08-beta-request-queue.md` (1245b), `output/summaries/docs-superpowers-plans-2026-06-18-player-generation-pipeline.md` (1340b), `output/summaries/docs-superpowers-plans-2026-06-21-club-init-pool-prewarm.md` (1077b), `output/summaries/docs-superpowers-plans-2026-06-21-remove-player-staff-club-fk.md` (1794b), `output/summaries/docs-superpowers-plans-2026-07-05-community-stats-landing-page.md` (1562b), `output/summaries/docs-superpowers-plans-2026-07-05-social-post-templates-and-cron.md` (2098b), `output/summaries/docs-superpowers-plans-2026-07-06-club-name-options-canonical-source.md` (1546b), `output/summaries/docs-superpowers-plans-2026-07-13-backend-appearance.md` (1796b), `output/summaries/docs-superpowers-plans-2026-07-16-account-deletion-endpoint.md` (1078b), `output/summaries/docs-superpowers-specs-2026-04-14-npc-club-generation-design.md` (1560b), `output/summaries/docs-superpowers-specs-2026-05-12-initialize-endpoint-redesign.md` (1630b), `output/summaries/docs-superpowers-specs-2026-06-18-player-generation-pipeline-design.md` (1682b), `output/summaries/docs-superpowers-specs-2026-06-21-club-init-pool-prewarm-design.md` (1100b), `output/summaries/docs-superpowers-specs-2026-06-21-remove-player-staff-club-fk-design.md` (1495b), `output/summaries/docs-superpowers-specs-2026-07-05-community-stats-landing-page-design.md` (1310b), `output/summaries/docs-superpowers-specs-2026-07-06-club-name-options-canonical-source-design.md` (1378b), `output/summaries/docs-superpowers-specs-2026-07-13-backend-appearance-design.md` (1532b), `output/summaries/docs-superpowers-specs-2026-07-16-account-deletion-endpoint-design.md` (1187b), `output/summaries/docs-wunderkind-backend-context.md` (5033b), `output/summaries/migrations-archive-readme.md` (611b), `output/summaries/scripts-global-context-generator-readme.md` (1196b) |
| `stages/06_synthesis/` | AI overview, architecture notes, focus | `output/overview.md` (634b), `output/architecture-notes.md` (1205b), `output/current-focus.md` (1492b) |

## Extraction provenance

| Output | Method |
|---|---|
| `03_data/schema.md` | static-regex-scan |
| `03_data/entities.md` | static-regex-scan |
| `03_data/migrations.md` | static-regex-scan |
| `04_interfaces/routes.md` | static-regex-fallback (live debug:router/route:list not attempted by this generator) |
| `04_interfaces/controllers.md` | static-regex-scan |
| `04_interfaces/services.md` | static-regex-scan |
| `04_interfaces/api-spec.md` | unavailable |
