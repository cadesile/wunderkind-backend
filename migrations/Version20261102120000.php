<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the `tutorialCompleted` audience criterion to `setupCompleted` in stored
 * AudienceGroup payloads.
 *
 * Data only — no schema change. `audience_group.criteria_payload` is a JSON column of
 * admin-authored keys, whitelisted by AudienceCriteriaEvaluator::SUPPORTED_KEYS.
 *
 * Why the key was wrong: it reads `Club::$tutorial_completed_at`, which stopped meaning
 * "finished the first-launch walkthrough" on 2026-09-29, when the client deleted that
 * walkthrough and with it the only thing that ever wrote the column. It now records
 * new-game SETUP completion (client resumed sending it 2026-11-02).
 *
 * Why this migration is not optional even though the evaluator aliases the old spelling:
 * that whitelist FAILS CLOSED, so a payload carrying a retired key matches nobody. The
 * alias (AudienceCriteriaEvaluator::LEGACY_KEYS) is a safety net for an environment that
 * has not run this yet, and it logs a deprecation when it fires. Once this has run
 * everywhere and the logs are quiet, the alias can go.
 *
 * Key-level rename, not a blob replace: `jsonb_set`-style surgery on the one key leaves
 * every other criterion in the payload untouched, and rows without the key are not
 * rewritten at all. Guarded by `? 'tutorialCompleted'` so the UPDATE only touches rows
 * that actually carry it — on an install with none, this is a no-op rather than a
 * full-table rewrite.
 *
 * Exercised against Postgres 16 before commit, on fixtures covering: the key alongside
 * other criteria (renamed, others untouched), `false` as its value (preserved, NOT
 * coerced — the `-> key` read is why), a row already using the new spelling (skipped),
 * an unrelated payload (untouched), and NULL (untouched, no error). Re-running `up()` is
 * a no-op.
 *
 * One asymmetry, inherent to reversing a rename: `down()` cannot tell a row this
 * migration converted from a row an admin authored with the new key, so it renames both.
 * That is safe only because AudienceCriteriaEvaluator::LEGACY_KEYS still accepts the old
 * spelling — a rollback leaves every group working. **Drop that alias and this `down()`
 * becomes destructive**, so retire them together.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the tutorialCompleted audience criterion to setupCompleted in stored AudienceGroup payloads.';
    }

    public function up(Schema $schema): void
    {
        // json, not jsonb, so cast both ways. `-` drops the old key, `||` adds the new one
        // carrying the same value, so `true`/`false` is preserved rather than assumed.
        $this->addSql(<<<'SQL'
            UPDATE audience_group
               SET criteria_payload = (
                       (criteria_payload::jsonb - 'tutorialCompleted')
                       || jsonb_build_object('setupCompleted', criteria_payload::jsonb -> 'tutorialCompleted')
                   )::json
             WHERE criteria_payload IS NOT NULL
               AND criteria_payload::jsonb ? 'tutorialCompleted'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE audience_group
               SET criteria_payload = (
                       (criteria_payload::jsonb - 'setupCompleted')
                       || jsonb_build_object('tutorialCompleted', criteria_payload::jsonb -> 'setupCompleted')
                   )::json
             WHERE criteria_payload IS NOT NULL
               AND criteria_payload::jsonb ? 'setupCompleted'
            SQL);
    }
}
