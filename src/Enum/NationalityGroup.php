<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Appearance\WorldRegion;
use App\Service\CountryContent\CountryContentRegistry;

/**
 * How the admin pool screens group nationalities in their picker.
 *
 * SEVEN HEADINGS, BECAUSE SEVENTY-ONE CHECKBOXES IN ONE LIST IS NOT A PICKER. Each is a
 * sticky header row the operator can tick to take the whole group.
 *
 * DERIVED FROM WorldRegion RATHER THAN HAND-LISTED. The design handoff asked for a fresh
 * country-to-group constant "beside NameGeneratorService". That would have been the third
 * copy of the country list: Country::nationalityMap() has one, and
 * CountryContentRegistry::WORLD_REGIONS already maps all 71 codes to a WorldRegion. Three
 * copies drift, and the one that drifts is the one nobody is looking at. So this folds the
 * fourteen regions into seven.
 *
 * THE EXCEPTIONS ARE NOT FUDGES, and there are twelve of them. WorldRegion lives in
 * App\Enum\Appearance and exists to weight skin tones — see its skinWeights(). Its groupings
 * were chosen for that, not for a geography menu, so a straight fold puts the Baltics under
 * "Nordic" and the Balkans under what becomes "Western Europe". Those are wrong on a screen
 * an operator reads, so they are corrected here, where the reason is visible, rather than by
 * editing a table that something else depends on for a different purpose.
 *
 * ISRAEL IS THE ONE JUDGEMENT CALL. WorldRegion has it in SOUTHERN_EUROPE, and it does play
 * in UEFA, so "Europe" is defensible. It sits under Asia & Middle East here because that is
 * what the group is called and where an operator would look for it. One line to change if
 * that reads wrong in practice.
 */
enum NationalityGroup: string
{
    case UK_IRELAND       = 'uk_ireland';
    case WESTERN_EUROPE   = 'western_europe';
    case NORDIC           = 'nordic';
    case CENTRAL_EASTERN  = 'central_eastern_europe';
    case AMERICAS         = 'americas';
    case AFRICA           = 'africa';
    case ASIA_MIDDLE_EAST = 'asia_middle_east';

    public function label(): string
    {
        return match ($this) {
            self::UK_IRELAND       => 'UK & Ireland',
            self::WESTERN_EUROPE   => 'Western Europe',
            self::NORDIC           => 'Nordic',
            self::CENTRAL_EASTERN  => 'Central & Eastern Europe',
            self::AMERICAS         => 'Americas',
            self::AFRICA           => 'Africa',
            self::ASIA_MIDDLE_EAST => 'Asia & Middle East',
        };
    }

    /**
     * Countries whose WorldRegion is right for skin tone and wrong for this menu.
     *
     * Baltics: NORTHERN_EUROPE with the Nordics. Balkans: SOUTHERN_EUROPE with Iberia and
     * Italy. Israel: SOUTHERN_EUROPE, see the class note.
     */
    private const OVERRIDES = [
        'EE' => self::CENTRAL_EASTERN, 'LV' => self::CENTRAL_EASTERN, 'LT' => self::CENTRAL_EASTERN,
        'AL' => self::CENTRAL_EASTERN, 'BA' => self::CENTRAL_EASTERN, 'HR' => self::CENTRAL_EASTERN,
        'ME' => self::CENTRAL_EASTERN, 'MK' => self::CENTRAL_EASTERN, 'RS' => self::CENTRAL_EASTERN,
        'SI' => self::CENTRAL_EASTERN, 'XK' => self::CENTRAL_EASTERN,
        'IL' => self::ASIA_MIDDLE_EAST,
    ];

    /**
     * Null when the country has no WorldRegion at all.
     *
     * Every Country case has one today — NationalityGroupTest pins that — so this is for the
     * case where somebody adds a country and not its region. Nullable rather than throwing:
     * the picker already renders unrecognised nationalities in a catch-all group, and a new
     * country belonging there for one deploy is better than an admin page that 500s.
     */
    public static function forCountry(Country $country): ?self
    {
        $region = CountryContentRegistry::worldRegion($country);

        if ($region === null) {
            return null;
        }

        return self::OVERRIDES[$country->value]
            ?? match ($region) {
                WorldRegion::BRITAIN_IRELAND  => self::UK_IRELAND,
                WorldRegion::NORTHERN_EUROPE  => self::NORDIC,
                WorldRegion::EASTERN_EUROPE   => self::CENTRAL_EASTERN,
                WorldRegion::WESTERN_EUROPE,
                WorldRegion::SOUTHERN_EUROPE  => self::WESTERN_EUROPE,
                WorldRegion::NORTH_AMERICA,
                WorldRegion::BRAZIL,
                WorldRegion::SOUTHERN_CONE,
                WorldRegion::MESOAMERICA_ANDES => self::AMERICAS,
                WorldRegion::WEST_AFRICA,
                WorldRegion::NORTH_AFRICA     => self::AFRICA,
                WorldRegion::EAST_ASIA,
                WorldRegion::MIDDLE_EAST,
                WorldRegion::SOUTH_ASIA       => self::ASIA_MIDDLE_EAST,
            };
    }

    /** Null for an unrecognised demonym, which the caller shows in a catch-all group. */
    public static function forNationality(?string $nationality): ?self
    {
        $country = $nationality === null ? null : Country::fromNationality(trim($nationality));

        return $country === null ? null : self::forCountry($country);
    }

    /** Declaration order, which is the order the picker renders them in. */
    public static function ordered(): array
    {
        return self::cases();
    }
}
