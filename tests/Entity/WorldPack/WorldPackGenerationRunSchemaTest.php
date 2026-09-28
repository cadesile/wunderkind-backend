<?php

declare(strict_types=1);

namespace App\Tests\Entity\WorldPack;

use App\Entity\NpcClub;
use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\CitySize;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Schema-level proof for the new WorldPackGenerationRun/TierRun/ClubRun tables:
 * relationships persist correctly, and both unique constraints (the plain
 * (run,tier)/(tierRun,npcClub) ones and the raw-SQL partial "one active run per
 * country" index) are actually enforced by the database, not just documented.
 */
class WorldPackGenerationRunSchemaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
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
        $this->em->flush();
        parent::tearDown();
    }

    private function track(object $entity): object
    {
        $this->cleanup[] = $entity;
        return $entity;
    }

    public function testRunTierRunClubRunPersistWithRealAssociations(): void
    {
        $npcClub = $this->track(new NpcClub('Schema Test FC', 'EN', 8, 50, '#111111', '#eeeeee', 100_000, [], citySize: CitySize::MEDIUM));
        $this->em->persist($npcClub);

        $run = $this->track(new WorldPackGenerationRun('EN', [8]));
        $this->em->persist($run);

        $tierRun = $this->track(new WorldPackGenerationTierRun($run, 'EN', 8));
        $tierRun->setTotalClubCount(1);
        $this->em->persist($tierRun);

        $clubRun = $this->track(new WorldPackGenerationClubRun($tierRun, $npcClub));
        $this->em->persist($clubRun);

        $this->em->flush();
        $this->em->clear();

        /** @var WorldPackGenerationRunRepository $runRepo */
        $runRepo = self::getContainer()->get(WorldPackGenerationRunRepository::class);
        /** @var WorldPackGenerationTierRunRepository $tierRepo */
        $tierRepo = self::getContainer()->get(WorldPackGenerationTierRunRepository::class);
        /** @var WorldPackGenerationClubRunRepository $clubRepo */
        $clubRepo = self::getContainer()->get(WorldPackGenerationClubRunRepository::class);

        $foundRun = $runRepo->findLatestForCountry('EN');
        $this->assertNotNull($foundRun);
        $this->assertSame($run->getId()->toRfc4122(), $foundRun->getId()->toRfc4122());
        $this->assertSame(WorldPackGenerationRunStatus::PENDING, $foundRun->getStatus());

        $tierRuns = $tierRepo->findByRun($foundRun);
        $this->assertCount(1, $tierRuns);
        $this->assertSame(8, $tierRuns[0]->getTier());
        $this->assertSame(WorldPackGenerationTierRunStatus::PENDING, $tierRuns[0]->getStatus());

        $clubRuns = $clubRepo->findByTierRun($tierRuns[0]);
        $this->assertCount(1, $clubRuns);
        $this->assertSame('Schema Test FC', $clubRuns[0]->getClubName());
        $this->assertSame(WorldPackGenerationClubRunStatus::PENDING, $clubRuns[0]->getStatus());
    }

    /**
     * Checked via raw DBAL insert rather than ORM persist+flush: a failed flush
     * closes the EntityManager (documented repo-wide gotcha — see CLAUDE.md's
     * Testing section), which would break tearDown()'s cleanup for every test
     * that runs after this one in the same process. A direct connection insert
     * sidesteps that entirely, same reasoning as CompetitionDrawService's claim
     * methods using raw SQL instead of the ORM for exactly this kind of check.
     */
    public function testDuplicateTierWithinSameRunIsRejected(): void
    {
        $run = $this->track(new WorldPackGenerationRun('DE', [3]));
        $this->em->persist($run);
        $this->em->flush();

        $first = $this->track(new WorldPackGenerationTierRun($run, 'DE', 3));
        $this->em->persist($first);
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->insert('world_pack_generation_tier_run', [
            'id'     => (string) new \Symfony\Component\Uid\UuidV7(),
            'run_id' => $run->getId()->toRfc4122(),
            'country' => 'DE',
            'tier'    => 3,
            'status'  => 'pending',
        ]);
    }

    /** See testDuplicateTierWithinSameRunIsRejected() docblock re: raw DBAL insert. */
    public function testOnlyOneActiveRunPerCountryIsAllowed(): void
    {
        $first = $this->track(new WorldPackGenerationRun('FR', [1]));
        $this->em->persist($first);
        $this->em->flush();

        // A second PENDING run for the same country must be rejected by the
        // partial unique index (country) WHERE status IN ('pending','in_progress').
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->insert('world_pack_generation_run', [
            'id'              => (string) new \Symfony\Component\Uid\UuidV7(),
            'country'         => 'FR',
            'status'          => 'pending',
            'requested_tiers' => '[2]',
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function testCompletedRunDoesNotBlockANewRunForTheSameCountry(): void
    {
        $completed = $this->track(new WorldPackGenerationRun('IT', [1]));
        $completed->setStatus(WorldPackGenerationRunStatus::COMPLETED);
        $this->em->persist($completed);
        $this->em->flush();

        $newRun = $this->track(new WorldPackGenerationRun('IT', [2]));
        $this->em->persist($newRun);
        $this->em->flush();

        $this->assertNotSame($completed->getId()->toRfc4122(), $newRun->getId()->toRfc4122());
    }
}
