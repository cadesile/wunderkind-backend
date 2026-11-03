<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Enum\Appearance\WorldRegion;
use App\Enum\Country;
use App\Service\CountryContent\CountryContentRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Guards the per-country content that is still authored in PHP.
 *
 * Written while moving place data out to the `location` table, because that move surfaced a
 * class of bug this file now pins: a duplicate key in a PHP array literal. PHP silently keeps
 * the last occurrence, so `WORLD_REGIONS` declared `'TR'` twice — SOUTHERN_EUROPE and then
 * MIDDLE_EAST — with no error, no warning, and nothing to tell a reader which one was live.
 * Four more keys were declared twice with identical values. All removed; the assertions below
 * are what stop them coming back.
 *
 * Duplicates cannot be detected at runtime (the literal has already collapsed by the time any
 * code sees it), so these tests assert the OUTCOMES instead: the correct value per country, and
 * complete coverage of the enum.
 */
class CountryContentRegistryTest extends TestCase
{
    /**
     * Turkey is MIDDLE_EAST, not SOUTHERN_EUROPE.
     *
     * The regression this file was created for. `WorldRegion`'s own docblock names Turkey and
     * Saudi Arabia as MIDDLE_EAST, and the duplicate key happened to resolve that way — so
     * deleting the stale line was a provably zero-behaviour change. This asserts the behaviour
     * rather than trusting that it stays accidental.
     */
    public function testTurkeyIsMiddleEast(): void
    {
        self::assertSame(WorldRegion::MIDDLE_EAST, CountryContentRegistry::worldRegion(Country::TR));
    }

    /** The other four keys that were declared twice, each asserted at its intended value. */
    public function testPreviouslyDuplicatedKeysKeepTheirValues(): void
    {
        self::assertSame(WorldRegion::BRITAIN_IRELAND, CountryContentRegistry::worldRegion(Country::SC));
        self::assertSame(WorldRegion::BRITAIN_IRELAND, CountryContentRegistry::worldRegion(Country::WA));
        self::assertSame(WorldRegion::WESTERN_EUROPE, CountryContentRegistry::worldRegion(Country::BE));
        self::assertSame(WorldRegion::EAST_ASIA, CountryContentRegistry::worldRegion(Country::KR));
        self::assertSame('east_asia', CountryContentRegistry::businessCluster(Country::KR));
    }

    /**
     * Every Country case has a skin-tone region.
     *
     * A missing entry is not an error — worldRegion() returns null and appearance generation
     * falls back to a uniform distribution — but it is always a mistake, because it makes every
     * generated player from that country look the same. Complete coverage is also what makes a
     * duplicate key impossible to hide behind: 71 cases, 71 mappings.
     */
    public function testEveryCountryHasAWorldRegion(): void
    {
        $missing = [];

        foreach (Country::cases() as $country) {
            if (CountryContentRegistry::worldRegion($country) === null) {
                $missing[] = $country->value;
            }
        }

        self::assertSame([], $missing, 'countries with no WorldRegion: ' . implode(', ', $missing));
    }

    /**
     * Every generation-capable country has the club-naming content it needs.
     *
     * `places` is excluded: it moved to the `location` table, and
     * CountryReadinessService checks it there against real rows.
     */
    public function testEveryGenerationCapableCountryHasNamingContent(): void
    {
        $problems = [];

        foreach (Country::generationCapable() as $country) {
            $missing = array_values(array_filter(
                CountryContentRegistry::missingCategories($country),
                static fn (string $category): bool => $category !== 'places',
            ));

            if ($missing !== []) {
                $problems[] = $country->value . ': ' . implode('/', $missing);
            }
        }

        self::assertSame([], $problems, implode('; ', $problems));
    }

    /** Suffix lists are non-trivial where they exist — one entry would make every club alike. */
    public function testSuffixListsAreSubstantial(): void
    {
        foreach (Country::generationCapable() as $country) {
            self::assertGreaterThanOrEqual(
                5,
                count(CountryContentRegistry::prestigeSuffixes($country)),
                "{$country->value} has too few prestige suffixes",
            );
            self::assertGreaterThanOrEqual(
                5,
                count(CountryContentRegistry::genericSuffixes($country)),
                "{$country->value} has too few generic suffixes",
            );
        }
    }

    /** Stadium formats must carry exactly one %s, or sprintf() silently produces nonsense. */
    public function testStadiumFormatsHaveASinglePlaceholder(): void
    {
        foreach (Country::cases() as $country) {
            foreach (CountryContentRegistry::stadiumFormats($country) as $format) {
                self::assertSame(
                    1,
                    substr_count($format, '%s'),
                    "{$country->value} stadium format '{$format}' should have exactly one %s",
                );
            }
        }
    }

    /** suffixLeadsName() is opt-in: only the countries that genuinely read name-first. */
    public function testSuffixLeadsNameIsOptIn(): void
    {
        $leading = array_values(array_filter(
            Country::cases(),
            static fn (Country $c): bool => CountryContentRegistry::suffixLeadsName($c),
        ));

        self::assertSame(
            ['PL', 'BE', 'SA', 'CO', 'EG', 'TN'],
            array_map(static fn (Country $c): string => $c->value, $leading),
        );
    }
}
