<?php

declare(strict_types=1);

namespace App\Tests\Service\WorldPack;

use App\Entity\Club;
use App\Entity\User;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPack\WorldPackSetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The claim is the one place the whole mechanism's promise is kept or broken: a world pack set
 * must go to exactly one club, and no two clubs may ever be served the same one.
 *
 * The obvious implementation — SELECT a candidate, then UPDATE it — hands the same set to two
 * clubs whenever two devices initialise within the same few milliseconds. These tests exist to
 * pin the single-statement version against that, and to pin the three ways a set is NOT stock
 * (claimed, stale-shaped, and squadless-from-the-migration), each of which would otherwise be
 * handed out and look fine right up until the player tried to play it.
 */
class WorldPackSetClaimTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorldPackSetService $service;
    private WorldPackSetRepository $repository;
    private string $country;
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->service    = self::getContainer()->get(WorldPackSetService::class);
        $this->repository = self::getContainer()->get(WorldPackSetRepository::class);
        $this->country    = $this->unusedCountryCode();
    }

    protected function tearDown(): void
    {
        // Sets first: a club cannot go while a set still points at it.
        foreach ($this->cleanup as $entity) {
            if ($entity instanceof WorldPackSet) {
                $managed = $this->em->find(WorldPackSet::class, (string) $entity->getId());
                if ($managed !== null) {
                    $managed->setClaimedByClub(null);
                    $this->em->remove($managed);
                }
            }
        }
        $this->em->flush();

        foreach (array_reverse($this->cleanup) as $entity) {
            if (!$entity instanceof WorldPackSet) {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            }
        }
        $this->em->flush();
        $this->cleanup = [];

        parent::tearDown();
    }

    /** The core guarantee. Two clubs, two ready sets, and they must not collide. */
    public function testTwoClubsClaimingTheSameCountryGetDifferentSets(): void
    {
        $a = $this->makeReadySet();
        $b = $this->makeReadySet();

        $clubA = $this->makeClub('a');
        $clubB = $this->makeClub('b');

        $claimedA = $this->service->claimForClub($clubA, $this->country);
        $claimedB = $this->service->claimForClub($clubB, $this->country);

        $this->assertNotNull($claimedA);
        $this->assertNotNull($claimedB);
        $this->assertNotSame(
            (string) $claimedA->getId(),
            (string) $claimedB->getId(),
            'two clubs must never be handed the same set',
        );
        $this->assertEqualsCanonicalizing(
            [(string) $a->getId(), (string) $b->getId()],
            [(string) $claimedA->getId(), (string) $claimedB->getId()],
        );

        // Both sides of the claim are recorded: the set knows its club, the club knows its set.
        $this->assertSame((string) $clubA->getId(), (string) $claimedA->getClaimedByClub()?->getId());
        $this->assertSame((string) $claimedA->getId(), (string) $clubA->getWorldPackSet()?->getId());
        $this->assertSame(WorldPackSetStatus::CLAIMED, $claimedA->getStatus());
        $this->assertNotNull($claimedA->getClaimedAt());
    }

    /**
     * A third club with nothing left gets null, NOT a set somebody already holds.
     *
     * Null is the signal the controller falls back on, and the fallback is what keeps this
     * change backwards compatible — so "ran out" must be distinguishable from "here, have
     * one anyway".
     */
    public function testAClubGetsNullWhenTheCountryIsOutOfStock(): void
    {
        $this->makeReadySet();

        $first  = $this->service->claimForClub($this->makeClub('first'), $this->country);
        $second = $this->service->claimForClub($this->makeClub('second'), $this->country);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    /** Re-claiming returns the club's OWN set rather than consuming a second one. */
    public function testAClubThatAlreadyHasASetIsGivenItBackRatherThanAnotherOne(): void
    {
        $this->makeReadySet();
        $this->makeReadySet();

        $club  = $this->makeClub('repeat');
        $first = $this->service->claimForClub($club, $this->country);
        $again = $this->service->claimForClub($club, $this->country);

        $this->assertNotNull($first);
        $this->assertSame((string) $first->getId(), (string) $again?->getId());
        $this->assertSame(
            1,
            $this->repository->countReadyUnclaimed($this->country, WorldInitializationService::WORLD_PACK_VERSION),
            'a retry must not draw the buffer down twice for one club',
        );
    }

    /**
     * The reconcile path: the claim committed but the club-side write was lost.
     *
     * Without it the retry claims a SECOND set and leaks the first, drawing the buffer down by
     * two for one player — and the leaked set is unreachable, since nothing points at it.
     */
    public function testAClaimWhoseClubWriteWasLostIsRecoveredRatherThanLeaked(): void
    {
        $set  = $this->makeReadySet();
        $spare = $this->makeReadySet();
        $club = $this->makeClub('halfclaimed');

        // Simulate the committed claim without the club-side flush.
        $this->em->getConnection()->executeStatement(
            "UPDATE world_pack_set SET status = 'claimed', claimed_by_club_id = :club, claimed_at = NOW() WHERE id = :id",
            ['club' => (string) $club->getId(), 'id' => $set->getId()->toRfc4122()],
        );
        $this->em->clear();

        $club = $this->em->find(Club::class, (string) $club->getId());
        $this->assertNull($club->getWorldPackSet(), 'precondition: the club does not know about its set');

        $recovered = $this->service->claimForClub($club, $this->country);

        $this->assertSame((string) $set->getId(), (string) $recovered?->getId());
        $this->assertSame((string) $set->getId(), (string) $club->getWorldPackSet()?->getId());
        $this->assertSame(
            1,
            $this->repository->countReadyUnclaimed($this->country, WorldInitializationService::WORLD_PACK_VERSION),
            'the spare must still be on the shelf',
        );
        $this->assertNotNull($this->em->find(WorldPackSet::class, (string) $spare->getId()));
    }

    /** A set built at a shape this code no longer serves is not stock. */
    public function testAStaleShapedSetIsNotClaimable(): void
    {
        $set = $this->makeReadySet(payloadVersion: WorldInitializationService::WORLD_PACK_VERSION - 1);

        $this->assertNull($this->service->claimForClub($this->makeClub('stale'), $this->country));
        $this->assertFalse($set->isClaimable(WorldInitializationService::WORLD_PACK_VERSION));
    }

    /**
     * A set with no starter squad is not stock either.
     *
     * This is the shape the introducing migration backfilled around each country's existing
     * cache rows, so `set_id` could become NOT NULL without discarding a warm payload. Handing
     * one out would give a club a world with no squad AND consume a buffer slot, which is
     * strictly worse than falling back to the live draw.
     */
    public function testAMigrationBackfilledSetWithNoSquadIsNotClaimable(): void
    {
        $set = $this->makeReadySet(starterPack: null);

        $this->assertNull($this->service->claimForClub($this->makeClub('squadless'), $this->country));
        $this->assertFalse($set->isClaimable(WorldInitializationService::WORLD_PACK_VERSION));
    }

    /** Oldest-first, so a set cannot sit unclaimed until it ages past a version bump. */
    public function testTheOldestReadySetIsClaimedFirst(): void
    {
        $older = $this->makeReadySet();
        $this->em->getConnection()->executeStatement(
            "UPDATE world_pack_set SET generated_at = NOW() - INTERVAL '1 day' WHERE id = :id",
            ['id' => $older->getId()->toRfc4122()],
        );
        $newer = $this->makeReadySet();
        $this->em->clear();

        $claimed = $this->service->claimForClub($this->makeClub('oldest'), $this->country);

        $this->assertSame((string) $older->getId(), (string) $claimed?->getId());
        $this->assertNotSame((string) $newer->getId(), (string) $claimed?->getId());
    }

    /** deleteUnclaimed() spares claimed sets — a device mid-init still needs its tiers. */
    public function testDeletingUnclaimedStockSparesAClaimedSet(): void
    {
        $claimedSet = $this->makeReadySet();
        $spare      = $this->makeReadySet();

        $club = $this->makeClub('midinit');
        $this->service->claimForClub($club, $this->country);

        $deleted = $this->service->deleteUnclaimed($this->country);

        // deleteUnclaimed() is a DQL bulk delete, which bypasses the UnitOfWork — without the
        // clear, find() would hand back the still-managed copy of a row that is gone.
        $this->em->clear();

        $this->assertSame(1, $deleted);
        $this->assertNotNull(
            $this->em->find(WorldPackSet::class, (string) $claimedSet->getId()),
            'the claimed set must survive — its club is still fetching tiers from it',
        );
        $this->assertNull($this->em->find(WorldPackSet::class, (string) $spare->getId()));
    }

    private function makeReadySet(
        ?int $payloadVersion = null,
        ?array $starterPack = ['players' => [['id' => 'p1']], 'staff' => [], 'scouts' => []],
    ): WorldPackSet {
        $set = new WorldPackSet(
            $this->country,
            $payloadVersion ?? WorldInitializationService::WORLD_PACK_VERSION,
            $starterPack,
            8,
        );
        $set->setStatus(WorldPackSetStatus::READY);
        $set->setReadyAt(new \DateTimeImmutable());

        $this->em->persist($set);
        $this->em->flush();
        $this->cleanup[] = $set;

        return $set;
    }

    private function makeClub(string $suffix): Club
    {
        $user = new User(sprintf('claimtest-%s-%s@example.test', strtolower($this->country), $suffix));
        $user->setPassword('not-a-real-hash');
        $this->em->persist($user);

        $club = new Club(sprintf('Claim Test %s %s', $this->country, $suffix), $user);
        $club->setCountry($this->country);
        $this->em->persist($club);
        $this->em->flush();

        $this->cleanup[] = $club;
        $this->cleanup[] = $user;

        return $club;
    }

    private function unusedCountryCode(): string
    {
        $conn = $this->em->getConnection();

        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $used = (int) $conn->fetchOne('SELECT count(*) FROM world_pack_set WHERE country = :c', ['c' => $code]);
            if ($used === 0) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }
}
