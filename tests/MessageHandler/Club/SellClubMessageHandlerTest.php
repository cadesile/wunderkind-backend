<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Club;

use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Enum\Club\ClubSaleStatus;
use App\Message\Club\SellClubMessage;
use App\MessageHandler\Club\SellClubMessageHandler;
use App\Service\Club\ClubSaleValuationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The handler's two quiet returns, which are the whole of its own logic.
 *
 * Everything the handler actually DOES lives in ClubSaleService and is tested there. What is
 * only testable here is that a message for a vanished or already-completed sale is a no-op
 * rather than an exception — because both are ordinary outcomes of a duplicate delivery or a
 * retry, and a handler that threw on them would push legitimate traffic into the `failed`
 * transport and look like an incident.
 */
class SellClubMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SellClubMessageHandler $handler;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->handler = self::getContainer()->get(SellClubMessageHandler::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $ids = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);

            if ($ids === []) {
                continue;
            }

            $managed = $this->em->find($entity::class, count($ids) === 1 ? reset($ids) : $ids);

            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }

        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testAMessageForASaleThatNoLongerExistsIsANoOp(): void
    {
        // An operator deleted the row between dispatch and consumption.
        ($this->handler)(new SellClubMessage((string) new \Symfony\Component\Uid\UuidV7()));

        $this->addToAssertionCount(1);
    }

    public function testACompletedSaleIsNotRunAgain(): void
    {
        $user = new User('handler-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Handler Town FC', $user);
        $this->em->persist($club);
        $this->cleanup[] = $club;
        $this->em->flush();

        $breakdown = self::getContainer()->get(ClubSaleValuationService::class)->breakdown($club);

        $sale = new ClubSale(
            $user,
            (string) $club->getId(),
            $club->getName(),
            (int) $breakdown['estimatePence'],
            500_000,
            $breakdown,
        );
        // Already done, by an earlier delivery. The club deliberately still exists here, so if
        // the handler ran the sale anyway the club would be gone and the assertion would catch it.
        $sale->markCompleted(['club' => 1]);
        $this->em->persist($sale);
        $this->cleanup[] = $sale;
        $this->em->flush();

        ($this->handler)(new SellClubMessage((string) $sale->getId()));

        self::assertSame(ClubSaleStatus::COMPLETED, $sale->getStatus());
        self::assertNull($sale->getLedgerEntry(), 'no credit should have been written');
        self::assertNotNull(
            $this->em->find(Club::class, (string) $club->getId()),
            'a completed sale must not re-scrub',
        );
    }
}
