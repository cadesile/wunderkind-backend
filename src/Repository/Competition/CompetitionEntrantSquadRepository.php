<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionEntrantSquad;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionEntrantSquad>
 */
class CompetitionEntrantSquadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionEntrantSquad::class);
    }

    public function findForEntrant(CompetitionEntrant $entrant): ?CompetitionEntrantSquad
    {
        return $this->findOneBy(['entrant' => $entrant]);
    }

    /**
     * Batch-fetch squads for a set of entrants, keyed by entrant id string.
     *
     * Use this wherever more than one squad is needed in a single pass — a round
     * resolution walks every fixture in the round, and fetching per entrant there is the
     * N+1 this table would otherwise make easy to write by accident.
     *
     * @param list<CompetitionEntrant> $entrants
     * @return array<string, CompetitionEntrantSquad>
     */
    public function findByEntrants(array $entrants): array
    {
        if ($entrants === []) {
            return [];
        }

        $squads = $this->createQueryBuilder('s')
            ->where('s.entrant IN (:entrants)')
            ->setParameter('entrants', $entrants)
            ->getQuery()
            ->getResult();

        $byEntrantId = [];
        foreach ($squads as $squad) {
            $byEntrantId[(string) $squad->getEntrant()->getId()] = $squad;
        }

        return $byEntrantId;
    }

    /**
     * Drops the engine inputs for every entrant in a completed competition.
     *
     * Safe only once no further round can resolve: these rows are the match engine's
     * only input, so deleting them for a live competition would make its remaining
     * fixtures unresolvable. Keyed through the entrant table rather than cascading from
     * the competition, because the FK here is to the entrant.
     */
    public function deleteForCompetition(string $activeCompetitionId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM competition_entrant_squad
             WHERE entrant_id IN (
                   SELECT id FROM competition_entrant WHERE active_competition_id = :competitionId
             )
            SQL,
            ['competitionId' => $activeCompetitionId],
        );
    }
}
