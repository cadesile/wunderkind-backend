<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\ClubFacility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClubFacilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubFacility::class);
    }

    public function findOrCreate(Club $club, string $facilitySlug): ClubFacility
    {
        $facility = $this->findOneBy([
            'club'         => $club,
            'facilitySlug' => $facilitySlug,
        ]);

        if ($facility === null) {
            $facility = new ClubFacility($club, $facilitySlug);
            $this->getEntityManager()->persist($facility);
        }

        return $facility;
    }

    /**
     * Sum of facility levels per club — the empire_index score.
     *
     * @return array<array{clubId: string, score: int}>
     */
    public function sumLevelsByClub(): array
    {
        $rows = $this->createQueryBuilder('cf')
            ->select('IDENTITY(cf.club) AS clubId', 'SUM(cf.level) AS score')
            ->groupBy('cf.club')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['clubId' => $row['clubId'], 'score' => (int) $row['score']],
            $rows,
        );
    }
}
