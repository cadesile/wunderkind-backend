<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Suggestion;

use App\Service\Location\Suggestion\SuggestedTown;
use App\Service\Location\Suggestion\TownSuggestionValidator;
use PHPUnit\Framework\TestCase;

/**
 * What survives a reply, and in what order.
 *
 * The ordering tests are the ones that matter most: the cap is only meaningful if the list is
 * sorted by size first, otherwise "max 300" means "whichever 300 the model happened to say
 * first" rather than "the 300 biggest", and a big country loses its cities instead of its
 * hamlets.
 */
class TownSuggestionValidatorTest extends TestCase
{
    public function testItKeepsTheBiggestWhenTheCapBites(): void
    {
        $verdict = $this->validator()->validate([
            new SuggestedTown('Tiny', 900),
            new SuggestedTown('Huge', 900_000),
            new SuggestedTown('Middling', 50_000),
        ], max: 2);

        $this->assertSame(['Huge', 'Middling'], $this->names($verdict['accepted']));
    }

    public function testTownsWithNoPopulationSortLast(): void
    {
        // A town the model would not size is not necessarily small, but it is the one we know
        // least about — so it is the one to drop first.
        $verdict = $this->validator()->validate([
            new SuggestedTown('Unknown', null),
            new SuggestedTown('Small', 1_000),
        ], max: 10);

        $this->assertSame(['Small', 'Unknown'], $this->names($verdict['accepted']));
    }

    public function testItDeduplicatesWithinOneReply(): void
    {
        // Asked for hundreds of names a model will occasionally repeat itself. That is not the
        // same event as a town we already hold, and is counted separately.
        $verdict = $this->validator()->validate([
            new SuggestedTown('Rosario', 1_000),
            new SuggestedTown('Rosario', 1_000),
        ], max: 10);

        $this->assertCount(1, $verdict['accepted']);
        $this->assertSame(1, $verdict['duplicates']);
    }

    public function testDeduplicationIgnoresAccentsAndCase(): void
    {
        // Normalised the same way town_normalised is, so "Córdoba" and "cordoba" cannot both
        // be accepted and then collide on insert.
        $verdict = $this->validator()->validate([
            new SuggestedTown('Córdoba', 1_000),
            new SuggestedTown('CORDOBA', 1_000),
        ], max: 10);

        $this->assertCount(1, $verdict['accepted']);
    }

    public function testBlankNamesAreRejected(): void
    {
        $verdict = $this->validator()->validate([
            new SuggestedTown('   '),
            new SuggestedTown('Real Town', 10),
        ], max: 10);

        $this->assertSame(['Real Town'], $this->names($verdict['accepted']));
        $this->assertNotEmpty($verdict['rejected']);
    }

    public function testNamesLongerThanTheColumnAreRejected(): void
    {
        $verdict = $this->validator()->validate([
            new SuggestedTown(str_repeat('a', TownSuggestionValidator::NAME_MAX_LENGTH + 1)),
        ], max: 10);

        $this->assertSame([], $verdict['accepted']);
        $this->assertCount(1, $verdict['rejected']);
    }

    public function testNamesAreTrimmed(): void
    {
        $verdict = $this->validator()->validate([new SuggestedTown('  Mendoza  ', 10)], max: 10);

        $this->assertSame(['Mendoza'], $this->names($verdict['accepted']));
    }

    public function testNonLatinNamesSurvive(): void
    {
        // The reason there is no "looks like a town name" heuristic. Nine of the twenty-five
        // countries write their place names in a non-Latin script, and a regex tuned on
        // English towns would throw all of these away while still admitting a confident
        // English-looking invention.
        $verdict = $this->validator()->validate([
            new SuggestedTown('横浜', 3_700_000),
            new SuggestedTown('대구', 2_400_000),
            new SuggestedTown('الإسكندرية', 5_200_000),
            new SuggestedTown('बेंगलुरु', 8_400_000),
        ], max: 10);

        $this->assertCount(4, $verdict['accepted']);
    }

    public function testAZeroCapAcceptsNothingRatherThanEverything(): void
    {
        $verdict = $this->validator()->validate([new SuggestedTown('Somewhere', 10)], max: 0);

        $this->assertSame([], $verdict['accepted']);
    }

    /**
     * @param list<SuggestedTown> $towns
     *
     * @return list<string>
     */
    private function names(array $towns): array
    {
        return array_map(static fn (SuggestedTown $t): string => $t->name, $towns);
    }

    private function validator(): TownSuggestionValidator
    {
        return new TownSuggestionValidator();
    }
}
