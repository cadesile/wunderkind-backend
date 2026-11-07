<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\CitySize;
use App\Enum\Location\LandPlotStatus;
use App\Repository\Location\LocationConfigRepository;
use App\Repository\Location\LocationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives every town the number of plots its size calls for.
 *
 * Seeding used to create exactly one plot per town, hardcoded, so a village and a capital city
 * could each hold exactly one club. Plot count now follows city size — which follows population,
 * which enrichment supplies — so the size of a place decides how much football it can hold.
 *
 * TOPS UP FREELY, AND TAKES BACK ONLY WHAT NOBODY HOLDS. A town that re-bands DOWNWARD used to
 * keep every plot it had and simply stop gaining more, which left the catalogue selling land the
 * town's size no longer justifies — a BIG town demoted to MEDIUM went on offering four plots
 * forever. reapSurplus() now retires the surplus, but only plots that are free: never one with a
 * live claim, and never one under a hold, because that hold is somebody mid-checkout.
 *
 * AN OWNED PLOT IS NOT LOST, IT IS DEFERRED. A demoted town whose plots are all sold stays over
 * target until a claim ends, and the reap on release is what collects it then — so the land goes
 * back to the band's target instead of back on the market. The player who bought it is never
 * disturbed, and the town converges on its true size as people sell.
 *
 * RETIRED, NOT DELETED, and the schema insists: land_plot is referenced by plot_claim with
 * ON DELETE RESTRICT, so a DELETE fails loudly on any plot that has ever been claimed, released
 * or not. LandPlotStatus::RETIRED is this codebase's delete — it is what takes a plot out of
 * LocationCapacityService's count and out of every sellable query, while the purchase history
 * that points at it stays intact.
 *
 * IDEMPOTENT. Running it twice adds nothing the second time, so it is safe on every seed, after
 * every reclassification, and by hand.
 *
 * ONLY PRICED TOWNS GET PLOTS, and that gate is the whole reason the two import paths now agree.
 * A town with no coordinates and no land value cannot be sold —  PlotHoldService refuses it with
 * "the land has not been valued yet" — and LocationCapacityService reports a town with zero
 * plots as `awaiting_enrichment`, which is the signal a client reads to decide whether to offer
 * a buy button at all. Giving an unpriced town a plot therefore does not make it buyable; it
 * only makes the catalogue lie about it, offering a plot that fails at the checkout. Seeding
 * used to do exactly that, hardcoding one plot per town before anything was priced.
 *
 * So: a town gets plots when enrichment prices it, and never before. Location::isSellable() is
 * the gate, because it is already the definition of "everything a plot needs before it can be
 * sold".
 *
 * COUNTS COME FROM LocationConfig, not from a constant — how much football a big city holds is a
 * balance dial an operator can turn at /admin?routeName=admin_location_config.
 */
final class LandPlotProvisioner
{
    public function __construct(
        private readonly LocationRepository       $locationRepository,
        private readonly LocationConfigRepository $configRepository,
        private readonly EntityManagerInterface   $em,
    ) {}

    /**
     * Bring every active town in a country up to its target plot count.
     *
     * @return array{locations: int, plotsAdded: int}
     */
    public function provisionCountry(string $countryCode, bool $dryRun = false): array
    {
        $code = strtoupper(trim($countryCode));

        // Current plot counts per town, in one query rather than one per location.
        $existing = [];
        foreach ($this->em->getConnection()->fetchAllAssociative(
            "SELECT p.location_id, COUNT(*) AS plots, MAX(p.plot_number) AS highest
               FROM land_plot p
               JOIN location l ON l.id = p.location_id
              WHERE l.country_code = :code
              GROUP BY p.location_id",
            ['code' => $code],
        ) as $row) {
            $existing[(string) $row['location_id']] = [
                'plots'   => (int) $row['plots'],
                'highest' => (int) $row['highest'],
            ];
        }

        $locations  = 0;
        $plotsAdded = 0;
        $config     = $this->configRepository->getConfig();

        foreach ($this->locationRepository->findByCountry($code) as $location) {
            // isSellable() rather than isActive(): an active but unpriced town must stay at zero
            // plots so the catalogue keeps reporting it as awaiting enrichment. See the class
            // docblock — this is the gate that makes seeding and importing agree.
            if (!$location->isSellable()) {
                continue;
            }

            $target  = $config->plotsForCitySize($location->getCitySize());
            $current = $existing[(string) $location->getId()] ?? ['plots' => 0, 'highest' => 0];

            if ($current['plots'] >= $target) {
                continue;
            }

            $locations++;
            $next = $current['highest'];

            for ($i = $current['plots']; $i < $target; $i++) {
                $plotsAdded++;

                if ($dryRun) {
                    continue;
                }

                // Numbered from the highest existing rather than from the count, so a town whose
                // plot 2 was retired cannot collide with uq_land_plot_location_number.
                $this->em->persist(new LandPlot($location, ++$next));
            }
        }

        if (!$dryRun && $plotsAdded > 0) {
            $this->em->flush();
            $this->em->clear();
        }

        return ['locations' => $locations, 'plotsAdded' => $plotsAdded];
    }

