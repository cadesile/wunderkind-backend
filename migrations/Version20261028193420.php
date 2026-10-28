<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stores the registration envelope a client actually posted, for competitions and friendlies.
 *
 * `clubDisplayBlock()` picks ~22 known keys and drops the rest, which is what keeps a public
 * bracket render bounded — but it meant everything outside that set was decoded and thrown
 * away. A real submission is ~1.4MB, nearly all of it `club` history the client sends every
 * time (cupTrophies, facilityUpgrades, financials, stadium). These two tables keep it.
 *
 * Additive only: nothing already stored moves or changes, so the split's read profiles are
 * untouched and there is no backfill — rows begin at the next registration. Deliberately not
 * pruned when a competition completes, unlike the matching *_squad tables.
 */
final class Version20261028193420 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the verbatim client registration envelope for competition entrants and friendly challenge sides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE competition_entrant_submission (id UUID NOT NULL, envelope_json JSON NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, entrant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_competition_entrant_submission_entrant ON competition_entrant_submission (entrant_id)');
        $this->addSql('CREATE TABLE friendly_challenge_submission (id UUID NOT NULL, side VARCHAR(16) NOT NULL, envelope_json JSON NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, challenge_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_friendly_challenge_submission_side ON friendly_challenge_submission (challenge_id, side)');
        $this->addSql('CREATE INDEX IDX_B5DAD9FE98A21AC6 ON friendly_challenge_submission (challenge_id)');
        $this->addSql('ALTER TABLE competition_entrant_submission ADD CONSTRAINT FK_9EF87D268BFF9D26 FOREIGN KEY (entrant_id) REFERENCES competition_entrant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friendly_challenge_submission ADD CONSTRAINT FK_B5DAD9FE98A21AC6 FOREIGN KEY (challenge_id) REFERENCES friendly_challenge (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_entrant_submission DROP CONSTRAINT FK_9EF87D268BFF9D26');
        $this->addSql('ALTER TABLE friendly_challenge_submission DROP CONSTRAINT FK_B5DAD9FE98A21AC6');
        $this->addSql('DROP TABLE competition_entrant_submission');
        $this->addSql('DROP TABLE friendly_challenge_submission');
    }
}
