<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sync-v2 columns to match_result: fixture_id, opponent_club_name, is_home, home_goals, away_goals, round, played_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE match_result
            ADD fixture_id VARCHAR(36) DEFAULT NULL,
            ADD opponent_club_name VARCHAR(100) DEFAULT NULL,
            ADD is_home BOOLEAN DEFAULT NULL,
            ADD home_goals SMALLINT DEFAULT NULL,
            ADD away_goals SMALLINT DEFAULT NULL,
            ADD round SMALLINT DEFAULT NULL,
            ADD played_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        ');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_match_result_fixture_id ON match_result (fixture_id) WHERE fixture_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_match_result_fixture_id');
        $this->addSql('ALTER TABLE match_result
            DROP COLUMN fixture_id,
            DROP COLUMN opponent_club_name,
            DROP COLUMN is_home,
            DROP COLUMN home_goals,
            DROP COLUMN away_goals,
            DROP COLUMN round,
            DROP COLUMN played_at
        ');
    }
}
