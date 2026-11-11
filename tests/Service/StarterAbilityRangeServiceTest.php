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
