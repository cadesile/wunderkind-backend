<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds denormalized club/lineup data to CompetitionResult — see the entity's docblocks.
 * A default of '{}' lets this apply cleanly against any pre-existing rows (there is no
 * backfill for their real club/lineup data, but Phase 1 has no production traffic yet);
 * the entity itself always supplies real values on construct going forward.
 *
 * `doctrine:migrations:diff` also proposed the same unrelated pre-existing schema drift
 * (hand-added partial unique indexes, DROP DEFAULT/rename noise) noted in
 * Version20260918205514 — none of that belongs here, so only the four new columns are kept.
 */
final class Version20260919163350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homeClubJson/awayClubJson/homeLineupJson/awayLineupJson to CompetitionResult';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE competition_result ADD home_club_json JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE competition_result ADD away_club_json JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE competition_result ADD home_lineup_json JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE competition_result ADD away_lineup_json JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE competition_result ALTER home_club_json DROP DEFAULT');
        $this->addSql('ALTER TABLE competition_result ALTER away_club_json DROP DEFAULT');
        $this->addSql('ALTER TABLE competition_result ALTER home_lineup_json DROP DEFAULT');
        $this->addSql('ALTER TABLE competition_result ALTER away_lineup_json DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_result DROP home_club_json');
        $this->addSql('ALTER TABLE competition_result DROP away_club_json');
        $this->addSql('ALTER TABLE competition_result DROP home_lineup_json');
        $this->addSql('ALTER TABLE competition_result DROP away_lineup_json');
    }
}
