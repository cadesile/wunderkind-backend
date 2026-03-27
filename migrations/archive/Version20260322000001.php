<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country column to academy table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy ADD country VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy DROP COLUMN country');
    }
}
