<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent_pool_target to pool_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config ADD agent_pool_target INT NOT NULL DEFAULT 20');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config DROP COLUMN agent_pool_target');
    }
}
