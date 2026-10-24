<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes POST /api/league/conclude-season idempotent per club + season.
 *
 * The client now queues and retries that call, and a cancelled request can never be proven
 * not to have reached the server — so a second arrival must be a no-op that replays the first
 * answer. `season_snapshot.response_json` holds that answer; the two unique indexes make a
 * duplicate archive row impossible even if two requests race.
 *
 * If `CREATE UNIQUE INDEX` fails here, this database already has duplicate archive rows from
 * before the guard existed. Find them with:
 *
 *   SELECT club_id, season, count(*) FROM season_record
 *   GROUP BY club_id, season HAVING count(*) > 1;
 *
 * and keep the earliest row of each group (its `created_at`), because the Hall of Fame score
 * is summed from these rows and currently double-counts every duplicate title.
 */
final class Version20261024132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idempotent conclude-season: season_snapshot.response_json + unique (club, season) on both season archive tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season_snapshot ADD response_json JSON DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_season_record_club_season ON season_record (club_id, season)');
        $this->addSql('CREATE UNIQUE INDEX uq_season_snapshot_club_season ON season_snapshot (club_id, season)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_season_snapshot_club_season');
        $this->addSql('DROP INDEX uq_season_record_club_season');
        $this->addSql('ALTER TABLE season_snapshot DROP response_json');
    }
}
