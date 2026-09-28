# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

- **Commit:** (uncommitted at writing, on top of 37110e2 — this pass's own
  changes pending the user's usual explicit commit request)
- **Date:** 2026-09-28
- **Stages touched this pass:** 03_data, 04_interfaces (targeted fix + doc
  pass, not a full stage regen — `.context/` was ~28 commits stale going
  into this session per the staleness check, including two admin
  club-profile commits (`2cb67c4`, `aa07cb9`) and the landing-page
  Boardroom Incident Feed (`0ebf4e4`) that `04_interfaces/output/
  controllers.md` still doesn't document; flagged to the user as a
  follow-up rather than regenerated unprompted). Fixed a real financial
  display bug found via user-supplied evidence (in-game ledger screen vs.
  admin club profile vs. raw sync payload for the same events, all three
  compared side by side): `payload.ledger[].amount` is 100x true pence, not
  real pence as every consumer assumed — confirmed by cross-referencing
  `excursions[].costPence` against its matching `fan_initiative` ledger
  line (always exactly 100x). Added `LiveTelemetryService::
  ledgerAmountToPence()` (corrects `capitalDeployedPence` and
  `buildLedgerEvents()`'s `amountPence`) and the `ledgerCurrency()` Twig
  macro (corrects `templates/admin/club_profile.html.twig`'s Ledger table,
  which previously ran raw `entry.amount` through the pence-only `currency()`
  macro). `LiveTelemetryServiceTest` updated for the corrected values (44
  tests green). Documented the convention in `03_data/output/schema.md`
  (new "`sync_record.payload` field conventions" section) and linked it
  from `entities.md`'s `SyncRecord` bullet and `04_interfaces/output/
  services.md`'s `LiveTelemetryService` bullet.
- **Stages touched this pass:** 03_data, 04_interfaces (added owner identity
  — `name`/`nationality`/`gender`/`dob`/`appearance` — to `User`, replacing
  the redundant `User::$managerProfile`/`Club::$managerProfile` blobs;
  new `AppearanceRole::OWNER` case wired into `AppearanceLifecycleSubscriber`
  and `app:backfill-appearances`; new `POST /api/owner-avatar` partial-update
  endpoint; `UserCrudController` EDIT enabled with an Owner Identity
  fieldset + the appearance widget; migration `Version20260927120000`)

---

- **Commit:** 9a5f72f34f7e02b092413285b761463ed08bb91c (as of writing — this pass's own changes are uncommitted on top of it, pending the user's usual explicit commit request)
- **Date:** 2026-09-20
- **Stages touched this pass:** 04_interfaces (new `push-notifications.md`, moved in from `docs/api/push-notifications.md` by explicit request — that file has been deleted; also documents the new `MATCH_RESULT` push type, fired per-fixture on `CompetitionRoundProcessorService::resolveFixture()`, to both sides), 06_documentation (index updated to point at the new location), 07_synthesis (current-focus.md repointed). `01_overview`/`02_architecture`/`05_ui` untouched this pass.
- **Follow-up pass, same session (2026-09-20):** notification observability + admin debug
  tooling, prompted by a real incident (missing `DEV_FIREBASE_SERVICE_ACCOUNT_JSON`/
  `PROD_FIREBASE_SERVICE_ACCOUNT_JSON` GitHub secrets caused push sends to fail silently, with
  zero trace, because `config/packages/messenger.yaml` had no `failure_transport`). Added:
  `NotificationLog` entity/table + `NotificationLogStatus` enum (`03_data/output/entities.md`,
  `schema.md`, `migrations.md` — migration `Version20260920075918`), `NotificationLoggingSubscriber`
  (Messenger `WorkerMessageHandledEvent`/`WorkerMessageFailedEvent` listener — the single place
  that uniformly catches both handler-body and handler-construction failures),
  `NotificationLogCrudController` (read-only), `NotificationDebugController` (force-trigger all 4
  push types at a chosen club, force-process the queue, validate the Firebase connection via new
  `FirebaseConnectionValidator`), and enabled `failure_transport: failed` in
  `messenger.yaml` (reuses the `messenger_messages` table via `queue_name`, no new migration for
  that part). Updated `04_interfaces/output/routes.md`, `controllers.md`, `services.md`. Full test
  suite green (859 tests) as of this pass, including new coverage:
  `NotificationLoggingSubscriberTest`, `NotificationDebugControllerTest`,
  `FirebaseConnectionValidatorTest`.
- **New pass (2026-09-22), on top of commit f770b15a518685a89b788ca1e05c046e3a3dae82 (this pass's own changes are uncommitted on top of it):** decoupled the competition round lifecycle's draw and resolve phases (previously fused into one `CompetitionRoundProcessorService::processRound()` pass) into two independently-scheduled, independently-claimed steps, per an explicit refactor request. `CompetitionRoundStatus` collapsed to `DRAW_PENDING|DRAWN|RESULTS_PUBLISHED|CANCELLED`; `CompetitionRound` gained `matchesResolveAt` and split its single claim column into `drawLockedAt`/`resolveLockedAt` (migration `Version20260922095155`, includes a data-only status-value remap); `CompetitionTemplate` gained `intermissionRatio` (default 0.3). New pure `CompetitionScheduleCalculator` service; `CompetitionRoundProcessorService` deleted and replaced by `CompetitionDrawService` + `CompetitionResultsService`, each behind its own new command/cron entry (`app:competition:draw-rounds`/`app:competition:resolve-rounds`, both every 1 min, replacing `app:competition:process-rounds`). Round 1 no longer draws synchronously at lock time — `CompetitionLockService` now only schedules it (making `ActiveCompetitionStatus::SCHEDULED` a real, observable window for the first time). `CompetitionRoundReminderService` repurposed around the resolve phase (`ROUND_RESOLVING_SOON` on a `DRAWN` round nearing `matchesResolveAt`, not `ROUND_STARTING_SOON` before a draw). Added an admin "Force Draw" action (`CompetitionDrawService::forceDrawRound()`) alongside the existing "Generate Result" one. Updated `03_data/output/{entities,schema,migrations}.md`, `04_interfaces/output/{services,push-notifications}.md`, `02_architecture/output/structure.md` (cron table). Full Competition-area test suite green (273 tests, 2473 assertions) except one pre-existing, unrelated failure (`RewardApplierServiceTest::testApplyingTwiceDoesNotDoubleGrantTheReward` — confirmed failing on the pre-refactor baseline too, not touched by this pass). New test coverage: `CompetitionScheduleCalculatorTest`, `CompetitionLockServiceTest`, `CompetitionDrawServiceTest`, `CompetitionResultsServiceTest`, `CompetitionLifecycleE2ETest`.
- **Merge pass (2026-09-27):** merged `development` (owner identity, NpcClub kit+badge
  identity rebuild, Facility Manager/DOF/scout staff config) into `competition` ahead of a
  planned `master` merge. Resolved a real conflict in `NpcClubGenerationService.php`: `development`
  replaced the old `pickColorPair()`/`contrastRatio()` color-pair generator with the kit+badge
  `generateIdentity()` shape, but `competition`'s own `CompetitionSpoofEntrantService` still calls
  `pickColorPair()` for synthetic spoof-entrant clubs — re-added it as a thin public wrapper over
  the new `KitColor` palette + `pickContrastingKitColor()` rather than reverting the identity
  rewrite or touching the spoof-entrant caller.

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (restructured
  NpcClub's `identity` to nested `home`/`away` kit variants + shared badge;
  `NpcClub::setIdentity()` now syncs `primaryColor`/`secondaryColor` from
  `identity.home`, replacing `NpcClubGenerationService`'s old separate
  color-pair generator; `primaryColor`/`secondaryColor` no longer
  independently admin-editable; also fixed the shorts/socks/trousers picker
  in both this widget and the player appearance widget to render color chips
  instead of a full kit thumbnail, which couldn't show the selected part
  color at all)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui, 07_synthesis
  (NpcClub kit+badge `identity` config — new `KitIdentityType`/admin widget,
  `NpcClubGenerationService::generateIdentity()`, `LeagueImportExportService`
  export/import coverage; also synced `CLAUDE.md`'s NpcClub entry and added a
  "Kit & Badge Identity" section)

---

- **Commit:** 36c6d31562c41bb91f27f352f7180df4e85f52e6
- **Date:** 2026-09-25
- **Stages touched this pass:** 01_overview (added `output/deployment.md` — the
  three-branch `development`→`dev`/`master` git merge/propagation policy; also
  synced `CLAUDE.md`'s Git Workflow section to match)

---

- **Commit:** dfc8035a8cb9f014abcf5777da6840bc1f18f7ef
- **Date:** 2026-09-18
- **Stages touched this pass:** 01_overview, 02_architecture, 03_data, 04_interfaces, 05_ui, 06_documentation, 07_synthesis (full warm — rebuilt from scratch after retiring the old generator-script-based `.context/`, previous content preserved in this repo's `git stash`)
