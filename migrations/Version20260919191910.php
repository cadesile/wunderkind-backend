<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds CompetitionResult::wentToExtraTime/wentToPenalties/penaltyHomeScore/
 * penaltyAwayScore — a knockout fixture can never end level, so these record how a tied
 * scoreline was actually settled (extra time and/or a penalty shootout).
 *
 * `doctrine:migrations:diff` also proposed the same unrelated pre-existing schema drift
 * (hand-added partial unique indexes, DROP DEFAULT/index-rename noise) documented in earlier
 * migrations in this file — none of that belongs here, so only the four new columns are kept.
 */
final class Version20260919191910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CompetitionResult wentToExtraTime/wentToPenalties/penaltyHomeScore/penaltyAwayScore';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_result ADD went_to_extra_time BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_result ADD went_to_penalties BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_result ADD penalty_home_score SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_result ADD penalty_away_score SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_result DROP went_to_extra_time');
        $this->addSql('ALTER TABLE competition_result DROP went_to_penalties');
        $this->addSql('ALTER TABLE competition_result DROP penalty_home_score');
        $this->addSql('ALTER TABLE competition_result DROP penalty_away_score');
    }
}
