<?php

namespace App\Repository;

use App\Entity\FacilityTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FacilityTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FacilityTemplate::class);
    }

    /** @return FacilityTemplate[] */
    public function getActiveTemplates(): array
    {
        return $this->createQueryBuilder('ft')
            ->where('ft.isActive = true')
            ->orderBy('ft.sortOrder', 'ASC')
            ->addOrderBy('ft.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return FacilityTemplate[] sorted by category then sortOrder then slug */
    public function findAllOrderedByCategoryAndSort(): array
    {
        return $this->createQueryBuilder('ft')
            ->orderBy('ft.category', 'ASC')
            ->addOrderBy('ft.sortOrder', 'ASC')
            ->addOrderBy('ft.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
