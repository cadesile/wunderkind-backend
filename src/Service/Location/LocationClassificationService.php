<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Enum\CitySize;
use App\Enum\Country;
use App\Repository\Location\LocationConfigRepository;
use App\Repository\Location\LocationRepository;
use App\Service\CountryContent\CountryContentRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assigns each location a BIG / MEDIUM / SMALL size, and persists it.
 *
 * WHY PERSIST SOMETHING DERIVABLE. The split is a property of the whole country's place set —
 * the top share by population BIG, the bottom share SMALL, the rest MEDIUM (the shares are
 * LocationConfig's, 20/50 by default), with a capital forced BIG
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
 *
 * IT ALSO FLAGS THE CAPITAL, because the forced-BIG override is useless if nothing sets the
 * flag. Curated place rows set it for 25 countries; the other 46 are built by the AI town pull,
 * which returns bare names. flagCapital() applies CountryContentRegistry's capital list at the
 * top of every recompute, so the rule finally fires everywhere and old rows heal themselves.
 *
 * RE-BANDING HAS A CONSEQUENCE IN LAND. A town that moves down is a town whose plot target has
 * just fallen, so reclassifyCountry() ends by asking LandPlotProvisioner to retire the surplus.
 * Only free plots go; an owned one survives until its claim ends. That call lives here rather
 * than at each trigger so the two cannot come apart.
 */
final class LocationClassificationService
{
    /**
     * The fallback split, used when no configuration is supplied.
     *
     * THE LIVE VALUES ARE LocationConfig's. These stay because classify() is static and has a
     * caller that has no container — NpcClubGenerationService::classifyPlaces() — and because
     * a fresh install needs the shape it always had. reclassifyCountry() reads the config.
     */
    public const BIG_SHARE = 0.20;

    /** Share classified SMALL, counted from the bottom. */
    public const SMALL_SHARE = 0.50;

    public function __construct(
        private readonly LocationRepository       $locationRepository,
        private readonly LandPlotProvisioner      $plotProvisioner,
        private readonly LocationConfigRepository $configRepository,
        private readonly EntityManagerInterface   $em,
    ) {}

    /**
     * The pure algorithm: sort by population descending and band the result.
     *
     * Byte-for-byte the logic that lived in NpcClubGenerationService::classifyPlaces(),
     * including the detail that a capital is forced BIG *before* the rank bands are consulted
     * (Nigeria's Abuja, not Lagos, is the precedent) and that the returned array is re-indexed
     * in population order rather than left in input order. Callers rely on both.
     *
     * THE SHARES ARE ARGUMENTS NOW, defaulting to the constants above. Passing them keeps this
     * pure and keeps NpcClubGenerationService — which has no container — calling it unchanged.
     *
     * @param list<array{name?: string, population_size: int, region?: string, is_capital?: bool}> $places
     * @param ?float $bigShare   0.0-1.0, or null for the default
     * @param ?float $smallShare 0.0-1.0, or null for the default
     *
     * @return list<array{population_size: int, is_capital?: bool, city_size: CitySize, ...}>
     */
    public static function classify(array $places, ?float $bigShare = null, ?float $smallShare = null): array
    {
        $sorted = $places;
        usort($sorted, static fn (array $a, array $b) => $b['population_size'] <=> $a['population_size']);

        $count      = count($sorted);
        $bigCutoff  = (int) ceil($count * ($bigShare   ?? self::BIG_SHARE));
        $smallCount = (int) ceil($count * ($smallShare ?? self::SMALL_SHARE));

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
     * Mark this country's capital before the bands are computed.
     *
     * THE OVERRIDE AT classify():69 WAS INERT FOR MOST OF THE REGISTRY. `is_capital` was only
     * ever set from a curated place row, and curated places exist for 25 of the 71 countries.
     * The other 46 are built entirely by the AI town pull, which returns names and nothing else
     * — so their capitals were banded on estimated population like any other town, and the
     * forced-BIG rule never fired. CountryContentRegistry::capitalAliases() is the fact that was
     * missing; this is where it gets applied.
     *
     * HERE RATHER THAN AT ROW CREATION, which is what makes it self-healing. Every path that
     * adds or prices a town ends in a recompute, so a capital that arrived through the pull
     * months ago is flagged the next time its country is reclassified — no backfill command, and
     * no import path that can forget.
     *
     * BEFORE findForClassification(), NOT AFTER. The flag is read by classify() to force the
     * town BIG, so setting it afterwards would leave the capital banded on population alone
     * until something else triggered another recompute.
     *
     * Normalised on both sides: town_normalised is ClubNameNormalizer output and so is every
     * alias, so "Warszawa" and "Warsaw" reach the one row without a LIKE or a collation.
     *
     * @return int rows flagged — 0 is the normal answer once the capital is already marked
     */
    private function flagCapital(string $countryCode): int
    {
        $country = Country::tryFrom(strtoupper(trim($countryCode)));

        if ($country === null) {
            return 0;
        }

        $aliases = CountryContentRegistry::capitalAliases($country);

        if ($aliases === []) {
            return 0;
        }

        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE location
                SET is_capital = true
              WHERE country_code = :code
                AND town_normalised IN (:names)
                AND is_capital = false',
            ['code' => $country->value, 'names' => $aliases],
            ['names' => ArrayParameterType::STRING],
        );
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
        $this->flagCapital($countryCode);

        $rows = $this->locationRepository->findForClassification($countryCode);

        if ($rows === []) {
            return [];
        }

        $places = array_map(static fn (array $row): array => [
            'id'              => $row['id'],
            'population_size' => $row['population'],
            'is_capital'      => $row['isCapital'],
        ], $rows);

        $config = $this->configRepository->getConfig();

        /** @var array<string, list<string>> $idsBySize */
        $idsBySize = [];
        foreach (self::classify($places, $config->getBigShare(), $config->getSmallShare()) as $place) {
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

        // RE-BANDING IS THE ONLY THING THAT MOVES A PLOT TARGET DOWNWARD, so the reap belongs
        // here rather than at the three call sites — a caller that reclassified and forgot to
        // reap would leave a demoted town selling land its size no longer justifies, and the
        // discrepancy would be invisible until somebody bought it. Occupied plots are left
        // alone and collected later, when their claim ends; see LandPlotProvisioner::reapSurplus().
        $this->plotProvisioner->reapSurplusForCountry($countryCode);

        return $distribution;
    }
}
