<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\User;
use App\Service\EconomicService;
use App\Service\InboxService;
use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EconomicServiceTest extends TestCase
{
    private EconomicService $service;

    protected function setUp(): void
    {
        $em              = $this->createStub(EntityManagerInterface::class);
        $inboxService    = $this->createStub(InboxService::class);
        $gameConfigRepo  = $this->createStub(GameConfigRepository::class);

        $this->service = new EconomicService($em, $inboxService, $gameConfigRepo);
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

}
