<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260329000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nationality to staff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE staff ADD nationality VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE staff DROP COLUMN nationality');
    }
}
