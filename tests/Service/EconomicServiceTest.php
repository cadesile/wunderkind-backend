<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\User;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Service\EconomicService;
use App\Service\InboxService;
use App\Repository\GameConfigRepository;
use App\Repository\InvestorRepository;
use App\Repository\SponsorRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EconomicServiceTest extends TestCase
{
    private EconomicService $service;

    protected function setUp(): void
    {
        $em              = $this->createMock(EntityManagerInterface::class);
        $inboxService    = $this->createMock(InboxService::class);
        $investorRepo    = $this->createMock(InvestorRepository::class);
        $sponsorRepo     = $this->createMock(SponsorRepository::class);
        $gameConfigRepo  = $this->createMock(GameConfigRepository::class);

        $this->service = new EconomicService($em, $inboxService, $investorRepo, $sponsorRepo, $gameConfigRepo);
    }

    public function testCalculatePlayerMarketValueReturnsPositiveInt(): void
    {
        $player = $this->makePlayer(age: 17, ability: 60, potential: 80);
        $value  = $this->service->calculatePlayerMarketValue($player);

        $this->assertIsInt($value);
        $this->assertGreaterThan(0, $value);
    }

    public function testCannotExceedFiftyPercentOwnership(): void
    {
        $user    = new User('test@example.com');
        $club = new Club('Test Club', $user);

        // Simulate 46 % already owned
        $investor = new Investor('Test Investor');
        $investor->setPercentageOwned(46.0);
        $investor->setClub($club);
        $club->getInvestors()->add($investor); // Manually add to collection

        $this->assertFalse($club->canAcceptInvestor(5.0));
        $this->assertTrue($club->getInvestors()->contains($investor));
        $this->assertTrue($club->canAcceptInvestor(3.0));
    }

    public function testAnnualPayoutCalculation(): void
    {
        $investor = new Investor('Test Investor');
        $investor->setPercentageOwned(10.0);

        $payout = $investor->calculateAnnualPayout(1_000_000);
        $this->assertSame(100_000, $payout);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePlayer(int $age, int $ability, int $potential): Player
    {
        $dob = (new \DateTimeImmutable())->modify("-{$age} years");

        return new Player(
            firstName:         'Test',
            lastName:          'Player',
            dateOfBirth:       $dob,
            nationality:       'English',
            position:          PlayerPosition::MIDFIELDER,
            recruitmentSource: RecruitmentSource::YOUTH_INTAKE,
            potential:         $potential,
            currentAbility:    $ability,
        );
    }
}
