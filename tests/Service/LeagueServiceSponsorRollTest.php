<?php

namespace App\Tests\Service;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\Sponsor;
use App\Enum\CompanySize;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LeagueServiceSponsorRollTest extends TestCase
{
    private function makeConfig(int $min, int $max): GameConfig
    {
        $config = new GameConfig();
        $config->setSmallSponsorMin($min);
        $config->setSmallSponsorMax($max);
        $config->setMediumSponsorMin($min * 2);
        $config->setMediumSponsorMax($max * 2);
        $config->setLargeSponsorMin($min * 4);
        $config->setLargeSponsorMax($max * 4);
        return $config;
    }

    private function makeService(): LeagueService
    {
        $repo       = $this->createStub(LeagueRepository::class);
        $em         = $this->createStub(EntityManagerInterface::class);
        $configRepo = $this->createStub(GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn($this->makeConfig(10000, 50000));
        $fixtureGenerationService = $this->createStub(\App\Service\FixtureGenerationService::class);
        $hallOfFameScoreService = $this->createStub(\App\Service\HallOfFameScoreService::class);
        $leaderboardCalculationService = $this->createStub(\App\Service\LeaderboardCalculationService::class);

        return new LeagueService(
            $repo,
            $em,
            $configRepo,
            $fixtureGenerationService,
            $hallOfFameScoreService,
            $leaderboardCalculationService,
        );
    }

    public function testRollLeagueSponsorsUpdatesRolledValueWithinRange(): void
    {
        $league  = new League('EN', 5, 'League 5');
        $sponsor = new Sponsor('Corp A');
        $sponsor->setSize(CompanySize::SMALL);
        $league->addSponsor($sponsor);

        $config = $this->makeConfig(10000, 50000);
        $service = $this->makeService();

        $total = $service->rollLeagueSponsors($league, $config);

        $ls = $league->getLeagueSponsors()->first();
        $this->assertGreaterThanOrEqual(10000, $ls->getRolledValue());
        $this->assertLessThanOrEqual(50000, $ls->getRolledValue());
        $this->assertSame($ls->getRolledValue(), $total);
    }

    public function testRollLeagueSponsorsSumsMultipleSponsors(): void
    {
        $league   = new League('EN', 3, 'League 3');
        $sponsor1 = new Sponsor('Corp A');
        $sponsor1->setSize(CompanySize::SMALL);
        $sponsor2 = new Sponsor('Corp B');
        $sponsor2->setSize(CompanySize::MEDIUM);
        $league->addSponsor($sponsor1);
        $league->addSponsor($sponsor2);

        $config  = $this->makeConfig(10000, 50000);
        $service = $this->makeService();
        $total   = $service->rollLeagueSponsors($league, $config);

        $sumOfRolled = 0;
        foreach ($league->getLeagueSponsors() as $ls) {
            $sumOfRolled += $ls->getRolledValue();
        }
        $this->assertSame($sumOfRolled, $total);
    }

    public function testRollLeagueSponsorsReturnsZeroForNoSponsors(): void
    {
        $league  = new League('EN', 8, 'League 8');
        $config  = $this->makeConfig(10000, 50000);
        $service = $this->makeService();

        $total = $service->rollLeagueSponsors($league, $config);

        $this->assertSame(0, $total);
    }

    public function testRollUsesMinWhenMinEqualsMax(): void
    {
        $league  = new League('EN', 5, 'League 5');
        $sponsor = new Sponsor('Corp A');
        $sponsor->setSize(CompanySize::SMALL);
        $league->addSponsor($sponsor);

        $config = new GameConfig();
        $config->setSmallSponsorMin(25000);
        $config->setSmallSponsorMax(25000); // min == max

        $service = $this->makeService();
        $total   = $service->rollLeagueSponsors($league, $config);

        $this->assertSame(25000, $total);
    }

    public function testRollUsesLargeSponsorRange(): void
    {
        $league  = new League('EN', 1, 'League 1');
        $sponsor = new Sponsor('Big Corp');
        $sponsor->setSize(CompanySize::LARGE);
        $league->addSponsor($sponsor);

        $config = new GameConfig();
        $config->setLargeSponsorMin(200000);
        $config->setLargeSponsorMax(1000000);

        $service = $this->makeService();
        $total   = $service->rollLeagueSponsors($league, $config);

        $ls = $league->getLeagueSponsors()->first();
        $this->assertGreaterThanOrEqual(200000, $ls->getRolledValue());
        $this->assertLessThanOrEqual(1000000, $ls->getRolledValue());
        $this->assertSame($ls->getRolledValue(), $total);
    }
}
