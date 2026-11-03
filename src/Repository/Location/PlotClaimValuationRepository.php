<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\PlotClaim;
use App\Entity\Location\PlotClaimValuation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlotClaimValuation>
 *
 * The only way to reach a sell-back working. PlotClaim declares no inverse association, because
 * an inverse OneToOne is always fetched eagerly and would reinstate the cost the split removed.
 */
class PlotClaimValuationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlotClaimValuation::class);
    }

    public function findForClaim(PlotClaim $claim): ?PlotClaimValuation
    {
        return $this->findOneBy(['claim' => $claim]);
    }

    /**
     * @param list<PlotClaim> $claims
     *
     * @return array<string, PlotClaimValuation> keyed by claim id
     */
    public function findByClaims(array $claims): array
    {
        if ($claims === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->where('v.claim IN (:claims)')
            ->setParameter('claims', $claims)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->getClaim()->getId()] = $row;
        }

        return $out;
    }
}
