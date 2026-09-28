<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionResult>
 */
class CompetitionResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionResult::class);
    }

    /**
     * Batch-fetch results for a set of fixtures, keyed by fixture id string — avoids an
     * N+1 when serializing a full bracket (rounds × fixtures) or an admin overview.
     *
     * @param list<\Symfony\Component\Uid\UuidV7> $fixtureIds
     * @return array<string, CompetitionResult>
     */
    public function findByFixtureIds(array $fixtureIds): array
    {
        if ($fixtureIds === []) {
            return [];
        }

        $results = $this->createQueryBuilder('r')
            ->where('r.fixture IN (:fixtureIds)')
            ->setParameter('fixtureIds', $fixtureIds)
            ->getQuery()
            ->getResult();

        $byFixtureId = [];
        foreach ($results as $result) {
            $byFixtureId[(string) $result->getFixture()->getId()] = $result;
        }

        return $byFixtureId;
    }
}
