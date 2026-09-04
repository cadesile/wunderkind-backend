<?php

namespace App\Tests\Repository;

use App\Entity\PoolConfig;
use App\Repository\PoolConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PoolConfig is a singleton in every sense except the schema — there is no country/tier
 * discriminator, and getConfig() is written as though exactly one row exists.
 *
 * When more than one row is present, an unordered findOneBy([]) returns whichever row
 * PostgreSQL yields first from the heap. That is not stable: an UPDATE writes a new tuple
 * version elsewhere in the heap, so the row returned before a save differs from the row
 * returned after it. In the admin this presented as the Player Pool Config form "resetting
 * to defaults" on save — the write succeeded, but the reload rendered a different row.
 *
 * Production never showed it because production has exactly one row.
 */
class PoolConfigRepositorySingletonTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PoolConfigRepository $repo;
    /** @var int[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(PoolConfigRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $id) {
            $managed = $this->em->find(PoolConfig::class, $id);
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function seedRows(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $c = new PoolConfig();
            $c->setPlayerPoolTarget(100 + $i);
            $this->em->persist($c);
            $this->em->flush();
            $this->cleanup[] = $c->getId();
        }
        $this->em->clear();
    }

    public function testGetConfigReturnsTheSameRowAcrossRepeatedCalls(): void
    {
        $this->seedRows(3);

        $first = $this->repo->getConfig()->getId();
        $this->em->clear();
        $second = $this->repo->getConfig()->getId();

        self::assertSame($first, $second, 'getConfig() must be deterministic when several rows exist');
    }

    public function testGetConfigReturnsTheSameRowAfterAnUpdateRelocatesTheTuple(): void
    {
        $this->seedRows(3);

        $before = $this->repo->getConfig();
        $beforeId = $before->getId();

        // Exactly what the admin save does: mutate the returned config and flush.
        // In PostgreSQL this rewrites the tuple at a new heap location.
        $before->setPlayerPoolTarget(4242);
        $this->em->flush();
        $this->em->clear();

        $after = $this->repo->getConfig();

        self::assertSame($beforeId, $after->getId(), 'getConfig() must return the same row after a save');
        self::assertSame(4242, $after->getPlayerPoolTarget(), 'the saved value must be the one read back');
    }
}
