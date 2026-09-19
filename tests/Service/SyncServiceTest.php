<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Service\SyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Covers only the new archival fields added to SyncRequest (fixtures, relationships,
 * promises, excursions) — see the Sync & ConcludeSeason Archive handoff, and the general
 * "unrecognized fields don't break sync" claim. SyncService::process()'s broader behavior
 * (leaderboards, transfers, financial year-end, etc.) is out of scope here.
 */
class SyncServiceTest extends KernelTestCase
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

    public function testNewArchivalFieldsArePersistedVerbatimOnTheSyncRecord(): void
    {
        [$user, $club] = $this->persistUserAndClub();

        $fixtures      = [['fixtureId' => 'f1', 'season' => 1, 'weekNumber' => 3, 'homeGoals' => 2, 'awayGoals' => 0, 'result' => 'HOME_WIN', 'scorers' => [], 'assists' => [], 'cards' => []]];
        $relationships = [['playerAId' => 'p1', 'playerBId' => 'p2', 'bondType' => 'friendship', 'strength' => 60]];
        $promises      = [['promiseId' => 'pr1', 'status' => 'resolved', 'kind' => 'playing_time']];
        $excursions    = [['bookingId' => 'ex1', 'status' => 'pending', 'destination' => 'training_camp']];

        $request                 = new SyncRequest();
        $request->weekNumber     = 1;
        $request->clientTimestamp = (new \DateTimeImmutable())->format(DATE_ATOM);
        $request->fixtures       = $fixtures;
        $request->relationships  = $relationships;
        $request->promises       = $promises;
        $request->excursions     = $excursions;

        self::getContainer()->get(SyncService::class)->process($user, $request);

        $syncRecord = $this->em->getRepository(SyncRecord::class)->findOneBy(['club' => $club]);
        $this->cleanup[] = $syncRecord;
        $this->cleanupLeaderboardEntriesFor($club);

        $this->assertNotNull($syncRecord);
        $payload = $syncRecord->getPayload();
        $this->assertSame($fixtures, $payload['fixtures']);
        $this->assertSame($relationships, $payload['relationships']);
        $this->assertSame($promises, $payload['promises']);
        $this->assertSame($excursions, $payload['excursions']);
    }

    public function testAGenuinelyUnknownFieldIsSilentlyDroppedNotRejected(): void
    {
        // Confirms the codebase-wide claim (default Symfony ALLOW_EXTRA_ATTRIBUTES behavior)
        // that an unrecognized JSON field is silently ignored during denormalization, not
        // rejected — for a field that isn't even one of the 6 named in the handoff, i.e. the
        // general case. Must go through the actual serializer, not direct PHP construction,
        // since a typed object simply has no way to hold an "unknown" property otherwise.
        [$user, $club] = $this->persistUserAndClub();

        $payload = [
            'weekNumber'                  => 1,
            'clientTimestamp'             => (new \DateTimeImmutable())->format(DATE_ATOM),
            'somethingNobodyDefinedYet'   => ['whatever' => 'shape', 'nested' => [1, 2, 3]],
        ];

        $request = self::getContainer()->get(SerializerInterface::class)
            ->denormalize($payload, SyncRequest::class);

        $this->assertInstanceOf(SyncRequest::class, $request);
        $this->assertFalse(property_exists($request, 'somethingNobodyDefinedYet'));

        $result = self::getContainer()->get(SyncService::class)->process($user, $request);

        $syncRecord = $this->em->getRepository(SyncRecord::class)->findOneBy(['club' => $club]);
        $this->cleanup[] = $syncRecord;
        $this->cleanupLeaderboardEntriesFor($club);

        $this->assertTrue($result['accepted']);
    }

    private function cleanupLeaderboardEntriesFor(Club $club): void
    {
        foreach ($this->em->getRepository(LeaderboardEntry::class)->findBy(['club' => $club]) as $entry) {
            $this->cleanup[] = $entry;
        }
    }

    /** @return array{0: User, 1: Club} */
    private function persistUserAndClub(): array
    {
        $user = new User(bin2hex(random_bytes(8)) . '@sync.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Sync Archive FC', $user);
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return [$user, $club];
    }
}
