<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync v2 (see App\Service\SyncService): adds `player_career_stat.appearance_config` (the
 * player's personal-traits-only avatar config, optional) and the new `staff_career_profile`
 * table (one row per currently-hired staff member — identity + avatar config, no stats
 * concept, keyed by client-generated staff_id the same way `player_career_stat` is keyed by
 * player_id, since Staff rows are pool-only and deleted on consumption — see CLAUDE.md's
 * hybrid model).
 *
 * `doctrine:migrations:diff` also proposed the same unrelated pre-existing schema drift
 * documented in earlier migrations in this file (hand-added partial unique indexes,
 * DROP DEFAULT/rename noise across game_config/pool_config/starter_config/excursion) — none
 * of that belongs here, so only the two real changes above are kept.
 */
final class Version20261003112841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add player_career_stat.appearance_config and staff_career_profile table (sync v2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_career_stat ADD appearance_config JSON DEFAULT NULL');

        $this->addSql('CREATE TABLE staff_career_profile (id UUID NOT NULL, staff_id VARCHAR(64) NOT NULL, staff_name VARCHAR(100) NOT NULL, staff_role VARCHAR(30) NOT NULL, appearance_config JSON DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, club_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_211C409461190A32 ON staff_career_profile (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_staff_career_profile_club_staff ON staff_career_profile (club_id, staff_id)');
        $this->addSql('ALTER TABLE staff_career_profile ADD CONSTRAINT FK_211C409461190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE staff_career_profile DROP CONSTRAINT FK_211C409461190A32');
        $this->addSql('DROP TABLE staff_career_profile');

        $this->addSql('ALTER TABLE player_career_stat DROP appearance_config');
    }
}
