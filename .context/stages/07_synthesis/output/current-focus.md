# Current Focus

From `.context/stages/02_architecture/output/git-activity.md`'s
`git log --oneline -30` / hotspot analysis:

1. **"Phase 1" Competition framework rollout** — entities → eligibility/
   registration API → round processing/match engines/cron provisioning,
   landing in sequence over recent commits. This is the single biggest
   active area of the codebase right now.
2. **Admin panel polish** — ongoing crash fixes, click-through fixes,
   and menu-ordering changes across `Controller/Admin/*CrudController.php`
   and `templates/admin/*`, paired consistently with test changes.
3. **Match-engine/notification depth pass on Competition (2026-09-19)**
   — `DeterministicEngine` fully replaced with a port of
   `wunderkind-app`'s `ResultsEngine.ts`, plus extra-time/penalty-shootout
   resolution so a knockout fixture can never end level; a new
   `MATCH_NARRATIVE` chain-graph content system
   (`MatchNarrativeGeneratorService`) generates the full match commentary
   server-side; and a first-time push-notification subsystem (FCM via
   `kreait/firebase-bundle`, Symfony Messenger — the first use of
   Messenger in this codebase) fires OS notifications for competition
   round-draws/new-registrants and, optionally, admin broadcasts. See
   `docs/api/push-notifications.md`,
   `docs/api/competition-registration-snapshot-v2.md`.

Clusters 1-2 are corroborated by migration history
(`.context/stages/03_data/output/migrations.md`: the large Competition
migration is the most structurally significant recent change) and by
stage 04's routes/services findings (the Competition subsystem is the
newest, most internally-consistent module in the interface layer).
