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
  palette/typography and the custom compound-field widgets
  (`appearance_theme.html.twig` + `appearance-widget.js` +
  `avatar-compositor.js`; and the sibling `kit_identity_theme.html.twig` +
  `kit-identity-widget.js` + `kit-compositor.js` for NpcClub kit/badge) are
  wired through `Controller/Admin/DashboardController.php` and
  `PlayerCrudController`/`ScoutCrudController`/`StaffCrudController`/
  `AgentCrudController`/`NpcClubCrudController` — exactly the controllers
  `.context/stages/02_architecture/output/git-activity.md` flags as one of
  the two active hotspot clusters. Admin-panel changes in this repo are as
  much UI work as backend work.
- **One remaining cross-repo "must stay bit-identical" invariant: `ClubNameNormalizer`**
  (must match the client's `clubName.ts` — not enforced by a shared package
  or test, changing it without checking the frontend repo would silently
  desync client/server name validation). The Appearance generator used to
  carry a second such invariant against a frontend `SeededRng`/
  `generateAppearance`; the pixel-sprite rewrite retired it — `sprite.ts`/
  `PlayerSprite.tsx` in `wunderkind-app` are pure rendering with no
  generation/RNG code, so there is currently no frontend counterpart for
  `AppearanceGeneratorService` to stay bit-identical with.
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

## Authority: the device owns club state, the backend owns the world

The single most load-bearing boundary in this codebase, and the easiest to
misread from the schema alone.

**Device-owned, mirrored into the backend by `POST /api/sync` only.** A club's
`balance`, `totalCareerEarnings`, `reputation`, squad, staff, facilities and
weekly progression. `SyncService::process()` assigns these from the request
absolutely rather than applying deltas, so the backend's copy is a snapshot of
what a device last reported — never a value the backend computed or can repair.
Two consequences:

- A server-side write to a mirrored field is **transient**. It is replaced on
  the next sync. Anything that must stick has to be enforced on the device.
- Deleting backend club-scoped rows loses history and derived artefacts, **not**
  the player's game.

**Backend-owned.** World data (leagues, NPC clubs, market pools, world packs),
cross-club aggregates no single device could compute (`LeaderboardEntry`,
`UserLedger`, `SeasonRecord`, transfer leaderboards), operator-authored content
(`AdminMessage`, narrative templates, excursions), and competition/friendly
*structure* — brackets, fixtures, results, rounds. Note the split inside
competitions: the backend owns the bracket and resolves matches, but each
entrant's submitted squad is a client snapshot it stores verbatim and never
recomputes.

**Why this is stated here rather than inferred:** during the 2026-10-28
tournament work a code review flagged that truncating competition entrants
destroyed entry fees "already deducted from `club.balance`", and that was
carried as a release blocker until the human pointed out that club balance only
matters on the device. The reasoning looked sound and was wrong at the premise —
a `balance` column in this schema does not imply the backend owns that balance.
Check which side of this boundary a field sits on before reasoning about losing
or correcting it.
