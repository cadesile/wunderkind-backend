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
