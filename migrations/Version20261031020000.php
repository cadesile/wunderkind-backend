<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The four tables behind periodic leaderboard awards and immutable period archives, in one
 * migration because they are one feature and a half-applied set is useless.
 *
 * leaderboard_award_definition + leaderboard_award_placement are admin config: which chart
 * awards, at what cadence, to how many places, with a distinct prize per place. Nothing
 * awards until is_enabled is turned on, which is why it defaults to false.
 * uq_leaderboard_award_definition_category_cadence is load-bearing rather than tidy — it is
 * what makes (category, period_key) a sound non-nullable claim key on the archive, since
 * two definitions on one category but different cadences produce different period keys and
 * so cannot collide.
 *
 * leaderboard_period_archive + leaderboard_period_archive_entry are the immutable history,
 * and the header carries the close job's CLAIM:
 * uq_leaderboard_period_archive_category_period. The close job INSERTs the header `pending`
 * with ON CONFLICT DO NOTHING, and whoever wins the insert owns the close — which is what
 * makes a re-run, concurrent or replayed, produce no second archive, no second award and no
 * second inbox message. Both columns in that unique are NOT NULL, so it is a PLAIN unique:
 * that is the entire reason `category` and `cadence` are denormalised onto the header
 * instead of keying on definition_id, which is nullable.
 *
 * definition_id is nullable with ON DELETE SET NULL on the header (the
 * UserLedger.source_sync_record_id precedent): an archive must outlive an admin deleting
 * the definition that produced it. The placement FK is the opposite — CASCADE — because a
 * placement is config, not history, and means nothing without its definition.
 *
 * club_id / user_id on the entry table are denormalised VARCHAR(36) with NO foreign key
 * (the season_ratings_snapshot / club_spotlight precedent), because the archive must
 * survive club and account deletion. The frozen kit/badge configs are there for the same
 * reason: the landing page's rule that any club listed anywhere shows at least its badge
 * has to keep holding for a club that no longer exists. inbox_message_id is likewise a
 * plain string and not a FK — inbox messages cascade-delete with the club, and an award
 * must not vanish with its delivery receipt.
 *
 * uq_lpae_archive_place is the one PARTIAL index in the feature, because `place` is
 * nullable and PostgreSQL treats NULLs as distinct in a plain unique. Its predicate is
 * spelled exactly as pg_get_expr(indpred, indrelid) reports it and is declared on the
 * entity too, so doctrine:schema:create builds it on a fresh wunderkind_test and
 * migrations:diff does not churn proposing to drop it.
 *
 * No backfill of any kind. There is no history to reconstruct: an archive is a record of a
 * close, and nothing has ever closed a period. The first close of each enabled definition
 * creates the first row, growth-scored from each club's first sighting inside that period
 * (see Version20261031010000 for the baseline mechanism).
 */
