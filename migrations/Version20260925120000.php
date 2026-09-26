<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add npc_club.identity (nullable json) for kit + badge configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club ADD identity JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club DROP identity');
    }
}
