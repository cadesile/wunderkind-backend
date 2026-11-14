<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Service\ClubNameNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Links already-generated NPC clubs back to the town they were named after.
 *
 * NPC clubs predate the location table. They were generated from the same curated place list the
 * locations were later seeded from, carrying `region`, `city_size`, `population_size` and
 * `is_capital` as COPIES rather than a reference — so the town is recoverable from the name, and
 * only from the name.
 *
 * THE MATCHING RULE NOW LIVES IN TownNameMatcher, which this delegates to. It was extracted
 * when ClubSaleValuationService needed the same rule to price the land under a player club that
 * has no `home_location_id` — the choice was one shared implementation or two copies free to
 * drift, and the rule's own reasoning (below, and at length in that class) is why a copy is not
 * acceptable. Read TownNameMatcher for the rule, the per-country position, and the three hazards
 * that make LIKE '%town%' wrong.
 *
 * LOCAL DATA IS NOT A PROOF. Club names are drawn at random from per-country affix pools, so
 * whether a given database contains a name that trips the rule is luck. Always dry-run against
 * the target environment and read the drift table: a club whose copied city_size disagrees with
 * the town it matched is the signature of a mis-link, not of drift.
 *
 * Measured over the real catalogues the rule resolves every NPC club — 1,535 locally and 3,239
 * on dev, zero unmatched, zero ambiguous.
 *
 * IT ALSO RE-SYNCS the four copied scalars from the linked town, which is what closes the drift
 * those copies have accumulated — city size is a percentile split across a country's whole set,
 * and the two code paths that computed it disagreed on ties. It deliberately does NOT touch
 * `name` (a club's name is its own from generation, so renaming a town must never rewrite it),
 * nor `reputation`/`balance`, which were rolled with randomness at generation and whose
 * re-rolling would be a far larger rewrite of live worlds.
 */
final class NpcClubLocationLinker
{
    public function __construct(
        private readonly Connection      $connection,
        private readonly TownNameMatcher $matcher,
    ) {}

    /**
     * @param list<string>|null $countryCodes null means every country
     *
     * @return array{considered: int, linked: int, resynced: int, unmatched: int,
     *     drift: list<array<string, string>>, unmatchedSamples: list<array<string, string>>}
     */
    public function link(
        ?array $countryCodes = null,
        bool $relink = false,
        bool $dryRun = false,
        int $sampleSize = 15,
    ): array {
        $clubs     = $this->fetchClubs($countryCodes, $relink);
        $townsByCc = $this->matcher->townsByCountry($countryCodes);

        $result = [
            'considered'       => count($clubs),
            'linked'           => 0,
            'resynced'         => 0,
            'unmatched'        => 0,
            'drift'            => [],
            'unmatchedSamples' => [],
        ];

        $updates = [];

        foreach ($clubs as $club) {
            $countryCode = (string) $club['country'];

            $town = $this->matcher->match(
                ClubNameNormalizer::normalize((string) $club['name']),
                $townsByCc[$countryCode] ?? [],
                $this->matcher->suffixLeads($countryCode),
            );

            if ($town === null) {
                $result['unmatched']++;

                if (count($result['unmatchedSamples']) < $sampleSize) {
                    $result['unmatchedSamples'][] = [
                        'country' => (string) $club['country'],
                        'club'    => (string) $club['name'],
                    ];
                }

                continue;
            }

            $result['linked']++;
            $updates[] = [$club['id'], $town];

            if ($this->hasDrift($club, $town)) {
                $result['resynced']++;

                if (count($result['drift']) < $sampleSize) {
                    $result['drift'][] = [
                        'club'     => (string) $club['name'],
                        'town'     => (string) $town['town'],
                        'clubSize' => (string) $club['city_size'],
                        'townSize' => (string) $town['city_size'],
                    ];
                }
            }
        }

        if (!$dryRun && $updates !== []) {
            $this->apply($updates);
        }

        return $result;
    }

    /**
     * Push a country's current town facts onto every NPC club already linked to one.
     *
     * The other half of "enrichment reaches NPC clubs". When an enriched population lands, the
     * country's city sizes are recomputed — and because that is a PERCENTILE split across the
     * whole set, a single town's new population can move OTHER towns across a band boundary. So
     * the re-sync has to cover the country, not just the town that changed.
     *
     * One set-based UPDATE ... FROM rather than a row loop: this runs on the enrichment path,
     * once per accepted population, and a per-row loop would make a sweep quadratic in the size
     * of the country. The IS DISTINCT FROM guard keeps the write to rows that actually moved, so
     * the common case touches nothing.
     *
     * Does NOT link anything — a club with no location_id is untouched. Backfilling is link()'s
     * job and needs the name matching.
     *
     * @return int clubs whose values changed
     */
    public function resyncCountry(string $countryCode): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE npc_club n
                SET region          = l.region,
                    city_size       = l.city_size,
                    population_size = l.population,
                    is_capital      = l.is_capital
               FROM location l
              WHERE l.id = n.location_id
                AND n.country = :country
                AND (n.region          IS DISTINCT FROM l.region
                  OR n.city_size       IS DISTINCT FROM l.city_size
                  OR n.population_size IS DISTINCT FROM l.population
                  OR n.is_capital      IS DISTINCT FROM l.is_capital)',
            ['country' => strtoupper(trim($countryCode))],
        );
    }

    /**
     * @param list<string>|null $countryCodes
     *
     * @return list<array<string, mixed>>
     */
    private function fetchClubs(?array $countryCodes, bool $relink): array
    {
        $sql    = 'SELECT id, name, country, region, city_size, population_size, is_capital
                     FROM npc_club WHERE TRUE';
        $params = [];
        $types  = [];

        if (!$relink) {
            $sql .= ' AND location_id IS NULL';
        }

        if ($countryCodes !== null && $countryCodes !== []) {
            $sql .= ' AND country IN (:countries)';
            $params['countries'] = $countryCodes;
            $types['countries']  = ArrayParameterType::STRING;
        }

        return $this->connection->fetchAllAssociative($sql . ' ORDER BY country, name', $params, $types);
    }

    /**
     * @param array<string, mixed> $club
     * @param array<string, mixed> $town
     */
    private function hasDrift(array $club, array $town): bool
    {
        return (string) $club['city_size'] !== (string) $town['city_size']
            || (string) ($club['region'] ?? '') !== (string) ($town['region'] ?? '')
            || (int) $club['population_size'] !== (int) ($town['population'] ?? 0)
            || (bool) $club['is_capital'] !== (bool) $town['is_capital'];
    }

    /**
     * Write the link and re-sync the copied scalars, in one transaction.
     *
     * All-or-nothing on purpose: a half-applied re-sync would leave a country where some clubs
     * agree with their town and some do not, which is harder to reason about than either end
     * state and impossible to tell apart from the drift this is fixing.
     *
     * @param list<array{0: mixed, 1: array<string, mixed>}> $updates
     */
    private function apply(array $updates): void
    {
        $this->connection->transactional(function (Connection $c) use ($updates): void {
            foreach ($updates as [$clubId, $town]) {
                $c->executeStatement(
                    'UPDATE npc_club
                        SET location_id     = :locationId,
                            region          = :region,
                            city_size       = :citySize,
                            population_size = :population,
                            is_capital      = :isCapital
                      WHERE id = :id',
                    [
                        'locationId' => $town['id'],
                        'region'     => $town['region'],
                        'citySize'   => $town['city_size'],
                        'population' => (int) ($town['population'] ?? 0),
                        'isCapital'  => (bool) $town['is_capital'],
                        'id'         => $clubId,
                    ],
                    // DBAL 4 takes its own ParameterType; a PDO constant is an int and is rejected.
                    ['isCapital' => ParameterType::BOOLEAN],
                );
            }
        });
    }
}
