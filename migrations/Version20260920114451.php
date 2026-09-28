<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920114451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reminder_sent_at to competition_round';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round ADD reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round DROP reminder_sent_at');
    }
}
