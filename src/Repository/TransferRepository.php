<?php

namespace App\Repository;

use App\Entity\Academy;
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
    public function findByAcademy(Academy $academy, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.academy = :academy')
            ->setParameter('academy', $academy)
            ->orderBy('t.occurredAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function getTotalAgentAssistedCount(Academy $academy): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.academy = :academy')
            ->andWhere('t.type = :type')
            ->setParameter('academy', $academy)
            ->setParameter('type', TransferType::AGENT_ASSISTED->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getTotalTransferRevenue(Academy $academy): int
    {
        return (int) ($this->createQueryBuilder('t')
            ->select('SUM(t.netProceeds)')
            ->where('t.academy = :academy')
            ->setParameter('academy', $academy)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }
}
