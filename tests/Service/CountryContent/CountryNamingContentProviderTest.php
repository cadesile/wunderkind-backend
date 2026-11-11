<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\CountryContent\CountryNamingContentProvider;
use PHPUnit\Framework\TestCase;

/**
 * Override wins, registry is the fallback, and neither can surprise the other.
 *
 * CountryContentRegistryTest guards the FALLBACK — that the twenty-five authored countries
 * have complete lists in PHP. This guards the RESOLVED view, which is the one every consumer
 * now reads and which did not exist before.
 */
class CountryNamingContentProviderTest extends TestCase
{
    /** An authored country, so the registry half of every case has something real in it. */
    private const AUTHORED = Country::ES;

    public function testWithNoProfileAtAllItFallsBackToTheRegistry(): void
    {
        $provider = $this->provider(null);

        self::assertSame(
            CountryContentRegistry::prestigeSuffixes(self::AUTHORED),
            $provider->prestigeSuffixes(self::AUTHORED),
        );
    }

    public function testAProfileWithNoOverrideFallsBackToTheRegistry(): void
    {
        $provider = $this->provider(new CountryProfile(self::AUTHORED->value, 'Spain'));

        self::assertSame(
            CountryContentRegistry::genericSuffixes(self::AUTHORED),
            $provider->genericSuffixes(self::AUTHORED),
        );
        self::assertSame([], $provider->missingCategories(self::AUTHORED));
    }

    public function testAnOverrideWins(): void
    {
        $profile = new CountryProfile(self::AUTHORED->value, 'Spain');
        $profile->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        $provider = $this->provider($profile);

        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'], $provider->prestigeSuffixes(self::AUTHORED));
        // Only that category: the other two still come from PHP.
        self::assertSame(
            CountryContentRegistry::genericSuffixes(self::AUTHORED),
            $provider->genericSuffixes(self::AUTHORED),
        );
    }

    /**
     * The test that proves the CONTENT blocker is actually fixable.
     *
     * Forty-six Country cases have no registry entry at all, which is what makes the readiness
     * check fail for every new country. An override has to clear it, or none of this is worth
     * building.
     */
    public function testAnOverrideClearsTheBlockerOnACountryWithNoRegistryEntry(): void
    {
        $unauthored = $this->firstUnauthoredCountry();

        $bare = $this->provider(new CountryProfile($unauthored->value, 'Nowhere'));
        self::assertSame(
            CountryNamingContentProvider::CATEGORIES,
            $bare->missingCategories($unauthored),
            'precondition: this country has nothing from either source',
        );

        $profile = new CountryProfile($unauthored->value, 'Nowhere');
        $profile->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
        $profile->setGenericSuffixes(['Zeta', 'Eta', 'Theta', 'Iota', 'Kappa']);
        $profile->setStadiumFormats(['%s Park', 'Estadio %s']);

        self::assertSame([], $this->provider($profile)->missingCategories($unauthored));
    }

    public function testMissingCategoriesNeverMentionsPlaces(): void
    {
        // The registry's own static still reports it; this one must not, because places live
        // in the location table and the readiness check covers them separately.
        $unauthored = $this->firstUnauthoredCountry();

        self::assertContains('places', CountryContentRegistry::missingCategories($unauthored));
        self::assertNotContains('places', $this->provider(null)->missingCategories($unauthored));
    }

    public function testAClearedOverrideGoesBackToTheRegistry(): void
    {
        $profile = new CountryProfile(self::AUTHORED->value, 'Spain');
        $profile->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
        $profile->setPrestigeSuffixes(null);

        self::assertSame(
            CountryContentRegistry::prestigeSuffixes(self::AUTHORED),
            $this->provider($profile)->prestigeSuffixes(self::AUTHORED),
        );
    }

    /**
     * An empty list is stored as null, so it can never read as "authored as nothing".
     *
     * It matters because the value reaches $pool[array_rand($pool)] with no guard, and
     * array_rand([]) throws.
     */
    public function testAnEmptyListIsCoercedToNoOverride(): void
    {
        $profile = new CountryProfile(self::AUTHORED->value, 'Spain');
        $profile->setPrestigeSuffixes([]);

        self::assertNull($profile->getPrestigeSuffixes());
        self::assertSame(
            CountryContentRegistry::prestigeSuffixes(self::AUTHORED),
            $this->provider($profile)->prestigeSuffixes(self::AUTHORED),
        );
    }

    public function testDescribeReportsWhereEachCategoryCameFrom(): void
    {
        $profile = new CountryProfile(self::AUTHORED->value, 'Spain');
        $profile->setPrestigeSuffixes(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);

        $described = $this->provider($profile)->describe(self::AUTHORED);

        self::assertSame(CountryNamingContentProvider::SOURCE_OVERRIDE, $described['prestigeSuffixes']['source']);
        self::assertSame(CountryNamingContentProvider::SOURCE_REGISTRY, $described['genericSuffixes']['source']);

        $none = $this->provider(null)->describe($this->firstUnauthoredCountry());
        self::assertSame(CountryNamingContentProvider::SOURCE_NONE, $none['stadiumFormats']['source']);
    }

    public function testHasOverrideIsOnlyTrueForAStoredList(): void
    {
        $profile = new CountryProfile(self::AUTHORED->value, 'Spain');

        self::assertFalse($this->provider($profile)->hasOverride(self::AUTHORED, 'stadiumFormats'));

        $profile->setStadiumFormats(['%s Park', 'Estadio %s']);
        self::assertTrue($this->provider($profile)->hasOverride(self::AUTHORED, 'stadiumFormats'));
    }

    private function provider(?CountryProfile $profile): CountryNamingContentProvider
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturn($profile);

        return new CountryNamingContentProvider($repo);
    }

    /** Any Country with no naming content in PHP — there are forty-six of them. */
    private function firstUnauthoredCountry(): Country
    {
        foreach (Country::cases() as $country) {
            if (CountryContentRegistry::prestigeSuffixes($country) === []) {
                return $country;
            }
        }

        self::fail('every Country case is authored, which this test assumes is not so');
    }
}
