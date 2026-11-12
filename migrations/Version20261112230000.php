<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a youth scholarship be anchored at tier 1 and scale down the ladder.
 *
 * ── WHAT `Version20261112220000` GOT HALF-RIGHT ───────────────────────────────────────────
 *
 * That migration capped the scholarship so a tier-8 scholar could not out-earn a professional,
 * and its docblock claimed "the flat figure is right at tiers 1-6 and wrong at 7-8". Measuring
 * the authored England ladder showed the claim was about plausibility rather than proportion:
 * £250/week is **0.19% of an average tier-1 first-team wage**. Not a cheap academy — a rounding
 * error. One absolute figure cannot be right at both ends of a ladder spanning 1,047x, so the
 * cap fixed the end that threw an error and left the other end quietly wrong.
 *
 * ── ONE KNOB, AND WHY IT IS NOT A TABLE ───────────────────────────────────────────────────
 *
 * `economy_youth_scholarship_tier1_pence` is what a scholar costs at the TOP. Every lower tier
 * follows the wage ladder in the same proportion:
 *
 *     scholarship(tier) = anchor x averageWage(tier) / averageWage(1)
 *
 * Not eight authored rows: the per-tier shape is already expressed by the turnover ladder, and
 * authoring it twice invites the two to disagree — the same reason sponsorship takes the
 * country factor but not the tier factor.
 *
 * And NOT scaled by `S_tier`, which was the obvious-looking choice and is catastrophic: it
 * carries almost none of the spread (1.72x against the wage ladder's 1,047x), so anchoring at
 * £1,500 would put a tier-8 scholar on £873 against an average professional's £127 — 6.9x worse
 * than the defect being fixed. Measured on a data clone before anything was written.
 *
 * ── WHY THERE IS ALSO A FLOOR ─────────────────────────────────────────────────────────────
 *
 * A pure proportion is as broken at the bottom as a flat figure is at the top. Anchored at
 * £1,500 the ladder reaches £5.05 at tier 7 and £1.43 at tier 8, and free is a different
 * mechanic from cheap. `economy_youth_scholarship_floor_pence` holds the bottom up; the
 * existing cap still bounds the top, applied LAST so it always wins. With the anchor at £1,500
 * and the floor at £25, against the authored England table:
 *
 *     tier 1  £1,500.00   1.1% of an average professional at that tier
 *     tier 2  £554.79     1.1%
 *     tier 3  £187.14     1.1%
 *     tier 4  £110.41     1.1%
 *     tier 5  £45.12      1.1%
 *     tier 6  £25.00      1.5%   floored
 *     tier 7  £25.00      5.6%   floored
 *     tier 8  £25.00      19.7%  floored
 *
 * ── THE CAP'S ANCHOR MOVED, AND THAT IS A DELIBERATE REDEFINITION ─────────────────────────
 *
 * `economy_youth_scholarship_cap_bp` was a share of the tier's WORST first-team wage. England
 * tier 8's cheapest professional earns **£12.84/week** — ability 2 at the bottom of a 2-7 band
 * through a 2.5 exponent — so half of that is £6.42, and the old rule did not deliver "cheaper
 * than a professional" at the bottom so much as "free". It is now a share of the tier's
 * AVERAGE first-team wage, which is the comparison the gate command already reports. The
 * invariant becomes sayable in one line: a scholar never earns more than `capBp` of an average
 * professional at their tier.
 *
 * No column changes type or name, and this is safe to redefine rather than replace **because
 * the cap has never applied to a published figure**: it is gated on `economyWageModel =
 * turnover`, which is `legacy` on every deployed box.
 *
 * The accepted cost, stated rather than buried: where a band is as wide as tier 8's, a
 * scholarship can exceed the single cheapest professional's wage. £12.84/week is an artefact of
 * the ability curve rather than a wage anybody authored, and it is logged as its own finding in
 * `docs/economy-threshold-review.md`.
 *
 * ── SHIPS INERT ───────────────────────────────────────────────────────────────────────────
 *
 * Both columns default to 0. Zero anchor means the flat figure and its cap stand exactly as
 * before, so this migration cannot move a published value on any box — and the mechanism is
 * additionally unreachable while `economyWageModel` is `legacy`. `youth_scholarship_wage_pence`
 * is untouched and still published: it remains the correct fallback for a client that does not
 * know tiers have their own scholarship.
 *
 * Reversible: two drops.
 */
final class Version20261112230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Anchor a youth scholarship at tier 1 and scale it down the wage ladder, with a floor; both default to off.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD economy_youth_scholarship_tier1_pence INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_youth_scholarship_floor_pence INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_youth_scholarship_tier1_pence');
        $this->addSql('ALTER TABLE game_config DROP economy_youth_scholarship_floor_pence');
    }
}
