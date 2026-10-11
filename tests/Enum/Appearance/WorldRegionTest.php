<?php
namespace App\Tests\Enum\Appearance;

use App\Enum\Appearance\SkinId;
use App\Enum\Appearance\WorldRegion;
use App\Enum\Country;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

class WorldRegionTest extends TestCase
{
    /** Every nationality the generator can produce must map to a region. */
    public function testEveryGeneratedNationalityMaps(): void
    {
        $nats = NameGeneratorService::nationalities();

        $this->assertNotEmpty($nats, 'NameGeneratorService::nationalities() should not be empty');

        foreach ($nats as $nat) {
            $this->assertInstanceOf(
                WorldRegion::class,
                WorldRegion::fromNationality($nat),
                sprintf('Nationality "%s" has no WorldRegion mapping', $nat),
            );
        }
    }

    /**
     * The inverse of the test above, and the one that actually bites: a region
     * can carry a full weight table and still be unreachable because no country
     * maps to it. EASTERN_EUROPE sat in exactly that state until PL was added —
     * its weights existed, were covered by the sum-to-100 test, and could never
     * be selected for a generated entity.
     */
    public function testEveryRegionIsReachableFromSomeCountry(): void
    {
        $reached = [];
        foreach (Country::cases() as $country) {
            $region = CountryContentRegistry::worldRegion($country);
            if ($region !== null) {
                $reached[$region->name][] = $country->value;
            }
        }

        foreach (WorldRegion::cases() as $region) {
            $this->assertArrayHasKey(
                $region->name,
                $reached,
                sprintf(
                    '%s has skin weights but no Country maps to it, so no generated entity can ever use them. '
                    . 'Either map a country to it in CountryContentRegistry::WORLD_REGIONS or remove the region.',
                    $region->name,
                ),
            );
        }
    }

    /** Every country must resolve to a region, or its entities fall back to a uniform skin roll. */
    public function testEveryCountryHasARegion(): void
    {
        foreach (Country::cases() as $country) {
            $this->assertInstanceOf(
                WorldRegion::class,
                CountryContentRegistry::worldRegion($country),
                sprintf('Country %s has no WorldRegion, so its entities get a uniform skin distribution', $country->value),
            );
        }
    }

    /**
     * Poland resolves through the ordinary Country chain. It used to be a
     * hardcoded 'polish' fallback inside this enum precisely because it had a
     * name pool but no Country case; both halves of that workaround are gone,
     * and this is what must keep working without them.
     */
    public function testPolishResolvesThroughTheCountryChain(): void
    {
        $this->assertSame(Country::PL, Country::fromNationality('Polish'));
        $this->assertSame(WorldRegion::EASTERN_EUROPE, WorldRegion::fromNationality('Polish'));
        $this->assertSame(WorldRegion::EASTERN_EUROPE, WorldRegion::fromNationality('  polish  '));
        $this->assertSame(WorldRegion::EASTERN_EUROPE, CountryContentRegistry::worldRegion(Country::PL));
    }

    public function testUnknownAndNullNationalityYieldNull(): void
    {
        $this->assertNull(WorldRegion::fromNationality(null));
        $this->assertNull(WorldRegion::fromNationality(''));
        $this->assertNull(WorldRegion::fromNationality('Martian'));
    }

    public function testMappingIsWhitespaceAndCaseTolerant(): void
    {
        $this->assertSame(WorldRegion::WEST_AFRICA, WorldRegion::fromNationality('  Nigerian '));
        $this->assertSame(WorldRegion::WEST_AFRICA, WorldRegion::fromNationality('nigerian'));
    }

    public function testRepresentativeMappings(): void
    {
        $this->assertSame(WorldRegion::BRITAIN_IRELAND, WorldRegion::fromNationality('English'));
        $this->assertSame(WorldRegion::WEST_AFRICA,     WorldRegion::fromNationality('Senegalese'));
        $this->assertSame(WorldRegion::BRAZIL,          WorldRegion::fromNationality('Brazilian'));
        $this->assertSame(WorldRegion::SOUTHERN_CONE,   WorldRegion::fromNationality('Argentine'));
        $this->assertSame(WorldRegion::EAST_ASIA,       WorldRegion::fromNationality('Japanese'));
    }

    /** Weights must cover every SkinId, in enum order, and sum to 100. */
    public function testEveryRegionHasCompleteNormalisedWeights(): void
    {
        $expectedKeys = array_map(static fn (SkinId $s) => $s->value, SkinId::cases());

        foreach (WorldRegion::cases() as $region) {
            $weights = $region->skinWeights();
            $this->assertSame(
                $expectedKeys,
                array_keys($weights),
                sprintf('%s weights must list every SkinId in enum order', $region->name),
            );
            $this->assertSame(
                100,
                array_sum($weights),
                sprintf('%s weights must sum to 100', $region->name),
            );
            foreach ($weights as $w) {
                $this->assertGreaterThanOrEqual(0, $w);
            }
        }
    }

    public function testWestAfricaIsOverwhelminglyDark(): void
    {
        $w = WorldRegion::WEST_AFRICA->skinWeights();
        $dark = $w[SkinId::S5->value] + $w[SkinId::S6->value];
        $this->assertGreaterThanOrEqual(95, $dark);
    }

    public function testNorthernEuropeIsMajorityLight(): void
    {
        $w = WorldRegion::NORTHERN_EUROPE->skinWeights();
        $light = $w[SkinId::S1->value] + $w[SkinId::S2->value];
        $this->assertGreaterThan(50, $light);
    }
}
