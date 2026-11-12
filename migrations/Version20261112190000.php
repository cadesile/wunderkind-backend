<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lift the five staff wage bands out of code, and settle a disagreement between two copies.
 *
 * These were hardcoded `random_int()` literals inside `StaffGenerationService::build()`, with a
 * SECOND, DIFFERENT set in `GenerateMarketDataCommand`. The two disagreed about a coach —
 * 2000-8000 against 4000-10000 — so the same role cost different money depending on which path
 * created it, and neither path was wrong on purpose.
 *
 * SEEDED WITH StaffGenerationService'S VALUES, because that is the path that generates the
 * world. A coach created by the market-data command therefore gets cheaper, which is the
 * correct direction: that command is a manual "generate dummy data" tool, not a world builder.
 *
 * THE BAND NOW DOES TWO JOBS, which is why it was worth lifting rather than replacing. Its
 * MIDPOINT is the role's weight under the turnover model — what a chairman costs relative to a
 * facility manager — and its SPREAD is how ability moves pay within the role. Both were already
 * tuned by hand, so deriving either from a new config knob would have thrown that away and
 * asked an operator to re-tune five roles from nothing.
 *
 * INERT: the seeded values are exactly what the code used, so `legacy` generation produces the
 * same figures it always did.
 *
 * JSON not JSONB, with the default dropped straight after the add — the same pattern as the
 * other json columns in this series, and for the same reason: `game_config` is a populated
 * singleton so NOT NULL needs something for the existing row, but a json column keeping a
 * database-side default is drift the mapping does not declare.
 */
final class Version20261112190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the five staff wage bands from code into game_config, seeded with the values StaffGenerationService used.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE game_config ADD economy_staff_role_bands_pence JSON DEFAULT '{"coach":[2000,8000],"manager":[10000,30000],"director_of_football":[12000,35000],"facility_manager":[3000,8500],"chairman":[20000,60000]}' NOT NULL
        SQL);
        $this->addSql('ALTER TABLE game_config ALTER economy_staff_role_bands_pence DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_staff_role_bands_pence');
    }
}
