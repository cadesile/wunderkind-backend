<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915221026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add live_telemetry_snapshot.goals_scored for the Chairman\'s Terminal widget';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD goals_scored INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP goals_scored');
    }
}
