# Last Sync

> Updated by the acting agent every time any stage's `output/` is written
> (see `SKILL.md`'s "How to use this skill", step 5). Read by the
> session-start staleness check (see `SKILL.md`'s Triggers table) to find
> commits that have landed since `.context/` was last reviewed.

- **Commit:** fa58cd2 (development/dev/master, pushed). **Stage touched:**
  01_overview (`environment.md` — documented `scripts/pull-prod-db.sh`,
  the reusable production-DB-pull-and-restore-locally workflow, plus a
  `CONTEXT.md` routing-table row mapping the human's "pull prod DB" trigger
  phrase directly to running it — explicit instruction, per the human, to
  run it rather than just describe it when that phrase is used. Raw/
  unscrubbed PII handling was an explicit human decision made via
  `AskUserQuestion` when the script was built this same session, recorded
  here so a future session doesn't second-guess or silently "fix" it.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request, same as the UserLedger pass below it this stacks on top of).
  **Stage touched:** 04_interfaces (`controllers.md` — admin User edit page
  now shows a per-club summary panel: kit/badge previews, last sync/league
  position/form, earnings vs. dividends drawn, and the user's overall
  cross-club balance. `UserCrudController::edit()` override +
  `admin/user_edit.html.twig`; extracted `resultBadge` macro from
  `club_profile.html.twig` into `admin/_macros.html.twig` for reuse; new
  `SyncRecordRepository::findLatestValid()` /
  `UserLedgerRepository::getTotalDividendsByClub()`. CLAUDE.md's Centralized
  User Ledger section updated to match. Covered by
  `tests/Controller/Admin/UserCrudControllerTest.php`.)

---

- **Commit:** b153556 (as of writing — this pass's own changes are
  uncommitted on top of it, pending the user's usual explicit commit
  request). Per the staleness check, `.context/` was 3 commits behind HEAD
  going into this session (`b153556` dedupe squad bonds / resolve player
  names, `2a1c108` positive bonds + telemetry feed categories, `ea2c2dd`
  tiered excursion cost/effect progression) — the user chose to proceed
  without reviewing those first, so they remain **unreflected** in
  `.context/` and are a follow-up, same as the admin club-profile/landing-
  page gap noted in the entry below from 2026-09-28.
- **Date:** 2026-10-02
- **Stages touched this pass:** 03_data, 04_interfaces (new `UserLedger`
  entity/`user_ledger` table — a centralized, cross-club financial audit
  trail for dividend draws; see `entities.md`'s `UserLedger` entry,
  `schema.md`'s `user_ledger` constraint note + the `dividend_draw`
  `ledger[].category` addendum, `migrations.md` entry 24, and
  `services.md`'s `UserLedgerService` entry. New read-only
  `UserLedgerCrudController`, documented in `controllers.md`/`routes.md`.
  Also widened `LiveTelemetryService::ledgerAmountToPence()` from private
  to `public static` so `UserLedgerService` could reuse the existing
  100x-pence correction instead of adding a third implementation — see
  `schema.md`'s "`sync_record.payload` field conventions" section.)
  CLAUDE.md's Architecture section synced with a new "Centralized User
  Ledger" subsection; Key Services/Key Entities tables updated to match.

---

- **Commit:** df082fe (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 01_overview (corrected the git branching
  policy in `output/deployment.md`, and synced `CLAUDE.md`'s Git Workflow
  section to match, per explicit user correction: `development` is the
  working branch for most tasks — commit directly there, it's a direct
  child of `master` — not a mandatory feature-branch + PR workflow as
  previously documented. `dev`/`master` are still only ever reached by
  merging `development` in, propagated as two separate merges in that
  order. Also recorded as a standing feedback memory outside this repo, as
  a backstop in case this doc drifts again.)

---

- **Commit:** 5cf9851 (also merged into `dev` and `master` this pass — see
  the merge-tagged entry immediately below)
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

---

- **Commit:** 160ac7a (development, merged into dev)
- **Date:** 2026-09-28
- **Stages touched this pass:** 02_architecture (World Pack Cache generation
  rewritten to generate every club's players/staff/scouts fresh, in-memory,
  rather than drawing from the shared pool — new `WorldPackClubGenerationService`,
  a tracking schema (`WorldPackGenerationRun`/`TierRun`/`ClubRun`, migration
  `Version20260928151627`), and Messenger-driven tier/club batching
  (`WorldPackGenerationOrchestrator`, `WarmWorldPackTierMessageHandler`,
  `WarmWorldPackClubMessageHandler`, `WorldPackTierAssemblyService`); admin UI
  (`worldpack_cache.html.twig`) reworked to trigger regenerates and poll live
  per-country/tier/club progress instead of a client-side synchronous loop;
  removed the now-unneeded `pool-warm.sh`/`worldpack-warm.sh` cron jobs; split
  World Pack messages onto their own `worldpack` Messenger transport, separate
  from `async` (push notifications) — a country regenerate's 100+ club-generation
  messages were sharing one queue with push sends and delaying each other, so a
  new `docker/worldpack-consume.sh` cron entry drains it independently). Also
  fixed the pre-existing PHPUnit "mock without expectations" notices across the
  suite (`createMock()` → `createStub()` where no `->expects()` is used).

---

- **Commit:** 790e98f (development, merged into dev)
- **Date:** 2026-09-27
- **Stages touched this pass:** 03_data, 04_interfaces (added owner identity
  — `name`/`nationality`/`gender`/`dob`/`appearance` — to `User`, replacing
  the redundant `User::$managerProfile`/`Club::$managerProfile` blobs;
  new `AppearanceRole::OWNER` case wired into `AppearanceLifecycleSubscriber`
  and `app:backfill-appearances`; new `POST /api/owner-avatar` partial-update
  endpoint; `UserCrudController` EDIT enabled with an Owner Identity
  fieldset + the appearance widget; migration `Version20260927120000`)

---

- **Commit:** daea03a (sprites, merged into dev)
- **Date:** 2026-09-26
- **Stages touched this pass:** 03_data, 04_interfaces, 05_ui (avatar/kit
  generation demographic + rarity rules: age-banded `hairStyleWeights()`,
  rare ginger `hairColorWeights()`, facial hair extended to players,
  player `face` fixed to neutral, player `headband` at 1-in-1000,
  skin-linked `LipColor::DEEP_BROWN` for dark skin tones, NPC club
  `badgeCentre` generation now excludes `INITIALS` too, and kit
  primary/secondary contrast-checked (WCAG >= 3.0) per variant)

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

---

- **Commit:** dfc8035a8cb9f014abcf5777da6840bc1f18f7ef
- **Date:** 2026-09-18
- **Stages touched this pass:** 01_overview, 02_architecture, 03_data, 04_interfaces, 05_ui, 06_documentation, 07_synthesis (full warm — rebuilt from scratch after retiring the old generator-script-based `.context/`, previous content preserved in this repo's `git stash`)
