<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plot ownership: `plot_hold`, `plot_claim`, and the sell-back working in
 * `plot_claim_valuation`.
 *
 * THREE PARTIAL UNIQUE INDEXES, and they are the feature rather than decoration. Each settles a
 * race in the database instead of in application code that two concurrent requests would both
 * pass:
 *
 *   uq_plot_hold_active_plot   - two players tapping HOLD on one plot at the same instant
 *   uq_plot_hold_active_user   - one player trying to reserve the whole map
 *   uq_plot_claim_active_plot  - "one club per plot", the brief's own words
 *
 * All three are partial because released, consumed and historic rows accumulate and must be
 * allowed to repeat; each predicate is spelled exactly as pg_get_expr(indpred, indrelid) reports
 * it, so doctrine:schema:create builds them on a fresh wunderkind_test and migrations:diff does
 * not churn.
 *
 * WHY NO PREDICATE MENTIONS TIME. A unique index predicate must be IMMUTABLE, so
 * `WHERE expires_at > now()` is rejected by PostgreSQL. The hold predicates therefore key on
 * released_at/consumed_at only, which means an EXPIRED-but-unreleased hold still occupies the
 * index. The brief asked for "expired holds can be ignored when read; you do not need a cleanup
 * job" — that is still delivered, but it needed a mechanism rather than only a predicate: every
 * read filters on expires_at, and the acquire path releases exactly the two rows that could
 * block it immediately before inserting. See PlotHoldRepository::releaseBlockingExpired().
 *
 * FOREIGN KEY POLICIES, each chosen rather than defaulted:
 *
 * - plot_hold.stadium_estimate_id is RESTRICT, and that FK is what actually enforces the frozen
 *   quote. An estimate refresh physically cannot delete a row a live hold points at, so it
 *   supersedes instead and the player keeps the price they were shown.
 * - plot_hold.plot_id / user_id are CASCADE: a hold is ephemeral, with nothing worth preserving.
 * - plot_claim.plot_id / location_id are RESTRICT: a claim is a financial record, and geography
 *   must not be deleted out from under it. Retiring is the supported path.
 * - plot_claim.club_id is SET NULL, so a purchase record outlives its club. Note the
 *   consequence: because uq_plot_claim_active_plot keys on plot_id, a nulled-but-still-active
 *   claim would leave a plot owned by NOBODY and permanently unbuyable — so the club-delete path
 *   must RELEASE the claim first (PlotClaimRepository::releaseActiveForClubs()), not merely let
 *   the FK null it.
 * - plot_claim.user_id is CASCADE: the owner is the claim's reason to exist.
 * - every *_ledger_entry_id is SET NULL: a ledger row is the money and must never be deleted to
 *   tidy a claim, but a claim should survive one being archived.
 *
 * plot_claim_valuation is a split row for the reason schema.md gives for the others: its
 * working_json is written once and read only when someone disputes a payout, while plot_claim is
 * read in bulk. It captures the land value AS AT THE SELL-BACK, not just what was paid, because
 * land is revalued over time and a payout is otherwise unreproducible by the time anyone asks.
 */