    /**
     * Top up one town, for the enrichment path.
     *
     * Called when a population change has re-banded a town upward: a town that becomes BIG
     * should gain the plots that size implies without waiting for a manual pass.
     */
    public function provisionLocation(Location $location): int
    {
        if (!$location->isSellable()) {
            return 0;
        }

        $target  = $this->configRepository->getConfig()->plotsForCitySize($location->getCitySize());
        $row     = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS plots, COALESCE(MAX(plot_number), 0) AS highest
               FROM land_plot WHERE location_id = :id',
            ['id' => (string) $location->getId()],
        );

        $current = (int) ($row['plots'] ?? 0);
        $next    = (int) ($row['highest'] ?? 0);

        if ($current >= $target) {
            return 0;
        }

        $added = 0;
        for ($i = $current; $i < $target; $i++) {
            $this->em->persist(new LandPlot($location, ++$next));
            $added++;
        }

        $this->em->flush();

        return $added;
    }

    /**
     * Retire free plots a town is no longer big enough to justify.
     *
     * THE COUNTERPART TO TOPPING UP, and the reason this class no longer only grows. Called
     * wherever a plot target can fall out of step with the plots on the ground: after a
     * reclassification re-bands a country, after a claim is released on a town that is already
     * over target, and from PlotHoldService on acquire and release — that last one because a
     * held plot is skipped here and an expiring hold runs no code to lift the protection, so
     * acquiring is the only moment left to collect it before it could be sold.
     *
     * WHAT IS SAFE TO TAKE. A plot is free only when it carries no live claim AND no unexpired
     * hold. The hold half matters as much as the claim: a hold is a player at the checkout with
     * a price quoted, and retiring that plot underneath them would fail the purchase for reasons
     * they could not have seen coming.
     *
     * THE ORDERING IS THE WHOLE ALGORITHM. Rows are ranked claimed-first, then held, then by
     * plot number, and only ranks beyond the target are retired. That ordering is what makes
     * occupied plots unreachable rather than merely skipped: a town with target 2 holding four
     * plots of which three are claimed keeps all three and retires the one free plot, landing at
     * three — over target, but every remaining plot is somebody's. Skipping occupied rows
     * without reordering would instead have retired a free plot that should have survived and
     * left the count wrong.
     *
     * ONE STATEMENT, NO ENTITIES. The callers include a path that has just run em->clear(), and
     * one that is midway through a sell-back holding a claim it still needs. Touching neither
     * the identity map nor the unit of work is what keeps this safe to call from both.
     *
     * $formerlyHeldOnly NARROWS IT TO PLOTS A DEAD HOLD IS HIDING, and exists because being over
     * target is not by itself a fault. An operator who lowers plotsByCitySize leaves every town
     * of that size over target on purpose — LandPlotProvisioningRulesTest pins that plots are not
     * taken away for it — and a town hand-given an extra plot is over target deliberately too.
     * An unconditional reap wherever we happen to look would quietly undo both. The only case
     * that genuinely needs collecting outside a re-band is a plot the reap already skipped
     * because it was held, whose hold has since died with no event to say so; requiring that the
     * plot has a hold row at all is what separates that plot from one that was simply never
     * wanted gone.
     *
     * @param list<string> $locationIds empty reaps nothing — pass a country code instead
     *
     * @return int plots retired
     */
    public function reapSurplus(array $locationIds, bool $formerlyHeldOnly = false): int
    {
        if ($locationIds === []) {
            return 0;
        }

        return $this->reap('p.location_id IN (:ids)', [
            'ids' => $locationIds,
        ], ['ids' => ArrayParameterType::STRING], $formerlyHeldOnly);
    }

    /** @return int plots retired */
    public function reapSurplusForCountry(string $countryCode): int
    {
        return $this->reap('l.country_code = :code', [
            'code' => strtoupper(trim($countryCode)),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function reap(string $scope, array $params, array $types = [], bool $formerlyHeldOnly = false): int
    {
        $config = $this->configRepository->getConfig();

        // Read through plotsForCitySize() rather than the stored array: it is the accessor that
        // sanitises an operator-edited row, and a CASE built from raw JSON could put a string
        // into the comparison.
        $params += [
            'big'      => $config->plotsForCitySize(CitySize::BIG),
            'medium'   => $config->plotsForCitySize(CitySize::MEDIUM),
            'small'    => $config->plotsForCitySize(CitySize::SMALL),
            'active'   => LandPlotStatus::ACTIVE->value,
            'retired'  => LandPlotStatus::RETIRED->value,
            'now'      => new \DateTimeImmutable(),
        ];

        $types += ['now' => 'datetime_immutable'];

        // "Has ever been held, but is not held now" — the NOT held above supplies the second
        // half. Any hold row will do: a live one has already excluded the plot.
        $heldFilter = $formerlyHeldOnly
            ? 'AND EXISTS (SELECT 1 FROM plot_hold ph WHERE ph.plot_id = ranked.id)'
            : '';

        return (int) $this->em->getConnection()->executeStatement(
            "UPDATE land_plot
                SET status = :retired, updated_at = :now
              WHERE id IN (
                    SELECT id FROM (
                        SELECT p.id,
                               -- CAST, not a bare placeholder: PDO sends these as text and
                               -- Postgres then has no `bigint > text` to compare keep_rank
                               -- against. The cast is on the CASE arms so the column is typed
                               -- at the point it is built.
                               CASE l.city_size
                                   WHEN 'BIG'    THEN CAST(:big AS integer)
                                   WHEN 'MEDIUM' THEN CAST(:medium AS integer)
                                   ELSE CAST(:small AS integer)
                               END AS target,
                               c.id IS NOT NULL AS claimed,
                               h.id IS NOT NULL AS held,
                               -- CLAIMED PLOTS RANK FIRST, HELD ONES DO NOT. A claim is
                               -- permanent occupancy and has to consume one of the target's
                               -- slots. A hold is fifteen minutes, and ranking it the same way
                               -- let a transient hold on plot 2 evict free plot 1 — retiring
                               -- the lasting plot in favour of a checkout that may never
                               -- complete. A hold only defers its OWN plot (the NOT held below).
                               row_number() OVER (
                                   PARTITION BY p.location_id
                                   ORDER BY (c.id IS NOT NULL) DESC,
                                            p.plot_number ASC
                               ) AS keep_rank
                          FROM land_plot p
                          JOIN location l ON l.id = p.location_id
                          LEFT JOIN plot_claim c ON c.plot_id = p.id AND c.released_at IS NULL
                          -- THE FULL LIVENESS TEST, matching PlotHoldRepository's: released and
                          -- consumed holds are both dead. Keying on expires_at alone made a
                          -- just-released hold go on protecting its plot, so releasing a hold on
                          -- a demoted town left the surplus active.
                          LEFT JOIN plot_hold  h ON h.plot_id = p.id
                                                AND h.released_at IS NULL
                                                AND h.consumed_at IS NULL
                                                AND h.expires_at > :now
                         WHERE p.status = :active
                           AND {$scope}
                    ) ranked
                     WHERE keep_rank > target
                       AND NOT claimed
                       AND NOT held
                       {$heldFilter}
              )",
            $params,
            $types,
        );
    }

    /**
     * Target plot counts across a whole country, for reporting.
     *
     * @return array<string, int> city size => towns at that size
     */
    public function targetsFor(string $countryCode): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT city_size, COUNT(*) AS towns
               FROM location WHERE country_code = :code AND status = :active
              GROUP BY city_size',
            ['code' => strtoupper(trim($countryCode)), 'active' => 'active'],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['city_size']] = (int) $row['towns'];
        }

        return $out;
    }
}
