<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915215939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add live_telemetry_snapshot.recent_events for the Chairman\'s Terminal real-events feed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE live_telemetry_snapshot ADD recent_events JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP recent_events');
    }
}
