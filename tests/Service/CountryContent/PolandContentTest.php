<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Enum\Country;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Poland's generation content, authored per docs/world-generation/adding-a-country.md.
 * Asserts the properties that make the content usable rather than the exact
 * values, so adding or correcting a city doesn't break the suite.
 */
class PolandContentTest extends TestCase
{
    public function testPolandIsGenerationCapableAndAdminVisible(): void
    {
        $this->assertTrue(Country::PL->isGenerationCapable());
        $this->assertSame('Poland', Country::generationCapableLabels()['PL'] ?? null);
    }

    public function testNoContentCategoryIsMissing(): void
    {
        $this->assertSame(
            [],
            CountryContentRegistry::missingCategories(Country::PL),
            'A missing category silently degrades to generic placeholder content.',
        );
    }

    /**
     * The runbook asks for ~28-34 so classifyPlaces()'s 20/30/50 BIG/MEDIUM/SMALL
     * split has enough spread to populate all eight tiers with variety.
     */
    public function testPlacesAreNumerousAndWellFormed(): void
    {
        $places = CountryContentRegistry::places(Country::PL);

        $this->assertNotNull($places);
        $this->assertGreaterThanOrEqual(28, count($places));

        foreach ($places as $place) {
            $this->assertArrayHasKey('name', $place);
            $this->assertArrayHasKey('population_size', $place);
            $this->assertArrayHasKey('region', $place);
            $this->assertNotSame('', trim($place['name']));
            $this->assertGreaterThan(0, $place['population_size']);
        }

        $names = array_column($places, 'name');
        $this->assertSame(array_unique($names), $names, 'Duplicate place names waste a generation slot.');
    }

    /** classifyPlaces() forces the capital to BIG, so exactly one must be flagged. */
    public function testExactlyOneCapital(): void
    {
        $capitals = array_values(array_filter(
            CountryContentRegistry::places(Country::PL),
            static fn (array $p): bool => ($p['is_capital'] ?? false) === true,
        ));

        $this->assertCount(1, $capitals);
        $this->assertSame('Warszawa', $capitals[0]['name']);
    }

    public function testEveryStadiumFormatHasExactlyOnePlaceholder(): void
    {
        $formats = CountryContentRegistry::stadiumFormats(Country::PL);

        $this->assertNotEmpty($formats);
        foreach ($formats as $format) {
            $this->assertSame(1, substr_count($format, '%s'), sprintf('"%s" must take exactly one place name', $format));
        }
    }

    public function testSuffixPoolsAreDistinctAndNonEmpty(): void
    {
        $prestige = CountryContentRegistry::prestigeSuffixes(Country::PL);
        $generic  = CountryContentRegistry::genericSuffixes(Country::PL);

        $this->assertGreaterThanOrEqual(8, count($prestige));
        $this->assertGreaterThanOrEqual(12, count($generic));
        $this->assertSame([], array_intersect($prestige, $generic), 'A token in both pools blurs the big/small-city distinction.');
    }

    /**
     * The reason NpcClubGenerationService grew a name-order option at all. Polish
     * clubs are uniformly "{club} {place}" — Legia Warszawa, Górnik Zabrze — and
     * the default composition would emit "Warszawa Legia".
     */
    public function testPolandComposesClubNamesClubFirst(): void
    {
        $this->assertTrue(CountryContentRegistry::suffixLeadsName(Country::PL));
    }

    /**
     * Club-first order stays opt-in, never a new default — so this pins the exact set rather
     * than just "everything except Poland". Each of these has the same justification Poland
     * does: Club Brugge, Al Ahli Jeddah, Deportivo Cali, Al Masry Port Said and Espérance
     * Tunis are how those clubs are actually written, and the default composition would emit
     * "Brugge Club" / "Jeddah Al Ahli". Adding a country here is a deliberate act; a country
     * appearing here by accident is what this test is for.
     */
    public function testOnlyTheDeclaredCountriesComposeClubNamesClubFirst(): void
    {
        $expected = [Country::PL, Country::BE, Country::SA, Country::CO, Country::EG, Country::TN];

        foreach (Country::cases() as $country) {
            $leads = CountryContentRegistry::suffixLeadsName($country);

            if (in_array($country, $expected, true)) {
                $this->assertTrue($leads, sprintf('%s is declared club-first', $country->value));

                continue;
            }

            $this->assertFalse(
                $leads,
                sprintf('%s must keep "{place} {suffix}" composition', $country->value),
            );
        }
    }

    public function testPolishNamePoolExistsForGeneratedPlayers(): void
    {
        $this->assertTrue(NameGeneratorService::hasNamePool('Polish'));
    }
}
