<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDevice::class);
    }

    public function findByDeviceToken(string $deviceToken): ?UserDevice
    {
        return $this->findOneBy(['deviceToken' => $deviceToken]);
    }

    /**
     * @param list<string> $userIds
     * @return list<UserDevice>
     */
    public function findByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->where('IDENTITY(d.user) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every User with at least one registered device — the candidate pool for eager
     * push-audience resolution (ResolveAdminMessageAudienceForPushMessageHandler), since a
     * user with no device can never receive a push regardless of targeting.
     *
     * @return list<User>
     */
    public function findDistinctUsersWithDevice(): array
    {
        // A plain `SELECT DISTINCT u` (hydrating the whole User row) fails on PostgreSQL —
        // User carries a json column, and Postgres has no equality operator for `json` to
        // dedup on. An IN-subquery against just the ids sidesteps that entirely: IN already
        // dedups, so no DISTINCT on a hydrated row is needed.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (SELECT IDENTITY(d.user) FROM ' . UserDevice::class . ' d)')
            ->getQuery()
            ->getResult();
    }
}
