<?php

declare(strict_types=1);

namespace App\Tests\Service\IllicitActivity;

use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\IllicitActivity;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Service\SyncService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The sync's `illicitActivity` list end to end through SyncService::process() (2026-11-27). */
final class IllicitActivitySyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private Club $club;
    private int $week = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->user = new User('illicit-' . bin2hex(random_bytes(6)) . '@sync.test');
        $this->user->setPassword('x');
        $this->em->persist($this->user);
        $this->club = new Club('Illicit Sync FC', $this->user);
        $this->em->persist($this->club);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn   = self::getContainer()->get(Connection::class);
        $clubId = (string) $this->club->getId();
        $conn->executeStatement('DELETE FROM club_illicit_activity WHERE club_id = ?', [$clubId]);
        $conn->executeStatement('DELETE FROM leaderboard_entry WHERE club_id = ?', [$clubId]);
        $conn->executeStatement('DELETE FROM sync_record WHERE club_id = ?', [$clubId]);
        $conn->executeStatement('DELETE FROM club WHERE id = ?', [$clubId]);
        $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(string) $this->user->getId()]);
        parent::tearDown();
    }

    public function testARecordIsInsertedThenUpdatedThenMarkedMissingThenFound(): void
    {
        $record = IllicitActivityValidatorTest::record();

        $this->sync([$record]);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]->getStatus());
        self::assertSame('Northgate Holdings Ltd', $rows[0]->getDetailName());
        self::assertSame(1250000, $rows[0]->getDetailWeeklyPayment());
        self::assertNotNull($rows[0]->getLastSyncRecord());
        $id = (string) $rows[0]->getId();

        $this->sync([array_replace($record, ['status' => 'ended', 'endedWeek' => 79])]);
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($id, (string) $rows[0]->getId(), 'a resend updates the same row');
        self::assertSame('ended', $rows[0]->getStatus());
        self::assertSame(79, $rows[0]->getEndedWeek());

        $this->sync([]);
        $rows = $this->rows();
        self::assertCount(1, $rows, 'rows are never deleted');
        self::assertNotNull($rows[0]->getMissingSince());

        $this->sync(null);
        self::assertNotNull($this->rows()[0]->getMissingSince(), 'a sync without the field changes nothing');

        $this->sync([$record]);
        self::assertNull($this->rows()[0]->getMissingSince(), 'a record that comes back is no longer missing');
    }

    public function testABadRecordNeitherFailsTheSyncNorCountsAsMissing(): void
    {
        $this->sync([IllicitActivityValidatorTest::record()]);

        $broken = IllicitActivityValidatorTest::record(['suspicion' => 900]);
        $other  = IllicitActivityValidatorTest::record(['id' => 'second']);
        $result = $this->sync([$broken, $other]);

        self::assertTrue($result['accepted']);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNull($row->getMissingSince());
        }
        self::assertSame(64, $this->rowFor(IllicitActivityValidatorTest::record()['id'])->getSuspicion(), 'the invalid resend did not overwrite the stored row');
    }

    public function testTheListTouchesNoMoneyAndIsArchived(): void
    {
        $result = $this->sync([IllicitActivityValidatorTest::record()], balance: 5_000_000);

        self::assertTrue($result['accepted']);
        $this->em->clear();
        $club = $this->em->find(Club::class, $this->club->getId());
        self::assertSame(5_000_000, $club->getBalance(), 'the balance is the one the device sent, nothing added');

        $ledgerRows = (int) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM user_ledger WHERE user_id = ?', [(string) $this->user->getId()]);
        self::assertSame(0, $ledgerRows);

        $record = $this->em->getRepository(SyncRecord::class)->findOneBy(['club' => $club], ['serverTimestamp' => 'DESC']);
        self::assertSame([IllicitActivityValidatorTest::record()], $record->getPayload()['illicitActivity']);
    }

    /** @return array<string, mixed> */
    private function sync(?array $list, int $balance = 1_000_000): array
    {
        $request                  = new SyncRequest();
        $request->clubId          = (string) $this->club->getId();
        $request->weekNumber      = ++$this->week;
        $request->clientTimestamp = (new \DateTimeImmutable())->format(DATE_ATOM);
        $request->balance         = $balance;
        $request->illicitActivity = $list;

        $user   = $this->em->find(User::class, $this->user->getId());
        $result = self::getContainer()->get(SyncService::class)->process($user, $request);
        $this->em->flush();

        return $result;
    }

    /** @return list<IllicitActivity> */
    private function rows(): array
    {
        $this->em->clear();

        return $this->em->getRepository(IllicitActivity::class)->findBy(['club' => (string) $this->club->getId()], ['firstSeenAt' => 'ASC', 'clientId' => 'ASC']);
    }

    private function rowFor(string $clientId): IllicitActivity
    {
        $this->em->clear();

        return $this->em->getRepository(IllicitActivity::class)->findOneBy(['club' => (string) $this->club->getId(), 'clientId' => $clientId]);
    }
}
