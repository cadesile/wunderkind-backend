<?php

namespace App\Repository;

use App\Entity\Club;
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
    public function findByClub(Club $club, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.club = :club')
            ->setParameter('club', $club)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countUnread(Club $club): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.club = :club')
            ->andWhere('m.status = :status')
            ->setParameter('club', $club)
            ->setParameter('status', MessageStatus::UNREAD)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByClubAndId(Club $club, string $id): ?InboxMessage
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.club = :club')
            ->andWhere('m.id = :id')
            ->setParameter('club', $club)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
