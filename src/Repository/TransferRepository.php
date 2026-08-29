<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\Transfer;
use App\Enum\StatsPeriod;
use App\Enum\TransferType;
use App\Service\PeriodResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transfer>
 */
class TransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    /** @return Transfer[] */
    public function findByClub(Club $club, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.club = :club')
            ->setParameter('club', $club)
            ->orderBy('t.occurredAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function getTotalAgentAssistedCount(Club $club): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.club = :club')
            ->andWhere('t.type = :type')
            ->setParameter('club', $club)
            ->setParameter('type', TransferType::AGENT_ASSISTED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalTransferRevenue(Club $club): int
    {
        return (int) ($this->createQueryBuilder('t')
            ->select('SUM(t.netProceeds)')
            ->where('t.club = :club')
            ->setParameter('club', $club)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostTransfersByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('c.id as clubId, c.name as clubName, COUNT(t.id) as value')
            ->innerJoin('t.club', 'c')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 't', 'occurredAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostDevelopmentByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('c.id as clubId, c.name as clubName, SUM(t.developmentPoints) as value')
            ->innerJoin('t.club', 'c')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 't', 'occurredAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Each club's single biggest transfer fee — the transfer_record (outgoing) and
     * transfer_spend (incoming) score. Collapses to one row per club the same way
     * PlayerCareerStatRepository::findTopPerformerByClub() does.
     *
     * Ranks on gross `fee` on both sides: a SIGNING row never populates netProceeds,
     * so gross is the only value the two directions share.
     *
     * @param  bool $incoming true for fees paid (SIGNING rows), false for fees received
     * @return array<array{clubId: string, score: int, displayLabel: string}>
     */
    public function findHighestFeeByClub(bool $incoming): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.club) AS clubId', 't.fee AS score', 't.playerName AS displayLabel')
            ->where('t.club IS NOT NULL')   // club FK is ON DELETE SET NULL
            ->andWhere($incoming ? 't.type = :signing' : 't.type != :signing')
            ->setParameter('signing', TransferType::SIGNING->value)
            ->orderBy('t.fee', 'DESC');

        $topPerClub = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $clubId = $row['clubId'];
            if (!isset($topPerClub[$clubId])) {
                $topPerClub[$clubId] = [
                    'clubId'       => $clubId,
                    'score'        => (int) $row['score'],
                    'displayLabel' => $row['displayLabel'] ?? 'Unknown Player',
                ];
            }
        }

        return array_values($topPerClub);
    }
}
