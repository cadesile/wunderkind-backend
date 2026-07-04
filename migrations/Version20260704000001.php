<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tutorial_completed_at to club table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD tutorial_completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP tutorial_completed_at');
    }
}
