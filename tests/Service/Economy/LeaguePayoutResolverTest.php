<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeaguePayoutResolver;
use PHPUnit\Framework\TestCase;

/**
 * The champion's prize and the position pot: a tier-1 base plus eight ratios in PARTS PER
 * MILLION, and what each tier is actually paid.
 *
 * ── WHAT THIS PINS ────────────────────────────────────────────────────────────────────────
 *
 * The defect being fixed was not an arithmetic bug — the old code multiplied nothing. It was a
 * LADDER SHAPE: per-club figures sized as though they were pools, which inverted at tier 3 and
 * paid a tier-8 champion 30.33x its own authored turnover. So the assertions here are about
 * SHARES and about the resolution ORDER, not about whether a multiplication happened.
 *
 * ── THE FOUR WAYS THIS GOES WRONG SILENTLY ────────────────────────────────────────────────
 *
 *  1. **int64 overflow.** The sibling resolvers end in one `intdiv` over `BP * BP`. At a ppm
 *     ratio and a £100,000,000 base that numerator is 1e20 against a 9.223e18 ceiling, and PHP
 *     does not raise — it silently becomes a float and loses pence. There is a test for exactly
 *     this below, because it is the one failure that would look like a rounding quirk.
 *  2. **A ratio applied to an override.** A league's own column is somebody's resolved answer
 *     for that division. Scaling it again means a hand-authored figure moves when a ratio is
 *     retuned, and nothing in the data would say which number you were going to get.
 *  3. **Null read as zero.** Null is "unauthored" and renders as a dash; zero is "this division
 *     pays nothing". Collapsing them would publish £0 prizes as though somebody had chosen them.
 *  4. **The taper sum assumed.** It is 5.5 for any league of 11+ clubs at a 10% taper, which is
 *     precisely why hardcoding it is tempting and wrong: `slots` is 10 in KR and 20 in FR, and
 *     the decrease percent has a per-league override.
 */
class LeaguePayoutResolverTest extends TestCase
{
    /**
     * The turnover ladder authored by `Version20261118120000` (resolution R2), in pence.
     *
     * RE-POINTED 2026-11-18 FROM THE 2026-11-13 LADDER, which is now `LEGACY_TURNOVER` below and
     * survives only in the historical regression test. The ratio tables ARE the turnover ladder
     * normalised to tier 1, so the two constants move together or this file pins a ladder that
     * no box is running.
     */
    private const TURNOVER = [
        33100000000, 11400000000, 3460000000, 2070000000,
        732000000, 269000000, 68600000, 21500000,
    ];

    /** The authored ladder: the turnover table normalised to tier 1, to three significant figures. */
    private const RATIO_PPM = [1000000, 344411, 104532, 62538, 22115, 8127, 2073, 650];

    /** £85,166,300 = 0.2573x tier 1's R2 turnover, down from £100,000,000. */
    private const TIER1_PENCE = 8_516_630_000;

    /**
     * The turnover ladder authored on dev 2026-11-13 and superseded by R2.
     *
     * Kept ONLY for `testTheOldLadderFailedTheConstraintsThisOneMeets`, which needs the old
     * denominator to show that the pre-`5dc3f51b` figures genuinely tripped the constraints.
     */
    private const LEGACY_TURNOVER = [
        38866153952, 12004975671, 3557660225, 1990900941,
        707179580, 233989880, 58278460, 16485154,
    ];

    /**
     * England's authored TV deal per club, in pence — an INPUT here, not something this work
     * moves.
     *
     * It is 35.0% of tier 1's OLD turnover and 3.0% of tier 8's, which is the monotone ladder
     * the class docblock quotes. R2 does not touch it, and that is exactly why it matters below:
     * turnover fell 15% at tier 1 while TV stayed put, so TV's share rises 35% -> 41%.
     */
    private const TV_PER_CLUB = [13600000000, 3360000000, 712000000, 299000000, 77800000, 18700000, 2910000, 495000];

    // ── Inert at parity, which is the shipped state ──────────────────────

