<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add abbreviation (VARCHAR 5, nullable) to club and npc_club tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD COLUMN abbreviation VARCHAR(5) DEFAULT NULL');
        $this->addSql('ALTER TABLE npc_club ADD COLUMN abbreviation VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP COLUMN abbreviation');
        $this->addSql('ALTER TABLE npc_club DROP COLUMN abbreviation');
    }
}
