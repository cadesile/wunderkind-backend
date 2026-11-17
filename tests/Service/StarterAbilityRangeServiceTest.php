<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\StarterConfig;
use App\Repository\StarterConfigRepository;
use App\Service\StarterAbilityRangeService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The nine lines behind the dashboard's "Seed defaults" button.
 *
 * Worth its own test despite its size, because all three of its edge cases are silent
 * failures rather than errors: seeding twice would double-report success, seeding over a
 * tuned band would undo an operator's work with no trace, and flushing the unmanaged
 * StarterConfig::defaults() that getConfig() hands back on a fresh database writes nothing
 * at all while reporting that it did.
 */
class StarterAbilityRangeServiceTest extends TestCase
{
    public function testSeedsACountryThatHasNoBand(): void
    {
        $config = StarterConfig::defaults();

        $this->assertArrayNotHasKey('PL', $config->getLeagueAbilityRanges(), 'precondition');

        $service = $this->serviceFor($config, managed: true, expectFlush: true);

        $this->assertTrue($service->seedDefaults('PL'));
        $this->assertSame(
            StarterConfig::defaultTierRanges(),
            $config->getLeagueAbilityRanges()['PL'] ?? null,
        );
    }

    public function testLeavesACountryThatAlreadyHasOneAlone(): void
    {
        $tuned  = ['1' => ['min' => 90, 'max' => 99]];
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['PL' => $tuned] + $config->getLeagueAbilityRanges());

        // No flush at all: "already present" must not write, or a hand-tuned band would be
        // overwritten by its own re-seed.
        $service = $this->serviceFor($config, managed: true, expectFlush: false);

