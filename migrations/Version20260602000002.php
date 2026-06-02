<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create season_ratings_snapshot table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE season_ratings_snapshot (
            id UUID NOT NULL,
            season SMALLINT NOT NULL,
            week_num SMALLINT NOT NULL,
            tier SMALLINT NOT NULL,
            club_id VARCHAR(36) NOT NULL,
            club_name VARCHAR(100) NOT NULL,
            overall_rating SMALLINT NOT NULL,
            expected_position SMALLINT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_srs_season_tier ON season_ratings_snapshot (season, tier)');
        $this->addSql("COMMENT ON COLUMN season_ratings_snapshot.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE season_ratings_snapshot');
    }
}
