<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An append-only record of every change the inflation rate has had.
 *
 * WHY THE RATE GETS A HISTORY WHEN NO OTHER ECONOMY FIGURE DOES. Changing a tier factor or a
 * turnover baseline re-prices what the backend GENERATES from then on — the old value stops
 * meaning anything, and game_config holding only the current one is honest. The inflation rate
 * is different in kind: the app compounds it over a club's season count, so a save twenty
 * seasons in has been living under whatever the rate was for each of them. Change it and every
 * existing save's prices move retroactively, and with only the current value stored there is
 * no way to answer "why was this club's ceiling that" afterwards.
 *
 * NOTHING READS THIS TABLE TO COMPUTE ANYTHING, and that is worth not quietly improving. The
 * index is always derived from the CURRENT rate in game_config, never replayed from here — a
 * replayed history would make a club's ceiling depend on when an operator happened to click
 * save, which is unexplainable to a player and untestable. This is for the operator and the
 * auditor, not the arithmetic.
 *
 * `previous_rate_per_season_bp` is STORED rather than inferred from the preceding row.
 * Reconstructing "what it was before" from a timestamp ordering is exactly the derivation that
 * breaks the first time two changes land in the same second. NULL only on the first ever row.
 *
 * `changed_by_admin_id` is nullable ON DELETE SET NULL: an audit row has to outlive the admin
 * account that wrote it. Same reasoning as user_ledger.source_sync_record_id.
 *
 * CREATED EMPTY. No row is written here, so an untouched rate has no history — which is
 * distinguishable from a recorded rate of zero, and is the inert state.
 */
final class Version20261112160200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create inflation_rate_history, the append-only audit trail for the one economy figure whose past values keep mattering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inflation_rate_history (
                id UUID NOT NULL,
                changed_by_admin_id UUID DEFAULT NULL,
                rate_per_season_bp INT NOT NULL,
                previous_rate_per_season_bp INT DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_inflation_rate_history_recorded ON inflation_rate_history (recorded_at)');
        $this->addSql('CREATE INDEX IDX_INFLATION_RATE_HISTORY_ADMIN ON inflation_rate_history (changed_by_admin_id)');
        $this->addSql(
            'ALTER TABLE inflation_rate_history ADD CONSTRAINT FK_INFLATION_RATE_HISTORY_ADMIN '
            . 'FOREIGN KEY (changed_by_admin_id) REFERENCES admin (id) ON DELETE SET NULL NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inflation_rate_history DROP CONSTRAINT FK_INFLATION_RATE_HISTORY_ADMIN');
        $this->addSql('DROP TABLE inflation_rate_history');
    }
}
