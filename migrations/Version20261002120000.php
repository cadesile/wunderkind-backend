<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `user_ledger` — a centralized, user-level financial audit trail (currently dividend
 * draws reported via the sync payload's free-form ledger; see UserLedgerService). Now that a
 * single owner avatar persists across every club a User owns (see "Owner Identity" in
 * CLAUDE.md), this is what centralizes earnings across those clubs rather than leaving a
 * manager's dividend trapped inside whichever single club's totalCareerEarnings it landed on.
 *
 * source_sync_record_id is nullable + ON DELETE SET NULL, not CASCADE: a rollback purges
 * superseded sync_record rows (SyncRecordRepository::deleteByClubFromWeek()), but a dividend
 * draw already folded into a user's balance must survive that purge.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_ledger table (centralized cross-club dividend-draw audit trail)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_ledger (id UUID NOT NULL, user_id UUID NOT NULL, club_id UUID NOT NULL, type VARCHAR(30) NOT NULL, amount_pence INT NOT NULL, balance_before_pence INT NOT NULL, balance_after_pence INT NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, description VARCHAR(255) DEFAULT NULL, source_sync_record_id UUID DEFAULT NULL, source_ledger_index INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_ledger_user_recorded ON user_ledger (user_id, recorded_at)');
        // Doctrine auto-generates an index on every ManyToOne FK column that isn't otherwise
        // indexed; this isn't declared as an explicit #[ORM\Index] on the entity, so its name
        // must match Doctrine's own hash-based convention (confirmed via
        // doctrine:schema:update --dump-sql) or a later `migrations:diff` proposes a pointless
        // rename — same "diff noise" class CLAUDE.md already documents for this table.
        $this->addSql('CREATE INDEX IDX_A529BF8161190A32 ON user_ledger (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_user_ledger_source_entry ON user_ledger (source_sync_record_id, source_ledger_index)');
        $this->addSql('ALTER TABLE user_ledger ADD CONSTRAINT fk_user_ledger_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_ledger ADD CONSTRAINT fk_user_ledger_club FOREIGN KEY (club_id) REFERENCES club (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_ledger ADD CONSTRAINT fk_user_ledger_sync_record FOREIGN KEY (source_sync_record_id) REFERENCES sync_record (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ledger DROP CONSTRAINT fk_user_ledger_user');
        $this->addSql('ALTER TABLE user_ledger DROP CONSTRAINT fk_user_ledger_club');
        $this->addSql('ALTER TABLE user_ledger DROP CONSTRAINT fk_user_ledger_sync_record');
        $this->addSql('DROP TABLE user_ledger');
    }
}
