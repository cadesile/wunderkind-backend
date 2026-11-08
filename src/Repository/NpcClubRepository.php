<?php

namespace App\Repository;

use App\Entity\League;
use App\Entity\NpcClub;
use App\Service\ClubNameNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NpcClub>
 */
class NpcClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NpcClub::class);
    }

    /**
     * Returns counts keyed as [country][tier] => count.
     * e.g. ['ES' => [1 => 8, 2 => 6], 'EN' => [3 => 10]]
     *
     * @return array<string, array<int, int>>
     */
    public function getCountsByCountryAndTier(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.country, c.tier, COUNT(c.id) AS cnt')
            ->groupBy('c.country, c.tier')
            ->orderBy('c.country', 'ASC')
            ->addOrderBy('c.tier', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['country']][(int) $row['tier']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function deleteByCountryAndTier(string $country, int $tier): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.country = :country')
            ->andWhere('c.tier = :tier')
            ->setParameter('country', $country)
            ->setParameter('tier', $tier)
            ->getQuery()
            ->execute();
    }

    /** @return NpcClub[] */
    public function findByLeague(League $league): array
    {
        return $this->findBy(['league' => $league]);
    }

    /**
     * The league's clubs with their town and plot already loaded.
     *
     * FOR THE WORLD PACK, which puts a `land` block on every club snapshot. findByLeague()
     * leaves `location` and `landPlot` as lazy proxies, so reading them per club costs two
     * extra queries each — forty per tier on a twenty-club division, and a pack is eight tiers.
     * Same reasoning as the note on WorldPackSnapshotBuilder::buildPlayerSnapshot() about
     * guardians: a lazy relation read inside a per-row loop is a query multiplier.
     *
     * LEFT joins, because both are legitimately null — a club generated before its country's
     * locations were seeded has no town, and only the first NPC_PLOT_CAP clubs in a town are
     * seated on a plot.
     *
     * @return list<NpcClub>
     */
    public function findByLeagueWithLand(League $league): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('loc', 'plot')
            ->leftJoin('c.location', 'loc')
            ->leftJoin('c.landPlot', 'plot')
            ->where('c.league = :league')
            ->setParameter('league', $league)
            ->getQuery()
            ->getResult();
    }

    /** @return string[] every NPC club name in the given country, across all tiers */
    public function findNamesByCountry(string $country): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.name')
            ->where('c.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * True if an NPC club in this country already uses this name, compared on
     * the normalised form (case/accent/whitespace insensitive).
     */
    public function clubNameExists(string $name, string $country): bool
    {
        return ClubNameNormalizer::isTaken($name, $this->findNamesByCountry($country));
    }

    /** @return string[] distinct, non-null region values currently in use, alphabetically sorted */
    public function findDistinctRegions(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.region')
            ->where('c.region IS NOT NULL')
            ->orderBy('c.region', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'region');
    }

    /**
     * Find NPC clubs NOT from the given country, optionally filtered by tier
     * derived from a rep string.
     *
     * @param string      $excludeCountry Country code to exclude (e.g. 'EN')
     * @param string|null $rep            'local'|'regional'|'national'|'elite'|null
     * @param int         $limitPerTier   Max clubs per tier
     * @return array<int, array{id: string, name: string, country: string, tier: int, region: ?string, citySize: string, populationSize: int, isCapital: bool}>
     */
    public function findForeignClubs(string $excludeCountry, ?string $rep = null, int $limitPerTier = 3): array
    {
        $tierMap = [
            'elite'    => [1],
            'national' => [2],
            'regional' => [3],
            'local'    => [4, 5],
        ];
        $tiers = ($rep !== null) ? ($tierMap[$rep] ?? null) : null;

        // EXPLICIT COLUMN LIST, so a new entity field is invisible here until it is named —
        // which is exactly how location_id was missed the first time.
        $columns = 'id, name, country, tier, region, city_size, population_size, is_capital, location_id';
        if ($tiers !== null) {
            $placeholders = implode(',', array_fill(0, count($tiers), '?'));
            $params       = array_merge([$excludeCountry], $tiers);
            $sql          = "SELECT {$columns} FROM npc_club WHERE country != ? AND tier IN ({$placeholders}) ORDER BY tier ASC, RANDOM()";
        } else {
            $params = [$excludeCountry];
            $sql    = "SELECT {$columns} FROM npc_club WHERE country != ? ORDER BY tier ASC, RANDOM()";
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        $byTier = [];
        foreach ($rows as $row) {
            $tier = (int) $row['tier'];
            if (!isset($byTier[$tier]) || count($byTier[$tier]) < $limitPerTier) {
                $byTier[$tier][] = [
                    'id'             => $row['id'],
                    'name'           => $row['name'],
                    'country'        => $row['country'],
                    'tier'           => $tier,
                    'region'         => $row['region'],
                    'citySize'       => $row['city_size'],
                    'populationSize' => (int) $row['population_size'],
                    'isCapital'      => (bool) $row['is_capital'],
                    // The town this club plays in, or null for one generated before its
                    // country's locations were seeded. Additive, and this endpoint is
                    // UNCACHED — unlike the world-pack snapshot, which is why that one
                    // deliberately does not carry this field.
                    'locationId'     => $row['location_id'] !== null ? (string) $row['location_id'] : null,
                ];
            }
        }

        ksort($byTier);
        return array_merge(...array_values($byTier)) ?: [];
    }

    /**
     * Returns all clubs that have a league assigned, grouped by league UUID string.
     *
     * @return array<string, NpcClub[]>
     */
    /**
     * Which of these towns already have a club in this tier, as a lookup set.
     *
     * A GENERATION CONCERN, NOT A LAND RULE. Its only caller is
     * NpcClubGenerationService::anchorPlaces(), which seeds a top division with the capital and
     * the biggest towns and needs to know which of them are already spoken for — so a second
     * run without --delete-existing moves on to the next towns down instead of stacking another
     * club on the same five.
     *
     * IT REPLACES findLocationIdsWithTopFlightClub(), which asked the same question for a
     * different reason: a town with a top-flight club could not be sold to a player, on the
     * reasoning that "the main cities are spoken for". That rule never had a caller and the
     * land model has moved past it — plots carry real-world prices now, so what a player can
     * reach is decided by what they can afford, and a town is gated by the cost of its land
     * rather than by who else plays there.
     *
     * One query for the whole set: anchoring asks about five or six towns at once.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, true> location id => true, absent when the town has no club in this tier
     */
    public function findLocationIdsWithClubInTier(array $locationIds, int $tier): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT location_id
               FROM npc_club
              WHERE location_id IN (:ids)
                AND tier = :tier',
            ['ids' => $locationIds, 'tier' => $tier],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map(static fn ($id): string => (string) $id, $rows), true);
    }

    /**
     * The NPC clubs standing in each of these towns, keyed by location id.
     *
     * Batched so a location detail page costs one query however many towns it covers — it asks
     * for the town itself and its rival towns together.
     *
     * PROJECTED, NOT HYDRATED. The caller wants four scalars per club; hydrating would decode
     * every column of every club, including the facilities json, to render a name and a tier.
     *
     * Ordered by tier then reputation so the strongest club in a town reads first, which is the
     * order a player comparing towns actually wants.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, list<array{id: string, name: string, tier: int, reputation: int}>>
     */
    public function findByLocations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT location_id, id, name, tier, reputation
               FROM npc_club
              WHERE location_id IN (:ids)
              ORDER BY location_id, tier ASC, reputation DESC',
            ['ids' => $locationIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['location_id']][] = [
                'id'         => (string) $row['id'],
                'name'       => (string) $row['name'],
                'tier'       => (int) $row['tier'],
                'reputation' => (int) $row['reputation'],
            ];
        }

        return $out;
    }

    public function getAllGroupedByLeague(): array
    {
        $clubs = $this->createQueryBuilder('c')
            ->innerJoin('c.league', 'l')
            ->addSelect('l')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($clubs as $club) {
            $grouped[(string) $club->getLeague()->getId()][] = $club;
        }
        return $grouped;
    }
}
