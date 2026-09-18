# Cross-Stage Architectural Notes

Connections backed by content actually present in the stages they link.

- **API Platform is installed but unused.** `composer.json` requires
  `api-platform/core`, and `config/packages/api_platform.yaml` mounts it
  at `/api` — but no entity carries `#[ApiResource]`
  (`.context/stages/04_interfaces/output/routes.md`), so it generates no
  routes. Every real `/api/*` route is a hand-written controller sharing
  that prefix. Anyone tempted to add a quick CRUD endpoint via
  `#[ApiResource]` should know it would be the *first* one in the repo —
  worth a deliberate decision, not a reflexive shortcut.
- **The Competition feature was built in one atomic push, and it shows
  in both the schema and the git history.** Migration
  `Version20260916112510`
  (`.context/stages/03_data/output/migrations.md`) created the entire
  Competition table set in a single migration, and
  `.context/stages/02_architecture/output/git-activity.md` shows commits
  consistently touching `Entity/Competition/`, `Repository/Competition/`,
  `Service/Competition/`, and their tests together — this is a
  cleanly-bounded module, not one that grew organically across many
  small migrations like most of the rest of the schema.
- **A recurring "new background job → Dockerfile change" pairing.**
  `git-activity.md` notes that adding a new `app:*` console command is
  consistently paired with a Dockerfile cron-table change and a new
  `docker/*.sh` wrapper script in the same commit (e.g. commit `53c6fff`
  adding `CompetitionProcessRoundsCommand`). If you add a new scheduled
  command, follow that same three-part pattern rather than relying on
  Symfony's own scheduler.
- **Admin panel customization goes deeper than most Symfony/EasyAdmin
  apps.** `.context/stages/05_ui/output/design-system.md`'s themed
  palette/typography and the custom compound-field widget
  (`appearance_theme.html.twig` + `avatar-compositor.js`) are wired
  through `Controller/Admin/DashboardController.php` and
  `PlayerCrudController`/`ScoutCrudController`/`StaffCrudController` —
  exactly the controllers `.context/stages/02_architecture/output/git-activity.md`
  flags as one of the two active hotspot clusters. Admin-panel changes
  in this repo are as much UI work as backend work.
- **Two independent, cross-repo "must stay bit-identical" invariants.**
  `.context/stages/04_interfaces/output/services.md` flags
  `Appearance/SeededRng` (must match the client's `SeededRng`) and
  `ClubNameNormalizer` (must match the client's `clubName.ts`). Neither
  is enforced by a shared package or test in this repo — changing either
  without checking the frontend repo would silently desync client/server
  appearance or name validation.
- **`SyncService` overwrites, not accumulates, club balance.**
  (`services.md`) A client resync applies `Club::setBalance()` as an
  absolute value. Combined with `SyncRecord.isRollback` tracking
  (`entities.md`) and `SyncRecordRepository::countRollbacksByClub`
  (`schema.md`), this is the backend's whole anti-cheat model for the
  client-authoritative sync design the root `README.md` describes — it's
  implemented as "detect and record," not "prevent."

## Flagged, not yet resolved

`.context/stages/05_ui/output/design-system.md` flags a remote script
(`perf-analytics.lndo.site/widget/...js`) loaded on every admin page via
`templates/bundles/EasyAdminBundle/layout.html.twig`. `.lndo.site` is
normally Lando-local-only, so this being checked into a template that
ships to production is unresolved and worth a human decision — noted
here again since it's the kind of thing a future agent touching the
admin layout should see before assuming it's intentional.
