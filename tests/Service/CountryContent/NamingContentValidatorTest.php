<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent;

use App\Service\CountryContent\NamingContentValidator;
use PHPUnit\Framework\TestCase;

/**
 * What operator-typed club-naming content has to clear.
 *
 * The stadium-format cases carry the most weight. Those strings are handed to sprintf(), which
 * THROWS in PHP 8 on a malformed format — so a percent sign typed into an admin textarea would
 * otherwise become a fatal inside club generation, a long way from the form that stored it.
 */
class NamingContentValidatorTest extends TestCase
{
    public function testAGoodSetIsAccepted(): void
    {
        $verdict = $this->validate();

        self::assertSame([], $verdict->rejected);
        self::assertCount(6, $verdict->accepted['prestigeSuffixes']);
        self::assertSame(['%s Park', 'Estadio %s'], $verdict->accepted['stadiumFormats']);
    }

    public function testBlankLinesAreDroppedWithoutComplaint(): void
    {
        $verdict = $this->validate(prestige: ['Legia', '', '   ', 'Wisła', 'Lech', 'Górnik', 'Pogoń', 'Polonia']);

        self::assertCount(6, $verdict->accepted['prestigeSuffixes']);
    }

    public function testARepeatIsDroppedAndNoted(): void
    {
        // Normalised, so case and a stripped diacritic both count as a repeat — the same
        // comparison the town suggester dedupes on.
        //
        // NOTE WHAT IT DOES NOT CATCH: ClubNameNormalizer strips diacritics, and Polish ł is a
        // distinct letter rather than an l with a mark, so 'Wisła' and 'Wisla' are two tokens
        // as far as this is concerned. That is the normaliser's behaviour everywhere in this
        // codebase and not something to work around here; it is recorded so the next person
        // does not read it as a gap in this test.
        $verdict = $this->validate(prestige: ['Legia', 'legia', 'Górnik', 'Gornik', 'Lech', 'Pogoń', 'Polonia', 'Stal']);

        self::assertCount(6, $verdict->accepted['prestigeSuffixes']);
        self::assertNotEmpty($verdict->notes['prestigeSuffixes']);
    }

    public function testAnOverlongTokenIsDropped(): void
    {
        $verdict = $this->validate(prestige: [str_repeat('x', 200), 'Legia', 'Wisła', 'Lech', 'Górnik', 'Pogoń', 'Polonia']);

        self::assertCount(6, $verdict->accepted['prestigeSuffixes']);
        self::assertStringContainsString('longer than', implode(' ', $verdict->notes['prestigeSuffixes']));
    }

    public function testAListIsCappedAndTheCapIsNoted(): void
    {
        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many[] = 'Suffix' . $i;
        }

        $verdict = $this->validate(prestige: $many);

