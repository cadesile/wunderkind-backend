<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619183830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add development tick frequency and feature cooldown fields to game_config';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD development_tick_frequency_weeks INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD address_squad_cooldown_weeks INT DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD fan_event_cooldown_weeks INT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD sponsor_offer_cooldown_weeks INT DEFAULT 8 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD investor_offer_cooldown_weeks INT DEFAULT 12 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD scout_report_cooldown_weeks INT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD youth_intake_cooldown_weeks INT DEFAULT 12 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD player_interaction_cooldown_weeks INT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD staff_meeting_cooldown_weeks INT DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE game_config ALTER max_sponsors_by_tier DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER max_investors_by_tier DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER scout_report_valid_weeks DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER scout_report_decay_rate_per_week DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER npc_scout_rejection_cooldown_weeks DROP DEFAULT');
        $this->addSql('ALTER TABLE sync_record ALTER is_rollback DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP development_tick_frequency_weeks');
        $this->addSql('ALTER TABLE game_config DROP address_squad_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP fan_event_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP sponsor_offer_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP investor_offer_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP scout_report_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP youth_intake_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP player_interaction_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config DROP staff_meeting_cooldown_weeks');
        $this->addSql('ALTER TABLE game_config ALTER scout_report_valid_weeks SET DEFAULT 26');
        $this->addSql('ALTER TABLE game_config ALTER scout_report_decay_rate_per_week SET DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ALTER npc_scout_rejection_cooldown_weeks SET DEFAULT 52');
        $this->addSql('ALTER TABLE game_config ALTER max_sponsors_by_tier SET DEFAULT \'{"local":3,"regional":5,"national":7,"elite":10}\'');
        $this->addSql('ALTER TABLE game_config ALTER max_investors_by_tier SET DEFAULT \'{"local":3,"regional":5,"national":7,"elite":10}\'');
        $this->addSql('ALTER TABLE sync_record ALTER is_rollback SET DEFAULT false');
    }
}