    public function testBothTablesShipAtParityAndBothBasesShipUnauthored(): void
    {
        $config = new GameConfig();

        self::assertSame([1000000, 1000000, 1000000, 1000000, 1000000, 1000000, 1000000, 1000000], $config->getEconomyPrizeTierRatioPpm());
        self::assertSame([1000000, 1000000, 1000000, 1000000, 1000000, 1000000, 1000000, 1000000], $config->getEconomyPositionPotTierRatioPpm());
        self::assertSame(0, $config->getEconomyLeaguePrizeTier1Pence());
        self::assertSame(0, $config->getEconomyLeaguePositionPotTier1Pence());
    }

    public function testAnUnauthoredBaseResolvesToNullWhateverTheRatiosSay(): void
    {
        // 0 PROPAGATES, exactly as the ticket base does. This is what makes the ratio ladder
        // safe to author before the base figure is agreed.
        $config = new GameConfig();
        $config->setEconomyPrizeTierRatioPpm(self::RATIO_PPM);
        $config->setEconomyPositionPotTierRatioPpm(self::RATIO_PPM);

        $league = new League('EN', 8, 'League 8');

        self::assertNull($this->resolver($config)->prizeFor($league, $config));
        self::assertNull($this->resolver($config)->positionPotFor($league, $config));
    }

    // ── The authored ladder, tier by tier ────────────────────────────────

    public function testTheAuthoredLadderPutsEveryTierAtTier1sShareOfItsOwnTurnover(): void
    {
        // THE WHOLE POINT OF THE WORK, as one assertion per tier. Tier 1 was already at 0.2573x
        // its authored turnover; the inversion was everything below it. Every tier must now
        // land at that same share, to the precision its own rung of the ladder can express.
        //
        // ⚠️ THE TOLERANCE IS PER-TIER, AND THAT IS NOT A FUDGE. The ratios are integers in
        // PARTS PER MILLION, so rounding one to 3 s.f. costs up to half a ppm — which is
        // 0.00005% of tier 1's 1,000,000 but 0.077% of tier 8's 650. A single flat tolerance
        // either has to be loose enough for the bottom rung (and so blind at the top) or tight
        // enough for the top (and so fails on correctly-authored bottom figures, which is what
        // it did under R2: tier 8 lands at 0.257479x against a 0.2573x target, 0.07% out, on a
        // ratio of 650 ppm whose exact value is 649.547).
        //
        // The gate's own flatness check is unaffected and passes with room to spare: prize +
        // position stays within 0.0065pp of turnover across all eight tiers, against its 0.5pp
        // tolerance. See testTheAuthoredLadderMeetsEveryConstraintItAloneControls().
        $config  = $this->authored();
        $resolver = $this->resolver($config);
        $target  = self::TIER1_PENCE / self::TURNOVER[0];

        for ($tier = 1; $tier <= 8; $tier++) {
            $league = new League('EN', $tier, 'League ' . $tier);
            $prize  = $resolver->prizeFor($league, $config);
            $pot    = $resolver->positionPotFor($league, $config);

            self::assertNotNull($prize);
            self::assertNotNull($pot);

            $share = $prize / self::TURNOVER[$tier - 1];

            // Half a ppm at this rung, floored at the 0.05% the top of the ladder is held to.
            $ratioPpm  = self::RATIO_PPM[$tier - 1];
            $tolerance = $target * max(0.0005, 0.5 / $ratioPpm);

            self::assertEqualsWithDelta(
                $target,
                $share,
                $tolerance,
                sprintf('tier %d prize is %.6fx its turnover, not %.6fx', $tier, $share, $target),
            );
            self::assertSame($prize, $pot, 'the two ladders ship with identical values');
        }
    }

    public function testTheResolvedPenceAreExactlyTheFiguresTheMigrationAuthors(): void
    {
        // Pinned as integers, not as shares: these are the numbers in the migration's docblock,
        // the runbook and the admin preview, and all four have to agree.
        $config   = $this->authored();
        $resolver = $this->resolver($config);

        $expected = [
            1 => 8_516_630_000,   // £85,166,300.00
            2 => 2_933_221_054,   // £29,332,210.54
            3 => 890_260_367,     // £8,902,603.67
            4 => 532_613_006,     // £5,326,130.06
            5 => 188_345_272,     // £1,883,452.72
            6 => 69_214_652,      // £692,146.52
            7 => 17_654_973,      // £176,549.73
            8 => 5_535_809,       // £55,358.09
        ];

        foreach ($expected as $tier => $pence) {
            self::assertSame($pence, $resolver->prizeFor(new League('EN', $tier, 'L'), $config), 'tier ' . $tier);
        }
    }

