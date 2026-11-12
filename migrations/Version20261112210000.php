<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The base owner-investment ceiling, shipping at zero so the feature is unreachable.
 *
 * ZERO IS NOT A NEUTRAL DEFAULT HERE, IT IS AN OFF SWITCH, and that is the whole point. The
 * resolved ceiling is `base x countryFactor x tierFactor x inflationIndex(season)`, so a base
 * of zero makes EVERY amount above the ceiling and `POST /api/club/season-investment` refuses
 * with `422 above_season_ceiling`. Both of that endpoint's new error codes are therefore
 * unreachable until an operator authors a figure — which is how a client-visible refusal ships
 * ahead of any client that understands it. Same shape as
 * `429 enrichment_quota_exhausted` arriving behind a cap of 0 = unlimited.
 *
 * NO LEDGER MIGRATION ACCOMPANIES THIS. `UserLedgerEntryType::SEASON_INVESTMENT` is a new enum
 * case on a `varchar(30)` column whose longest value is 17 characters — `season_investment` is
 * also 17 — so the column needs nothing. `UserLedgerEntryTypeTest` asserts that bound so a
 * future case cannot silently overflow it. Idempotency comes from the existing
 * `uq_user_ledger_backend_source` partial unique index on `(source_kind, source_ref, type)`;
 * no new index either.
 *
 * Reversible: one drop.
 */
final class Version20261112210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the base owner season-investment ceiling, defaulting to 0 which leaves the feature unreachable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD economy_season_investment_base_pence BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_season_investment_base_pence');
    }
}
