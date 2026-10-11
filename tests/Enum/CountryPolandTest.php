<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Country;
use App\Service\NameGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * PL was added so WorldRegion::EASTERN_EUROPE became reachable. Polish already
 * had a first/last name pool and a skin-weight table; what it lacked was the
 * Country case joining them up, which had forced a hardcoded addendum into both
 * NameGeneratorService and WorldRegion.
 */
class CountryPolandTest extends TestCase
{
    public function testPolandIsAFullyFormedCountryCase(): void
    {
        $pl = Country::PL;

        $this->assertSame('Poland', $pl->label());
        $this->assertSame('Polish', $pl->nationality());
        $this->assertSame('pl_PL', $pl->locale());
    }

    /**
     * Generation capability is asserted in PolandContentTest alongside the
     * registry content that earns it. This test file deliberately stays about
     * the enum wiring only — it briefly asserted the opposite, back when PL was
     * a nationality with no content behind it.
     */

    public function testPolandAppearsInTheWholeSetMaps(): void
    {
        $this->assertSame('Polish', Country::nationalityMap()['PL'] ?? null);
        $this->assertSame('Poland', Country::labelMap()['PL'] ?? null);
    }

    /**
     * The regression this change could most easily have introduced: 'Polish' was
     * previously appended by NameGeneratorService::LEGACY_NATIONALITIES. Leaving
     * that in place alongside the new Country case would list it twice.
     */
    public function testNationalitiesHasNoDuplicates(): void
    {
        $nats = NameGeneratorService::nationalities();

        $this->assertSame(
            array_values(array_unique($nats)),
            array_values($nats),
            'NameGeneratorService::nationalities() must not repeat a demonym.',
        );
        $this->assertContains('Polish', $nats);
        $this->assertCount(1, array_keys($nats, 'Polish', true));
    }

    public function testPolishStillHasItsNamePool(): void
    {
        $this->assertTrue(NameGeneratorService::hasNamePool('Polish'));
    }
}