    public function testATierEightChampionNoLongerOutEarnsItsOwnDivision(): void
    {
        // The headline defect, as a regression test. Before: £5,000,000 prize + £2,000,000 pot
        // against £164,852 of authored turnover — 4,246%.
        $config   = $this->authored();
        $resolver = $this->resolver($config);
        $league   = new League('EN', 8, 'League 8');

        $champion = $resolver->prizeFor($league, $config) + $resolver->positionPotFor($league, $config);

        self::assertLessThan(
            self::TURNOVER[7],
            $champion,
            'a champion must not bank more than its own club earns in a year',
        );
        self::assertEqualsWithDelta(0.5150, $champion / self::TURNOVER[7], 0.001);
    }

    // ── Resolution order ─────────────────────────────────────────────────

    public function testTheLeaguesOwnColumnWinsAndIsNotScaled(): void
    {
        // An override is somebody's resolved answer. It must survive a ratio retune untouched,
        // and it must NOT be country-scaled either — it was authored for that division as it is.
        $config = $this->authored();
        $league = new League('EN', 8, 'League 8');
        $league->setPrizeMoney(777_777_777);
        $league->setLeaguePositionPot(123_456_789);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 2000)]);

        self::assertSame(777_777_777, $resolver->prizeFor($league, $config));
        self::assertSame(123_456_789, $resolver->positionPotFor($league, $config));

        $cheap = new League('ES', 8, 'Liga 8');
        $cheap->setPrizeMoney(777_777_777);

        self::assertSame(
            777_777_777,
            $resolver->prizeFor($cheap, $config),
            'an override is verbatim, country factor included',
        );
    }

    public function testZeroIsAnOverrideAndNotAnAbsentOne(): void
    {
        // A division that pays its champion nothing is a legitimate authored state, and it must
        // not fall through to the ladder. This is the null/zero distinction the publish sites
        // already rely on for their dash.
        $config = $this->authored();
        $league = new League('EN', 8, 'League 8');
        $league->setPrizeMoney(0);

        self::assertSame(0, $this->resolver($config)->prizeFor($league, $config));
    }

    // ── Country scaling ─────────────────────────────────────────────────

    public function testACheapCountryScalesTheInheritedLadderOnceAndLast(): void
    {
        $config   = $this->authored();
        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 2000)]);

        $full  = $resolver->prizeFor(new League('EN', 8, 'L'), $config);
        $cheap = $resolver->prizeFor(new League('ES', 8, 'L'), $config);

        self::assertSame(5_535_809, $full);
        self::assertSame(1_107_161, $cheap, 'exactly 20% — the factor applies once, not twice');
    }

    public function testTheTierFactorIsDeliberatelyNOTApplied(): void
    {
        // The same contract SponsorBandResolverTest pins for sponsors. Tier variation is this
        // ratio table's whole job; the wage tier factor on top would re-tier an already-tiered
        // ladder, and a wage retune would silently re-price every prize in the world.
        $config = $this->authored();
        $config->setEconomyTierFactorsBp([10000, 10000, 10000, 10000, 10000, 10000, 10000, 1]);

        self::assertSame(
            5_535_809,
            $this->resolver($config)->prizeFor(new League('EN', 8, 'L'), $config),
            'the tier factor must not reach this chain',
        );
    }

    // ── The overflow the sibling resolvers do not have ──────────────────

    public function testTheTier1BaseAtParityDoesNotOverflowInt64(): void
    {
        // THE TEST THIS CLASS EXISTS FOR. `base * ratioPpm * countryFactorBp` at tier 1 is
        // 1e10 * 1e6 * 1e4 = 1e20, against PHP_INT_MAX of about 9.223e18. The sibling
        // resolvers' single-intdiv chain would silently become a float here and lose pence;
        // combining the dimensionless factors first keeps it at 1e16.
        self::assertGreaterThan(
            PHP_INT_MAX,
            1e20,
            'the premise: the naive numerator does not fit in int64',
        );

        $config = $this->authored();

        $resolved = $this->resolver($config)->prizeFor(new League('EN', 1, 'L'), $config);

        self::assertIsInt($resolved);
        self::assertSame(self::TIER1_PENCE, $resolved, 'tier 1 at parity must be the base, to the penny');
    }

    public function testAVeryRichCountryStillResolvesExactly(): void
    {
        // The other end of the same risk: a factor above parity multiplies the numerator again.
        $config   = $this->authored();
        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'QA' => $this->profile('QA', 50000)]);

        self::assertSame(42_583_150_000, $resolver->prizeFor(new League('QA', 1, 'L'), $config));
    }

    // ── Shallow pyramids and the edges of the table ─────────────────────

    public function testAFourTierCountryReadsRowsOneToFourAndNeverPastThem(): void
    {
        // RAW ROW INDEXING, no stretching — the convention `economyTierFactorsBp` documents.
        // Scotland is the shape most likely to look wrong.
        $config   = $this->authored();
        $resolver = $this->resolver($config);

        foreach ([1 => 8_516_630_000, 2 => 2_933_221_054, 3 => 890_260_367, 4 => 532_613_006] as $tier => $pence) {
            self::assertSame($pence, $resolver->prizeFor(new League('SC', $tier, 'L'), $config), 'SC tier ' . $tier);
        }
    }

    public function testATierOutsideOneToEightResolvesUnauthoredRatherThanReadingPastTheTable(): void
    {
        $config   = $this->authored();
        $resolver = $this->resolver($config);

        self::assertNull($resolver->prizeFor(new League('EN', 0, 'L'), $config));
        self::assertNull($resolver->prizeFor(new League('EN', 9, 'L'), $config));
        self::assertNull($resolver->positionPotFor(new League('EN', 9, 'L'), $config));
    }

    public function testASteepRatioNeverTruncatesIntoReadingAsUnauthored(): void
    {
        // A null here would not read as "very small", it would read as "nobody priced this
        // division" — the same trap the ticket resolver floors at 1p for.
        $config = new GameConfig();
        $config->setEconomyLeaguePrizeTier1Pence(100);
        $config->setEconomyPrizeTierRatioPpm([1000000, 1, 1, 1, 1, 1, 1, 1]);

        $resolved = $this->resolver($config)->prizeFor(new League('EN', 8, 'L'), $config);

        self::assertSame(1, $resolved, 'floored at a penny, never nulled');
    }

    // ── The taper sum ───────────────────────────────────────────────────

    public function testTheTaperSumIs5Point5ForAnyLeagueOfElevenOrMoreAtTenPercent(): void
    {
        $resolver = $this->resolver(new GameConfig());

        // 1.0 + 0.9 + ... + 0.1, and positions 11 and below are floored at 0 rather than
        // clawing money back.
        foreach ([11, 18, 20, 24] as $clubs) {
            self::assertSame(5_500_000, $resolver->taperSumPpm($clubs, 10), $clubs . ' clubs');
        }
    }

    public function testTheTaperSumIsNotConstantAcrossRealLeagueSizes(): void
    {
        // WHY IT IS COMPUTED AND NOT HARDCODED. KR is 10 clubs, BE 14, EN 18, FR 20 — and the
        // first of those pays less than 5.5x because the taper has not run out yet.
        $resolver = $this->resolver(new GameConfig());

        self::assertSame(5_500_000, $resolver->taperSumPpm(18, 10));
        self::assertSame(5_500_000, $resolver->taperSumPpm(10, 10), '10 clubs at 10% is exactly 5.5 too');
        self::assertSame(3_000_000, $resolver->taperSumPpm(10, 20), 'a steeper taper pays less');
        self::assertSame(10_000_000, $resolver->taperSumPpm(10, 0), 'a flat taper pays every club the full pot');
    }

    public function testAZeroDecreasePaysEveryClubTheFullPot(): void
    {
        // 0 is a legitimate setting, per League::$leaguePositionDecreasePercent — it means every
        // finishing position pays the same, and it must not be read as "no taper configured".
        self::assertSame(18_000_000, $this->resolver(new GameConfig())->taperSumPpm(18, 0));
    }

    // ── Normalisation ───────────────────────────────────────────────────

    public function testTheRatioListIsAlwaysEightEntriesPaddedAtParity(): void
    {
        $config = (new GameConfig())->setEconomyPrizeTierRatioPpm([1000000, 344411]);

        self::assertSame(
            [1000000, 344411, 1000000, 1000000, 1000000, 1000000, 1000000, 1000000],
            $config->getEconomyPrizeTierRatioPpm(),
        );
    }

    public function testANegativeRatioIsClampedAndZeroMeansNoPayout(): void
    {
        $config = $this->authored();
        $config->setEconomyPositionPotTierRatioPpm([1000000, -500, 0, 62538, 22115, 8127, 2073, 650]);

        self::assertSame(0, $config->getEconomyPositionPotTierRatioPpm()[1]);

        $resolver = $this->resolver($config);

        self::assertNull($resolver->positionPotFor(new League('EN', 2, 'L'), $config));
        self::assertNull($resolver->positionPotFor(new League('EN', 3, 'L'), $config));
    }

    public function testANegativeBaseIsClampedToUnauthored(): void
    {
        $config = (new GameConfig())->setEconomyLeaguePrizeTier1Pence(-1);

        self::assertSame(0, $config->getEconomyLeaguePrizeTier1Pence());
    }

    // ── The acceptance constraints, as the gate checks them ─────────────

    /**
     * The constraints check 8 of `app:economy:wage-table` enforces, asserted here on the
     * authored England figures so they are pinned without a database.
     *
     * These are the POINT of the work. The ladder is deliberately decoupled from the turnover
     * table, so nothing but a check can notice the two drifting apart — and a retune that broke
     * one of these would otherwise ship looking fine.
     *
     * ⚠️ CONSTRAINT (b) IS TV-DEPENDENT AND IS DELIBERATELY NOT ASSERTED HERE. R2 took both its
     * halves past the bounds they had, the owner re-set those bounds on 2026-11-18 (50 -> 55,
     * 4 -> 5), and the figures plus both sets of bounds are pinned in
     * `testR2SitsInsideCheck8sAmendedBoundsAndWouldHaveBreachedTheOldOnes()`. What this test
     * asserts is only what the LADDER itself controls — (a), (c) and (d).
     */
    public function testTheAuthoredLadderMeetsEveryConstraintItAloneControls(): void
    {
        $config   = $this->authored();
        $resolver = $this->resolver($config);

        // England: 18 clubs, 10% taper. Σ of the taper multipliers is 5.5.
        $slots    = 18;
        $decrease = 10;
        $taperPpm = $resolver->taperSumPpm($slots, $decrease);

        self::assertSame(5_500_000, $taperPpm);

        $payoutOnly = [];

        for ($tier = 1; $tier <= 8; $tier++) {
            $league = new League('EN', $tier, 'L');
            $prize  = $resolver->prizeFor($league, $config);
            $pot    = $resolver->positionPotFor($league, $config);
            $turn   = self::TURNOVER[$tier - 1];

            // (c) A CHAMPION'S OWN TAKE. The headline defect: tier 8 was at 4,246%.
            self::assertLessThanOrEqual(
                100.0,
                ($prize + $pot) * 100 / $turn,
                sprintf('tier %d champion banks more than its own turnover', $tier),
            );

            $positionTotal     = intdiv($pot * $taperPpm, 1_000_000);
            $payoutOnly[$tier] = (intdiv($positionTotal, $slots) + intdiv($prize, $slots)) * 100 / $turn;
        }

        // (a) BOTH LADDERS NON-INCREASING. Structural rather than lucky: they are the turnover
        // ladder, which is strictly decreasing.
        foreach ([$config->getEconomyPrizeTierRatioPpm(), $config->getEconomyPositionPotTierRatioPpm()] as $table) {
            for ($i = 1; $i < 8; $i++) {
                self::assertLessThanOrEqual($table[$i - 1], $table[$i], 'ratio rises at index ' . $i);
            }
        }

        // (d) THE INVARIANT: prize + position is a flat share of turnover at every tier, so the
        // only tier-to-tier variation left in (b) comes from TV, which is out of scope. This is
        // the check that is blind to TV and therefore the one that catches a turnover retune —
        // and it is the one that proves the ladder was genuinely re-derived onto R2's turnover.
        self::assertLessThanOrEqual(
            0.5,
            max($payoutOnly) - min($payoutOnly),
            'prize + position is no longer a flat share of turnover — re-derive the ratios',
        );
        self::assertEqualsWithDelta(9.29, $payoutOnly[1], 0.01);
        self::assertEqualsWithDelta(9.29, $payoutOnly[8], 0.01);
    }

    /**
     * R2 against check 8's TV-DEPENDENT bounds, which the owner AMENDED on 2026-11-18 because R2
     * showed them mis-calibrated: `PAYOUT_SHARE_MAX_PCT` 50 -> 55 and `PAYOUT_SHARE_SPREAD`
     * 4 -> 5.
     *
     * ── WHY THIS TEST STRADDLES BOTH SETS OF BOUNDS ───────────────────────────────────────────
     *
     * It would be easy to assert only "R2 passes", and that test would be worthless: it would go
     * green again the moment somebody moved the bounds BACK, which is exactly the regression worth
     * catching. So this pins the two FIGURES — tier 1 at 50.4% and a 4.34x spread — and asserts
     * they sit inside the amended bounds **while still being above the old ones**. Either bound
     * reverting, or either figure moving materially, fails here.
     *
     * ── WHAT THE FIGURES MEAN, SO NOBODY RE-DERIVES THE WRONG THING ───────────────────────────
     *
     * Constraint (b) measures `TV + position + prize` per average club against authored turnover.
     * R2 cuts tier 1's turnover 15% (£388.7m -> £331m) and deliberately does NOT re-derive the TV
     * table (`REMAINING-WORK.md` §2f.5), so TV's share of tier-1 turnover rises 35.0% -> 41.1%
     * with no figure in the TV table changing, and carries the combined figure to 50.4%.
     *
     * ⚠️ **NONE OF IT IS THE LADDER'S DOING**, which is the whole point: the payout half is a flat
     * 9.29% of turnover at BOTH ends (constraint (d), above, with 0.0065pp of slack). The spread
     * is structural too — a top flight is broadcast-led and EN tier 8's TV deal is £4,950 a year.
     * The alternatives were costed and all cost more than they fix; see the constants' docblocks
     * in `EconomyWageTableCommand`.
     */
    public function testR2SitsInsideCheck8sAmendedBoundsAndWouldHaveBreachedTheOldOnes(): void
    {
        // The bounds as they were, and as the owner re-set them.
        $oldCeiling = 50.0;
        $newCeiling = 55.0;
        $oldSpread  = 4.0;
        $newSpread  = 5.0;

        $config   = $this->authored();
        $resolver = $this->resolver($config);
        $slots    = 18;
        $taperPpm = $resolver->taperSumPpm($slots, 10);

        $shares = [];

        for ($tier = 1; $tier <= 8; $tier++) {
            $league = new League('EN', $tier, 'L');
            $prize  = $resolver->prizeFor($league, $config);
            $pot    = $resolver->positionPotFor($league, $config);

            $positionTotal = intdiv($pot * $taperPpm, 1_000_000);
            $perClub       = self::TV_PER_CLUB[$tier - 1] + intdiv($positionTotal, $slots) + intdiv($prize, $slots);

            $shares[$tier] = $perClub * 100 / self::TURNOVER[$tier - 1];
        }

        // THE FIGURE, pinned. Tier 1 is the only tier that was ever outside the band.
        self::assertEqualsWithDelta(50.38, $shares[1], 0.02, 'tier 1 TV+payout share');

        // Inside the amended ceiling...
        self::assertLessThanOrEqual($newCeiling, $shares[1], 'tier 1 must be inside the amended 55% ceiling');
        // ...and still above the old one, so moving it back cannot pass silently.
        self::assertGreaterThan(
            $oldCeiling,
            $shares[1],
            'tier 1 no longer exceeds the OLD 50% ceiling — if that is deliberate, this test and '
            . 'PAYOUT_SHARE_MAX_PCT need revisiting together',
        );

        // Every other tier was always inside the band, on either set of bounds.
        for ($tier = 2; $tier <= 8; $tier++) {
            self::assertGreaterThanOrEqual(10.0, $shares[$tier], sprintf('tier %d below the band', $tier));
            self::assertLessThanOrEqual($oldCeiling, $shares[$tier], sprintf('tier %d above the band', $tier));
        }

        // And the spread, which the same TV move drove: inside 5x, still over 4x.
        $spread = $shares[1] / $shares[8];

        self::assertEqualsWithDelta(4.34, $spread, 0.02, 'tier-1/tier-8 spread');
        self::assertLessThanOrEqual($newSpread, $spread, 'the spread must be inside the amended 5x cap');
        self::assertGreaterThan(
            $oldSpread,
            $spread,
            'the spread no longer exceeds the OLD 4x cap — if that is deliberate, this test and '
            . 'PAYOUT_SHARE_SPREAD need revisiting together',
        );

        // THE ATTRIBUTION, so a future reader does not blame the ladder: TV alone is already
        // 41.1% of tier-1 turnover, against 35.0% of the turnover it was authored for.
        self::assertEqualsWithDelta(41.09, self::TV_PER_CLUB[0] * 100 / self::TURNOVER[0], 0.02);
        self::assertEqualsWithDelta(34.99, self::TV_PER_CLUB[0] * 100 / self::LEGACY_TURNOVER[0], 0.02);
    }

    public function testTheOldLadderFailedTheConstraintsThisOneMeets(): void
    {
        // THE REGRESSION, FROM THE OTHER DIRECTION. A check that passes on both the old and the
        // new figures would not be a check, so this pins that the old ladder genuinely trips it.
        $old = [
            'prize' => [10000000000, 7500000000, 5000000000, 3050000000, 2550000000, 1500000000, 1000000000, 500000000],
            'pot'   => [10000000000, 8000000000, 5000000000, 3000000000, 1000000000, 870000000, 500000000, 200000000],
        ];

        $championShare = ($old['prize'][7] + $old['pot'][7]) * 100 / self::LEGACY_TURNOVER[7];

        self::assertGreaterThan(100.0, $championShare, 'the old tier-8 champion share must breach the ceiling');
        self::assertEqualsWithDelta(4246.0, $championShare, 1.0);

        // And it inverted: tier 8 drew a larger multiple of its own turnover than tier 1.
        self::assertGreaterThan(
            $old['prize'][0] / self::LEGACY_TURNOVER[0],
            $old['prize'][7] / self::LEGACY_TURNOVER[7],
            'the old ladder inverted down the pyramid',
        );
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** A config with the authored England ladder on it. */
    private function authored(): GameConfig
    {
        $config = new GameConfig();
        $config->setEconomyLeaguePrizeTier1Pence(self::TIER1_PENCE);
        $config->setEconomyLeaguePositionPotTier1Pence(self::TIER1_PENCE);
        $config->setEconomyPrizeTierRatioPpm(self::RATIO_PPM);
        $config->setEconomyPositionPotTierRatioPpm(self::RATIO_PPM);
        $config->setEconomyBaselineTurnoverPence(self::TURNOVER);

        return $config;
    }

    /** @param array<string, CountryProfile> $profiles */
    private function resolver(GameConfig $config, array $profiles = []): LeaguePayoutResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturnCallback(
            static fn (string $c) => $profiles[strtoupper(trim($c))] ?? null,
        );
        $profileRepo->method('findEconomyBaseline')->willReturnCallback(
            static function () use ($profiles) {
                foreach ($profiles as $p) {
                    if ($p->isEconomyBaseline()) {
                        return $p;
                    }
                }

                return null;
            },
        );

        return new LeaguePayoutResolver(
            $gameConfigRepo,
            new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo),
        );
    }

    private function profile(string $code, int $factorBp, bool $baseline = false): CountryProfile
    {
        $p = new CountryProfile($code, $code);
        $p->setEconomyFactorBp($factorBp);
        $p->setIsEconomyBaseline($baseline);

        return $p;
    }
}
