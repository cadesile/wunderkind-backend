<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalAssistant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PersonalAssistant> */
class PersonalAssistantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalAssistant::class);
    }

    /** @return PersonalAssistant[] active only, in picker order */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.isActive = true')
            ->orderBy('pa.sortOrder', 'ASC')
            ->addOrderBy('pa.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveBySlug(string $slug): ?PersonalAssistant
    {
        return $this->findOneBy(['slug' => $slug, 'isActive' => true]);
    }
}
