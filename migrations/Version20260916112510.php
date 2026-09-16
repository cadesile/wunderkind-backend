<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 multiplayer Cup competition framework: CompetitionTemplate, ActiveCompetition,
 * CompetitionEntrant, CompetitionRound, CompetitionFixture, CompetitionResult,
 * RewardTemplate, EntrantRewardClaim.
 *
 * Pre-existing, unrelated schema drift (excursion/game_config/live_telemetry_snapshot/
 * starter_config/deletion_request default+index drift) was excluded from this migration
 * by hand — doctrine:migrations:diff picked it up from the working DB, but it predates
 * this change and isn't part of it.
 */
final class Version20260916112510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Phase 1 competition framework tables (template/instance/entrant/round/fixture/result/reward)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE active_competition (id UUID NOT NULL, status VARCHAR(255) NOT NULL, entrant_capacity SMALLINT NOT NULL, duration_option VARCHAR(255) NOT NULL, registration_opened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancellation_reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, template_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E1F5D9FA5DA0FB8 ON active_competition (template_id)');
        $this->addSql('CREATE INDEX idx_active_competition_template_status ON active_competition (template_id, status)');
        // Partial unique index — at most one REGISTERING instance per template. Not expressible via
        // Doctrine attributes; this is the DB-level backstop for the instance-provisioning cron.
        $this->addSql('CREATE UNIQUE INDEX uq_active_competition_one_open_per_template ON active_competition (template_id) WHERE (status = \'registering\')');

        $this->addSql('CREATE TABLE competition_entrant (id UUID NOT NULL, seed SMALLINT NOT NULL, status VARCHAR(255) NOT NULL, snapshot_json JSON NOT NULL, snapshot_locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, snapshot_version SMALLINT DEFAULT 1 NOT NULL, registered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, active_competition_id UUID NOT NULL, club_id UUID NOT NULL, eliminated_in_round_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_41CE6EBB433F4B12 ON competition_entrant (active_competition_id)');
        $this->addSql('CREATE INDEX IDX_41CE6EBB61190A32 ON competition_entrant (club_id)');
        $this->addSql('CREATE INDEX IDX_41CE6EBB4AE95C37 ON competition_entrant (eliminated_in_round_id)');
        $this->addSql('CREATE INDEX idx_competition_entrant_competition_status ON competition_entrant (active_competition_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uq_competition_entrant_club_competition ON competition_entrant (active_competition_id, club_id)');

        $this->addSql('CREATE TABLE competition_fixture (id UUID NOT NULL, slot_index SMALLINT NOT NULL, status VARCHAR(255) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, round_id UUID NOT NULL, home_entrant_id UUID DEFAULT NULL, away_entrant_id UUID DEFAULT NULL, winner_entrant_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1A508040A6005CA0 ON competition_fixture (round_id)');
        $this->addSql('CREATE INDEX IDX_1A508040FA30CCD6 ON competition_fixture (home_entrant_id)');
        $this->addSql('CREATE INDEX IDX_1A5080404A01E065 ON competition_fixture (away_entrant_id)');
        $this->addSql('CREATE INDEX IDX_1A508040D966ED77 ON competition_fixture (winner_entrant_id)');
        $this->addSql('CREATE INDEX idx_competition_fixture_round_slot ON competition_fixture (round_id, slot_index)');

        $this->addSql('CREATE TABLE competition_result (id UUID NOT NULL, home_score SMALLINT NOT NULL, away_score SMALLINT NOT NULL, event_log_json JSON NOT NULL, narrative_payload JSON DEFAULT NULL, engine_identifier VARCHAR(255) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, fixture_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7C2901C0E524616D ON competition_result (fixture_id)');
        $this->addSql('CREATE INDEX idx_competition_result_fixture ON competition_result (fixture_id)');

        $this->addSql('CREATE TABLE competition_round (id UUID NOT NULL, round_index SMALLINT NOT NULL, label VARCHAR(20) NOT NULL, status VARCHAR(255) NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, match_engine_identifier VARCHAR(255) DEFAULT NULL, locked_for_processing_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, active_competition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3659D8E2433F4B12 ON competition_round (active_competition_id)');
        $this->addSql('CREATE INDEX idx_competition_round_status_scheduled ON competition_round (status, scheduled_at)');
        $this->addSql('CREATE UNIQUE INDEX uq_competition_round_competition_index ON competition_round (active_competition_id, round_index)');

        $this->addSql('CREATE TABLE competition_template (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(80) NOT NULL, entrant_capacity SMALLINT NOT NULL, duration_option VARCHAR(255) NOT NULL, allowed_tiers JSON DEFAULT NULL, min_club_reputation SMALLINT DEFAULT 0 NOT NULL, min_club_age_seasons SMALLINT DEFAULT 0 NOT NULL, entry_fee_per_round INT DEFAULT 0 NOT NULL, victor_prize INT DEFAULT 0 NOT NULL, round_engine_config JSON DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A61124AC989D9B62 ON competition_template (slug)');
        $this->addSql('CREATE INDEX idx_competition_template_active ON competition_template (is_active)');

        $this->addSql('CREATE TABLE competition_template_reward_template (competition_template_id UUID NOT NULL, reward_template_id UUID NOT NULL, PRIMARY KEY (competition_template_id, reward_template_id))');
        $this->addSql('CREATE INDEX IDX_FF2247252D7BA0C6 ON competition_template_reward_template (competition_template_id)');
        $this->addSql('CREATE INDEX IDX_FF224725104FE26A ON competition_template_reward_template (reward_template_id)');

        $this->addSql('CREATE TABLE entrant_reward_claim (id UUID NOT NULL, trigger_context VARCHAR(40) NOT NULL, applied_effects_json JSON NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, entrant_id UUID NOT NULL, reward_template_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D8DFF6518BFF9D26 ON entrant_reward_claim (entrant_id)');
        $this->addSql('CREATE INDEX IDX_D8DFF651104FE26A ON entrant_reward_claim (reward_template_id)');
        $this->addSql('CREATE INDEX idx_entrant_reward_claim_lookup ON entrant_reward_claim (entrant_id, reward_template_id, trigger_context)');
        // Two partial unique indexes stand in for one plain UNIQUE(entrant_id, reward_template_id,
        // trigger_context): Postgres treats NULLs as distinct in a standard unique index, so a plain
        // constraint would not dedupe reward_template_id IS NULL rows (victor_prize/entry_fee claims).
        $this->addSql('CREATE UNIQUE INDEX uq_entrant_reward_claim_with_template ON entrant_reward_claim (entrant_id, reward_template_id, trigger_context) WHERE (reward_template_id IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uq_entrant_reward_claim_without_template ON entrant_reward_claim (entrant_id, trigger_context) WHERE (reward_template_id IS NULL)');

        $this->addSql('CREATE TABLE reward_template (id UUID NOT NULL, slug VARCHAR(80) NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, effects_json JSON NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_24ECD250989D9B62 ON reward_template (slug)');

        $this->addSql('ALTER TABLE active_competition ADD CONSTRAINT FK_E1F5D9FA5DA0FB8 FOREIGN KEY (template_id) REFERENCES competition_template (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_entrant ADD CONSTRAINT FK_41CE6EBB433F4B12 FOREIGN KEY (active_competition_id) REFERENCES active_competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_entrant ADD CONSTRAINT FK_41CE6EBB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_entrant ADD CONSTRAINT FK_41CE6EBB4AE95C37 FOREIGN KEY (eliminated_in_round_id) REFERENCES competition_round (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_fixture ADD CONSTRAINT FK_1A508040A6005CA0 FOREIGN KEY (round_id) REFERENCES competition_round (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_fixture ADD CONSTRAINT FK_1A508040FA30CCD6 FOREIGN KEY (home_entrant_id) REFERENCES competition_entrant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_fixture ADD CONSTRAINT FK_1A5080404A01E065 FOREIGN KEY (away_entrant_id) REFERENCES competition_entrant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_fixture ADD CONSTRAINT FK_1A508040D966ED77 FOREIGN KEY (winner_entrant_id) REFERENCES competition_entrant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_result ADD CONSTRAINT FK_7C2901C0E524616D FOREIGN KEY (fixture_id) REFERENCES competition_fixture (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_round ADD CONSTRAINT FK_3659D8E2433F4B12 FOREIGN KEY (active_competition_id) REFERENCES active_competition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE competition_template_reward_template ADD CONSTRAINT FK_FF2247252D7BA0C6 FOREIGN KEY (competition_template_id) REFERENCES competition_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE competition_template_reward_template ADD CONSTRAINT FK_FF224725104FE26A FOREIGN KEY (reward_template_id) REFERENCES reward_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entrant_reward_claim ADD CONSTRAINT FK_D8DFF6518BFF9D26 FOREIGN KEY (entrant_id) REFERENCES competition_entrant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE entrant_reward_claim ADD CONSTRAINT FK_D8DFF651104FE26A FOREIGN KEY (reward_template_id) REFERENCES reward_template (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE active_competition DROP CONSTRAINT FK_E1F5D9FA5DA0FB8');
        $this->addSql('ALTER TABLE competition_entrant DROP CONSTRAINT FK_41CE6EBB433F4B12');
        $this->addSql('ALTER TABLE competition_entrant DROP CONSTRAINT FK_41CE6EBB61190A32');
        $this->addSql('ALTER TABLE competition_entrant DROP CONSTRAINT FK_41CE6EBB4AE95C37');
        $this->addSql('ALTER TABLE competition_fixture DROP CONSTRAINT FK_1A508040A6005CA0');
        $this->addSql('ALTER TABLE competition_fixture DROP CONSTRAINT FK_1A508040FA30CCD6');
        $this->addSql('ALTER TABLE competition_fixture DROP CONSTRAINT FK_1A5080404A01E065');
        $this->addSql('ALTER TABLE competition_fixture DROP CONSTRAINT FK_1A508040D966ED77');
        $this->addSql('ALTER TABLE competition_result DROP CONSTRAINT FK_7C2901C0E524616D');
        $this->addSql('ALTER TABLE competition_round DROP CONSTRAINT FK_3659D8E2433F4B12');
        $this->addSql('ALTER TABLE competition_template_reward_template DROP CONSTRAINT FK_FF2247252D7BA0C6');
        $this->addSql('ALTER TABLE competition_template_reward_template DROP CONSTRAINT FK_FF224725104FE26A');
        $this->addSql('ALTER TABLE entrant_reward_claim DROP CONSTRAINT FK_D8DFF6518BFF9D26');
        $this->addSql('ALTER TABLE entrant_reward_claim DROP CONSTRAINT FK_D8DFF651104FE26A');
        $this->addSql('DROP TABLE active_competition');
        $this->addSql('DROP TABLE competition_entrant');
        $this->addSql('DROP TABLE competition_fixture');
        $this->addSql('DROP TABLE competition_result');
        $this->addSql('DROP TABLE competition_round');
        $this->addSql('DROP TABLE competition_template');
        $this->addSql('DROP TABLE competition_template_reward_template');
        $this->addSql('DROP TABLE entrant_reward_claim');
        $this->addSql('DROP TABLE reward_template');
    }
}
