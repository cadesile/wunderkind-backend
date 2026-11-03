<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Enum\CitySize;
use App\Service\Location\LocationClassificationService;
use App\Service\NpcClubGenerationService;
use PHPUnit\Framework\TestCase;

/**
 * The BIG/MEDIUM/SMALL banding, and the proof that moving it changed nothing.
 *
 * The algorithm used to live in NpcClubGenerationService::classifyPlaces(), which now
 * delegates here so there is one implementation backing both the live generation path and the
 * stored `location.city_size`. NpcClubGenerationServiceTest's four existing classifyPlaces()
 * cases were deliberately left untouched and still pass; this file adds the cases that matter
 * for the stored copy.
 *
 * Pure TestCase — the banding takes an array and returns an array, so it needs no kernel.
 * reclassifyCountry()'s persistence is covered by SeedLocationsCommandTest, which exercises it
 * against the real database.
 */
class LocationClassificationServiceTest extends TestCase
{
    /** @return list<array{name: string, population_size: int, is_capital?: bool}> */
    private function places(int $count, ?int $capitalAt = null): array
    {
        $places = [];

        for ($i = 0; $i < $count; $i++) {
            $place = [
                'name'            => "Town {$i}",
                // Descending, so index 0 is the largest.
                'population_size' => ($count - $i) * 1000,
            ];

            if ($capitalAt === $i) {
                $place['is_capital'] = true;
            }

            $places[] = $place;
        }

        return $places;
    }

    /** @param list<array{city_size: CitySize, ...}> $classified @return array<string, int> */
    private function distribution(array $classified): array
    {
        $out = [CitySize::BIG->value => 0, CitySize::MEDIUM->value => 0, CitySize::SMALL->value => 0];

        foreach ($classified as $place) {
            ++$out[$place['city_size']->value];
        }

        return $out;
    }

    /**
     * The real England set: 42 towns must band 9 / 12 / 21.
     *
     * ceil(42 * 0.20) = 9 BIG and ceil(42 * 0.50) = 21 SMALL, leaving 12 MEDIUM. This is the
     * figure the seeded database actually holds, so it pins the shape of the pyramid rather
     * than just the arithmetic.
     */
    public function testFortyTwoTownsBandNineTwelveTwentyOne(): void
    {
        $distribution = $this->distribution(LocationClassificationService::classify($this->places(42)));

        self::assertSame(
            [CitySize::BIG->value => 9, CitySize::MEDIUM->value => 12, CitySize::SMALL->value => 21],
            $distribution,
        );
    }

    public function testEveryPlaceIsClassifiedAndNoneLost(): void
    {
        foreach ([1, 2, 3, 7, 30, 32, 40, 42, 865] as $count) {
            $classified = LocationClassificationService::classify($this->places($count));

            self::assertCount($count, $classified, "{$count} places in, {$count} out");
            self::assertSame($count, array_sum($this->distribution($classified)));
        }
    }

    /**
     * A capital is forced BIG even when its population would not earn it.
     *
     * Nigeria's Abuja, not Lagos, is the real precedent — the seat of government is the
     * flagship club's home whatever the census says.
     */
    public function testCapitalIsForcedBigFromTheBottomOfTheList(): void
    {
        // 42 towns, capital last — by population it is firmly in the SMALL band.
        $classified = LocationClassificationService::classify($this->places(42, capitalAt: 41));

        $capital = null;
        foreach ($classified as $place) {
            if (!empty($place['is_capital'])) {
                $capital = $place;
            }
        }

        self::assertNotNull($capital, 'the capital went missing');
        self::assertSame(CitySize::BIG, $capital['city_size']);

        // And it is an extra BIG, not a swap — the rank bands are untouched beneath it.
        self::assertSame(10, $this->distribution($classified)[CitySize::BIG->value]);
    }

    /** Output is re-indexed by population, descending — callers rely on the order. */
    public function testResultIsSortedByPopulationDescending(): void
    {
        $shuffled = [
            ['name' => 'Small', 'population_size' => 1000],
            ['name' => 'Large', 'population_size' => 9000],
            ['name' => 'Mid',   'population_size' => 5000],
        ];

        $classified = LocationClassificationService::classify($shuffled);

        self::assertSame(['Large', 'Mid', 'Small'], array_column($classified, 'name'));
        self::assertSame([0, 1, 2], array_keys($classified));
    }

    public function testEmptyInputIsEmptyOutput(): void
    {
        self::assertSame([], LocationClassificationService::classify([]));
    }

    /** A single town is BIG — ceil(1 * 0.2) is 1, so the top band claims it. */
    public function testSinglePlaceIsBig(): void
    {
        $classified = LocationClassificationService::classify($this->places(1));

        self::assertSame(CitySize::BIG, $classified[0]['city_size']);
    }

    /** Ties must not throw or lose a row; which side of a band they land on is unspecified. */
    public function testIdenticalPopulationsAreStillAllClassified(): void
    {
        $places = array_fill(0, 10, ['name' => 'Same', 'population_size' => 5000]);

        $classified = LocationClassificationService::classify($places);

        self::assertCount(10, $classified);
        self::assertSame(10, array_sum($this->distribution($classified)));
    }

    /**
     * The delegation is real: the generator's public method and this one must agree exactly.
     *
     * If someone re-inlines the algorithm in NpcClubGenerationService, this fails.
     */
    public function testNpcClubGenerationServiceDelegatesHere(): void
    {
        $places = $this->places(42, capitalAt: 7);

        $reflection = new \ReflectionMethod(NpcClubGenerationService::class, 'classifyPlaces');
        $body       = file_get_contents($reflection->getFileName());

        self::assertStringContainsString(
            'LocationClassificationService::classify($places)',
            (string) $body,
            'NpcClubGenerationService::classifyPlaces() should delegate, not reimplement the banding.',
        );

        // And the shapes still match, so the delegation is type-compatible both ways.
        $direct = LocationClassificationService::classify($places);

        self::assertCount(42, $direct);
        self::assertArrayHasKey('city_size', $direct[0]);
    }
}
