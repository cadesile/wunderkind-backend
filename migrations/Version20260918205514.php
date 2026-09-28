<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Club.isSpoof for admin-generated spoof entrants (CompetitionSpoofEntrantService).
 *
 * `doctrine:migrations:diff` also proposed dropping several hand-added partial unique
 * indexes (uq_active_competition_one_open_per_template, uq_entrant_reward_claim_*) and
 * unrelated DROP DEFAULT/rename noise from pre-existing schema drift that predates this
 * change — none of that belongs here, so only the is_spoof column is kept.
 */
final class Version20260918205514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Club.isSpoof for admin-generated spoof competition entrants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD is_spoof BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP is_spoof');
    }
}
