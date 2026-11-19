<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StarterConfig;
use PHPUnit\Framework\TestCase;

/**
 * The catchment share ladder (`fanBaseSharePercent`), and the absolute band it sits BESIDE.
 *
 * ⚠️ **The failure this file exists to catch is the two keys being confused for one.**
 * `fanBaseRanges` is an absolute headcount and `fanBaseSharePercent` is a percent of a club's
 * catchment — 1,000x apart in unit. Reinterpreting the old key as a share would publish a
 * tier-1 fan base of 700,000% of the catchment to every running save on the next config sync,
 * and the app falls back to `fanBaseRanges` whenever the ladder is absent, so the old key
 * moving is not a cosmetic change either.
 */
final class StarterConfigFanBaseShareLadderTest extends TestCase
{
    /**
     * A share above 100% is not a share, and a non-monotonic ladder inverts the pyramid at
     * whichever tier it breaks — a tier-7 club drawing a larger share of its town than a
     * tier-6 one would be the gradient running backwards.
     *
     * Monotonic NON-STRICTLY on purpose: an operator flattening two adjacent tiers to the same
     * band is a legitimate authoring choice, and today's default happens to be strictly
     * decreasing anyway.
     */
    public function testTheDefaultLadderIsMonotonicAndWithinZeroToOneHundred(): void
    {
        $ladder = StarterConfig::defaults()->getFanBaseSharePercent();

        for ($tier = 1; $tier <= 8; $tier++) {
            self::assertArrayHasKey((string) $tier, $ladder, "the ladder has no tier {$tier}");
            $band = $ladder[(string) $tier];

            self::assertGreaterThanOrEqual(0, $band['min'], "tier {$tier} min is negative");
            self::assertLessThanOrEqual(100, $band['max'], "tier {$tier} max is above 100%");
            self::assertLessThanOrEqual(
                $band['max'],
                $band['min'],
                "tier {$tier}'s band is inverted",
            );
        }

        for ($tier = 2; $tier <= 8; $tier++) {
            $higher = $ladder[(string) ($tier - 1)];
            $lower  = $ladder[(string) $tier];

            self::assertLessThanOrEqual(
                $higher['min'],
                $lower['min'],
                "tier {$tier}'s min share exceeds tier " . ($tier - 1) . "'s — the pyramid is inverted there",
            );
            self::assertLessThanOrEqual(
                $higher['max'],
                $lower['max'],
                "tier {$tier}'s max share exceeds tier " . ($tier - 1) . "'s — the pyramid is inverted there",
            );
        }
    }

    /** The authored values, pinned so a drive-by re-tuning is a deliberate edit to this file. */
    public function testTheAuthoredLadderIsTheOneTheOwnerSettled(): void
    {
        self::assertSame(
            [
                '1' => ['min' => 60, 'max' => 70],
                '2' => ['min' => 49, 'max' => 59],
                '3' => ['min' => 40, 'max' => 49],
                '4' => ['min' => 33, 'max' => 41],
                '5' => ['min' => 27, 'max' => 34],
                '6' => ['min' => 22, 'max' => 29],
                '7' => ['min' => 18, 'max' => 24],
                '8' => ['min' => 15, 'max' => 20],
            ],
            StarterConfig::defaults()->getFanBaseSharePercent(),
        );
    }

    /**
     * 🔴 `fanBaseRanges` IS UNCHANGED, and this is the assertion that matters most in this file.
     *
     * It is the app's fallback: `resolveStartingFanCount` takes the absolute path whenever the
     * share ladder is absent or a club's catchment is unknown, and a pack written before
     * `populationSize` shipped carries no catchment at all. So these values are still live
     * behaviour for real clubs and are not superseded by the ladder.
     *
     * These are the ENTITY defaults — what a fresh database gets. The deployed row has been
     * admin-edited to 700,000-1,500,000 at tier 1 down to 10,000-20,000 at tier 8 and is not
     * reachable from a unit test; what this pins is that nobody re-authored the shipped
     * defaults, and above all that nobody turned the absolute key into a percentage one.
     */
    public function testFanBaseRangesIsUntouchedAndStillAbsoluteHeadcounts(): void
    {
        $ranges = StarterConfig::defaults()->getFanBaseRanges();

        self::assertSame(
            [
                '1' => ['min' => 50000, 'max' => 150000],
                '2' => ['min' => 20000, 'max' => 70000],
                '3' => ['min' => 10000, 'max' => 30000],
                '4' => ['min' => 5000,  'max' => 15000],
                '5' => ['min' => 2000,  'max' => 8000],
                '6' => ['min' => 1000,  'max' => 4000],
                '7' => ['min' => 500,   'max' => 2000],
                '8' => ['min' => 200,   'max' => 1000],
            ],
            $ranges,
            'fanBaseRanges moved — it is the app fallback and an absolute headcount, not a share',
        );

        // The unit check, stated as a relationship rather than as eight more literals: every
        // tier's band is orders of magnitude above anything a percentage could be. If this ever
        // fails, the absolute key has been reinterpreted as a share.
        foreach ($ranges as $tier => $band) {
            self::assertGreaterThan(
                100,
                $band['min'],
                "tier {$tier}'s absolute fan band dropped into percentage range",
            );
        }
    }

    /** The two keys are separate objects — editing one must never reach the other. */
    public function testTheTwoLaddersAreIndependent(): void
    {
        $config = StarterConfig::defaults();
        $config->setFanBaseSharePercent(['1' => ['min' => 5, 'max' => 6]]);

        self::assertSame(['1' => ['min' => 5, 'max' => 6]], $config->getFanBaseSharePercent());
        self::assertSame(
            ['min' => 50000, 'max' => 150000],
            $config->getFanBaseRanges()['1'],
            'setting the share ladder changed the absolute band',
        );
    }
}
