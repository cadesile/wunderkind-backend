<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915230707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace live_telemetry_snapshot.goals_scored with results_wins/draws/losses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot RENAME COLUMN goals_scored TO results_wins');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD results_draws INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ADD results_losses INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE live_telemetry_snapshot RENAME COLUMN results_wins TO goals_scored');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP results_draws');
        $this->addSql('ALTER TABLE live_telemetry_snapshot DROP results_losses');
    }
}
