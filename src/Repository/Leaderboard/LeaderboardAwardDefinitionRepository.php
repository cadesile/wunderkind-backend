<?php

declare(strict_types=1);

namespace App\Repository\Leaderboard;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaderboardAwardDefinition>
 */
class LeaderboardAwardDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardAwardDefinition::class);
    }

    /**
     * Every enabled definition, placements eagerly joined.
     *
     * Read by the close tick and by ActivePeriodService — both of which immediately need
     * the placements, and the table holds a handful of rows.
     *
     * @return LeaderboardAwardDefinition[]
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.placements', 'p')
            ->addSelect('p')
            ->where('d.isEnabled = true')
            ->orderBy('d.category', 'ASC')
            ->addOrderBy('d.cadence', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCategoryAndCadence(LeaderboardCategory $category, AwardCadence $cadence): ?LeaderboardAwardDefinition
    {
        return $this->findOneBy(['category' => $category, 'cadence' => $cadence]);
    }

    /**
     * Guards the uq_leaderboard_award_definition_category_cadence unique from the admin
     * form, so a duplicate is a flash message rather than a UniqueConstraintViolation out
     * of flush() — which would close the EntityManager.
     */
    public function existsForCategoryAndCadence(LeaderboardCategory $category, AwardCadence $cadence, ?string $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.category = :category')
            ->andWhere('d.cadence = :cadence')
            ->setParameter('category', $category)
            ->setParameter('cadence', $cadence);

        if ($exceptId !== null) {
            $qb->andWhere('d.id != :exceptId')->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