        $this->assertFalse($service->seedDefaults('PL'));
        $this->assertSame($tuned, $config->getLeagueAbilityRanges()['PL']);
    }

    public function testAnEmptyBandCountsAsAbsent(): void
    {
        // The readiness check treats [] as missing, so seeding must agree with it — otherwise
        // the button reports "already has one" against a blocker that stays red.
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['PL' => []] + $config->getLeagueAbilityRanges());

        $service = $this->serviceFor($config, managed: true, expectFlush: true);

        $this->assertFalse($service->hasEntry('PL'));
        $this->assertTrue($service->seedDefaults('PL'));
    }

    public function testPersistsTheConfigRowWhenTheDatabaseHasNoneYet(): void
    {
        // getConfig() returns an UNMANAGED defaults() when row 1 is absent. Flushing that
        // writes nothing, so the band would vanish on the next request — on exactly the fresh
        // environment where an operator is bootstrapping their first country.
        $config = StarterConfig::defaults();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects($this->once())->method('persist')->with($config);
        $em->expects($this->once())->method('flush');

        $repo = $this->createStub(StarterConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        $this->assertTrue((new StarterAbilityRangeService($repo, $em))->seedDefaults('PL'));
    }

    public function testCodeIsNormalisedAndEmptyIsRefused(): void
    {
        $config  = StarterConfig::defaults();
        $service = $this->serviceFor($config, managed: true, expectFlush: true);

        $this->assertTrue($service->seedDefaults(' pl '));
        $this->assertArrayHasKey('PL', $config->getLeagueAbilityRanges());

        $empty = $this->serviceFor(StarterConfig::defaults(), managed: true, expectFlush: false);
        $this->assertFalse($empty->seedDefaults('  '));
    }

    // ── syncToPyramid(): the authored band follows the pyramid ────────────────────────────
    //
    // Added 2026-11-17 with the method. The defect it closes is silent in both directions: a
    // grown pyramid leaves tiers with no authored band (generation falls through to
    // WorldInitializationService::ABILITY_RANGES and looks correct, while the table that
    // prices players and feeds the app's "N TIERS" chip describes a shallower country), and a
    // shrunk one leaves bands for divisions that no longer exist.

    public function testFillsTheTiersAGrownPyramidAdded(): void
    {
        // Scotland as it actually was on dev: authored at four tiers, pyramid rebuilt to eight.
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['SC' => $this->band([1, 2, 3, 4])]);

        $result = $this->serviceFor($config, managed: true, expectFlush: true)
            ->syncToPyramid('SC', 8);

        $this->assertSame([5, 6, 7, 8], $result['added']);
        $this->assertSame([], $result['removed']);
        // INT keys, not string. PHP normalises a numeric string key to an integer on write,
        // and json_decode does the same on the way back out of the column — so the band is
        // int-keyed at runtime no matter how it was authored, including the `'1' =>` rows in
        // StarterConfig::DEFAULT_TIER_RANGES. Readers index it with `(string) $tier` and are
        // unaffected (PHP normalises the lookup too), and json_encode still emits an OBJECT
        // because the keys do not start at 0 — so the published shape is unchanged.
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8],
            array_keys($config->getLeagueAbilityRanges()['SC']),
            'the app counts these keys to show the country depth',
        );
        $this->assertSame(
            StarterConfig::defaultTierRanges()['6'],
            $config->getLeagueAbilityRanges()['SC']['6'],
        );
    }

    public function testNeverRewritesATierThatIsAlreadyAuthored(): void
    {
        // THE PROMISE THAT MAKES THIS SAFE TO RUN ON EVERY LEAGUE GENERATION. Tier 1 here is
        // nothing like the default; it must come back out untouched.
        $tuned  = ['min' => 41, 'max' => 93];
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['SC' => ['1' => $tuned]]);

        $this->serviceFor($config, managed: true, expectFlush: true)->syncToPyramid('SC', 4);

        $this->assertSame($tuned, $config->getLeagueAbilityRanges()['SC']['1']);
    }

    public function testDropsBandsForTiersThePyramidNoLongerHas(): void
    {
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['SC' => $this->band([1, 2, 3, 4, 5, 6, 7, 8])]);

        $result = $this->serviceFor($config, managed: true, expectFlush: true)
            ->syncToPyramid('SC', 4);

        $this->assertSame([], $result['added']);
        $this->assertSame([5, 6, 7, 8], $result['removed']);
        $this->assertSame([1, 2, 3, 4], array_keys($config->getLeagueAbilityRanges()['SC']));
    }

    public function testReplacesAStoredBandThatWouldGenerateZeroAbilityPlayers(): void
    {
        // A zero floor is not "authored", it is a broken row — and it does not throw anywhere,
        // it just generates a division of 0-ability players.
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges(['SC' => ['1' => ['min' => 0, 'max' => 0], '2' => 'nonsense']]);

        $result = $this->serviceFor($config, managed: true, expectFlush: true)
            ->syncToPyramid('SC', 2);

        $this->assertSame([1, 2], $result['added']);
        $this->assertSame(StarterConfig::defaultTierRanges()['1'], $config->getLeagueAbilityRanges()['SC']['1']);
    }

    public function testWritesNothingWhenTheBandAlreadyMatchesThePyramid(): void
    {
        // Every league generation calls this, and most of them have nothing to do. A flush per
        // call would be a write on every admin "generate leagues" press for no change at all.
        $config = StarterConfig::defaults();

        $result = $this->serviceFor($config, managed: true, expectFlush: false)
            ->syncToPyramid('EN', 8);

        $this->assertSame(['added' => [], 'removed' => []], $result);
    }

    public function testSeedsACountryWithNoBandAtAllFromTheDepthAsked(): void
    {
        $config = StarterConfig::defaults();

        $this->serviceFor($config, managed: true, expectFlush: true)->syncToPyramid('PL', 3);

        $this->assertSame([1, 2, 3], array_keys($config->getLeagueAbilityRanges()['PL']));
    }

    public function testClampsAndNormalisesTheArgumentsTheSameWaySeedDefaultsDoes(): void
    {
        // LeagueService clamps to 1..8 before it ever calls this, but this is a public method
        // on a service an admin action can reach, so it clamps for itself too.
        $config = StarterConfig::defaults();
        $config->setLeagueAbilityRanges([]);

        $this->serviceFor($config, managed: true, expectFlush: true)->syncToPyramid(' sc ', 99);
        $this->assertCount(8, $config->getLeagueAbilityRanges()['SC']);

        $empty = StarterConfig::defaults();
        $this->assertSame(
            ['added' => [], 'removed' => []],
            $this->serviceFor($empty, managed: true, expectFlush: false)->syncToPyramid('  ', 8),
        );
    }

    public function testPersistsAnUnmanagedConfigBeforeWriting(): void
    {
        // Same fresh-database trap seedDefaults() has: flushing the unmanaged defaults object
        // getConfig() hands back writes nothing while reporting success.
        $config = StarterConfig::defaults();
        $em     = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects($this->once())->method('persist')->with($config);
        $em->expects($this->once())->method('flush');

        $repo = $this->createStub(StarterConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        (new StarterAbilityRangeService($repo, $em))->syncToPyramid('PL', 2);
    }

    /**
     * A band covering exactly these tiers, with figures that are NOT the defaults — so a test
     * asserting a tier survived cannot pass by accident when it was rewritten.
     *
     * @param int[] $tiers
     *
     * @return array<string, array{min: int, max: int}>
     */
    private function band(array $tiers): array
    {
        $band = [];
        foreach ($tiers as $tier) {
            $band[(string) $tier] = ['min' => $tier, 'max' => $tier + 1];
        }

        return $band;
    }

    private function serviceFor(StarterConfig $config, bool $managed, bool $expectFlush): StarterAbilityRangeService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn($managed);
        $em->expects($expectFlush ? $this->once() : $this->never())->method('flush');

        $repo = $this->createStub(StarterConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        return new StarterAbilityRangeService($repo, $em);
    }
}
