<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Staff pay gets a tier dimension under the `legacy` wage model.
 *
 * ── The defect ───────────────────────────────────────────────────────────────────────────
 *
 * A tier-8 club was paying roughly £1,000/week in staff against ~£165/week for its entire
 * squad. Two causes, and only the second is schema:
 *
 *   1. Staff ABILITY came from one global range (`pool_config.coach_ability_min/max`, 4-75 on
 *      dev) whatever the club's tier, so the bottom of the pyramid drew managers from the same
 *      distribution as the top — and ability drives `staffMultiplier`, 2.0 up to 12.0. Fixed in
 *      code by passing the club's tier band through `StaffGenerationService::build()`.
 *   2. `economy_staff_role_bands_pence` is a single absolute figure per role, so it can only be
 *      right at ONE tier. Players do not have this problem: a legacy player's wage scales with
 *      ability and ability is per-tier. Staff had no tier dimension at all.
 *
 * This is the same shape of defect as the youth scholarship, where a flat £250 was 1.97x an
 * average tier-8 wage AND 0.19% of an average tier-1 wage — a single absolute figure cannot be
 * right across a 1,047x spread.
 *
 * ── What these two columns do ────────────────────────────────────────────────────────────
 *
 * `economy_staff_tier_scaling_enabled` turns on an anchored ladder: the role band is scaled by
 * the tier's authored ability-band midpoint over the top tier's, read from
 * `starter_config.league_ability_ranges` (see `Economy\TierBandScaleResolver` for why that
 * table and not `EconomyScaleResolver`, which needs turnover nobody has authored).
 * `economy_staff_tier_floor_pence` stops the bottom of the coach band landing near £1.38/week,
 * which reads as broken rather than as cheap.
 *
 * **It ships ON, which is NOT this repo's usual ship-inert pattern, and that is deliberate.**
 * The inert default exists for changes that are merely untuned; this one is agreed to be
 * wrong. The switch is still a column rather than a constant so a box can have the old figures
 * back without a deploy — and it needs to be reversible, because the scaling is baked into
 * cached world packs.
 *
 * Applies to `legacy` only. Under `turnover`, `turnoverStaffPence()` already scales the band by
 * the tier's real turnover, which is strictly better; this is what lets `legacy` behave sanely
 * until that model is switched on.
 *
 * ── Paired with a WORLD_PACK_VERSION bump, 7 -> 8 ────────────────────────────────────────
 *
 * `WorldPackSnapshotBuilder::buildStaffSnapshot()` writes `weeklySalary` into every cached
 * pack, so repricing staff changes pack CONTENT without changing its shape — exactly what that
 * constant's docblock is a post-mortem of, twice over. The bump makes every existing
 * `WorldPackSet` unclaimable at once; countries restock through `app:worldpack:maintain`
 * without an operator, and until they do, new games generate their world live.
 */
final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Staff pay scales by tier under the legacy wage model (+ a floor).';
    }

    public function up(Schema $schema): void
    {
        // Both carry their shipped defaults, so the existing singleton row picks them up with
        // no backfill — and the ON default is the behaviour change, stated plainly rather than
        // smuggled in as a side effect of running migrations.
        $this->addSql('ALTER TABLE game_config ADD economy_staff_tier_scaling_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_staff_tier_floor_pence INT DEFAULT 500 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_staff_tier_floor_pence');
        $this->addSql('ALTER TABLE game_config DROP economy_staff_tier_scaling_enabled');
    }
}
