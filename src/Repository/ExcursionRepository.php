<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Excursion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Excursion>
 */
class ExcursionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Excursion::class);
    }

    /**
     * Active excursions, cheapest first, with an MD5 version hash so the client
     * can skip a full refetch when nothing has changed.
     *
     * The hash covers every field the client actually consumes — a price or
     * risk tweak in admin must invalidate the cache, not just adding a row.
     *
     * @return array{excursions: Excursion[], versionHash: string}
     */
    public function findActiveWithVersionHash(): array
    {
        /** @var Excursion[] $excursions */
        $excursions = $this->createQueryBuilder('e')
            ->andWhere('e.active = :active')
            ->setParameter('active', true)
            ->orderBy('e.costPerPersonPence', 'ASC')
            ->getQuery()
            ->getResult();

        $hashInput = implode('|', array_map(
            fn (Excursion $e) => implode(':', [
                $e->getSlug(),
                $e->getTitle(),
                $e->getBody(),
                (string) $e->getImagePath(),
                (string) $e->getCostPerPersonPence(),
                (string) $e->getEffectValue(),
                (string) $e->getNegativeFrequency(),
                $e->getTargetAudience(),
                $e->isPostSeasonOnly() ? '1' : '0',
                (string) $e->getCooldownWeeks(),
            ]),
            $excursions,
        ));

        return [
            'excursions'  => $excursions,
            'versionHash' => md5($hashInput),
        ];
    }

    public function findBySlug(string $slug): ?Excursion
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
