<?php
namespace App\Enum\Appearance;

/**
 * Groups the nationalities produced by NameGeneratorService into broad world
 * regions, and carries the skin-tone distribution for each.
 *
 * The weights are footballing-population distributions, not census ones: modern
 * British, French and Dutch squads carry a substantial non-white minority, which
 * a naive "European = pale" table would erase. Each region's weights are
 * percentages over SkinTone::cases() in enum order (lightest → darkest) and sum
 * to 100 — WorldRegionTest enforces both.
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

    /**
     * Demonym → region. Keys are lower-cased; lookup normalises the input, so
     * casing and stray whitespace from admin-entered data still resolve.
     */
    private const NATIONALITY_REGIONS = [
        'english'      => self::BRITAIN_IRELAND,
        'irish'        => self::BRITAIN_IRELAND,
        'french'       => self::WESTERN_EUROPE,
        'german'       => self::WESTERN_EUROPE,
        'dutch'        => self::WESTERN_EUROPE,
        'swedish'      => self::NORTHERN_EUROPE,
        'danish'       => self::NORTHERN_EUROPE,
        'spanish'      => self::SOUTHERN_EUROPE,
        'portuguese'   => self::SOUTHERN_EUROPE,
        'italian'      => self::SOUTHERN_EUROPE,
        'polish'       => self::EASTERN_EUROPE,
        'nigerian'     => self::WEST_AFRICA,
        'ghanaian'     => self::WEST_AFRICA,
        'ivorian'      => self::WEST_AFRICA,
        'senegalese'   => self::WEST_AFRICA,
        'brazilian'    => self::BRAZIL,
        'argentine'    => self::SOUTHERN_CONE,
        'japanese'     => self::EAST_ASIA,
        'south korean' => self::EAST_ASIA,
        'chinese'      => self::EAST_ASIA,
    ];

    /** Returns null for null, empty, or unrecognised nationalities. */
    public static function fromNationality(?string $nationality): ?self
    {
        if ($nationality === null) {
            return null;
        }
        $key = strtolower(trim($nationality));

        return self::NATIONALITY_REGIONS[$key] ?? null;
    }

    /**
     * Percentage weights keyed by SkinTone hex value, in SkinTone enum order.
     *
     * @return array<string, int>
     */
    public function skinToneWeights(): array
    {
        // [VERY_LIGHT, LIGHT, MEDIUM, TAN, BROWN, DARK]
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
        };

        return array_combine(
            array_map(static fn (SkinTone $t) => $t->value, SkinTone::cases()),
            $weights,
        );
    }
}
