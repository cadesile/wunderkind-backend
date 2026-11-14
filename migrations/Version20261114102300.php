<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * club_sale — the authorisation, figure and receipt for an admin club buyout.
 *
 * ADDITIVE AND INERT. One new table, no column added to or removed from an existing one, no data
 * backfilled. Nothing reads it until an operator uses the new admin action, so applying this
 * changes no behaviour anywhere.
 *
 * The partial unique index is the whole point of the table's integrity: one live sale per club,
 * with FAILED rows excluded so a retry after a fixed fault does not need a row deleted by hand.
 * It has to be raw SQL — a plain UNIQUE would make a failed attempt permanent.
 *
 * `club_id` CARRIES NO FOREIGN KEY, deliberately. ClubSaleService deletes the club immediately
 * after crediting, so this row exists precisely to outlive it; an FK would be SET NULL at best
 * and would take the club's identity with it. Same reasoning as season_ratings_snapshot.club_id.
 */
final class Version20261114102300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club_sale: admin club-buyout authorisation, valuation working and receipt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE club_sale (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                club_id VARCHAR(36) NOT NULL,
                club_name VARCHAR(100) NOT NULL,
                estimate_pence BIGINT NOT NULL,
                agreed_pence BIGINT NOT NULL,
                working_json JSON NOT NULL,
                ledger_note VARCHAR(255) DEFAULT NULL,
                admin_id VARCHAR(36) DEFAULT NULL,
                admin_email VARCHAR(180) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                ledger_entry_id UUID DEFAULT NULL,
                scrub_counts_json JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_club_sale_user_created ON club_sale (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_club_sale_status ON club_sale (status)');
        $this->addSql('CREATE INDEX IDX_81830AE5A76ED395 ON club_sale (user_id)');
        $this->addSql('CREATE INDEX IDX_81830AE5EB264CB8 ON club_sale (ledger_entry_id)');

        // One live sale per club. Partial, so a FAILED attempt can be retried.
        $this->addSql(
            "CREATE UNIQUE INDEX uq_club_sale_club_live ON club_sale (club_id)"
            . " WHERE ((status)::text <> 'failed'::text)"
        );

        $this->addSql(
            'ALTER TABLE club_sale ADD CONSTRAINT FK_81830AE5A76ED395'
            . ' FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE'
        );

        // SET NULL: the money outlives this record, not the reverse.
        $this->addSql(
            'ALTER TABLE club_sale ADD CONSTRAINT FK_81830AE5EB264CB8'
            . ' FOREIGN KEY (ledger_entry_id) REFERENCES user_ledger (id) ON DELETE SET NULL NOT DEFERRABLE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_sale DROP CONSTRAINT FK_81830AE5A76ED395');
        $this->addSql('ALTER TABLE club_sale DROP CONSTRAINT FK_81830AE5EB264CB8');
        $this->addSql('DROP TABLE club_sale');
    }
}
