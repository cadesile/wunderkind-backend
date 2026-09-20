# Migration History

Entities under `src/Entity/` are the authoritative current schema —
migrations are the change log, not the source of truth (see
`schema.md`/`entities.md`).

## Archive

`migrations/archive/` holds 29 old MySQL-era migrations plus a
`README.md` stating plainly: *"Archived MySQL migrations... Archived
during migration to PostgreSQL (2026-03-26). Do not run these... A
single fresh baseline migration replaces them for Postgres."* Dead
history — not part of the live migration chain.

## Live migration chain: `migrations/` (133 files)

Most recent migrations (chronological):

1. `Version20260913203511`, `Version20260914090400`,
   `Version20260914094959`, `Version20260914153431` — older, not
   inspected in detail this pass.
2. `Version20260915213526` — creates `live_telemetry_snapshot`
   (`id`, `fixtures_simulated`, `capital_deployed_pence`,
   `generated_at`).
3. `Version20260915215939` — adds `recent_events JSON` to
   `live_telemetry_snapshot`.
4. `Version20260915221026` — adds `goals_scored INT` to
   `live_telemetry_snapshot`.
5. `Version20260915230707` — renames `goals_scored` →
   `results_wins`, adds `results_draws`, `results_losses`.
6. `Version20260915233540` — adds `active_clubs`, `weeks_played` to
   `live_telemetry_snapshot`.
7. `Version20260916112510` — large migration creating the entire
   Competition module schema (`active_competition`,
   `competition_entrant`, `competition_fixture`, `competition_result`,
   and related tables/indexes per `entities.md`'s Competition section),
   including the partial unique index enforcing one open
   `active_competition` per template.
8. `Version20260918205514` — adds
   `is_spoof BOOLEAN DEFAULT false NOT NULL` to `club`.
9. `Version20260919163350` — adds `home_club_json`, `away_club_json`,
   `home_lineup_json`, `away_lineup_json` to `competition_result` (full
   stored/served match payload — club kits/badges + starting XI with
   goals/assists/cards/ratings).
10. `Version20260919181112` — adds `losing_team_card_multiplier_max`,
    `losing_team_card_goal_diff_cap` to `game_config` (card-generation
    knobs for the `ResultsEngine.ts`-ported `DeterministicEngine`).
11. `Version20260919191910` — adds `went_to_extra_time`,
    `went_to_penalties`, `penalty_home_score`, `penalty_away_score` to
    `competition_result` — a knockout fixture can never end level;
    these record how a tie was actually settled.
12. `Version20260919211831` — push-notification infrastructure: creates
    `user_device` (FCM registration tokens, unique `device_token`, FK to
    `user` `ON DELETE CASCADE`) and `messenger_messages` (Symfony
    Messenger's Doctrine transport — first use of Messenger in this
    codebase), and adds `send_as_push BOOLEAN`, `push_sent_at TIMESTAMP`
    to `admin_message`.
13. `Version20260920075918` — creates `notification_log` (see
    `entities.md`), the audit trail for the push pipeline. Reuses the
    existing `messenger_messages` table for the new `failed` transport
    (`config/packages/messenger.yaml`) via a distinct `queue_name` rather
    than a separate table — no migration needed for that part.
14. `Version20260920114451` — adds `reminder_sent_at` to
    `competition_round` — claim-lock for the new `ROUND_STARTING_SOON`
    push (`CompetitionRoundReminderService`), same idiom as
    `locked_for_processing_at`'s round-processing claim-lock.
15. `Version20260920133550` (most recent) — adds `auto_fill_spoof_entrants`
    (default false) and `auto_fill_delay_minutes` (default 20) to
    `competition_template` — dev/testing auto-fill, see `entities.md`
    and `CompetitionAutoFillService`.

## Takeaway

Active development from mid-September through 2026-09-19 progressed
through: (a) `LiveTelemetrySnapshot` built incrementally field-by-field,
(b) the full Competition subsystem stood up in one large migration, (c)
a `club.is_spoof` flag, (d) the match-result payload enriched with full
club/lineup data and extra-time/penalty-shootout outcomes so a knockout
fixture is never left looking like an unresolved draw, and (e) push
notifications (device tokens + a Messenger-backed async send pipeline)
added so the backend can trigger native OS notifications for
competition events (round drawn, new registrant, match result) and
admin broadcasts, later followed by (f) a `NotificationLog` audit trail
+ admin debug tooling once a missing `FIREBASE_SERVICE_ACCOUNT_JSON`
secret caused sends to fail invisibly, and (g) three more competition
push types (`COMPETITION_COMPLETED`, `NEW_COMPETITION_OPEN`,
`ROUND_STARTING_SOON`) filling the remaining gaps identified once the
pipeline was actually confirmed working end-to-end on a real device —
see
`04_interfaces/output/services.md` and `services.md`'s
`PushNotificationService`/`NotificationLoggingSubscriber` entries.
Consistent with
`02_architecture/output/git-activity.md`'s hotspot findings (Competition
feature + admin panel polish).
