<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stop a youth scholar out-earning an average first-team player.
 *
 * THE DEFECT, found by the step-8 threshold review: `youthScholarshipWagePence` is a FLAT
 * £250/week, and the turnover model makes an average England tier-8 first-team wage about £127.
 * So a thirteen-year-old scholar was set to earn nearly twice an average professional, and at
 * tier 7 about half. The legacy model never inverts (tier 8 averages ~£480 there), so this is a
 * consequence of the overhaul rather than a pre-existing bug.
 *
 * A CAP, NOT A REPLACEMENT. The flat figure is right at tiers 1-6 and wrong at 7-8. Expressing
 * the whole scholarship as a share of the band would have re-priced every tier to fix two of
 * them and thrown away a hand-authored number that works. The cap changes only the tiers where
 * the flat figure is implausible:
 *
 *     scholarship = min(youthScholarshipWagePence, worstFirstTeamWageAtTier x capBp)
 *
 * Anchored to the WORST first-team wage at the tier rather than the average, because that is
 * the comparison the defect is about — a scholar must cost less than the cheapest professional
 * at the club, and anchoring to the average would still let one out-earn the bottom of the
 * squad.
 *
 * ── 50% RATHER THAN THE USUAL ZERO-MEANS-OFF, AND STILL A PROVABLE NO-OP ──────────────────
 *
 * Every other switch in this series ships in its inert position. This one ships ON, and is
 * still inert: the cap is computed from the TURNOVER model's band wage, and under `legacy` — or
 * with the turnover table unauthored, which is the shipped state — there is no band wage to cap
 * against, so the flat figure is returned untouched. Verified against a full data clone.
 *
 * Shipping it off would have meant a cap that has to be remembered at flip time, and the whole
 * point is that the inversion cannot reach a player. 0 still means "no cap" for an operator who
 * wants the flat figure whatever happens.
 *
 * ── `youthScholarshipWagePence` IS UNTOUCHED ──────────────────────────────────────────────
 *
 * Still published, still a flat weekly figure, still meaning exactly what it meant. The
 * resolved per-tier figure arrives as a NEW key inside `economyTierBaseline.tiers[]`, so an
 * older build keeps reading the flat one — which is the correct fallback for a client that does
 * not know about tiers having their own scholarship. No key removed, renamed or re-meant.
 *
 * Reversible: one drop.
 */
final class Version20261112220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cap a youth scholarship at a share of the tier\'s worst first-team wage, so a scholar cannot out-earn a professional.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD economy_youth_scholarship_cap_bp INT DEFAULT 5000 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_youth_scholarship_cap_bp');
    }
}
