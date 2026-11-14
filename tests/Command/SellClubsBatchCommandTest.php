<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Enum\Club\ClubSaleStatus;
use App\Message\Club\SellClubMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The go-live batch's safety rails.
 *
 * This command is the only path that authorises payouts with NOBODY LOOKING — it pays the
 * model's own estimate unreviewed, across every account. So the things worth pinning are not
 * the happy path (which is ClubSaleService's, tested there) but the four ways it refuses:
 * no selector, spoof clubs, a club already sold, and --dry-run.
 */
class SellClubsBatchCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $app;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em  = self::getContainer()->get(EntityManagerInterface::class);
        $this->app = new Application(self::$kernel);
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

    private function tester(): CommandTester
    {
        return new CommandTester($this->app->find('app:clubs:sell-batch'));
    }

    private function club(string $name, bool $spoof = false): Club
    {
        $user = new User('batch-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club($name, $user);
        $club->setBalance(500_000);
        $club->setSpoof($spoof);
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return $club;
    }

    public function testItRefusesToRunWithNoSelector(): void
    {
        // A bare invocation must do nothing rather than sell the world. There is no interactive
        // confirmation on this command, so this is the only thing standing between a stray
        // `app:clubs:sell-batch` and every account on the server.
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Nothing selected', $tester->getDisplay());
    }

    public function testADryRunQueuesNothing(): void
    {
        $club = $this->club('Dry Run Town FC');

        $tester = $this->tester();
        $tester->execute(['--club' => [(string) $club->getId()], '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('would queue', $tester->getDisplay());
        self::assertStringContainsString('nobody will be paid', $tester->getDisplay());

        self::assertNull(
            $this->em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]),
        );
        self::assertNotNull($this->em->find(Club::class, (string) $club->getId()));
    }

    public function testASpoofClubIsRefusedEvenWhenNamedExplicitly(): void
    {
        // Spoof clubs are generated test clones whose accounts get purged wholesale by
        // app:clubs:purge-spoof, so crediting one is never the intent — and an id typed by hand
        // is no more an authorisation than a sweep is.
        $spoof = $this->club('Spoof Town FC', true);

        $tester = $this->tester();
        $tester->execute(['--club' => [(string) $spoof->getId()]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No eligible clubs', $tester->getDisplay());
        self::assertNull(
            $this->em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $spoof->getId()]),
        );
    }

    public function testItQueuesARealRunAndSkipsAClubAlreadySold(): void
    {
        $club = $this->club('Batch Town FC');

        $tester = $this->tester();
        $tester->execute(['--club' => [(string) $club->getId()], '--note' => 'Thanks for playing.']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $sale = $this->em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]);
        $this->cleanup[] = $sale;

        self::assertNotNull($sale);
        self::assertSame(ClubSaleStatus::PENDING, $sale->getStatus());
        // The estimate IS the authorised figure on this path — that is the difference from the
        // overlay, where a human adjusts it.
        self::assertSame($sale->getEstimatePence(), $sale->getAgreedPence());
        self::assertSame('Thanks for playing.', $sale->getLedgerNote());
        self::assertSame('console:app:clubs:sell-batch', $sale->getAdminEmail());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.clubsale');
        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(SellClubMessage::class, $transport->getSent()[0]->getMessage());

        // Queued, not done — the club is still standing until a worker drains the queue.
        self::assertNotNull($this->em->find(Club::class, (string) $club->getId()));

        // Re-running is safe and quiet: pre-checked rather than left to the partial unique
        // index, because a flush violation would close the EM and abort the whole batch on its
        // first repeat.
        $second = $this->tester();
        $second->execute(['--club' => [(string) $club->getId()]]);

        self::assertStringContainsString('already sold or queued', $second->getDisplay());
        self::assertCount(
            1,
            $this->em->getRepository(ClubSale::class)->findBy(['clubId' => (string) $club->getId()]),
        );
    }

    public function testRequirePlotSkipsAClubWhoseTownWasOnlyInferred(): void
    {
        // The option exists because this path pays without review: a club whose land was guessed
        // from its name is exactly the one an operator may want to handle by hand.
        $club = $this->club('Zzyzx Wanderers');

        $tester = $this->tester();
        $tester->execute(['--club' => [(string) $club->getId()], '--require-plot' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('skipped: land', $tester->getDisplay());
        self::assertNull(
            $this->em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]),
        );
    }
}
