<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Country;
use App\Enum\NationalityGroup;
use PHPUnit\Framework\TestCase;

/**
 * The seven headings the pool pickers group nationalities under.
 *
 * The point of these is totality and the overrides. The fold itself is mechanical; what is
 * worth pinning is that nothing falls through it, and that the twelve countries whose
 * WorldRegion is right for skin tone and wrong for a geography menu stay corrected.
 */
class NationalityGroupTest extends TestCase
{
    public function testEveryCountryLandsInAGroup(): void
    {
        $missing = array_values(array_filter(
            Country::cases(),
            static fn (Country $c): bool => NationalityGroup::forCountry($c) === null,
        ));

        self::assertSame([], array_map(static fn (Country $c): string => $c->value, $missing),
            'a country with no WorldRegion would fall into the picker\'s catch-all');
    }

    public function testEveryNationalityResolvesThroughItsDemonym(): void
    {
        // The picker looks groups up by demonym, not by code, because that is what the
        // generate endpoints take and what the entities store.
        foreach (Country::nationalityMap() as $code => $nationality) {
            self::assertNotNull(
                NationalityGroup::forNationality($nationality),
                \sprintf('%s (%s) should resolve', $nationality, $code),
            );
        }
    }

    /** The Baltics are not Nordic, whatever the skin-tone table says. */
    public function testTheBalticsAreCentralAndEastern(): void
    {
        foreach (['EE', 'LV', 'LT'] as $code) {
            self::assertSame(
                NationalityGroup::CENTRAL_EASTERN,
                NationalityGroup::forCountry(Country::from($code)),
                $code,
            );
        }
    }

    /** Nor are the Balkans Western European, which is where the raw fold puts them. */
    public function testTheBalkansAreCentralAndEastern(): void
    {
        foreach (['AL', 'BA', 'HR', 'ME', 'MK', 'RS', 'SI', 'XK'] as $code) {
            self::assertSame(
                NationalityGroup::CENTRAL_EASTERN,
                NationalityGroup::forCountry(Country::from($code)),
                $code,
            );
        }
    }

    public function testTheFoldStillWorksWhereItIsRight(): void
    {
        $expected = [
            'EN' => NationalityGroup::UK_IRELAND,
            'SC' => NationalityGroup::UK_IRELAND,
            'FR' => NationalityGroup::WESTERN_EUROPE,
            'ES' => NationalityGroup::WESTERN_EUROPE,   // no Southern bucket in the design
            'SE' => NationalityGroup::NORDIC,
            'PL' => NationalityGroup::CENTRAL_EASTERN,
            'BR' => NationalityGroup::AMERICAS,
            'MX' => NationalityGroup::AMERICAS,
            'NG' => NationalityGroup::AFRICA,
            'EG' => NationalityGroup::AFRICA,
            'JP' => NationalityGroup::ASIA_MIDDLE_EAST,
            'SA' => NationalityGroup::ASIA_MIDDLE_EAST,
            'IL' => NationalityGroup::ASIA_MIDDLE_EAST, // the one judgement call
        ];

        foreach ($expected as $code => $group) {
            self::assertSame($group, NationalityGroup::forCountry(Country::from($code)), $code);
        }
    }
}
