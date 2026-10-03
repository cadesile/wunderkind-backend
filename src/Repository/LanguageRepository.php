<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Language>
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /** @return Language[] */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.isEnabled = true')
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefault(): ?Language
    {
        return $this->findOneBy(['isDefault' => true]);
    }

    public function findByCode(string $code): ?Language
    {
        return $this->findOneBy(['code' => $code]);
    }
}
