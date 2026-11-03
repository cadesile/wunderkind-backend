<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opens `user_ledger` up to account-level entries, and fixes a money bug on the way.
 *
 * Needed because land and stadium purchases are charged to ACCOUNT credit, never to
 * `club.balance`: the device owns a club's balance and overwrites it absolutely on every sync
 * (SyncService::process()), so a server-side deduction there would survive until the next sync
 * and then vanish. `user_ledger` is the backend-owned, durable record, which means it has to be
 * able to hold entries that belong to an account rather than to a club.
 *
 * THREE CHANGES.
 *
 * 1. `club_id` becomes NULLABLE, and its FK becomes ON DELETE SET NULL.
 *
 *    This is a bug fix, not only an enabler. While the column was NOT NULL with a plain
 *    no-action FK it could neither cascade nor be nulled, so BOTH delete paths had to DELETE a
 *    club's ledger rows in order to delete the club at all. The balance is a chain of
 *    before/after snapshots and UserLedgerRepository::getCurrentBalance() reads the latest
 *    surviving row — so deleting a club that owned the most recent entries rolled its owner's
 *    balance back to an earlier figure. Money disappeared from an account whose owner was not
 *    being deleted. DeleteAdminController's own comment described the symptom and wished it were
 *    otherwise. The rows are now detached by the database and the chain stays whole.
 *
 *    Existing rows are unaffected: dropping NOT NULL cannot fail, and every current row keeps
 *    the club it already names. The FK is recreated rather than altered because PostgreSQL has
 *    no ALTER CONSTRAINT for ON DELETE, which also means its generated name changes from
 *    `fk_user_ledger_club` to Doctrine's `FK_A529BF8161190A32`.
 *
 * 2. `source_kind` + `source_ref`, with a PARTIAL unique index over them plus `type`.
 *
 *    `uq_user_ledger_source_entry` makes sync-sourced rows idempotent, but it keys on
 *    (source_sync_record_id, source_ledger_index) — both NULL for every backend-originated row,
 *    and PostgreSQL treats NULLs as distinct, so it offers those rows no protection whatsoever.
 *    A double-tapped purchase would write two rows and debit the account twice. These columns
 *    are that guard.
 *
 *    The index is partial for the same NULL reason, and `type` is part of the key because one
 *    plot claim legitimately writes both a LAND_PURCHASE and a STADIUM_PURCHASE against the
 *    same source_ref. Declared on the entity with the predicate spelled exactly as
 *    pg_get_expr(indpred, indrelid) reports it, so doctrine:schema:create builds it on a fresh
 *    wunderkind_test and migrations:diff does not churn.
 *
 *    No backfill: every existing row has both columns NULL, which the predicate excludes, so the
 *    index cannot be violated by existing data.
 *
 * 3. Nothing here changes any stored VALUE. The behavioural half of this change — filtering
 *    UserLedgerRepository's dividend aggregates by type so a negative purchase row cannot
 *    deflate the PUBLIC career-earnings leaderboard, and restricting the landing page's Rich
 *    List to accounts that have actually drawn a dividend — is in that repository, and ships in
 *    the same commit deliberately. Shipping this schema without it would leave a window in
 *    which the first purchase row silently corrupted a public board.
 */
final class Version20261103180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_ledger: nullable club (SET NULL), source_kind/source_ref + partial unique for backend-originated entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ledger ADD source_kind VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_ledger ADD source_ref VARCHAR(64) DEFAULT NULL');

        $this->addSql('ALTER TABLE user_ledger ALTER club_id DROP NOT NULL');

        // PostgreSQL cannot ALTER a constraint's ON DELETE, so the FK is replaced.
        $this->addSql('ALTER TABLE user_ledger DROP CONSTRAINT fk_user_ledger_club');
        $this->addSql('ALTER TABLE user_ledger ADD CONSTRAINT FK_A529BF8161190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE UNIQUE INDEX uq_user_ledger_backend_source ON user_ledger (source_kind, source_ref, type) WHERE ((source_kind IS NOT NULL) AND (source_ref IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_user_ledger_backend_source');

        $this->addSql('ALTER TABLE user_ledger DROP CONSTRAINT FK_A529BF8161190A32');

        // Going back requires every row to name a club again. Account-level entries (grants,
        // pre-club purchases) genuinely have none, so they cannot be represented in the old
        // shape and are deleted — this is a lossy down(), and deliberately so rather than
        // silently attaching them to an arbitrary club.
        $this->addSql('DELETE FROM user_ledger WHERE club_id IS NULL');

        $this->addSql('ALTER TABLE user_ledger ALTER club_id SET NOT NULL');
        $this->addSql('ALTER TABLE user_ledger ADD CONSTRAINT fk_user_ledger_club FOREIGN KEY (club_id) REFERENCES club (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE user_ledger DROP source_ref');
        $this->addSql('ALTER TABLE user_ledger DROP source_kind');
    }
}
