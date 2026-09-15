<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915233540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add live_telemetry_snapshot.active_clubs/weeks_played for the Chairman\'s Terminal footer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD active_clubs INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD weeks_played INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP active_clubs');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP weeks_played');
    }
}
