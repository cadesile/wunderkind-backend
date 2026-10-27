<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionResponseCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionResponseCache>
 */
class CompetitionResponseCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionResponseCache::class);
    }

    public function findForCompetition(ActiveCompetition $activeCompetition): ?CompetitionResponseCache
    {
        return $this->findOneBy(['activeCompetition' => $activeCompetition]);
    }

    public function deleteForCompetition(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM competition_response_cache WHERE active_competition_id = :competitionId',
            ['competitionId' => $activeCompetition->getId()->toRfc4122()],
        );
    }

    /**
     * Competition ids whose cached document is built from a superseded payload shape.
     *
     * What app:competition:cache:warm rebuilds after a deploy that changes the serializer:
     * those rows are not wrong so much as unreadable by the current code, and they would
     * otherwise be rebuilt one at a time by whichever unlucky request arrived first.
     *
     * @return list<string>
     */
    public function findStalePayloadVersionIds(int $currentPayloadVersion): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT active_competition_id FROM competition_response_cache WHERE payload_version <> :version',
            ['version' => $currentPayloadVersion],
        );

        return array_map('strval', $rows);
    }
}
