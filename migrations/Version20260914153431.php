<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914153431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add beta_request.invited_at to track admin-sent beta invite emails';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beta_request ADD invited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beta_request DROP invited_at');
    }
}
