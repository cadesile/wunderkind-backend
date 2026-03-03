<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\InboxMessage;
use App\Enum\MessageStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboxMessage>
 */
class InboxMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxMessage::class);
    }

    /** @return InboxMessage[] */
    public function findByAcademy(Academy $academy, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.academy = :academy')
            ->setParameter('academy', $academy)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnread(Academy $academy): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.academy = :academy')
            ->andWhere('m.status = :status')
            ->setParameter('academy', $academy)
            ->setParameter('status', MessageStatus::UNREAD)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByAcademyAndId(Academy $academy, string $id): ?InboxMessage
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.academy = :academy')
            ->andWhere('m.id = :id')
            ->setParameter('academy', $academy)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
