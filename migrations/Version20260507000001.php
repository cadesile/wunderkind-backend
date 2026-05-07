<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add facilityMaintenanceFrequencyWeeks and systemNotificationFrequencyWeeks to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD COLUMN facility_maintenance_frequency_weeks SMALLINT NOT NULL DEFAULT 4');
        $this->addSql('ALTER TABLE game_config ADD COLUMN system_notification_frequency_weeks SMALLINT NOT NULL DEFAULT 8');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN facility_maintenance_frequency_weeks');
        $this->addSql('ALTER TABLE game_config DROP COLUMN system_notification_frequency_weeks');
    }
}
