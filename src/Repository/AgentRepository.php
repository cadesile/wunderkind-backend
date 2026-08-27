<?php

namespace App\Repository;

use App\Entity\Agent;
use App\Entity\Player;
use App\Service\Admin\StatBuckets;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /**
     * @return Agent[]
     * @param int|null $ratingMin If provided, only agents with rating >= this value
     * @param int|null $ratingMax If provided, only agents with rating <= this value
     */
    public function findInPool(int $limit = 20, ?int $ratingMin = null, ?int $ratingMax = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if ($ratingMin !== null) {
            $qb->andWhere('a.rating >= :ratingMin')->setParameter('ratingMin', $ratingMin);
        }

        if ($ratingMax !== null) {
            $qb->andWhere('a.rating <= :ratingMax')->setParameter('ratingMax', $ratingMax);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Pool breakdown for the admin dashboard, nested on nationality.
     *
     * Agents are a persistent shared pool (never consumed), so this also
     * surfaces the representation load: how many players each agent carries,
     * and how many players currently have no agent at all.
     *
     * @return array{total: int, unagentedPlayers: int, facets: array<string, list<array{key: string, count: int}>>, nested: array{dimension: string, children: list<string>, rows: list<array<string, mixed>>}}
     */
    public function getPoolBreakdown(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.nationality AS nationality', 'a.dob AS dob', 'a.rating AS rating', 'a.reputation AS reputation', 'a.experience AS experience', 'a.commissionRate AS commission')
            ->getQuery()
            ->getArrayResult();

        $now = new \DateTimeImmutable();

        $nationality = [];
        $age         = StatBuckets::seed(StatBuckets::AGE_LABELS) + [StatBuckets::UNKNOWN => 0];
        $rating      = StatBuckets::seed(StatBuckets::RATING_LABELS);
        $reputation  = StatBuckets::seed(StatBuckets::RATING_LABELS);
        $commission  = StatBuckets::seed(StatBuckets::COMMISSION_LABELS);
        $experience  = StatBuckets::seed(StatBuckets::EXPERIENCE_LABELS);
        $nested      = [];

        foreach ($rows as $row) {
            $nat = (string) ($row['nationality'] ?? StatBuckets::UNKNOWN);

            $ageBucket    = StatBuckets::age($row['dob'] instanceof \DateTimeInterface ? $row['dob'] : null, $now);
            $ratingBucket = StatBuckets::rating((int) $row['rating']);
            $commBucket   = StatBuckets::commission($row['commission']);

            $nationality[$nat] = ($nationality[$nat] ?? 0) + 1;
            $age[$ageBucket]   = ($age[$ageBucket] ?? 0) + 1;
            $rating[$ratingBucket]++;
            $reputation[StatBuckets::rating((int) $row['reputation'])]++;
            $commission[$commBucket]++;
            $experience[StatBuckets::experience((int) $row['experience'])]++;

            $nested[$nat]['rating'][$ratingBucket]   = ($nested[$nat]['rating'][$ratingBucket] ?? 0) + 1;
            $nested[$nat]['commission'][$commBucket] = ($nested[$nat]['commission'][$commBucket] ?? 0) + 1;
            $nested[$nat]['age'][$ageBucket]         = ($nested[$nat]['age'][$ageBucket] ?? 0) + 1;
        }

        arsort($nationality);

        return [
            'total'            => count($rows),
            'unagentedPlayers' => $this->countUnagentedPlayers(),
            'facets'           => [
                'rating'      => StatBuckets::facet($rating),
                'reputation'  => StatBuckets::facet($reputation),
                'commission'  => StatBuckets::facet($commission),
                'experience'  => StatBuckets::facet($experience),
                'nationality' => StatBuckets::facet($nationality),
                'age'         => StatBuckets::facet($age),
                'clientLoad'  => $this->clientLoadFacet(),
            ],
            'nested' => StatBuckets::nested('nationality', $nationality, $nested, ['rating', 'commission', 'age']),
        ];
    }

    /** Players in the pool with no agent FK set. */
    public function countUnagentedPlayers(): int
    {
        return (int) $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Player::class, 'p')
            ->where('p.agent IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Distribution of how many pool players each agent represents, bucketed.
     *
     * @return list<array{key: string, count: int}>
     */
    private function clientLoadFacet(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.id AS id', 'COUNT(p.id) AS clients')
            ->leftJoin('a.players', 'p')
            ->groupBy('a.id')
            ->getQuery()
            ->getArrayResult();

        $buckets = ['0' => 0, '1-5' => 0, '6-10' => 0, '11-20' => 0, '20+' => 0];
        foreach ($rows as $row) {
            $n = (int) $row['clients'];
            $buckets[match (true) {
                $n === 0     => '0',
                $n <= 5      => '1-5',
                $n <= 10     => '6-10',
                $n <= 20     => '11-20',
                default      => '20+',
            }]++;
        }

        return StatBuckets::facet($buckets);
    }
}
