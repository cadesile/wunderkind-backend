# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

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
