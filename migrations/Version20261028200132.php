<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `competition_entrant.squad_state`, the flag that makes queued registration safe.
 *
 * Registration now reserves a slot in a short locked transaction and queues the ~1.4MB squad
 * and submission write, so an entrant briefly exists holding a capacity slot with no XI. This
 * column is how the draw knows: CompetitionDrawService refuses to draw a competition with any
 * entrant not in 'stored'.
 *
 * Deliberately a separate axis from `status`, which is serialized straight to a client already
 * live in production — see App\Enum\Competition\EntrantSquadState.
 *
 * DEFAULT 'stored' is what makes this need no backfill: every existing row was written by the
 * old synchronous path and genuinely does have its squad, so the default is the truth for all
 * of them rather than a placeholder.
 */
final class Version20261028200132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add competition_entrant.squad_state so a queued registration cannot be drawn before its XI lands.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_entrant ADD squad_state VARCHAR(16) DEFAULT \'stored\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_entrant DROP squad_state');
    }
}
