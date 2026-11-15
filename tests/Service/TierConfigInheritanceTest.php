<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\StarterConfig;
use App\Repository\StarterConfigRepository;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

/**
 * An unconfigured tier inherits from CONFIGURATION, not from a table in the code.
 *
 * ── THE DEFECT THIS PINS ──────────────────────────────────────────────────────────────────
 *
 * `defaultTierConfig()` disagreed with the live `npcSquadConfig` in four places: no director of
 * football at tiers 3-4, no facility manager at 5-6, `coachCount` capped at 1 against an
 * authored 7-13, and no scout at tiers 7-8 against an authored 1.
 *
 * ⚠️ **None of it was biting, and that is what made it dangerous.** The table only applies
 * where configuration is absent, so nothing exercised it and it drifted silently — the same
 * defect as `app:pool:warm`'s hardcoded role list: staffing numbers living in code beside the
 * configuration that owns them.
 */
class TierConfigInheritanceTest extends TestCase
{
    /** @param array<string, mixed> $npcSquadConfig */
    private function service(array $npcSquadConfig): WorldInitializationService
    {
        $config = new StarterConfig();
        $config->setNpcSquadConfig($npcSquadConfig);

        $repo = $this->createStub(StarterConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        $service = (new \ReflectionClass(WorldInitializationService::class))
            ->newInstanceWithoutConstructor();

        $prop = (new \ReflectionClass(WorldInitializationService::class))
            ->getProperty('starterConfigRepository');
        $prop->setValue($service, $repo);

        return $service;
    }

    private const TIER_1 = [
        'playerMin' => 32, 'playerMax' => 35, 'managerCount' => 1, 'coachCount' => 13,
        'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1,
        'scoutCount' => 3, 'foreignPercent' => 50,
    ];

    public function testAConfiguredTierIsReturnedAsAuthored(): void
    {
        $resolved = $this->service(['1' => self::TIER_1])->resolveTierConfig(1);

        self::assertSame(13, $resolved['coachCount']);
        self::assertSame(3, $resolved['scoutCount']);
    }

    public function testAnUnconfiguredTierBorrowsFromTheNearestConfiguredOne(): void
    {
        // THE WHOLE POINT. Tier 2 has no row, so it takes tier 1's authored 13 coaches rather
        // than the code table's 1 — a figure nobody has reviewed since it was typed.
        $resolved = $this->service(['1' => self::TIER_1])->resolveTierConfig(2);

        self::assertSame(13, $resolved['coachCount']);
        self::assertSame(1, $resolved['directorOfFootballCount']);
    }

    public function testTheNEARESTConfiguredTierWins(): void
    {
        $far  = self::TIER_1;
        $near = ['coachCount' => 7, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 1];

        // Tier 7 is unconfigured; 8 is one away, 1 is six away.
        $resolved = $this->service(['1' => $far, '8' => $near])->resolveTierConfig(7);

        self::assertSame(7, $resolved['coachCount']);
    }

    public function testAPartialRowIsFILLEDRatherThanReadAsZero(): void
    {
        // ⚠️ The generators read these with `?? 0`, so an omitted key used to remove the role
        // from every club in the tier — a config edit that dropped one key silently changed
        // what a club IS.
        $partial  = ['playerMin' => 20, 'playerMax' => 24, 'coachCount' => 9];
        $resolved = $this->service(['1' => self::TIER_1, '4' => $partial])->resolveTierConfig(4);

        self::assertSame(9, $resolved['coachCount'], 'the authored key wins');
        self::assertSame(1, $resolved['directorOfFootballCount'], 'the missing key is inherited, not 0');
        self::assertSame(1, $resolved['facilityManagerCount']);
        self::assertSame(3, $resolved['scoutCount']);
    }

    public function testTheCodeFallbackIsReachedONLYWhenNothingIsConfigured(): void
    {
        $resolved = $this->service([])->resolveTierConfig(8);

        self::assertGreaterThan(0, $resolved['coachCount']);
    }

    public function testTheCodeFallbackStaffsEveryRoleAtEveryTier(): void
    {
        // It used to zero the director of football at tiers 3-8 and the facility manager at
        // 5-8, so a box with no configuration generated clubs missing roles the rest of the
        // game assumes exist. A fallback should produce a WORKING club, not a minimal one.
        $service = $this->service([]);

        foreach (range(1, 8) as $tier) {
            $row = $service->resolveTierConfig($tier);

            foreach (['managerCount', 'coachCount', 'chairmanCount', 'directorOfFootballCount', 'facilityManagerCount', 'scoutCount'] as $key) {
                self::assertGreaterThanOrEqual(1, $row[$key], "tier {$tier} {$key}");
            }
        }
    }
}
