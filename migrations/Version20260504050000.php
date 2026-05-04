<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add league_player_ability_ranges JSON column to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD COLUMN league_player_ability_ranges JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS league_player_ability_ranges');
    }
}
