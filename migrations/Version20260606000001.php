<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_rollback flag to sync_record';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_record ADD is_rollback BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_record DROP COLUMN is_rollback');
    }
}
