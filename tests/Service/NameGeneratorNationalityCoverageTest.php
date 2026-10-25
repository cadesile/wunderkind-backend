<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Country;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Country cases outnumber name pools on purpose: the enum carries nations that only ever
 * appear as an owner's nationality, never as a world-generation target. That split is fine
 * right up until something picks a nationality from the enum and asks for a name.
 *
 * It did. Adding the UEFA nations took the enum to 71 cases, getRandomNationality() picked
 * uniformly across all of them, and "Unknown nationality: Cypriot" took down world-pack,
 * tier-pack and spoof-entrant generation. The same hole had existed quietly for one case
 * before that (South Korean), which is why these assert the rule rather than a country list.
 */
class NameGeneratorNationalityCoverageTest extends TestCase
{
    /**
     * The invariant that matters: a country the world generator can build for must be able
     * to name the players it generates.
     */
    public function testEveryGenerationCapableCountryHasANamePool(): void
    {
        $missing = [];

        foreach (Country::cases() as $country) {
            if ($country->isGenerationCapable() && !NameGeneratorService::hasNamePool($country->nationality())) {
                $missing[] = sprintf('%s (%s)', $country->value, $country->nationality());
            }
        }

        $this->assertSame([], $missing, sprintf(
            'Generation-capable countries with no name pool: %s. Either add the pool to '
            . 'NameGeneratorService::getNamePools() or drop the country from '
            . 'Country::isGenerationCapable().',
            implode(', ', $missing),
        ));
    }

    /** Whatever getRandomNationality() returns must be nameable — it feeds generateName() directly. */
    public function testRandomNationalityAlwaysHasAPool(): void
    {
        $service = new NameGeneratorService();

        for ($i = 0; $i < 300; $i++) {
            $nationality = $service->getRandomNationality();

            $this->assertTrue(
                NameGeneratorService::hasNamePool($nationality),
                sprintf('getRandomNationality() returned "%s", which generateName() cannot name.', $nationality),
            );
        }
    }

    /**
     * A Country case without a pool is allowed — this pins that it stays allowed, so nobody
     * "fixes" the above by forcing a pool for every nation in the enum.
     */
    public function testACountryCaseMayExistWithoutANamePool(): void
    {
        $this->assertGreaterThan(
            count(array_filter(Country::cases(), static fn (Country $c): bool => NameGeneratorService::hasNamePool($c->nationality()))),
            count(Country::cases()),
            'If every case gained a pool this test is obsolete rather than wrong — delete it deliberately.',
        );
    }
}
