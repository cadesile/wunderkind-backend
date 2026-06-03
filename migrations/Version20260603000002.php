<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sponsor_count to league table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE league ADD COLUMN sponsor_count SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE league DROP COLUMN sponsor_count');
    }
}
