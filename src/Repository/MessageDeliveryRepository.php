<?php

namespace App\Repository;

use App\Entity\AdminMessage;
use App\Entity\User;
use App\Entity\MessageDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageDelivery>
 */
class MessageDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageDelivery::class);
    }

    public function findOneByUserAndMessage(User $user, AdminMessage $message): ?MessageDelivery
    {
        return $this->findOneBy(['user' => $user, 'message' => $message]);
    }

    /**
     * Delivery counts keyed by MessageDeliveryStatus value, for the admin detail page.
     *
     * @return array<string, int>
     */
    public function countByMessageGroupedByStatus(AdminMessage $message): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.status AS status, COUNT(d.id) AS total')
            ->where('d.message = :message')
            ->setParameter('message', $message)
            ->groupBy('d.status')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof \BackedEnum ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }
}
