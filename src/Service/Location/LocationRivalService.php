<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\Location;
use App\Entity\Location\LocationRival;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\LocationRivalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes each location's nearest rivals from coordinates, and stores them.
 *
 * RIVALRY IS DIRECTIONAL, AND THAT IS THE DESIGN. "Nearest four within 25 miles" is not a
 * symmetric relation: a small town's nearest rival is the big city down the road, while the
 * city's own four nearest are other cities. So A routinely lists B without B listing A. The
 * alternative — storing unordered pairs so every rivalry is mutual — was considered and
 * rejected, because it lets a big city accumulate unbounded rivals as every surrounding town
 * names it, and `rank` then means nothing.
 *
 * ORDERING AND COMPLETENESS: a set is rewritten wholesale rather than diffed. The pass
 * recomputes all four ranks together and UNIQUE(location, rank) makes an in-place reshuffle a
 * constraint fight for no benefit.
 *
 * WHY THIS HAS TO BE RE-RUNNABLE, AND IS NOT A ONE-OFF. Coordinates arrive by AI enrichment,
 * one location at a time and in no particular order. A newly placed town changes which towns
 * are its NEIGHBOURS' nearest four, not only its own — so enrichment nulls `rivals_computed_at`
 * across a radius (LocationEnrichmentService::applyResult()), and this service's --stale-only
 * mode picks those up. Until a country is fully enriched its rival sets are therefore
 * PROVISIONAL. That is acceptable because CountryProfile::$status gates an unready country out
 * of the API entirely, but it must be understood rather than mistaken for a bug.
 *
 * There is no PostGIS (postgres:16-alpine in both compose files), so distance is a bounding-box
 * prefilter plus haversine — see Haversine.
 */
final class LocationRivalService
{
    /**
     * "Within 25 miles", converted once rather than approximated.
     *
     * Stored distances are always kilometres; the mile figure is the product requirement, and
     * CountryProfile::$distanceUnit decides what a client is told to render.
     */
    public const SEARCH_RADIUS_MILES = 25.0;

    public function __construct(
        private readonly LocationRepository      $locationRepository,
        private readonly LocationRivalRepository $rivalRepository,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
    ) {}

    public static function searchRadiusKm(): float
    {
        return Haversine::milesToKm(self::SEARCH_RADIUS_MILES);
    }

    /**
     * Recompute one location's rival set.
     *
     * Returns the number of rivals stored — which may legitimately be fewer than four, or zero
     * for an isolated town, and zero is a real answer rather than a failure. `rivals_computed_at`
     * is stamped either way, so an isolated town is not revisited on every pass.
     */
    public function recomputeFor(Location $location, bool $crossBorder = false): int
    {
        if (!$location->hasCoordinates()) {
            // Not an error: most locations have no coordinates until enrichment reaches them.
            return 0;
        }

        $nearest = $this->locationRepository->findNearest(
            $location,
            self::searchRadiusKm(),
            LocationRival::MAX_RANK,
            sameCountryOnly: !$crossBorder,
        );

        $this->em->wrapInTransaction(function () use ($location, $nearest): void {
            $this->rivalRepository->deleteForLocation($location);

            $rank = 1;
            foreach ($nearest as $row) {
                $rival = $this->locationRepository->find($row['id']);

                if ($rival === null) {
                    continue;
                }

                $this->em->persist(new LocationRival($location, $rival, $rank, $row['distance_km']));
                ++$rank;
            }

            // Stamped inside the same transaction as the rows it describes, so a crash leaves
            // the set stale-and-marked-stale rather than empty-and-marked-fresh.
            $location->setRivalsComputedAt(new \DateTimeImmutable());

            $this->em->flush();
        });

        return count($nearest);
    }

    /**
     * Recompute every location whose set is missing or stale.
     *
     * @return array{processed: int, rivals: int, skipped: int}
     */
    public function recomputeStale(
        ?string $countryCode = null,
        int $limit = 5000,
        bool $crossBorder = false,
    ): array {
        $locations = $this->locationRepository->findWithStaleRivals($countryCode, $limit);

        // Counted with its own query, not by filtering the list above: findWithStaleRivals()
        // excludes coordinate-less rows on purpose (they cannot be ranked, and returning them
        // would make every pass churn over the same rows), so a counter incremented inside that
        // loop could only ever report zero. This figure is the "waiting on enrichment" signal.
        $skipped = $this->locationRepository->countStaleWithoutCoordinates($countryCode);

        $processed = 0;
        $rivals    = 0;

        foreach ($locations as $location) {
            $rivals += $this->recomputeFor($location, $crossBorder);
            ++$processed;
        }

        if ($processed > 0) {
            $this->logger->info('Rival sets recomputed', [
                'country'   => $countryCode ?? 'all',
                'processed' => $processed,
                'rivals'    => $rivals,
            ]);
        }

        return ['processed' => $processed, 'rivals' => $rivals, 'skipped' => $skipped];
    }

    /**
     * Recompute every location in a country, stale or not.
     *
     * For after a bulk coordinate correction, where the staleness flags cannot be trusted.
     *
     * @return array{processed: int, rivals: int, skipped: int}
     */
    public function recomputeCountry(string $countryCode, bool $crossBorder = false): array
    {
        $processed = 0;
        $rivals    = 0;
        $skipped   = 0;

        foreach ($this->locationRepository->findByCountry($countryCode) as $location) {
            if (!$location->isActive() || !$location->hasCoordinates()) {
                ++$skipped;
                continue;
            }

            $rivals += $this->recomputeFor($location, $crossBorder);
            ++$processed;
        }

        return ['processed' => $processed, 'rivals' => $rivals, 'skipped' => $skipped];
    }
}
