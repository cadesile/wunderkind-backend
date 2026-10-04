<?php
namespace App\Enum\Appearance;

use App\Enum\Country;
use App\Service\CountryContent\CountryContentRegistry;

/**
 * Groups the nationalities produced by NameGeneratorService into broad world
 * regions, and carries the skin-tone distribution for each.
 *
 * The weights are footballing-population distributions, not census ones: modern
 * British, French and Dutch squads carry a substantial non-white minority, which
 * a naive "European = pale" table would erase. Each region's weights are
 * percentages over SkinId::cases() in enum order (lightest → darkest) and sum
 * to 100 — WorldRegionTest enforces both.
 *
 * The demonym → region mapping itself lives in CountryContentRegistry, keyed by
 * Country, not here — this avoids a third hand-duplicated copy of the nationality
 * list (see CountryContentRegistry's own docblock). 'Polish' is the one exception:
 * it has no Country case, so it keeps a tiny standalone fallback below.
 */
enum WorldRegion
{
    case BRITAIN_IRELAND;
    case WESTERN_EUROPE;
    case NORTHERN_EUROPE;
    case SOUTHERN_EUROPE;
    case EASTERN_EUROPE;
    case WEST_AFRICA;
    case BRAZIL;
    case SOUTHERN_CONE;
    case EAST_ASIA;
    case NORTH_AMERICA;

    /** The one demonym with no Country case (see class docblock). */
    private const LEGACY_NATIONALITY_REGIONS = [
        'polish' => self::EASTERN_EUROPE,
    ];

    /** Returns null for null, empty, or unrecognised nationalities. */
    public static function fromNationality(?string $nationality): ?self
    {
        if ($nationality === null) {
            return null;
        }
        $trimmed = trim($nationality);

        $country = Country::fromNationality($trimmed);
        if ($country !== null) {
            return CountryContentRegistry::worldRegion($country);
        }

        return self::LEGACY_NATIONALITY_REGIONS[strtolower($trimmed)] ?? null;
    }

    /**
     * Percentage weights keyed by SkinId value, in SkinId enum order.
     *
     * @return array<string, int>
     */
    public function skinWeights(): array
    {
        // [S1, S2, S3, S4, S5, S6] (lightest → darkest)
        $weights = match ($this) {
            self::BRITAIN_IRELAND => [40, 25, 10,  6, 11,  8],
            self::WESTERN_EUROPE  => [33, 24, 11,  7, 14, 11],
            self::NORTHERN_EUROPE => [50, 27,  8,  4,  6,  5],
            self::SOUTHERN_EUROPE => [22, 38, 24,  8,  5,  3],
            self::EASTERN_EUROPE  => [55, 32,  9,  2,  1,  1],
            self::WEST_AFRICA     => [ 0,  0,  0,  1, 44, 55],
            self::BRAZIL          => [12, 20, 24, 18, 15, 11],
            self::SOUTHERN_CONE   => [40, 34, 18,  5,  2,  1],
            self::EAST_ASIA       => [26, 46, 24,  4,  0,  0],
            self::NORTH_AMERICA   => [20, 28, 18, 10, 14, 10],
        };

        return array_combine(
            array_map(static fn (SkinId $s) => $s->value, SkinId::cases()),
            $weights,
        );
    }
}
