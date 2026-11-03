<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\CitySize;
use App\Repository\Location\LocationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assigns each location a BIG / MEDIUM / SMALL size, and persists it.
 *
 * WHY PERSIST SOMETHING DERIVABLE. The split is a property of the whole country's place set —
 * top 20% by population BIG, bottom 50% SMALL, the rest MEDIUM, with a capital forced BIG
 * regardless of rank. So it is not a function of one row, and computing it per call would make
 * the same town classify differently before and after a neighbouring town is added. NpcClub
 * already stores its own `citySize` and `populationSize` at generation time, so a live
 * recomputation could disagree with every club already generated from it. Storing the answer
 * once per country, and recomputing it only when the set changes, removes that whole class of
 * drift.
 *
 * WHERE THE ALGORITHM CAME FROM. Moved verbatim from
 * NpcClubGenerationService::classifyPlaces(), which now delegates here so there is exactly one
 * implementation. The direction matters: the generator depends on the location layer, not the
 * other way round. NpcClubGenerationServiceTest's four existing classifyPlaces() cases are
 * what prove the move changed no behaviour — they were not touched.
 *
 * Recompute triggers: the end of app:locations:seed, the end of an enrichment run that changed
 * any population, and any admin CRUD write that adds or removes a location or edits its
 * population.
 */
final class LocationClassificationService
{
    /** Share of a country's places, by population rank, classified BIG. */
    public const BIG_SHARE = 0.20;

    /** Share classified SMALL, counted from the bottom. */
    public const SMALL_SHARE = 0.50;

    public function __construct(
        private readonly LocationRepository     $locationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * The pure algorithm: sort by population descending and band the result.
     *
     * Byte-for-byte the logic that lived in NpcClubGenerationService::classifyPlaces(),
     * including the detail that a capital is forced BIG *before* the rank bands are consulted
     * (Nigeria's Abuja, not Lagos, is the precedent) and that the returned array is re-indexed
     * in population order rather than left in input order. Callers rely on both.
     *
     * @param list<array{name?: string, population_size: int, region?: string, is_capital?: bool}> $places
     *
     * @return list<array{population_size: int, is_capital?: bool, city_size: CitySize, ...}>
     */
    public static function classify(array $places): array
    {
        $sorted = $places;
        usort($sorted, static fn (array $a, array $b) => $b['population_size'] <=> $a['population_size']);

        $count      = count($sorted);
        $bigCutoff  = (int) ceil($count * self::BIG_SHARE);
        $smallCount = (int) ceil($count * self::SMALL_SHARE);

        foreach ($sorted as $i => &$place) {
            if (!empty($place['is_capital'])) {
                $place['city_size'] = CitySize::BIG;
            } elseif ($i < $bigCutoff) {
                $place['city_size'] = CitySize::BIG;
            } elseif ($i >= $count - $smallCount) {
                $place['city_size'] = CitySize::SMALL;
            } else {
                $place['city_size'] = CitySize::MEDIUM;
            }
        }
        unset($place);

        return $sorted;
    }

    /**
     * Recompute and store `city_size` for every active location in one country.
     *
     * Written as at most three UPDATEs — one per size, each with an id list — rather than one
     * per row. A country has tens of locations today but this also runs over a whole sweep, and
     * a per-row flush would be hundreds of statements for a value that only ever takes three
     * values.
     *
     * RETURNS THE DISTRIBUTION, NOT THE NUMBER OF ROWS CHANGED. Those differ, and the
     * difference is actively misleading: `city_size` defaults to SMALL on a new entity, so a
     * freshly seeded country reports zero SMALL rows changed while in fact half of them are
     * SMALL. An operator reading the output wants to know the shape of the pyramid, not how
     * many rows happened to need a write.
     *
     * @return array<string, int> city size value => locations in that band
     */
    public function reclassifyCountry(string $countryCode): array
    {
        $rows = $this->locationRepository->findForClassification($countryCode);

        if ($rows === []) {
            return [];
        }

        $places = array_map(static fn (array $row): array => [
            'id'              => $row['id'],
            'population_size' => $row['population'],
            'is_capital'      => $row['isCapital'],
        ], $rows);

        /** @var array<string, list<string>> $idsBySize */
        $idsBySize = [];
        foreach (self::classify($places) as $place) {
            $idsBySize[$place['city_size']->value][] = $place['id'];
        }

        $connection   = $this->em->getConnection();
        $distribution = [];

        foreach ($idsBySize as $size => $ids) {
            // `city_size <> :size` keeps the write to rows whose band actually moved; the
            // returned figure deliberately ignores that and reports the band total.
            $connection->executeStatement(
                'UPDATE location SET city_size = :size WHERE id IN (:ids) AND city_size <> :size',
                ['size' => $size, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );

            $distribution[$size] = count($ids);
        }

        return $distribution;
    }
}
