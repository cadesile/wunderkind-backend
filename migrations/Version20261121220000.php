<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A32 — one dial saying how good a CLUB scout is at judging players, relative to the staff
 * level of the tier they work in.
 *
 * Owner ruling, 2026-11-21, asked directly: ***"Lower tier scouts genuinely worse at judging
 * players is the expectation."***
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────────────────────
 *
 * `ScoutGenerationService` rolled all seven judgement categories from the flat global
 * `pool_config.scout_judgement_min/max` — **15-90 on the live dev row against the 40-80 the
 * entity promised**, which is this workstream's governing trap once more — for every scout at
 * every tier of every country. So a tier-8 scout judged players exactly as well as a tier-1
 * one, which the ruling above says is not what the game means.
 *
 * It also put a FLAT TERM inside a tier-relative figure. `ability` is
 * `0.7 x experience + 0.3 x mean(judgements)`; A25 banded the experience per country and tier,
 * so `0.3 x mean(judgements)` contributed a constant **15.75 points to every scout at every
 * tier**. Measured on the live EN bands: at tier 1 that is 34.9% of a 45.15 against a coach's
 * 42, and the two roles stay comparable; at tier 8 it is 88.2% of a 17.85 against a coach's 3.
 * Because A24 prices a scout's wage off `ability` through `ability^k` (k = 2.5 live), that
 * 5.95x ability gap became **65.6x in money** — reproduced to the penny on the owner's own
 * club, where one scout took GBP 9,740.57 of a GBP 10,881.71 weekly staff bill, 89.5%.
 *
 * ── WHAT THIS ADDS, AND WHY IT IS A SCALAR AND NOT A TABLE ───────────────────────────────
 *
 * A club scout's judgements now band off `StaffAbilityRangeService::resolve($country, $tier)`,
 * **the same authored per-country/per-tier table the experience and a coach's ability already
 * come from**, stretched by this one number in basis points.
 *
 * A per-country, per-tier judgement grid was deliberately NOT added. It would be a second
 * authored surface for "how good is a person at this tier", which is the defect class this
 * whole workstream exists to remove — and it would need 26 countries x 8 tiers of new figures
 * before anything could ship. One source for the band, one dial for the relationship.
 *
 * ── WHY THE DEFAULT IS 10000 AND WHY IT IS NOT A VERDICT ─────────────────────────────────
 *
 * 10000 = x1.00 = exactly the tier's own staff band, which is the only value that needs no new
 * figure to be authored. The owner deferred the number on purpose, same day: *we can worry
 * about the bands after we've tested that the process of matching the scaling to the tier is
 * correctly implemented, because we can then view the raw data against the scouts' reviews and
 * make a judgment call.* This column is what makes that later call a DATA EDIT rather than
 * another release — it is admin-editable on Staff Pool Rules, beside the untiered pair.
 *
 * ⚠️ **Measured consequence of the default at the TOP of the pyramid, so it is not a
 * surprise:** an EN tier-1 scout's judgement mean becomes 42 against today's 52.5, so their
 * displayed accuracy reads 42% rather than 53%. **12500 holds tier 1's mean at exactly today's
 * 52.5** while still banding every tier below it; that figure is derived (52.5 / 42), not
 * chosen. Full curve at both ends of the pyramid:
 * `wunderkind-docs/economy-loose-ends/a32-scout-ability-floor-report.md`.
 *
 * ── NO BACKFILL, AND THAT IS NOT AN OVERSIGHT ────────────────────────────────────────────
 *
 * Nothing re-rolls an existing scout's judgements. A scout's judgements are the person, not a
 * price, and `app:backfill-scout-abilities` re-derives `ability` from stored inputs rather than
 * re-rolling them — so there is nothing here for it to do either. This changes what gets
 * GENERATED, which means new saves and newly generated scouts.
 *
 * ⚠️ **World packs hold `judgements` as well as `ability`** (`WorldScout`), so existing sets carry
 * scouts rolled under the flat window and a save built from one gets the old scouts whatever the
 * generator now does. ✅ **So `WorldInitializationService::WORLD_PACK_VERSION` goes 14 -> 15 in
 * this same change, by owner ruling 2026-11-21.** Both `countReadyUnclaimed()` and
 * `claimOldestReady()` filter on `payload_version`, so the bump makes every v14 set uncountable
 * (a deficit appears and the per-init top-up rebuilds the country on its own) AND unclaimable, so
 * no new save can be handed an old-scout set even by accident.
 *
 * ⚠️ **THE BUMP AND THIS MIGRATION MUST DEPLOY TOGETHER.** Bump first and every country rebuilds
 * at v15 with the OLD scouts baked in; generate first and new sets are stamped v14 beside the
 * existing v14 sets, so two populations of scouts share one version number and the pack indicator
 * cannot distinguish them. Landing them together means neither state exists.
 */
final class Version20261121220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pool_config.scout_judgement_band_bp — how good a club scout judges, relative to its tier.';
    }

    public function up(Schema $schema): void
    {
        // NOT NULL with a DB-level DEFAULT that is KEPT, matching the entity's own default and
        // the sibling scout columns beside it. The default is what makes this a no-op on every
        // existing row: a deployment that applies this migration and nothing else generates
        // judgements off the staff band at x1.00, with no operator action required.
        $this->addSql('ALTER TABLE pool_config ADD scout_judgement_band_bp INT NOT NULL DEFAULT 10000');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config DROP scout_judgement_band_bp');
    }
}
