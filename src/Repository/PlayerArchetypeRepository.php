<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlayerArchetype;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerArchetype>
 */
class PlayerArchetypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerArchetype::class);
    }

    /**
     * Returns all archetypes grouped by polarity, alongside an MD5 version hash.
     *
     * The hash covers every field the client consumes — slug, polarity, description and
     * traitWeights — so any catalogue edit invalidates the client cache. (Description was
     * previously omitted, which meant copy fixes shipped but never reached cached clients.)
     *
     * @return array{archetypes: PlayerArchetype[], versionHash: string}
     */
    public function findAllWithVersionHash(): array
    {
        $archetypes = $this->createQueryBuilder('a')
            ->orderBy('a.polarity', 'ASC')
            ->addOrderBy('a.slug', 'ASC')
            ->getQuery()
            ->getResult();

        $hashInput = implode('|', array_map(
            fn (PlayerArchetype $a) => implode(':', [
                $a->getSlug(),
                $a->getPolarity()->value,
                $a->getDescription(),
                json_encode($a->getTraitWeights()),
            ]),
            $archetypes,
        ));

        return [
            'archetypes'  => $archetypes,
            'versionHash' => md5($hashInput),
        ];
    }

    public function findBySlug(string $slug): ?PlayerArchetype
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findByName(string $name): ?PlayerArchetype
    {
        return $this->findOneBy(['name' => $name]);
    }
}
