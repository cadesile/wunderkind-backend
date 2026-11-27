<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Game over and liquidation.
 *
 * - club.liquidated_at / club.delete_after: set when the app reports a game over. The club's plot
 *   claim is detached (the owner keeps the plot) and app:clubs:purge-liquidated deletes the club
 *   once delete_after passes.
 * - game_config.game_over_fan_morale_threshold (default 30) and
 *   game_config.liquidated_club_delete_after_days (default 30).
 * - game_config.plot_dormancy_reclaim_days (default 30): the inactivity sell-back window, which
 *   was the DormancyWarningStage::RECLAIM_AFTER_DAYS constant.
 * - plot_claim.vacated_at: when the plot last became empty; starts an empty plot's sell-back clock.
 */
final class Version20261127000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club liquidation dates and the game-over config keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD liquidated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD delete_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE game_config ADD game_over_fan_morale_threshold INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD liquidated_club_delete_after_days INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD plot_dormancy_reclaim_days INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE plot_claim ADD vacated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP liquidated_at');
        $this->addSql('ALTER TABLE club DROP delete_after');
        $this->addSql('ALTER TABLE game_config DROP game_over_fan_morale_threshold');
        $this->addSql('ALTER TABLE game_config DROP liquidated_club_delete_after_days');
        $this->addSql('ALTER TABLE game_config DROP plot_dormancy_reclaim_days');
        $this->addSql('ALTER TABLE plot_claim DROP vacated_at');
    }
}
