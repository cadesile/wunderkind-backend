<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CountryProfile>
 */
class CountryProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CountryProfile::class);
    }

    public function findByCode(string $code): ?CountryProfile
    {
        return $this->find(strtoupper(trim($code)));
    }

    /** @return list<CountryProfile> */
    public function findLive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :live')
            ->setParameter('live', CountryProfileStatus::LIVE)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The codes of every LIVE country, which is now the definition of "open to players".
     *
     * REPLACED StarterConfig::$enabledCountries, a hand-maintained checkbox list that could and
     * did disagree with the thing it was describing: a country could be ticked while its profile
     * was still DRAFT, offering players a country whose locations were not enriched, or sit
     * un-ticked long after it went LIVE. One flag, set where the readiness is actually known,
     * cannot drift from itself.
     *
     * Projected rather than hydrated — every caller wants codes, and findLive() already exists
     * for the ones that want the rows.
     *
     * @return list<string>
     */
    public function liveCountryCodes(): array
    {
        return array_map(
            static fn (mixed $code): string => (string) $code,
            $this->createQueryBuilder('c')
                ->select('c.code')
                ->where('c.status = :live')
                ->setParameter('live', CountryProfileStatus::LIVE)
                ->orderBy('c.code', 'ASC')
                ->getQuery()
                ->getSingleColumnResult(),
        );
    }

    /** @return list<CountryProfile> */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * code => name, for admin dropdowns and the /api/countries join.
     *
     * @return array<string, string>
     */
    /**
     * Every country's name-pool override, in ONE projected query.
     *
     * PROJECTED, NOT HYDRATED, and that is the point. NamePoolProvider fills its whole memo
     * from this once per process; hydrating 71 CountryProfile entities to read two columns
     * would put them in the identity map, where MarketPoolService's clear() every fifty
     * players throws them away again. Arrays survive that.
     *
     * Null columns are returned as null, so the caller can tell "no override" from "an
     * override that happens to be empty" — though the entity setters make the latter
     * impossible to store.
     *
     * @return array<string, array{firstNames: ?list<string>, lastNames: ?list<string>}> keyed by code
     */
    public function namePoolOverrides(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.code AS code', 'c.firstNames AS firstNames', 'c.lastNames AS lastNames')
            ->getQuery()
            ->getArrayResult();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['code']] = [
                'firstNames' => $row['firstNames'] ?? null,
                'lastNames'  => $row['lastNames'] ?? null,
            ];
        }

        return $out;
    }

    public function nameMap(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.code AS code', 'c.name AS name')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['code']] = (string) $row['name'];
        }

        return $out;
    }
}
