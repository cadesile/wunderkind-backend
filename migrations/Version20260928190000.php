<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the boardroom-dilution, dressing-room-fallout, commercial-covenant, and
 * community-morale stat columns to live_telemetry_snapshot for the redesigned
 * categorized "Boardroom Incident & Consequence Feed" on the landing page.
 */
final class Version20260928190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dilution/dressing-room/covenant/community stat columns to live_telemetry_snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dressing_room_fallout_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD community_morale_delta INT DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_equity_percent INT DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_club_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_counterparty VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD covenant_active_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD covenant_seasonal_value_pence BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dressing_room_fallout_count');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP community_morale_delta');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_equity_percent');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_club_name');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_counterparty');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP covenant_active_count');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP covenant_seasonal_value_pence');
    }
}
