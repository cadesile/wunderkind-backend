<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Landing-page spotlight enrichment + dormancy-based reputation decay.
 *
 *  - club.reputation_decayed_at — how far app:reputation:decay has already charged
 *    this club. Nullable: before a club's first decay, dormancy is measured from
 *    last_synced_at (or created_at). See ReputationDecayService.
 *  - club_spotlight.trophies — the featured club's honours board, per league tier.
 *  - club_spotlight.established_at — the featured club's founding date (its
 *    Club.created_at, denormalized like every other column on that singleton).
 */
final class Version20261012215721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club.reputation_decayed_at and club_spotlight.trophies/established_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD reputation_decayed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // club_spotlight is a singleton that already holds a row, so a bare NOT NULL
        // add would fail. Backfill with an empty array via a column default, then drop
        // the default — the entity declares no default and ClubSpotlightService always
        // writes the column explicitly.
        $this->addSql("ALTER TABLE club_spotlight ADD trophies JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE club_spotlight ALTER COLUMN trophies DROP DEFAULT');

        $this->addSql('ALTER TABLE club_spotlight ADD established_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP reputation_decayed_at');
        $this->addSql('ALTER TABLE club_spotlight DROP trophies');
        $this->addSql('ALTER TABLE club_spotlight DROP established_at');
    }
}
