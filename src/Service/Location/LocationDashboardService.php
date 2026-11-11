<?php

declare(strict_types=1);

namespace App\Service\Location;

use Doctrine\DBAL\Connection;

/**
 * The per-country figures the locations dashboard prints.
 *
 * TWO QUERIES FOR THE WHOLE TABLE, not two per country. The screen lists every country
 * profile with its town counts, enrichment breakdown and land position, so anything per-row
 * would be a query per country on a page that exists to show all of them at once.
 *
 * THE ENRICHMENT HALF IS LIFTED, NOT REWRITTEN. LocationEnrichmentController::countryRows()
 * has done exactly this FILTER-per-status count for a while; it moved here and that
 * controller now calls this, because two copies of the same five-way breakdown would drift
 * the first time a status is added.
 *
 * SELLABLE MEANS WHAT CountryReadinessService SAYS IT MEANS — active, with lat, lng and
 * land_pence all present, against MIN_SELLABLE_LOCATIONS. If the two predicates ever
 * disagree, the dashboard says a country is ready while the gate that opens it says blocked,
 * which is worse than not showing the figure at all.
 */
final class LocationDashboardService
{
    /**
     * Country-level fields an enrichment pass can fill.
     *
     * The eight nullable columns on country_profile, and nothing else: `name`,
     * `default_locale` and `distance_unit` are NOT NULL with values from the seeder, so
     * counting them would make every country look partly done before anything had run.
     */
    public const PROFILE_FIELDS = 8;

    public function __construct(private readonly Connection $connection) {}

    /**
     * Every country profile, with its towns, enrichment split and sellable count.
     *
     * @return array<string, array{code: string, name: string, status: string, total: int,
     *     counts: array<string, int>, sellable: int, profileSet: int, profileTotal: int}>
     */
    public function countryRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.code, c.name, c.status,
                    COUNT(l.id) AS total,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'pending')      AS pending,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'queued')       AS queued,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'enriched')     AS enriched,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'needs_review') AS needs_review,
                    COUNT(l.id) FILTER (WHERE l.enrichment_status = 'failed')       AS failed,
                    -- Byte-identical to CountryReadinessService's sellable check. If these
                    -- ever drift, this screen and the gate that opens a country disagree.
                    COUNT(l.id) FILTER (
                        WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL AND l.land_pence IS NOT NULL
                    ) AS sellable,
                    -- How much of the COUNTRY's own row is filled in. Counted here rather
                    -- than in Twig because the bbox and the land band are what every town
                    -- answer in this country is validated against, so a progress figure that
                    -- left them out would be incomplete -- and a second count elsewhere would
                    -- be the copy that drifts, which is why the status breakdown above moved
                    -- into this service in the first place.
                    (
                        (c.bbox IS NOT NULL)::int + (c.land_pence_min IS NOT NULL)::int
                      + (c.land_pence_max IS NOT NULL)::int + (c.iso_alpha3 IS NOT NULL)::int
                      + (c.continent IS NOT NULL)::int + (c.default_timezone IS NOT NULL)::int
                      + (c.currency_code IS NOT NULL)::int + (c.land_price_currency_code IS NOT NULL)::int
                    ) AS profile_set
               FROM country_profile c
               LEFT JOIN location l ON l.country_code = c.code AND l.status = 'active'
              -- GROUPED BY THE PRIMARY KEY ALONE, not by every column selected from it.
              -- Postgres resolves the rest by functional dependency on country_profile.code,
              -- and it has to: `bbox` is json, which has no equality operator, so naming it
              -- in GROUP BY is an outright error rather than merely redundant.
              GROUP BY c.code
              ORDER BY c.name",
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['code']] = [
                'code'     => (string) $row['code'],
                'name'     => (string) $row['name'],
                'status'   => (string) $row['status'],
                'total'    => (int) $row['total'],
                'sellable' => (int) $row['sellable'],
                // Out of PROFILE_FIELDS. A country at full marks needs no attention; anything
                // less is how many facts its towns are NOT being validated against.
                'profileSet'   => (int) $row['profile_set'],
                'profileTotal' => self::PROFILE_FIELDS,
                'counts'   => [
                    'pending'      => (int) $row['pending'],
                    'queued'       => (int) $row['queued'],
                    'enriched'     => (int) $row['enriched'],
                    'needs_review' => (int) $row['needs_review'],
                    'failed'       => (int) $row['failed'],
                ],
            ];
        }

        return $out;
    }

    /**
     * Plots per country, split by what is holding them, plus the median price.
     *
     * THE THREE BUCKETS ARE MUTUALLY EXCLUSIVE, which is what makes them sum to `plots`. The
     * `c.id IS NULL` inside the held filter is doing that work: a plot with both a claim and
     * a live hold counts once, as claimed.
     *
     * THE FULL HOLD-LIVENESS TEST, not just expires_at. A released or consumed hold is dead,
     * and keying on expiry alone is the exact bug LandPlotProvisioner::reap() carries a
     * comment about.
     *
     * "UNCLAIMED" RATHER THAN "AVAILABLE", deliberately: this ignores NPC occupancy, while
     * LocationCapacityService subtracts it. Calling it available would make the dashboard
     * contradict what the API tells a client about the same towns.
     *
     * @return array<string, array{plots: int, claimed: int, held: int, unclaimed: int, retired: int, medianPence: ?int}>
     */
    public function plotRows(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT l.country_code AS code,
                    COUNT(p.id) FILTER (WHERE p.status = 'active')                      AS plots,
                    COUNT(p.id) FILTER (WHERE p.status = 'active' AND c.id IS NOT NULL) AS claimed,
                    COUNT(p.id) FILTER (WHERE p.status = 'active' AND c.id IS NULL
                                                                 AND h.id IS NOT NULL)  AS held,
                    COUNT(p.id) FILTER (WHERE p.status = 'retired')                     AS retired,
                    percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY COALESCE(p.land_pence_override, l.land_pence)
                    ) FILTER (
                        WHERE p.status = 'active'
                          AND COALESCE(p.land_pence_override, l.land_pence) IS NOT NULL
                    ) AS median_pence
               FROM land_plot p
               JOIN location l ON l.id = p.location_id AND l.status = 'active'
               LEFT JOIN plot_claim c ON c.plot_id = p.id AND c.released_at IS NULL
               LEFT JOIN plot_hold  h ON h.plot_id = p.id
                                     AND h.released_at IS NULL AND h.consumed_at IS NULL
                                     AND h.expires_at > :now
              GROUP BY l.country_code",
            ['now' => new \DateTimeImmutable()],
            ['now' => 'datetime_immutable'],
        );

        $out = [];

        foreach ($rows as $row) {
            $plots   = (int) $row['plots'];
            $claimed = (int) $row['claimed'];
            $held    = (int) $row['held'];

            $out[(string) $row['code']] = [
                'plots'       => $plots,
                'claimed'     => $claimed,
                'held'        => $held,
                'unclaimed'   => max(0, $plots - $claimed - $held),
                'retired'     => (int) $row['retired'],
                'medianPence' => $row['median_pence'] === null ? null : (int) round((float) $row['median_pence']),
            ];
        }

        return $out;
    }
}
