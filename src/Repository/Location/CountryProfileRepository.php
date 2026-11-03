<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CountryProfile>
 */
class CountryProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CountryProfile::class);
    }

    public function findByCode(string $code): ?CountryProfile
    {
        return $this->find(strtoupper(trim($code)));
    }

    /** @return list<CountryProfile> */
    public function findLive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :live')
            ->setParameter('live', CountryProfileStatus::LIVE)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<CountryProfile> */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * code => name, for admin dropdowns and the /api/countries join.
     *
     * @return array<string, string>
     */
    public function nameMap(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.code AS code', 'c.name AS name')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['code']] = (string) $row['name'];
        }

        return $out;
    }
}
