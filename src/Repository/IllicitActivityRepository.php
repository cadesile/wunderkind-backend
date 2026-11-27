<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\IllicitActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IllicitActivity>
 */
class IllicitActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IllicitActivity::class);
    }

    /** @return array<string, IllicitActivity> every row the club has, keyed by the device's id */
    public function findByClubKeyedByClientId(Club $club): array
    {
        $out = [];
        foreach ($this->findBy(['club' => $club]) as $row) {
            $out[$row->getClientId()] = $row;
        }

        return $out;
    }

    public function countForClub(Club $club): int
    {
        return $this->count(['club' => $club]);
    }
}
