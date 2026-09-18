# Git Activity — Hotspots & Coupling

Evidence: `git log --since="2 months ago" --name-only --pretty=format:` piped
through path-prefix counting, plus `git log --stat -15`/`--oneline -30`.

## Hotspot counts (path-prefix, last ~2 months)

```
163 public/screenshots
139 public/images
 58 tests/Service
 47 templates/landing
 44 Controller/Admin
 26 templates/admin
 24 Controller/Api
 23 tests/Controller
 14 Service/Competition
 13 Form/Type
 13 Entity/Competition
 13 .github/workflows
 12 CLAUDE.md
 10 tests/Command
 10 Repository/Competition
  9 docs/api
  9 config/packages
  8 tests/Repository / tests/Entity
  8 Service/SyncService.php / Entity/GameConfig.php
```

(`.context/stages` also appeared high in the raw count — that's this
tool's own prior housekeeping commits, not app activity, and is excluded
above.)

## What's actually changing together

Two active clusters, both confirmed by reading actual commits (not just
the aggregate counts):

1. **Admin panel polish** — `Controller/Admin/*CrudController.php` +
   `templates/admin/*` + their tests change together frequently (crash
   fixes, click-through fixes, menu ordering).
2. **New "Competition" feature (Phase 1 rollout)** — commits touch
   `Entity/Competition/`, `Repository/Competition/`, `Service/Competition/`,
   `Enum/Competition/`, `Dto/Competition/`, and their tests together, e.g.:
   - `9668eea` "Add Phase 1 competition framework: entities, enums,
     migration" — touches `migrations/Version20260916112510.php` + 5 files
     under `src/Entity/Competition/`.
   - `d1071bb` "Add competition eligibility, registration, and public API
     endpoints" — touches `config/packages/security.yaml`,
     `src/Controller/Api/CompetitionController.php`,
     `src/Dto/Competition/*`, `src/Repository/Competition/*` together.
   - `53c6fff` "Add round processor, match engines, and provisioning cron"
     — touches `Dockerfile`, `config/services.yaml`, new scripts under
     `docker/`, and `src/Command/CompetitionProcessRoundsCommand.php`
     together — i.e. a new background command is consistently
     accompanied by a Dockerfile cron-registration change and a
     `docker/*.sh` wrapper script.
   - Recent single-file bugfix commits (`e350125`, `1e7da60`, `0d99d1f`)
     pair a `Controller/Admin/*CrudController.php` or
     `Service/Competition/SnapshotValidator.php` change with its own test
     file in the same commit — this source+test pairing is visible across
     essentially all 15 commits inspected.

## Current focus

Per `git log --oneline -30`: a "Phase 1 competition framework" rollout
(entities → eligibility/registration API → round processing/match
engines/cron provisioning) plus ongoing admin-panel polish (crash fixes,
click-through, menu ordering).
