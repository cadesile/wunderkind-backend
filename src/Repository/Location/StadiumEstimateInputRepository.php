<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\StadiumEstimate;
use App\Entity\Location\StadiumEstimateInput;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StadiumEstimateInput>
 *
 * The only way to reach a split row — StadiumEstimate declares no inverse association, because
 * an inverse OneToOne is always fetched eagerly and would reinstate the cost the split removed.
 */
class StadiumEstimateInputRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StadiumEstimateInput::class);
    }

    public function findForEstimate(StadiumEstimate $estimate): ?StadiumEstimateInput
    {
        return $this->findOneBy(['estimate' => $estimate]);
    }

    /**
     * @param list<StadiumEstimate> $estimates
     *
     * @return array<string, StadiumEstimateInput> keyed by estimate id
     */
    public function findByEstimates(array $estimates): array
    {
        if ($estimates === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->where('i.estimate IN (:estimates)')
            ->setParameter('estimates', $estimates)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->getEstimate()->getId()] = $row;
        }

        return $out;
    }
}
