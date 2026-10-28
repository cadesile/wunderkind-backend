<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the leftover DC2Type column comments on friendly_challenge and idempotency_record.
 *
 * Comments only — no column, index or data is touched. Both tables were created by
 * migrations written before the DBAL 4 upgrade, which annotated uuid and
 * datetime_immutable columns with `(DC2Type:...)`; the current mapping emits none, so
 * every `doctrine:schema:validate` run reported the schema out of sync over eleven empty
 * strings. That red was load-bearing noise: it hides real structural drift from whoever
 * runs the command next, which during the tournament data-model work was the gate used to
 * catch an entity that had silently lost its ORM import.
 */
final class Version20261028141656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop leftover DC2Type column comments so doctrine:schema:validate is green again';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN friendly_challenge.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.challenger_club_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.acceptor_club_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.expires_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.accepted_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.kickoff_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.resolved_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.resolve_locked_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN idempotency_record.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN idempotency_record.created_at IS \'\'');
    }

    public function down(Schema $schema): void
    {
        // Restores the comments exactly as the pre-DBAL-4 migrations wrote them.
        $this->addSql('COMMENT ON COLUMN friendly_challenge.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.accepted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.kickoff_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.resolved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.resolve_locked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.challenger_club_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN friendly_challenge.acceptor_club_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN idempotency_record.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN idempotency_record.created_at IS \'(DC2Type:datetime_immutable)\'');
    }
}
