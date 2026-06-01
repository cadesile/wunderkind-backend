<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add league_win_points JSON column to game_config (competition history scoring)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD COLUMN league_win_points JSONB NOT NULL DEFAULT '{\"1\":10000000,\"2\":1000000,\"3\":100000,\"4\":10000,\"5\":1000,\"6\":100,\"7\":10,\"8\":1}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN league_win_points');
    }
}
