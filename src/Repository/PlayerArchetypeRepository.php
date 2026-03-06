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
     * Returns all archetypes ordered by name, alongside an MD5 version hash.
     * The hash is derived from each archetype's name + traitMapping, so the client
     * can detect when definitions have changed and invalidate its local cache.
     *
     * @return array{archetypes: PlayerArchetype[], versionHash: string}
     */
    public function findAllWithVersionHash(): array
    {
        $archetypes = $this->createQueryBuilder('a')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();

        $hashInput = implode('|', array_map(
            fn (PlayerArchetype $a) => $a->getName() . ':' . json_encode($a->getTraitMapping()),
            $archetypes,
        ));

        return [
            'archetypes'  => $archetypes,
            'versionHash' => md5($hashInput),
        ];
    }

    public function findByName(string $name): ?PlayerArchetype
    {
        return $this->findOneBy(['name' => $name]);
    }
}
