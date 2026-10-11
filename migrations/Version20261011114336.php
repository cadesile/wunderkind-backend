<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-trimmed. `doctrine:migrations:diff` generated ~100 extra statements here
 * from pre-existing drift between the local dev DB and the ORM mapping — mostly
 * harmless DROP DEFAULT / index renames, but it also wanted to drop four raw-SQL
 * partial unique indexes that are not representable as ORM metadata and are
 * load-bearing in production (uq_active_competition_one_open_per_template,
 * uq_entrant_reward_claim_with_template / _without_template,
 * uq_world_pack_generation_run_one_active_per_country — see CLAUDE.md). All of
 * that is deliberately removed; this migration does one thing.
 */
final class Version20261011114336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club_spotlight.club_id so the spotlight can recognise the incumbent club exactly (names are not unique)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight ADD club_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight DROP club_id');
    }
}
