<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PoolConfig;
use App\Entity\StarterConfig;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StaffAbilityRangeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A25 (2026-11-20) — the authored staff ability table, its precedence, its seed and its depth.
 *
 * Four things are worth a test here, and they are the four the ruling turns on:
 *   1. the authored table wins, and the flat globals cannot reach a club's staff;
 *   2. an UNTIERED call still gets the flat pair, so the narrowing is a narrowing and not a
 *      retirement — the pool has no tier to be banded against;
 *   3. the committed `DEFAULT_TIER_BANDS` are exactly `bandFromPlayerBand()` applied to
 *      `StarterConfig`'s committed player defaults, so the two tables cannot drift apart;
 *   4. a 4-tier country is seeded at four tiers and never padded to eight.
 */
final class StaffAbilityRangeServiceTest extends TestCase
{
    private function make(PoolConfig $pool, ?StarterConfig $starter = null): StaffAbilityRangeService
    {
        $poolRepo = $this->createStub(PoolConfigRepository::class);
        $poolRepo->method('getConfig')->willReturn($pool);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter ?? new StarterConfig());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);

        return new StaffAbilityRangeService($poolRepo, $starterRepo, $em);
    }

    public function testTheAuthoredTableWinsOverTheFlatGlobals(): void
    {
        $pool = new PoolConfig();
        // Deliberately disjoint from the authored band, so a read from the wrong source
        // cannot pass by luck.
        $pool->setCoachAbilityMin(90)->setCoachAbilityMax(95);
        $pool->setStaffAbilityRanges(['EN' => ['8' => ['min' => 11, 'max' => 14]]]);

        $band = $this->make($pool)->resolve('EN', 8);

        self::assertSame(11, $band['min']);
        self::assertSame(14, $band['max']);
        self::assertSame('poolConfig.staffAbilityRanges', $band['source']);
    }

    /**
     * The country code is upper-cased on the way in, because `resolve()` is called with
     * whatever a league row holds and a lower-cased code would silently miss an authored band
     * and fall through to the defaults — a wrong band that looks like a plausible one.
     */
    public function testTheCountryCodeIsCaseInsensitive(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['3' => ['min' => 20, 'max' => 30]]]);

        self::assertSame('poolConfig.staffAbilityRanges', $this->make($pool)->resolve('en', 3)['source']);
    }

    public function testAnUnauthoredTierFallsThroughToTheCommittedDefaults(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['1' => ['min' => 30, 'max' => 50]]]);

        $band = $this->make($pool)->resolve('EN', 7);

        self::assertSame(StaffAbilityRangeService::DEFAULT_TIER_BANDS[7]['min'], $band['min']);
        self::assertSame(StaffAbilityRangeService::DEFAULT_TIER_BANDS[7]['max'], $band['max']);
        self::assertSame('StaffAbilityRangeService::DEFAULT_TIER_BANDS', $band['source']);
    }

    /**
     * A zeroed row is a real state of a hand-edited grid and must NOT be taken literally: an
     * ability-0 band would generate ability-0 staff rather than fail. Same guard the player
     * table's `isUsableBand()` makes.
     */
    public function testAZeroedAuthoredRowIsTreatedAsUnauthored(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['4' => ['min' => 0, 'max' => 0]]]);

        self::assertSame(
            'StaffAbilityRangeService::DEFAULT_TIER_BANDS',
            $this->make($pool)->resolve('EN', 4)['source'],
        );
    }

    public function testATransposedAuthoredRowIsOrdered(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['4' => ['min' => 40, 'max' => 12]]]);

        $band = $this->make($pool)->resolve('EN', 4);

        self::assertSame(12, $band['min']);
        self::assertSame(40, $band['max']);
    }

    /**
     * 🔴 **THE NARROWING, PINNED FROM BOTH SIDES.** `coachAbilityMin/Max` still bands a staff
     * member with no tier — the shared hire-market pool, one row served to every country at
     * every tier. If this ever starts reporting the authored table, a pool coach has been given
     * a tier it does not have.
     */
    public function testAnUntieredResolveStillUsesTheFlatPair(): void
    {
        $pool = new PoolConfig();
        $pool->setCoachAbilityMin(4)->setCoachAbilityMax(75);
        $pool->setStaffAbilityRanges(['EN' => ['1' => ['min' => 30, 'max' => 50]]]);

        foreach ([['', 0], ['EN', 0], ['', 1]] as [$country, $tier]) {
            $band = $this->make($pool)->resolve($country, $tier);

            self::assertSame(4, $band['min'], "country '$country', tier $tier");
            self::assertSame(75, $band['max'], "country '$country', tier $tier");
            self::assertSame('poolConfig.coachAbilityMin/Max (untiered)', $band['source']);
        }
    }

    /**
     * 🔴 **THE TWO COMMITTED DEFAULT TABLES AGREE BY CONSTRUCTION, AND THIS IS WHAT KEEPS THEM
     * AGREEING.** `DEFAULT_TIER_BANDS` is `bandFromPlayerBand()` applied to
     * `StarterConfig::defaultTierRanges()`, tier for tier. Retuning the player defaults without
     * this test would leave the staff defaults quietly describing a scale that no longer exists
     * — one quantity with two sources and nothing saying which is right, which is the defect
     * this whole workstream exists to remove.
     */
    public function testTheCommittedDefaultBandsAreTheTransformOfTheCommittedPlayerDefaults(): void
    {
        foreach (StarterConfig::defaultTierRanges() as $tier => $playerBand) {
            self::assertSame(
                StaffAbilityRangeService::bandFromPlayerBand($playerBand),
                StaffAbilityRangeService::DEFAULT_TIER_BANDS[(int) $tier],
                "tier $tier",
            );
        }
    }

    /** The measured transform itself: 0.45x-0.85x of the player band's midpoint. */
    public function testTheSeedTransformIsTheMeasuredFractionOfTheBandMidpoint(): void
    {
        // Live EN tier 1 on 2026-11-20: 40-90, midpoint 65. Measured player current ability at
        // that tier spanned 29 (p25) to 54 (p95); see a25-ability-bands-report.md.
        self::assertSame(['min' => 29, 'max' => 55], StaffAbilityRangeService::bandFromPlayerBand(['min' => 40, 'max' => 90]));
        // Live EN tier 8: 2-7, midpoint 4.5. Measured 2 (p25) to 5 (p95).
        self::assertSame(['min' => 2, 'max' => 4], StaffAbilityRangeService::bandFromPlayerBand(['min' => 2, 'max' => 7]));
    }

    /** Never zero and never above 100, whatever the player band says. */
    public function testTheSeedTransformIsClampedIntoTheOneToHundredScale(): void
    {
        self::assertSame(['min' => 1, 'max' => 1], StaffAbilityRangeService::bandFromPlayerBand(['min' => 0, 'max' => 1]));
        self::assertSame(['min' => 45, 'max' => 85], StaffAbilityRangeService::bandFromPlayerBand(['min' => 100, 'max' => 100]));
        // Transposed, because the player grid is hand-edited too.
        self::assertSame(
            StaffAbilityRangeService::bandFromPlayerBand(['min' => 10, 'max' => 90]),
            StaffAbilityRangeService::bandFromPlayerBand(['min' => 90, 'max' => 10]),
        );
    }

    /**
     * 🔴 **SCOTLAND HAS FOUR TIERS — RULING LIMB 3.** The seed takes its DEPTH from the
     * country's own authored player band, so a 4-tier country is authored at four tiers and
     * never padded with rows for divisions that do not exist. On the live row this is SC
     * against 25 eight-tier countries.
     */
    public function testAFourTierCountryIsSeededAtFourTiersAndNotPadded(): void
    {
        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges([
            'SC' => [
                '1' => ['min' => 40, 'max' => 90],
                '2' => ['min' => 30, 'max' => 60],
                '3' => ['min' => 20, 'max' => 40],
                '4' => ['min' => 15, 'max' => 35],
            ],
        ]);

        $pool = new PoolConfig();
        self::assertTrue($this->make($pool, $starter)->seedDefaults('SC'));

        $band = $pool->getStaffAbilityRanges()['SC'];

        // PHP normalises a numeric string array key to an int, so these come back as ints
        // even though the JSON column holds them as strings — the count and the SET are what
        // this test is about.
        self::assertSame([1, 2, 3, 4], array_keys($band));
        self::assertSame(['min' => 29, 'max' => 55], $band['1']);
        self::assertSame(['min' => 11, 'max' => 21], $band['4']);
    }

    /** Seeded from the country's OWN authored player band, not from the committed defaults. */
    public function testTheSeedIsDerivedFromThatCountrysOwnPlayerBand(): void
    {
        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => ['1' => ['min' => 40, 'max' => 90]]]);

        $pool = new PoolConfig();
        $this->make($pool, $starter)->seedDefaults('EN');

        self::assertSame(['min' => 29, 'max' => 55], $pool->getStaffAbilityRanges()['EN']['1']);
        self::assertNotSame(StaffAbilityRangeService::DEFAULT_TIER_BANDS[1], $pool->getStaffAbilityRanges()['EN']['1']);
    }

    /** MERGE-IF-ABSENT, NEVER OVERWRITE — the promise the player twin makes. */
    public function testSeedDefaultsNeverOverwritesAnExistingBand(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['1' => ['min' => 1, 'max' => 2]]]);

        self::assertFalse($this->make($pool)->seedDefaults('EN'));
        self::assertSame(['min' => 1, 'max' => 2], $pool->getStaffAbilityRanges()['EN']['1']);
    }

    public function testSyncToPyramidFillsMissingTiersAndKeepsAuthoredOnes(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['1' => ['min' => 33, 'max' => 44]]]);

        $result = $this->make($pool)->syncToPyramid('EN', 3);

        self::assertSame([2, 3], $result['added']);
        self::assertSame([], $result['removed']);
        self::assertSame(['min' => 33, 'max' => 44], $pool->getStaffAbilityRanges()['EN']['1'], 'an authored tier is never rewritten');
        self::assertSame([1, 2, 3], array_keys($pool->getStaffAbilityRanges()['EN']));
    }

    /**
     * A tier deeper than the pyramid is DROPPED, and that is the deliberately destructive half:
     * a band left behind by a shrunk pyramid reads as a division that still exists. Same trade
     * `StarterAbilityRangeService::syncToPyramid()` records for the player table.
     */
    public function testSyncToPyramidDropsTiersDeeperThanThePyramid(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges([
            'SC' => [
                '1' => ['min' => 30, 'max' => 50], '2' => ['min' => 25, 'max' => 45],
                '3' => ['min' => 20, 'max' => 40], '4' => ['min' => 15, 'max' => 35],
                '5' => ['min' => 10, 'max' => 30],
            ],
        ]);

        $result = $this->make($pool)->syncToPyramid('SC', 4);

        self::assertSame([], $result['added']);
        self::assertSame([5], $result['removed']);
        self::assertSame([1, 2, 3, 4], array_keys($pool->getStaffAbilityRanges()['SC']));
    }

    public function testSyncToPyramidIsANoOpWhenTheBandAlreadyMatchesTheDepth(): void
    {
        $pool = new PoolConfig();
        $pool->setStaffAbilityRanges(['EN' => ['1' => ['min' => 30, 'max' => 50], '2' => ['min' => 25, 'max' => 45]]]);

        self::assertSame(['added' => [], 'removed' => []], $this->make($pool)->syncToPyramid('EN', 2));
    }
}
