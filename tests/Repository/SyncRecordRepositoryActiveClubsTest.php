<?php

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Repository\SyncRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findMostActiveClubs()'s $minSyncCount eligibility floor — the gate behind the
 * landing page's Club Spotlight (ClubSpotlightService::MIN_SYNC_COUNT).
 *
 * Every assertion is scoped by a $since captured in setUp(), before any fixture
 * exists: SyncRecord stamps serverTimestamp at construction, so the window only
 * ever contains rows this test made. Without that, leftover rows from other
 * tests in the same run would make "no eligible clubs returns []" unassertable.
 */
class SyncRecordRepositoryActiveClubsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private \DateTimeImmutable $since;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em    = self::getContainer()->get(EntityManagerInterface::class);
        $this->since = new \DateTimeImmutable();
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

    private function repo(): SyncRecordRepository
    {
        return self::getContainer()->get(SyncRecordRepository::class);
    }

    /** A club with $valid valid syncs and $invalid rejected ones, all inside the window. */
    private function clubWithSyncs(string $name, int $valid, int $invalid = 0): Club
    {
        $user = $this->persist(new User(bin2hex(random_bytes(8)) . '@spotlight.test'));
        $user->setPassword('x');
        $club = $this->persist(new Club($name, $user));

        for ($i = 0; $i < $valid + $invalid; $i++) {
            $record = $this->persist(new SyncRecord($club, $i + 1, new \DateTimeImmutable(), []));
            if ($i >= $valid) {
                $record->markInvalid('week-rollback');
            }
        }

        return $club;
    }

    /** @return list<string> */
    private function activeClubNames(int $minSyncCount): array
    {
        $this->em->flush();

        return array_map(
            static fn (Club $c): string => $c->getName(),
            $this->repo()->findMostActiveClubs($this->since, 10, $minSyncCount),
        );
    }

    public function testClubsBelowTheSyncFloorAreNotEligible(): void
    {
        $this->clubWithSyncs('Three Syncs FC', 3);
        $this->clubWithSyncs('Four Syncs FC', 4);
        $this->clubWithSyncs('Five Syncs FC', 5);

        $names = $this->activeClubNames(4);

        $this->assertContains('Five Syncs FC', $names);
        $this->assertContains('Four Syncs FC', $names, 'The floor is inclusive — exactly 4 syncs qualifies.');
        $this->assertNotContains('Three Syncs FC', $names);
    }

    public function testInvalidSyncsDoNotCountTowardsTheFloor(): void
    {
        $this->clubWithSyncs('Padded With Rejects FC', 3, 3);

        $this->assertNotContains('Padded With Rejects FC', $this->activeClubNames(4));
    }

    public function testNoEligibleClubsReturnsEmptyRatherThanFallingBack(): void
    {
        $this->clubWithSyncs('Barely Active FC', 1);
        $this->clubWithSyncs('Also Barely Active FC', 2);

        $this->assertSame(
            [],
            $this->activeClubNames(4),
            'A sparse window must yield no candidates, not the best of a bad bunch.',
        );
    }

    public function testFloorOfOnePreservesThePreExistingUnfilteredBehaviour(): void
    {
        $this->clubWithSyncs('Single Sync FC', 1);

        $this->assertContains('Single Sync FC', $this->activeClubNames(1));
    }
}
