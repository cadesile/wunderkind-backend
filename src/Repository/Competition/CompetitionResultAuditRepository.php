<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionResultAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionResultAudit>
 */
class CompetitionResultAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionResultAudit::class);
    }

    public function findForResult(CompetitionResult $result): ?CompetitionResultAudit
    {
        return $this->findOneBy(['result' => $result]);
    }

    /**
     * Audits for a set of results, keyed by result id string.
     *
     * The shape the admin competition detail page wants: it renders every result in a
     * bracket on one screen, so fetching these per result would replace the hydration cost
     * the split removed with an N+1 of the same size.
     *
     * @param list<CompetitionResult> $results
     * @return array<string, CompetitionResultAudit>
     */
    public function findByResults(array $results): array
    {
        if ($results === []) {
            return [];
        }

        $audits = $this->createQueryBuilder('a')
            ->where('a.result IN (:results)')
            ->setParameter('results', $results)
            ->getQuery()
            ->getResult();

        $byResultId = [];
        foreach ($audits as $audit) {
            $byResultId[(string) $audit->getResult()->getId()] = $audit;
        }

        return $byResultId;
    }
}
