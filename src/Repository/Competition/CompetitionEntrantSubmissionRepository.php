<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionEntrantSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionEntrantSubmission>
 */
class CompetitionEntrantSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionEntrantSubmission::class);
    }

    public function findForEntrant(CompetitionEntrant $entrant): ?CompetitionEntrantSubmission
    {
        return $this->findOneBy(['entrant' => $entrant]);
    }

    /**
     * Batch-fetch submissions for a set of entrants, keyed by entrant id string.
     *
     * Present for the same reason as CompetitionEntrantSquadRepository::findByEntrants(): a
     * split must not trade a hydration cost for an N+1 of the same size. These are the
     * largest rows in the domain, so looping `findForEntrant()` over a bracket is the single
     * worst thing that could be written against this table — which is why the batch form
     * exists before anything needs it.
     *
     * @param list<CompetitionEntrant> $entrants
     * @return array<string, CompetitionEntrantSubmission>
     */
    public function findByEntrants(array $entrants): array
    {
        if ($entrants === []) {
            return [];
        }

        $submissions = $this->createQueryBuilder('s')
            ->where('s.entrant IN (:entrants)')
            ->setParameter('entrants', $entrants)
            ->getQuery()
            ->getResult();

        $byEntrantId = [];
        foreach ($submissions as $submission) {
            $byEntrantId[(string) $submission->getEntrant()->getId()] = $submission;
        }

        return $byEntrantId;
    }
}
