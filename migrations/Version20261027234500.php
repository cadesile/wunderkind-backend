<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds competition_response_cache: the prebuilt, viewer-independent body of
 * GET /api/competitions/{id}.
 *
 * A bracket response only changes on discrete events — a registration, a withdrawal, a
 * resubmission, a round drawn, results published, the competition completing — and all of
 * those are either an API write or a cron pass. So it is built when it changes rather than
 * reassembled on every request.
 *
 * A database table rather than a Symfony cache pool, for the same reason
 * CountryWorldPackCache is one: only Postgres has a volume, and `up -d` recreates the app
 * container on every deploy, so `var/cache` (including the filesystem-backed `cache.app`
 * pool) is destroyed each time — whatever cache.yaml's comment about persisting between
 * deploys says. A prewarmed response kept there would vanish on deploy and only come back on
 * the next cron tick, leaving a cold window for exactly the requests this exists to make
 * cheap.
 *
 * For a COMPLETED competition this row is also the archive: the document can never change
 * again, so nothing further is needed to keep serving a finished bracket after its engine
 * inputs are pruned.
 *
 * No backfill. An absent row is simply a miss, and the first reader (or the next cron pass)
 * builds it.
 */
final class Version20261027234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add competition_response_cache, the prebuilt viewer-independent bracket response.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competition_response_cache (
                id UUID NOT NULL,
                active_competition_id UUID NOT NULL,
                payload JSON NOT NULL,
                state_version INT NOT NULL,
                payload_version SMALLINT NOT NULL,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // Unique on the competition: one document per competition, and the index the
        // lookup on every request rides on.
        $this->addSql('CREATE UNIQUE INDEX uq_competition_response_cache_competition ON competition_response_cache (active_competition_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE competition_response_cache
                ADD CONSTRAINT fk_competition_response_cache_competition
                FOREIGN KEY (active_competition_id) REFERENCES active_competition (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_response_cache DROP CONSTRAINT fk_competition_response_cache_competition');
        $this->addSql('DROP TABLE competition_response_cache');
    }
}
