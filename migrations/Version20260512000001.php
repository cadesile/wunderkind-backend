<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add starter_initialized_at to club; create country_world_pack_cache table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD starter_initialized_at TIMESTAMPTZ NULL');

        $this->addSql('CREATE TABLE country_world_pack_cache (
            id UUID NOT NULL,
            country CHAR(2) NOT NULL,
            tier SMALLINT NOT NULL,
            payload JSONB NOT NULL,
            generated_at TIMESTAMPTZ NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT uq_country_tier UNIQUE (country, tier)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP COLUMN starter_initialized_at');
        $this->addSql('DROP TABLE country_world_pack_cache');
    }
}
