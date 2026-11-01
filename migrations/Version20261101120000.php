<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets POST /api/league/conclude-season correct a season it has already archived, and keeps
 * a record of having done so.
 *
 * Until now a second payload for an already-concluded (club, season) replayed the first
 * call's stored response and wrote nothing, so a season filed with wrong figures stayed
 * wrong permanently. A resubmission whose result figures *differ* now overwrites the row,
 * bumps overwrite_count and appends the superseded position and W/D/L to overwrite_audit;
 * one with identical figures still writes nothing, which is what keeps the client's retry
 * queue safe (see LeagueService::concludeSeason).
 *
 * INT rather than SMALLINT on the counter so a client stuck in a resubmission loop cannot
 * overflow it into a 500 that the outbox then retries forever. No backfill: 0 and NULL are
 * truthful on every existing row — none of them has been corrected.
 */
final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add season_record.overwrite_count/overwrite_audit so a concluded season can be corrected and audited.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_record ADD overwrite_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE season_record ADD overwrite_audit JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_record DROP overwrite_count');
        $this->addSql('ALTER TABLE season_record DROP overwrite_audit');
    }
}
