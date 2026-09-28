<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * World Pack Cache admin-triggered generation tracking: WorldPackGenerationRun (one
 * per "Regenerate country cache" trigger) -> WorldPackGenerationTierRun (one per
 * tier) -> WorldPackGenerationClubRun (one per club, the level the admin progress
 * UI renders). Cron-triggered warms (app:worldpack:warm) do not create rows here.
 *
 * The partial unique index on world_pack_generation_run enforces single-flight per
 * country server-side — not expressible as a Doctrine ORM mapping (same category as
 * ActiveCompetition's "one open per template" index), so it's raw SQL here rather
 * than something doctrine:migrations:diff can regenerate on its own.
 */
final class Version20260928151627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'World Pack Cache generation tracking: run/tier-run/club-run tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE world_pack_generation_run (id UUID NOT NULL, country VARCHAR(2) NOT NULL, status VARCHAR(255) NOT NULL, requested_tiers JSON NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_world_pack_generation_run_country_status ON world_pack_generation_run (country, status)');
        $this->addSql('CREATE UNIQUE INDEX uq_world_pack_generation_run_one_active_per_country ON world_pack_generation_run (country) WHERE (status IN (\'pending\', \'in_progress\'))');

        $this->addSql('CREATE TABLE world_pack_generation_tier_run (id UUID NOT NULL, run_id UUID NOT NULL, country VARCHAR(2) NOT NULL, tier SMALLINT NOT NULL, status VARCHAR(255) NOT NULL, total_club_count SMALLINT DEFAULT 0 NOT NULL, completed_club_count SMALLINT DEFAULT 0 NOT NULL, failed_club_count SMALLINT DEFAULT 0 NOT NULL, agent_pool_ids JSON DEFAULT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D904D51D84E3FEC4 ON world_pack_generation_tier_run (run_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_world_pack_generation_tier_run_run_tier ON world_pack_generation_tier_run (run_id, tier)');
        $this->addSql('ALTER TABLE world_pack_generation_tier_run ADD CONSTRAINT FK_D904D51D84E3FEC4 FOREIGN KEY (run_id) REFERENCES world_pack_generation_run (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE world_pack_generation_club_run (id UUID NOT NULL, tier_run_id UUID NOT NULL, npc_club_id UUID NOT NULL, club_name VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, attempts SMALLINT DEFAULT 0 NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, snapshot_json JSON DEFAULT NULL, error_message TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9E74579110B32B18 ON world_pack_generation_club_run (tier_run_id)');
        $this->addSql('CREATE INDEX IDX_9E7457918AF1000D ON world_pack_generation_club_run (npc_club_id)');
        $this->addSql('CREATE INDEX idx_world_pack_generation_club_run_tier_status ON world_pack_generation_club_run (tier_run_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uq_world_pack_generation_club_run_tier_club ON world_pack_generation_club_run (tier_run_id, npc_club_id)');
        $this->addSql('ALTER TABLE world_pack_generation_club_run ADD CONSTRAINT FK_9E74579110B32B18 FOREIGN KEY (tier_run_id) REFERENCES world_pack_generation_tier_run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE world_pack_generation_club_run ADD CONSTRAINT FK_9E7457918AF1000D FOREIGN KEY (npc_club_id) REFERENCES npc_club (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE world_pack_generation_club_run DROP CONSTRAINT FK_9E74579110B32B18');
        $this->addSql('ALTER TABLE world_pack_generation_club_run DROP CONSTRAINT FK_9E7457918AF1000D');
        $this->addSql('ALTER TABLE world_pack_generation_tier_run DROP CONSTRAINT FK_D904D51D84E3FEC4');
        $this->addSql('DROP TABLE world_pack_generation_club_run');
        $this->addSql('DROP TABLE world_pack_generation_tier_run');
        $this->addSql('DROP TABLE world_pack_generation_run');
    }
}
