<?php

namespace App\Repository;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameEventTemplate>
 */
class GameEventTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameEventTemplate::class);
    }

    /**
     * Returns all templates with weight > 0, ordered for deterministic client consumption.
     *
     * @return GameEventTemplate[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.weight > 0')
            ->orderBy('g.category', 'ASC')
            ->addOrderBy('g.weight', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?GameEventTemplate
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return GameEventTemplate[]
     */
    public function findByCategory(EventCategory $category): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.category = :category')
            ->andWhere('g.weight > 0')
            ->setParameter('category', $category->value)
            ->orderBy('g.weight', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
