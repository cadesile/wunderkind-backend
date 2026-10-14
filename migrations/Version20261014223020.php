<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Telemetry HUD columns 3 & 4: pyramid-wide totals replace single-club highlights.
 *
 * Adds total_goals / total_matches (Goals Logged, plus the goals-per-match sub-line) and
 * table_turnover (promotions + relegations), and drops the six columns that fed the old
 * Boardroom Dilution and Covenants & Fallout boxes.
 *
 * Dropping them is safe: live_telemetry_snapshot is a single-row cache rebuilt in full by
 * app:telemetry:generate every 15 minutes, so none of this is source data — every value is
 * re-derived from sync_record payloads and SeasonRecord rows on the next run. The dilution
 * and covenant NARRATIVES are not lost either; they remain feed categories
 * (LiveTelemetryService::buildDilutionEvents()/buildCovenantEvents()), which is where a
 * one-club story belongs now that these two boxes are collective metrics.
 */
final class Version20261014223020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telemetry HUD cols 3-4: add goals/table-turnover totals, drop dilution+covenant columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD total_goals INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD total_matches INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD table_turnover INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dressing_room_fallout_count');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_equity_percent');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_club_name');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP dilution_counterparty');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP covenant_active_count');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP covenant_seasonal_value_pence');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dressing_room_fallout_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_equity_percent INT DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_club_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD dilution_counterparty VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD covenant_active_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD covenant_seasonal_value_pence BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP total_goals');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP total_matches');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP table_turnover');
    }
}
