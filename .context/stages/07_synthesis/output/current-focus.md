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
   round-draws/new-registrants/individual match results and, optionally,
   admin broadcasts. See
   `.context/stages/04_interfaces/output/push-notifications.md`,
   `docs/api/competition-registration-snapshot-v2.md`.

4. **Platform security upgrade (2026-10-21)** — a dependency/runtime pass rather
   than a feature one, and the reason the stack's version numbers moved. PHP
   8.4 -> 8.5 (deployed pinned at an exact `php:8.5.12-fpm-alpine`; local Lando
   capped at 8.5.7 by Lando's own image family) for the 2026-10-21 Core
   advisories, then Symfony 8.0.* -> 8.1.* plus EasyAdmin 5.0->5.7, Twig
   3.23->3.30 and API Platform 4.2->4.4 to clear 43 Composer advisories down to
   zero. Touched almost no application logic — the real work was four
   compatibility breaks the upgrade exposed (PHP 8.5 static opcache failing the
   image build, `ReflectionMethod::setAccessible()` deprecation, Symfony 8.1's
   `runCommand()` trait collision killing the whole suite, and EasyAdmin moving
   `ea-clickable-row` to client-side JS). See `.context/shared/stack.md`'s
   dependency-floor bullet, `01_overview/output/environment.md`'s "Keeping PHP
   and dependencies patched", and root `CLAUDE.md`'s Testing section.

Clusters 1-2 are corroborated by migration history
(`.context/stages/03_data/output/migrations.md`: the large Competition
migration is the most structurally significant recent change) and by
stage 04's routes/services findings (the Competition subsystem is the
newest, most internally-consistent module in the interface layer).