        self::assertCount(NamingContentValidator::MAX_PER_LIST, $verdict->accepted['prestigeSuffixes']);
        self::assertStringContainsString('kept the first', implode(' ', $verdict->notes['prestigeSuffixes']));
    }

    /**
     * The floor matches what CountryContentRegistryTest demands of the shipped consts.
     *
     * Content an operator types must not be allowed to be worse than content shipped in PHP —
     * and a list of three makes every club in a size band read the same.
     */
    public function testTooFewSuffixesRefusesTheWholeCategory(): void
    {
        $verdict = $this->validate(prestige: ['Legia', 'Wisła', 'Lech']);

        self::assertArrayHasKey('prestigeSuffixes', $verdict->rejected);
        self::assertArrayNotHasKey('prestigeSuffixes', $verdict->accepted);
        // Per category: the other two are unaffected.
        self::assertArrayHasKey('genericSuffixes', $verdict->accepted);
        self::assertArrayHasKey('stadiumFormats', $verdict->accepted);
    }

    public function testAFormatWithNoPlaceholderIsRefused(): void
    {
        $verdict = $this->validate(formats: ['Stadion Miejski', 'Estadio %s']);

        self::assertStringContainsString('%s exactly once', $verdict->rejected['stadiumFormats'] ?? '');
    }

    public function testAFormatWithTwoPlaceholdersIsRefused(): void
    {
        $verdict = $this->validate(formats: ['%s Park %s', 'Estadio %s']);

        self::assertArrayHasKey('stadiumFormats', $verdict->rejected);
    }

    public function testAFormatWithANumericConversionIsRefused(): void
    {
        $verdict = $this->validate(formats: ['%s Park %d', 'Estadio %s']);

        self::assertArrayHasKey('stadiumFormats', $verdict->rejected);
    }

    public function testAFormatWithAPositionalConversionIsRefused(): void
    {
        $verdict = $this->validate(formats: ['%1$s Park', 'Estadio %s']);

        self::assertArrayHasKey('stadiumFormats', $verdict->rejected);
    }

    public function testAFormatWithAnEscapedPercentIsRefused(): void
    {
        // Legal sprintf, but an operator typing %% almost certainly means a literal percent,
        // and a ground called "100% Park" is not what they are going to get.
        $verdict = $this->validate(formats: ['%s 100%% Park', 'Estadio %s']);

        self::assertArrayHasKey('stadiumFormats', $verdict->rejected);
    }

    public function testAStrayPercentIsRefused(): void
    {
        $verdict = $this->validate(formats: ['%s 50% Stadium', 'Estadio %s']);

        self::assertStringContainsString('percent sign', $verdict->rejected['stadiumFormats'] ?? '');
    }

    public function testABarePlaceholderIsRefused(): void
    {
        $verdict = $this->validate(formats: ['%s', 'Estadio %s']);

        self::assertStringContainsString('nothing else', $verdict->rejected['stadiumFormats'] ?? '');
    }

    public function testASingleFormatIsRefused(): void
    {
        $verdict = $this->validate(formats: ['Estadio %s']);

        self::assertStringContainsString('reads the same', $verdict->rejected['stadiumFormats'] ?? '');
    }

    public function testEverySurvivingFormatActuallyRuns(): void
    {
        $verdict = $this->validate();

        foreach ($verdict->accepted['stadiumFormats'] as $format) {
            self::assertSame(
                1,
                substr_count($format, '%s'),
                'anything stored must be safe to hand to sprintf()',
            );
            self::assertIsString(sprintf($format, 'Testville'));
        }
    }

    public function testAnEmptyCategoryIsNotSuppliedRatherThanRefused(): void
    {
        // Which is what lets the form be saved one field at a time.
        $verdict = $this->validate(prestige: []);

        self::assertArrayNotHasKey('prestigeSuffixes', $verdict->accepted);
        self::assertArrayNotHasKey('prestigeSuffixes', $verdict->rejected);
    }

    public function testATokenInBothListsIsANoteNotARefusal(): void
    {
        $shared = ['Legia', 'Wisła', 'Lech', 'Górnik', 'Pogoń', 'Polonia'];

        $verdict = $this->validate(prestige: $shared, generic: $shared);

        self::assertSame([], $verdict->rejected, 'overlap is cosmetic — refusing the save would be a surprise');
        self::assertNotEmpty($verdict->notes['prestigeSuffixes']);
    }

    public function testNonStringsAreIgnoredRatherThanCrashing(): void
    {
        $verdict = $this->validate(prestige: ['Legia', 42, null, 'Wisła', 'Lech', 'Górnik', 'Pogoń', 'Polonia']);

        self::assertCount(6, $verdict->accepted['prestigeSuffixes']);
    }

    /**
     * @param list<mixed>|null $prestige
     * @param list<mixed>|null $generic
     * @param list<mixed>|null $formats
     */
    private function validate(?array $prestige = null, ?array $generic = null, ?array $formats = null)
    {
        return (new NamingContentValidator())->validate([
            'prestigeSuffixes' => $prestige ?? ['Legia', 'Wisła', 'Lech', 'Górnik', 'Pogoń', 'Polonia'],
            'genericSuffixes'  => $generic ?? ['Stal', 'Odra', 'Warta', 'Motor', 'Unia', 'Sokół'],
            'stadiumFormats'   => $formats ?? ['%s Park', 'Estadio %s'],
        ]);
    }
}
