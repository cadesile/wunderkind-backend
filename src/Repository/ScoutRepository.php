<?php

namespace App\Repository;

use App\Entity\Scout;
use App\Service\Admin\StatBuckets;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ScoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scout::class);
    }

    /**
     * @return Scout[]
     * @param int|null $experienceMin If provided, only scouts with experience >= this value
     * @param int|null $experienceMax If provided, only scouts with experience <= this value
     */
    public function findInPool(int $limit = 10, ?int $experienceMin = null, ?int $experienceMax = null, ?string $nationality = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit);

        if ($experienceMin !== null) {
            $qb->andWhere('s.experience >= :expMin')->setParameter('expMin', $experienceMin);
        }

        if ($experienceMax !== null) {
            $qb->andWhere('s.experience <= :expMax')->setParameter('expMax', $experienceMax);
        }

        if ($nationality !== null) {
            $qb->andWhere('s.nationality = :nationality')->setParameter('nationality', $nationality);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByNationality(string $nationality): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.nationality = :nat')
            ->setParameter('nat', $nationality)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pool breakdown for the admin dashboard, nested on nationality.
     * Scout carries only `experience` — there is no rating or reputation field.
     *
     * @return array{total: int, facets: array<string, list<array{key: string, count: int}>>, nested: array{dimension: string, children: list<string>, rows: list<array<string, mixed>>}}
     */
    public function getPoolBreakdown(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.nationality AS nationality', 's.dob AS dob', 's.experience AS experience')
            ->getQuery()
            ->getArrayResult();

        $now = new \DateTimeImmutable();

        $nationality = [];
        $age         = StatBuckets::seed(StatBuckets::AGE_LABELS) + [StatBuckets::UNKNOWN => 0];
        $experience  = StatBuckets::seed(StatBuckets::EXPERIENCE_LABELS);
        $nested      = [];

        foreach ($rows as $row) {
            $nat = (string) ($row['nationality'] ?? StatBuckets::UNKNOWN);

            $ageBucket = StatBuckets::age($row['dob'] instanceof \DateTimeInterface ? $row['dob'] : null, $now);
            $expBucket = StatBuckets::experience((int) $row['experience']);

            $nationality[$nat] = ($nationality[$nat] ?? 0) + 1;
            $age[$ageBucket]   = ($age[$ageBucket] ?? 0) + 1;
            $experience[$expBucket]++;

            $nested[$nat]['experience'][$expBucket] = ($nested[$nat]['experience'][$expBucket] ?? 0) + 1;
            $nested[$nat]['age'][$ageBucket]        = ($nested[$nat]['age'][$ageBucket] ?? 0) + 1;
        }

        arsort($nationality);

        return [
            'total'  => count($rows),
            'facets' => [
                'experience'  => StatBuckets::facet($experience),
                'nationality' => StatBuckets::facet($nationality),
                'age'         => StatBuckets::facet($age),
            ],
            'nested' => StatBuckets::nested('nationality', $nationality, $nested, ['experience', 'age']),
        ];
    }
}
