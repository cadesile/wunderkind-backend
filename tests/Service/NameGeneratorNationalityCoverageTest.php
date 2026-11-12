<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\CountryContent\NamePoolRegistry;
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
            if ($country->isGenerationCapable() && !NamePoolRegistry::has($country->nationality())) {
                $missing[] = sprintf('%s (%s)', $country->value, $country->nationality());
            }
        }

        $this->assertSame([], $missing, sprintf(
            'Generation-capable countries with no name pool: %s. Either add the pool to '
            . 'NamePoolRegistry or drop the country from '
            . 'Country::isGenerationCapable().',
            implode(', ', $missing),
        ));
    }

    /**
     * Exactly as many pools as there are demonyms holding one — no duplicates, no orphans.
     *
     * THE DUPLICATE THIS GUARDS AGAINST WAS REAL. 'South Korean' was declared TWICE in the
     * pool literal; PHP keeps the last, so the first block was unreachable and a reader
     * editing it changed nothing. It is deleted, and this pins the count so a second one
     * cannot creep back in unnoticed — a duplicate key costs no syntax error and no test
     * failure anywhere else.
     *
     * The second assertion is the stronger one: every pool key must be a demonym Country
     * knows. A pool keyed on something it does not is a pool nothing can ever ask for — the
     * shape the old standalone 'Polish' addendum had before Poland got an enum case.
     */
    public function testEveryPoolKeyIsARealDemonymAndNoneAreDuplicated(): void
    {
        $orphans = array_values(array_filter(
            NamePoolRegistry::pooledNationalities(),
            static fn (string $nationality): bool => Country::fromNationality($nationality) === null,
        ));

        self::assertSame([], $orphans, sprintf(
            'Pools keyed on demonyms no Country case carries, so nothing can request them: %s.',
            implode(', ', $orphans),
        ));

        $pooled = array_values(array_filter(
            Country::cases(),
            static fn (Country $c): bool => NamePoolRegistry::has($c->nationality()),
        ));

        $this->assertCount(
            32,
            $pooled,
            'The number of authored pools changed. That is fine if deliberate — update the '
            . 'number — but a sudden drop of one usually means a duplicate array key silently '
            . 'swallowed a pool.',
        );
    }

    /** Whatever getRandomNationality() returns must be nameable — it feeds generateName() directly. */
    public function testRandomNationalityAlwaysHasAPool(): void
    {
        $service = $this->shippedPoolService();

        for ($i = 0; $i < 300; $i++) {
            $nationality = $service->getRandomNationality();

            $this->assertTrue(
                $service->hasNamePool($nationality),
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
            count(array_filter(Country::cases(), static fn (Country $c): bool => NamePoolRegistry::has($c->nationality()))),
            count(Country::cases()),
            'If every case gained a pool this test is obsolete rather than wrong — delete it deliberately.',
        );
    }

    /**
     * The service over the SHIPPED pools.
     *
     * A stubbed repository returns null from findByCode() and an empty override set, so the
     * provider resolves to NamePoolRegistry — which is what these tests mean by "the pool".
     * Keeping it a plain TestCase matters: a fact about what ships must be assertable without
     * a kernel or a database.
     */
    private function shippedPoolService(): NameGeneratorService
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn(null);
        $repo->method('namePoolOverrides')->willReturn([]);

        return new NameGeneratorService(new NamePoolProvider($repo));
    }
}
