<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scout_report_valid_weeks, scout_report_decay_rate_per_week, npc_scout_rejection_cooldown_weeks to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD COLUMN scout_report_valid_weeks INTEGER NOT NULL DEFAULT 26');
        $this->addSql('ALTER TABLE game_config ADD COLUMN scout_report_decay_rate_per_week INTEGER NOT NULL DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ADD COLUMN npc_scout_rejection_cooldown_weeks INTEGER NOT NULL DEFAULT 52');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN scout_report_valid_weeks');
        $this->addSql('ALTER TABLE game_config DROP COLUMN scout_report_decay_rate_per_week');
        $this->addSql('ALTER TABLE game_config DROP COLUMN npc_scout_rejection_cooldown_weeks');
    }
}
