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
 * THE MATCHING RULE, and why it is this and not a LIKE '%town%'.
 * NpcClubGenerationService::generateName() composes a name as exactly one space joining a town
 * and an affix: town-leading in 19 countries, affix-leading in the six listed in
 * CountryContentRegistry::SUFFIX_LEADS_NAME (PL, BE, SA, CO, EG, TN — Legia Lodz, Gornik Gdynia).
 * The town is always a whole, verbatim, space-delimited token. So:
 *
 *   1. longest whole-token PREFIX match, then
 *   2. longest whole-token match anywhere.
 *
 * Measured over the real catalogue this resolves 1,535 of 1,535 clubs: zero unmatched, zero
 * ambiguous. Three hazards make the obvious alternatives wrong:
 *
 *   - A SUBSTRING IS NOT A TOKEN. Manchester contains Chester; Enschede contains Ede (NL);
 *     Calabar contains Aba (NG); Las Palmas contains Palma (ES). Token matching rejects all of
 *     them, LIKE '%town%' does not.
 *   - SOME AFFIXES ARE THEMSELVES TOWNS. Burgos is an ES generic suffix and an ES town; so are
 *     Bari and Palermo (IT prestige), Amiens (FR generic), Covilha (PT generic). So a club named
 *     Metz Amiens matches two towns and the LONGER one is the wrong answer — which is exactly
 *     why prefix position outranks length rather than the reverse.
 *   - COMPOUND TOWNS CONTAIN SHORTER ONES. Aparecida de Goiania contains Goiania; Richmond Hill
 *     contains Richmond. Longest-prefix picks the compound, correctly.
 *
 * MATCHED IN PHP, NOT SQL, and that is deliberate. The comparison key is
 * ClubNameNormalizer::normalize() — the same function that produced `location.town_normalised`
 * and `club.name_normalised`. Reproducing it in SQL would need the `unaccent` extension and
 * would be a SECOND definition of the normalisation rules, free to drift from the PHP one; the
 * normalizer's own docblock already warns that the client mirrors it too. Two passes over a few
 * thousand short strings costs nothing.
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
    public function __construct(private readonly Connection $connection) {}

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
        $townsByCc = $this->fetchTowns($countryCodes);

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
            $town = $this->matchTown(
                ClubNameNormalizer::normalize((string) $club['name']),
                $townsByCc[(string) $club['country']] ?? [],
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
     * The rule, applied to one club name against one country's towns.
     *
     * Towns arrive sorted longest-normalised-name first, so the first prefix hit is the longest
     * prefix and the first token hit is the longest token — no scoring pass needed.
     *
     * @param list<array<string, mixed>> $towns
     *
     * @return array<string, mixed>|null
     */
    private function matchTown(string $clubNorm, array $towns): ?array
    {
        $tokenHit = null;

        foreach ($towns as $town) {
            $t = (string) $town['town_normalised'];

            if ($t === '') {
                continue;
            }

            // Pass 1 wins outright, so return immediately rather than scoring.
            if ($clubNorm === $t || str_starts_with($clubNorm, $t . ' ')) {
                return $town;
            }

            // Remember only the first (longest) token hit as the fallback.
            if ($tokenHit === null
                && (str_ends_with($clubNorm, ' ' . $t) || str_contains($clubNorm, ' ' . $t . ' '))) {
                $tokenHit = $town;
            }
        }

        return $tokenHit;
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
     * Towns per country, longest normalised name first — which is what lets matchTown() return
     * on its first hit instead of scoring every candidate.
     *
     * @param list<string>|null $countryCodes
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchTowns(?array $countryCodes): array
    {
        $sql    = "SELECT id, country_code, town, town_normalised, region, city_size,
                          population, is_capital
                     FROM location WHERE status = 'active'";
        $params = [];
        $types  = [];

        if ($countryCodes !== null && $countryCodes !== []) {
            $sql .= ' AND country_code IN (:countries)';
            $params['countries'] = $countryCodes;
            $types['countries']  = ArrayParameterType::STRING;
        }

        $sql .= ' ORDER BY country_code, length(town_normalised) DESC, town ASC';

        $out = [];
        foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $out[(string) $row['country_code']][] = $row;
        }

        return $out;
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