final class Version20261031020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add leaderboard award definitions/placements and immutable period archives (4 tables).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE leaderboard_award_definition (
                id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                category VARCHAR(40) NOT NULL,
                cadence VARCHAR(20) NOT NULL,
                minimum_score BIGINT DEFAULT 0 NOT NULL,
                is_enabled BOOLEAN DEFAULT false NOT NULL,
                last_closed_period_key VARCHAR(20) DEFAULT NULL,
                last_closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_lad_enabled ON leaderboard_award_definition (is_enabled)');
        $this->addSql('CREATE UNIQUE INDEX uq_leaderboard_award_definition_category_cadence ON leaderboard_award_definition (category, cadence)');

        $this->addSql(<<<'SQL'
            CREATE TABLE leaderboard_award_placement (
                id UUID NOT NULL,
                definition_id UUID NOT NULL,
                place SMALLINT NOT NULL,
                prize_pence BIGINT DEFAULT 0 NOT NULL,
                reputation_delta INT DEFAULT 0 NOT NULL,
                reward_template_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_leaderboard_award_placement_def_place ON leaderboard_award_placement (definition_id, place)');
        $this->addSql('CREATE INDEX IDX_ECCE5730D11EA911 ON leaderboard_award_placement (definition_id)');
        $this->addSql('CREATE INDEX IDX_ECCE5730104FE26A ON leaderboard_award_placement (reward_template_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE leaderboard_period_archive (
                id UUID NOT NULL,
                definition_id UUID DEFAULT NULL,
                category VARCHAR(40) NOT NULL,
                cadence VARCHAR(20) NOT NULL,
                definition_name VARCHAR(100) NOT NULL,
                period_key VARCHAR(20) NOT NULL,
                period_starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                baseline_period_key VARCHAR(20) DEFAULT NULL,
                entry_count INT DEFAULT 0 NOT NULL,
                archived_entry_count SMALLINT DEFAULT 0 NOT NULL,
                placements_awarded SMALLINT DEFAULT 0 NOT NULL,
                status VARCHAR(20) NOT NULL,
                claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_leaderboard_period_archive_category_period ON leaderboard_period_archive (category, period_key)');
        $this->addSql('CREATE INDEX idx_lpa_status_period ON leaderboard_period_archive (status, period_ends_at)');
        $this->addSql('CREATE INDEX idx_lpa_cadence_closed ON leaderboard_period_archive (cadence, closed_at)');
        $this->addSql('CREATE INDEX IDX_E861F877D11EA911 ON leaderboard_period_archive (definition_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE leaderboard_period_archive_entry (
                id UUID NOT NULL,
                archive_id UUID NOT NULL,
                rank_position INT NOT NULL,
                club_id VARCHAR(36) NOT NULL,
                club_name VARCHAR(100) NOT NULL,
                user_id VARCHAR(36) DEFAULT NULL,
                score BIGINT NOT NULL,
                cumulative_score BIGINT NOT NULL,
                baseline_score BIGINT DEFAULT 0 NOT NULL,
                baseline_missing BOOLEAN DEFAULT false NOT NULL,
                award_eligible BOOLEAN DEFAULT true NOT NULL,
                display_label VARCHAR(100) DEFAULT NULL,
                home_kit_config JSON DEFAULT NULL,
                away_kit_config JSON DEFAULT NULL,
                badge_config JSON DEFAULT NULL,
                country_code VARCHAR(2) DEFAULT NULL,
                awarded BOOLEAN DEFAULT false NOT NULL,
                place SMALLINT DEFAULT NULL,
                applied_effects_json JSON DEFAULT NULL,
                reward_template_id UUID DEFAULT NULL,
                inbox_message_id VARCHAR(36) DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_lpae_archive_club ON leaderboard_period_archive_entry (archive_id, club_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_lpae_archive_rank ON leaderboard_period_archive_entry (archive_id, rank_position)');
        $this->addSql('CREATE UNIQUE INDEX uq_lpae_archive_place ON leaderboard_period_archive_entry (archive_id, place) WHERE (place IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_lpae_club ON leaderboard_period_archive_entry (club_id)');
        $this->addSql('CREATE INDEX idx_lpae_user ON leaderboard_period_archive_entry (user_id)');
        $this->addSql('CREATE INDEX IDX_EB1146772956195F ON leaderboard_period_archive_entry (archive_id)');
        $this->addSql('CREATE INDEX IDX_EB114677104FE26A ON leaderboard_period_archive_entry (reward_template_id)');

        $this->addSql('ALTER TABLE leaderboard_award_placement ADD CONSTRAINT FK_ECCE5730D11EA911 FOREIGN KEY (definition_id) REFERENCES leaderboard_award_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leaderboard_award_placement ADD CONSTRAINT FK_ECCE5730104FE26A FOREIGN KEY (reward_template_id) REFERENCES reward_template (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leaderboard_period_archive ADD CONSTRAINT FK_E861F877D11EA911 FOREIGN KEY (definition_id) REFERENCES leaderboard_award_definition (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leaderboard_period_archive_entry ADD CONSTRAINT FK_EB1146772956195F FOREIGN KEY (archive_id) REFERENCES leaderboard_period_archive (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE leaderboard_period_archive_entry ADD CONSTRAINT FK_EB114677104FE26A FOREIGN KEY (reward_template_id) REFERENCES reward_template (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // Children first — the FKs are CASCADE, but dropping in dependency order keeps the
        // rollback readable and independent of constraint behaviour.
        $this->addSql('DROP TABLE leaderboard_period_archive_entry');
        $this->addSql('DROP TABLE leaderboard_period_archive');
        $this->addSql('DROP TABLE leaderboard_award_placement');
        $this->addSql('DROP TABLE leaderboard_award_definition');
    }
}
