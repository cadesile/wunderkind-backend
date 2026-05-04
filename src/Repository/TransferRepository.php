<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\Transfer;
use App\Enum\TransferType;
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

}
