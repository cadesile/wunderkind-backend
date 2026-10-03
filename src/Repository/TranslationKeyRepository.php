<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TranslationKey;
use App\Enum\TranslatableEntityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TranslationKey>
 */
class TranslationKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TranslationKey::class);
    }

    public function findOneByKey(string $key): ?TranslationKey
    {
        return $this->findOneBy(['key' => $key]);
    }

    public function findOneForNarrativeField(TranslatableEntityType $type, string $slug, string $field): ?TranslationKey
    {
        return $this->findOneBy([
            'entityType' => $type->value,
            'entitySlug' => $slug,
            'fieldName'  => $field,
        ]);
    }

    /** @return TranslationKey[] */
    public function findGeneric(): array
    {
        return $this->createQueryBuilder('tk')
            ->andWhere('tk.entityType IS NULL')
            ->orderBy('tk.key', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TranslationKey[] every narrative-linked key for one entity type, deterministically ordered */
    public function findAllForEntityType(TranslatableEntityType $type): array
    {
        return $this->createQueryBuilder('tk')
            ->andWhere('tk.entityType = :type')
            ->setParameter('type', $type->value)
            ->orderBy('tk.entitySlug', 'ASC')
            ->addOrderBy('tk.fieldName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TranslationKey[] every translatable key for one narrative entity row, keyed by fieldName */
    public function findForEntity(TranslatableEntityType $type, string $slug): array
    {
        return $this->createQueryBuilder('tk')
            ->andWhere('tk.entityType = :type')
            ->andWhere('tk.entitySlug = :slug')
            ->setParameter('type', $type->value)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getResult();
    }

    /**
     * Generic keys with zero Translation rows left in ANY language — used after a "clear
     * existing" bulk import to prune keys the uploaded file (and every other language)
     * no longer mentions at all. A key still holding a value in some other language is
     * never touched here, only ones that are now completely dead.
     *
     * @return TranslationKey[]
     */
    public function findGenericOrphaned(): array
    {
        return $this->createQueryBuilder('tk')
            ->andWhere('tk.entityType IS NULL')
            ->andWhere('tk.translations IS EMPTY')
            ->getQuery()
            ->getResult();
    }
}
