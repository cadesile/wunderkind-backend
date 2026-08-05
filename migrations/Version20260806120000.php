<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region/citySize/populationSize/isCapital to npc_club and npc_club_size_weights to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club ADD region VARCHAR(100) DEFAULT NULL');
        $this->addSql("ALTER TABLE npc_club ADD city_size VARCHAR(255) DEFAULT 'MEDIUM' NOT NULL");
        $this->addSql('ALTER TABLE npc_club ADD population_size BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE npc_club ADD is_capital BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("ALTER TABLE game_config ADD npc_club_size_weights JSONB NOT NULL DEFAULT '{\"tier1\":{\"big\":70,\"medium\":25,\"small\":5},\"tier8\":{\"big\":5,\"medium\":25,\"small\":70}}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club DROP region');
        $this->addSql('ALTER TABLE npc_club DROP city_size');
        $this->addSql('ALTER TABLE npc_club DROP population_size');
        $this->addSql('ALTER TABLE npc_club DROP is_capital');
        $this->addSql('ALTER TABLE game_config DROP npc_club_size_weights');
    }
}
