<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add squad_size_min and squad_size_max to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD squad_size_min SMALLINT DEFAULT 11 NOT NULL, ADD squad_size_max SMALLINT DEFAULT 25 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN squad_size_min, DROP COLUMN squad_size_max');
    }
}
