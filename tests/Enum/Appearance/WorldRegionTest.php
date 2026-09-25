<?php
namespace App\Tests\Enum\Appearance;

use App\Enum\Appearance\SkinId;
use App\Enum\Appearance\WorldRegion;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

class WorldRegionTest extends TestCase
{
    /** Every nationality the generator can produce must map to a region. */
    public function testEveryGeneratedNationalityMaps(): void
    {
        $ref  = new \ReflectionClass(NameGeneratorService::class);
        $nats = $ref->getConstant('NATIONALITIES');

        $this->assertNotEmpty($nats, 'NameGeneratorService::NATIONALITIES should not be empty');

        foreach ($nats as $nat) {
            $this->assertInstanceOf(
                WorldRegion::class,
                WorldRegion::fromNationality($nat),
                sprintf('Nationality "%s" has no WorldRegion mapping', $nat),
            );
        }
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
