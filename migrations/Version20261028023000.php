<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces competition_response_cache.state_version with a state_fingerprint hash.
 *
 * The integer was additive (`1 + entrantCount + roundStates + snapshotVersionSum`) and
 * **collided**: the entrant count excludes withdrawn rows while the snapshot sum includes
 * them, so a withdrawal netted -1 and another club's resubmission netted +1. They cancelled
 * out, the cached row still looked fresh, and a stale bracket was served behind a 304. It
 * also omitted inputs the document renders — the competition's status, cancellation and
 * completion, and every template field embedded in the summary — so cancelling a
 * REGISTERING competition left the body stale indefinitely.
 *
 * A hash over an explicit labelled tuple removes the whole class of arithmetic coincidence
 * instead of patching one instance of it. See
 * `CompetitionResponseBuilder::computeStateFingerprint()`.
 *
 * The table is a cache, so existing rows are simply dropped rather than converted: every
 * competition's document is rebuilt by its next reader, which is the behaviour a miss
 * already has. That is also why the new column can be NOT NULL with no default.
 */
final class Version20261028023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace competition_response_cache.state_version with a collision-free state_fingerprint.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE competition_response_cache');
        $this->addSql('ALTER TABLE competition_response_cache DROP state_version');
        $this->addSql('ALTER TABLE competition_response_cache ADD state_fingerprint VARCHAR(64) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE competition_response_cache');
        $this->addSql('ALTER TABLE competition_response_cache DROP state_fingerprint');
        $this->addSql('ALTER TABLE competition_response_cache ADD state_version INT NOT NULL');
    }
}
