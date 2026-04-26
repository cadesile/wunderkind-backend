<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add no_interact flag to game_event_template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_event_template ADD COLUMN no_interact BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_event_template DROP COLUMN no_interact');
    }
}
