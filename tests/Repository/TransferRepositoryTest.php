<?php

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\StatsPeriod;
use App\Enum\TransferType;
use App\Repository\TransferRepository;
use App\Service\PeriodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransferRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PeriodResolver $resolver;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(PeriodResolver::class);
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

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    private function club(string $name): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@transfer-repo.test'));
        $user->setPassword('x');

        return $this->persist(new Club($name, $user));
    }

    private function transfer(Club $club, TransferType $type, int $fee): Transfer
    {
        $transfer = $this->persist(new Transfer(null, $club, 'Some Other Club', $type, new \DateTimeImmutable()));
        $transfer->setFee($fee);

        return $transfer;
    }

    private function repo(): TransferRepository
    {
        return self::getContainer()->get(TransferRepository::class);
    }

    public function testGetBiggestSpendersByClubOnlyCountsSignings(): void
    {
        $buyer = $this->club('Big Spender FC');
        $this->transfer($buyer, TransferType::SIGNING, 500000);
        $this->transfer($buyer, TransferType::SIGNING, 250000);
        // A sale by the same club (money received, not spent) must not count.
        $this->transfer($buyer, TransferType::SALE, 900000);

        $seller = $this->club('Pure Seller FC');
        $this->transfer($seller, TransferType::SALE, 1000000);

        $this->em->flush();

        $results = $this->repo()->getBiggestSpendersByClub(StatsPeriod::ALL, 10, $this->resolver);
        $byClubId = [];
        foreach ($results as $row) {
            $byClubId[(string) $row['clubId']] = $row;
        }

        $this->assertArrayHasKey((string) $buyer->getId(), $byClubId);
        $this->assertSame(750000, $byClubId[(string) $buyer->getId()]['value']);
        $this->assertArrayNotHasKey((string) $seller->getId(), $byClubId, 'a club with only outgoing sales must not appear on the spenders board');
    }

    public function testGetBiggestSpendersByClubRespectsPeriodFilter(): void
    {
        $club = $this->club('Old Spend FC');
        $oldTransfer = $this->persist(new Transfer(null, $club, 'Some Other Club', TransferType::SIGNING, (new \DateTimeImmutable())->modify('-60 days')));
        $oldTransfer->setFee(1000000);

        $this->em->flush();

        $results = $this->repo()->getBiggestSpendersByClub(StatsPeriod::WEEK, 10, $this->resolver);
        $ids = array_map(static fn (array $r) => (string) $r['clubId'], $results);

        $this->assertNotContains((string) $club->getId(), $ids, 'a signing outside the window must not count');
    }
}
