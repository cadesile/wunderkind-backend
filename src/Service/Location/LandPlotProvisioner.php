<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\CitySize;
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
 * TOPS UP, NEVER REMOVES, and that is not a convenience. A plot can carry an active claim and
 * real money; land_plot is referenced by plot_claim with ON DELETE RESTRICT precisely so a
 * delete fails loudly rather than cascading. So a town that re-bands DOWNWARD keeps the plots it
 * already had: it simply stops gaining more. The alternative — removing a plot someone bought
 * because a population estimate moved — is not a trade worth making.
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
