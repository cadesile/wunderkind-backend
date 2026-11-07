<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-batch progress rows for a queued town pull.
 *
 * The admin "Pull towns" action used to make one 300-town AI call inside the request and be
 * cut off by nginx at 60s. It now queues one message per ~50 towns, and this table is what
 * makes that legible: one row per batch, carrying its counts, its error and — the part a
 * counter could not do — the names it offered, which every later batch of the run excludes so
 * it does not pay for the same answer twice.
 *
 * ON DELETE CASCADE off location_enrichment_run: a batch has no meaning without its run.
 */
final class Version20261106235526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add town_suggestion_batch: per-batch progress for queued town pulls.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE town_suggestion_batch (id UUID NOT NULL, country_code VARCHAR(2) NOT NULL, batch_index SMALLINT NOT NULL, total_batches SMALLINT NOT NULL, status VARCHAR(255) NOT NULL, requested SMALLINT NOT NULL, offered SMALLINT DEFAULT 0 NOT NULL, accepted SMALLINT DEFAULT 0 NOT NULL, imported SMALLINT DEFAULT 0 NOT NULL, already_held SMALLINT DEFAULT 0 NOT NULL, offered_names JSON DEFAULT NULL, attempts SMALLINT DEFAULT 0 NOT NULL, error_message TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_town_suggestion_batch_run_index ON town_suggestion_batch (run_id, batch_index)');
        $this->addSql('CREATE INDEX IDX_F82D39A184E3FEC4 ON town_suggestion_batch (run_id)');
        $this->addSql('ALTER TABLE town_suggestion_batch ADD CONSTRAINT FK_F82D39A184E3FEC4 FOREIGN KEY (run_id) REFERENCES location_enrichment_run (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_suggestion_batch DROP CONSTRAINT FK_F82D39A184E3FEC4');
        $this->addSql('DROP TABLE town_suggestion_batch');
    }
}
