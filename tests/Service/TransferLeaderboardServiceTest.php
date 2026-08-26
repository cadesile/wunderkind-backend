<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\TransferType;
use App\Service\TransferLeaderboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Transfer leaderboards must only surface clubs that have concluded at least one full
 * season (Club::$currentSeason > 1), same eligibility rule as the category leaderboards.
 */
class TransferLeaderboardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testGetTopSellersExcludesClubsThatHaveNotConcludedASeason(): void
    {
        $rookie  = $this->persistClub('Transfer Rookie FC', currentSeason: 1);
        $veteran = $this->persistClub('Transfer Veteran FC', currentSeason: 2);

        $this->persistTransfer($rookie, 5000);
        $this->persistTransfer($veteran, 1000);
        $this->em->flush();

        $service = self::getContainer()->get(TransferLeaderboardService::class);
        $results = $service->getTopSellers('all-time', 10);

        $names = array_column($results, 'clubName');

        $this->assertNotContains('Transfer Rookie FC', $names, 'a club on its first season must not appear');
        $this->assertContains('Transfer Veteran FC', $names, 'a club that has concluded a season must appear');
    }

    public function testGetMostValuableSaleExcludesClubsThatHaveNotConcludedASeason(): void
    {
        $rookie = $this->persistClub('Valuable Rookie FC', currentSeason: 1);
        $this->persistTransfer($rookie, 999999);
        $this->em->flush();

        $service = self::getContainer()->get(TransferLeaderboardService::class);
        $result  = $service->getMostValuableSale('all-time');

        $this->assertTrue(
            $result === null || $result['clubName'] !== 'Valuable Rookie FC',
            'a club on its first season must not win most-valuable-sale'
        );
    }

    private function persistClub(string $name, int $currentSeason): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@tlb.test'));
        $user->setPassword('x');

        $club = $this->persist(new Club($name, $user));
        $club->setCurrentSeason($currentSeason);

        return $club;
    }

    private function persistTransfer(Club $club, int $netProceeds): Transfer
    {
        $transfer = $this->persist(new Transfer(
            player: null,
            club: $club,
            destinationClubName: 'Some Buyer FC',
            type: TransferType::SALE,
            occurredAt: new \DateTimeImmutable(),
        ));
        $transfer->setNetProceeds($netProceeds);

        return $transfer;
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}
