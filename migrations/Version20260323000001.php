<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manager_profile JSON column to academy and user tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy ADD manager_profile JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD manager_profile JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy DROP manager_profile');
        $this->addSql('ALTER TABLE `user` DROP manager_profile');
    }
}
