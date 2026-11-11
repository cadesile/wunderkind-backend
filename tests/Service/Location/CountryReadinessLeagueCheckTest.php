<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\League;
use App\Repository\LeagueRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\CountryReadinessService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The readiness gate must not overrule the generate form.
 *
 * It used to demand eight league tiers. Once an admin can deliberately choose three, that
 * rule turns a supported configuration into a country that can never go Live — and it
 * fails in three places at once: the admin refuses the LIVE transition, GET /api/locations
 * hides the country's land, and app:country:check reports a permanent failure.
 *
 * There was no test on this check at all, so relaxing it would otherwise have broken
 * nothing and proved nothing.
 */
class CountryReadinessLeagueCheckTest extends TestCase
{
    public function testAShallowPyramidPassesTheLeagueCheck(): void
    {
        $verdict = $this->leagueCheckFor(3);

        $this->assertTrue($verdict['ok'], 'a deliberately 3-tier country must be allowed to go Live');
        $this->assertStringContainsString('3', $verdict['detail']);
    }

    public function testASingleTierCountryPasses(): void
    {
        $this->assertTrue($this->leagueCheckFor(1)['ok']);
    }

    public function testAFullPyramidStillPasses(): void
    {
        $this->assertTrue($this->leagueCheckFor(8)['ok']);
    }

    public function testACountryWithNoLeaguesIsStillBlocked(): void
    {
        // The check still has a job: no pyramid at all means nothing can serve a pack,
        // place a founder, or run a season.
        $verdict = $this->leagueCheckFor(0);

        $this->assertFalse($verdict['ok']);
        $this->assertStringContainsString('no league tiers', $verdict['detail']);
    }

    /** @return array{key: string, ok: bool, detail: string} */
    private function leagueCheckFor(int $tierCount): array
    {
        $leagues = [];
        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $leagues[] = new League('ZZ', $tier, 'League ' . $tier);
        }

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findByCountry')->willReturn($leagues);

        $service = new CountryReadinessService(
            $leagueRepo,
            $this->createStub(LocationRepository::class),
            $this->createStub(StarterConfigRepository::class),
            $this->createStub(Connection::class),
            // A real provider over a stubbed repository: findByCode() returns null, so the
            // content check falls through to CountryContentRegistry exactly as before.
            new \App\Service\CountryContent\CountryNamingContentProvider(
                $this->createStub(\App\Repository\Location\CountryProfileRepository::class),
            ),
        );

        // leagueCheck() is private; evaluate() is the public surface and returns every
        // check keyed by name.
        $checks = $service->evaluate('ZZ')['checks'] ?? [];

        foreach ($checks as $check) {
            if (($check['key'] ?? null) === 'leagues') {
                return $check;
            }
        }

        self::fail('evaluate() returned no "leagues" check.');
    }
}
