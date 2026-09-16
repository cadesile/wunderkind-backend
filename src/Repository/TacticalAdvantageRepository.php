<?php

namespace App\Repository;

use App\Entity\TacticalAdvantage;
use App\Enum\PlayingStyle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TacticalAdvantageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TacticalAdvantage::class);
    }

    /**
     * @return TacticalAdvantage[]
     */
    public function findAllAsArray(): array
    {
        return $this->findAll();
    }

    /**
     * The multiplier for a club playing $style against an opponent playing $opponentStyle.
     * 1.0 (neutral) if no row is configured for that pairing — same fail-closed-to-neutral
     * reasoning as an unconfigured criteria key elsewhere in this codebase, just inverted
     * (absence means "no effect" rather than "no match").
     */
    public function findMultiplier(PlayingStyle $style, PlayingStyle $opponentStyle): float
    {
        $row = $this->createQueryBuilder('ta')
            ->where('ta.style = :style')
            ->andWhere('ta.opponentStyle = :opponentStyle')
            ->setParameter('style', $style)
            ->setParameter('opponentStyle', $opponentStyle)
            ->getQuery()
            ->getOneOrNullResult();

        return $row?->getMultiplier() ?? 1.0;
    }
}