final class Version20261103220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plot ownership: plot_hold, plot_claim, plot_claim_valuation, with three partial unique indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plot_hold (id UUID NOT NULL, land_pence BIGINT NOT NULL, stadium_pence BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, plot_id UUID NOT NULL, user_id UUID NOT NULL, stadium_estimate_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plot_hold_expires ON plot_hold (expires_at)');
        $this->addSql('CREATE INDEX idx_plot_hold_estimate ON plot_hold (stadium_estimate_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_plot_hold_active_plot ON plot_hold (plot_id) WHERE ((released_at IS NULL) AND (consumed_at IS NULL))');
        $this->addSql('CREATE UNIQUE INDEX uq_plot_hold_active_user ON plot_hold (user_id) WHERE ((released_at IS NULL) AND (consumed_at IS NULL))');
        $this->addSql('CREATE INDEX IDX_F86E9B55680D0B01 ON plot_hold (plot_id)');
        $this->addSql('CREATE INDEX IDX_F86E9B55A76ED395 ON plot_hold (user_id)');
        $this->addSql('CREATE TABLE plot_claim (id UUID NOT NULL, club_name_at_claim VARCHAR(100) DEFAULT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, release_reason VARCHAR(24) DEFAULT NULL, land_pence_paid BIGINT NOT NULL, stadium_pence_paid BIGINT NOT NULL, sell_back_pence BIGINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, plot_id UUID NOT NULL, location_id UUID NOT NULL, user_id UUID NOT NULL, club_id UUID DEFAULT NULL, stadium_estimate_id UUID DEFAULT NULL, land_ledger_entry_id UUID DEFAULT NULL, stadium_ledger_entry_id UUID DEFAULT NULL, sell_back_ledger_entry_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plot_claim_location_released ON plot_claim (location_id, released_at)');
        $this->addSql('CREATE INDEX idx_plot_claim_club ON plot_claim (club_id)');
        $this->addSql('CREATE INDEX idx_plot_claim_user_claimed ON plot_claim (user_id, claimed_at)');
        $this->addSql('CREATE UNIQUE INDEX uq_plot_claim_active_plot ON plot_claim (plot_id) WHERE (released_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_22EB4A69680D0B01 ON plot_claim (plot_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A6964D218E ON plot_claim (location_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A69A76ED395 ON plot_claim (user_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A6969A68164 ON plot_claim (stadium_estimate_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A69CBC40606 ON plot_claim (land_ledger_entry_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A69ABC5F078 ON plot_claim (stadium_ledger_entry_id)');
        $this->addSql('CREATE INDEX IDX_22EB4A69436BCD8 ON plot_claim (sell_back_ledger_entry_id)');
        $this->addSql('CREATE TABLE plot_claim_valuation (id UUID NOT NULL, working_json JSON NOT NULL, model_version VARCHAR(20) NOT NULL, computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reversed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reversal_reason VARCHAR(255) DEFAULT NULL, claim_id UUID NOT NULL, reversal_ledger_entry_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8C2400257096A49F ON plot_claim_valuation (claim_id)');
        $this->addSql('CREATE INDEX IDX_8C2400257CF0C63F ON plot_claim_valuation (reversal_ledger_entry_id)');
        $this->addSql('ALTER TABLE plot_hold ADD CONSTRAINT FK_F86E9B55680D0B01 FOREIGN KEY (plot_id) REFERENCES land_plot (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_hold ADD CONSTRAINT FK_F86E9B55A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_hold ADD CONSTRAINT FK_F86E9B5569A68164 FOREIGN KEY (stadium_estimate_id) REFERENCES stadium_estimate (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A69680D0B01 FOREIGN KEY (plot_id) REFERENCES land_plot (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A6964D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A69A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A6961190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A6969A68164 FOREIGN KEY (stadium_estimate_id) REFERENCES stadium_estimate (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A69CBC40606 FOREIGN KEY (land_ledger_entry_id) REFERENCES user_ledger (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A69ABC5F078 FOREIGN KEY (stadium_ledger_entry_id) REFERENCES user_ledger (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim ADD CONSTRAINT FK_22EB4A69436BCD8 FOREIGN KEY (sell_back_ledger_entry_id) REFERENCES user_ledger (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim_valuation ADD CONSTRAINT FK_8C2400257096A49F FOREIGN KEY (claim_id) REFERENCES plot_claim (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plot_claim_valuation ADD CONSTRAINT FK_8C2400257CF0C63F FOREIGN KEY (reversal_ledger_entry_id) REFERENCES user_ledger (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // FKs before tables, per the house convention.
        $this->addSql('ALTER TABLE plot_claim_valuation DROP CONSTRAINT FK_8C2400257096A49F');
        $this->addSql('ALTER TABLE plot_claim_valuation DROP CONSTRAINT FK_8C2400257CF0C63F');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A69680D0B01');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A6964D218E');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A69A76ED395');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A6961190A32');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A6969A68164');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A69CBC40606');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A69ABC5F078');
        $this->addSql('ALTER TABLE plot_claim DROP CONSTRAINT FK_22EB4A69436BCD8');
        $this->addSql('ALTER TABLE plot_hold DROP CONSTRAINT FK_F86E9B55680D0B01');
        $this->addSql('ALTER TABLE plot_hold DROP CONSTRAINT FK_F86E9B55A76ED395');
        $this->addSql('ALTER TABLE plot_hold DROP CONSTRAINT FK_F86E9B5569A68164');

        $this->addSql('DROP TABLE plot_claim_valuation');
        $this->addSql('DROP TABLE plot_claim');
        $this->addSql('DROP TABLE plot_hold');
    }
}
