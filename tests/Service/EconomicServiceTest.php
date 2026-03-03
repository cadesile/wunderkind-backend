<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\User;
use App\Enum\InvestorTier;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Service\EconomicService;
use App\Service\InboxService;
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

        $this->service = new EconomicService($em, $inboxService, $investorRepo, $sponsorRepo);
    }

    public function testCalculatePlayerMarketValueReturnsPositiveInt(): void
    {
        $player = $this->makePlayer(age: 17, ability: 60, potential: 80);
        $value  = $this->service->calculatePlayerMarketValue($player);

        $this->assertIsInt($value);
        $this->assertGreaterThan(0, $value);
    }

    public function testAgeOutWarningTriggeredFourWeeksBefore(): void
    {
        $player    = $this->makePlayer(age: 20, ability: 50, potential: 70);
        $academy   = $player->getAcademy();
        $timestamp = new \DateTimeImmutable();

        // Set forced sale week to currentWeek + 3 (within warning window)
        $player->setForcedSaleWeek(103);

        $inboxService = $this->createMock(InboxService::class);
        $inboxService->expects($this->once())->method('sendAgeOutWarning');

        $em           = $this->createMock(EntityManagerInterface::class);
        $investorRepo = $this->createMock(InvestorRepository::class);
        $sponsorRepo  = $this->createMock(SponsorRepository::class);

        $service = new EconomicService($em, $inboxService, $investorRepo, $sponsorRepo);
        $service->checkAgeOutPlayers($academy, 100, $timestamp);

        $this->assertTrue($player->isAgeOutWarningIssued());
    }

    public function testForcedSaleExecutedAtForcedSaleWeek(): void
    {
        $player  = $this->makePlayer(age: 21, ability: 50, potential: 70);
        $academy = $player->getAcademy();

        $player->setForcedSaleWeek(100);

        $inboxService = $this->createMock(InboxService::class);
        $inboxService->expects($this->atLeastOnce())->method('sendForcedSaleNotification');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $investorRepo = $this->createMock(InvestorRepository::class);
        $sponsorRepo  = $this->createMock(SponsorRepository::class);

        $service = new EconomicService($em, $inboxService, $investorRepo, $sponsorRepo);
        $service->checkAgeOutPlayers($academy, 100, new \DateTimeImmutable());

        $this->assertTrue($player->isForcedSaleExecuted());
    }

    public function testCannotExceedFiftyPercentOwnership(): void
    {
        $user    = new User('test@example.com');
        $academy = new Academy('Test Academy', $user);

        // Simulate 46 % already owned
        $investor = new Investor('Test Investor');
        $investor->setPercentageOwned(46.0);
        $investor->setAcademy($academy);

        $this->assertFalse($academy->canAcceptInvestor(5.0));
        $this->assertTrue($academy->canAcceptInvestor(3.0));
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
        $user    = new User('test@example.com');
        $academy = new Academy('Test Academy', $user);
        $dob     = (new \DateTimeImmutable())->modify("-{$age} years");

        $player = new Player(
            firstName:         'Test',
            lastName:          'Player',
            dateOfBirth:       $dob,
            nationality:       'English',
            position:          PlayerPosition::MIDFIELDER,
            recruitmentSource: RecruitmentSource::YOUTH_INTAKE,
            potential:         $potential,
            currentAbility:    $ability,
            academy:           $academy,
        );

        return $player;
    }
}
