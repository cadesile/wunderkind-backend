<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Service\CountryContent\NamePoolValidator;
use PHPUnit\Framework\TestCase;

/**
 * What an authored or suggested name pool has to clear.
 *
 * The case that matters most is the diacritic one. This validator deliberately does NOT reuse
 * ClubNameNormalizer, which strips accents — correct for club-name collisions, silently
 * destructive for people.
 */
class NamePoolValidatorTest extends TestCase
{
    public function testAGoodPoolIsAccepted(): void
    {
        $verdict = $this->validate();

        self::assertSame([], $verdict->rejected);
        self::assertCount(12, $verdict->accepted['firstNames']);
        self::assertCount(12, $verdict->accepted['lastNames']);
    }

    /**
     * ACCENTED NAMES ARE DISTINCT NAMES.
     *
     * Dedupe through ClubNameNormalizer would fold every one of these pairs together and
     * silently halve a Turkish, German, Polish or Nordic pool. They are different people.
     */
    public function testAccentedNamesAreNotFoldedIntoTheirPlainSpellings(): void
    {
        $accented = ['Güneş', 'Gunes', 'Özkan', 'Ozkan', 'Müller', 'Muller', 'Håland', 'Haland', 'Łukasz', 'Lukasz', 'Yıldırım', 'Yildirim'];

        $verdict = $this->validate(first: $accented);

        self::assertCount(12, $verdict->accepted['firstNames'], 'accents were stripped and real names were lost');
        self::assertContains('Güneş', $verdict->accepted['firstNames']);
        self::assertContains('Gunes', $verdict->accepted['firstNames']);
    }

    /** Case alone is not a different name. */
    public function testACaseOnlyRepeatIsDroppedAndNoted(): void
    {
        // Zeta appears twice in different cases; the base eleven are all distinct.
        $verdict = $this->validate(first: array_merge($this->names('A', 11), ['Zeta', 'ZETA']));

        self::assertCount(12, $verdict->accepted['firstNames']);
        self::assertNotEmpty($verdict->notes['firstNames']);
    }

    public function testBlankLinesAreDroppedWithoutComplaint(): void
    {
        $verdict = $this->validate(first: array_merge($this->names('A', 12), ['', '   ']));

        self::assertCount(12, $verdict->accepted['firstNames']);
    }

    public function testAnOverlongEntryIsDropped(): void
    {
        $verdict = $this->validate(first: array_merge($this->names('A', 12), [str_repeat('x', 200)]));

        self::assertCount(12, $verdict->accepted['firstNames']);
        self::assertStringContainsString('longer than', implode(' ', $verdict->notes['firstNames']));
    }

    /** Real names with spaces and apostrophes must survive. */
    public function testMultiWordAndApostrophedNamesSurvive(): void
    {
        $real = array_merge($this->names('A', 9), ['Al Dawsari', "O'Neill", 'van der Berg']);

        $verdict = $this->validate(first: $real);

        self::assertContains('Al Dawsari', $verdict->accepted['firstNames']);
        self::assertContains("O'Neill", $verdict->accepted['firstNames']);
        self::assertContains('van der Berg', $verdict->accepted['firstNames']);
    }

    public function testTooShortAListIsRefusedAndTheOtherStillSaves(): void
    {
        $verdict = $this->validate(first: $this->names('A', 3));

        self::assertArrayHasKey('firstNames', $verdict->rejected);
        self::assertStringContainsString('same handful', $verdict->rejected['firstNames']);
        self::assertArrayHasKey('lastNames', $verdict->accepted, 'one bad list must not throw away the other');
    }

    public function testAnEmptyListIsNotSuppliedRatherThanRefused(): void
    {
        // Which is what lets an operator save surnames alone and leave first names on the
        // shipped pool.
        $verdict = $this->validate(first: []);

        self::assertArrayNotHasKey('firstNames', $verdict->accepted);
        self::assertArrayNotHasKey('firstNames', $verdict->rejected);
    }

    public function testAnEnormousPasteIsCappedAndSaysSo(): void
    {
        $verdict = $this->validate(first: $this->names('A', 500));

        self::assertCount(NamePoolValidator::MAX_PER_LIST, $verdict->accepted['firstNames']);
        self::assertStringContainsString('kept the first', implode(' ', $verdict->notes['firstNames']));
    }

    public function testNonStringsAreIgnoredRatherThanCrashing(): void
    {
        $verdict = $this->validate(first: array_merge($this->names('A', 12), [42, null, ['nested']]));

        self::assertCount(12, $verdict->accepted['firstNames']);
    }

    /** @return list<string> */
    private function names(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $prefix . 'lpha' . $i;
        }

        return $out;
    }

    /** @param list<mixed>|null $first @param list<mixed>|null $last */
    private function validate(?array $first = null, ?array $last = null)
    {
        return (new NamePoolValidator())->validate([
            'firstNames' => $first ?? $this->names('A', 12),
            'lastNames'  => $last ?? $this->names('B', 12),
        ]);
    }
}
