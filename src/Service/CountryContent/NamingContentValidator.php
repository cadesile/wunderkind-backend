<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

use App\Service\ClubNameNormalizer;

/**
 * Decides whether operator-authored club-naming content is safe to store.
 *
 * TWO STAGES PER CATEGORY: drop bad tokens with a note, then refuse the whole category if what
 * survives is under its floor. A list that silently fell below five is worse than one refused
 * out loud — it would store, pass the readiness check, and then make every club in a size band
 * read the same.
 *
 * THE FLOORS ARE THE NUMBERS THE EXISTING TESTS ALREADY ASSERT.
 * CountryContentRegistryTest::testSuffixListsAreSubstantial demands five of each from every
 * authored country, so MIN_SUFFIXES is five here too: content an operator types must not be
 * allowed to be worse than content shipped in PHP.
 *
 * STADIUM FORMATS ARE THE DANGEROUS ONE, and they are the reason this class is strict rather
 * than forgiving. They are fed to sprintf(), which in PHP 8 THROWS on a malformed format
 * string — so a stray percent sign typed into an admin textarea becomes a fatal inside club
 * generation, a long way from the form that stored it. Four rules guard it, the last of which
 * simply runs the thing.
 *
 * A BAD FORMAT REFUSES ITS WHOLE CATEGORY rather than being dropped quietly. One broken
 * template among four is a typo the operator wants to see; silently keeping the other three
 * would teach them the form accepts anything.
 */
final class NamingContentValidator
{
    /** Exactly what CountryContentRegistryTest::testSuffixListsAreSubstantial demands of the consts. */
    public const MIN_SUFFIXES = 5;

    /** One format makes every ground in the country identically patterned. */
    public const MIN_STADIUM_FORMATS = 2;

    /** The longest authored list is twenty. Headroom, not a target. */
    public const MAX_PER_LIST = 40;

    public const TOKEN_MAX_LENGTH = 40;

    public const FORMAT_MAX_LENGTH = 60;

    /** What the form and the prompt both ask for, so the three cannot drift. */
    public const SUGGESTED_PRESTIGE = 10;
    public const SUGGESTED_GENERIC  = 18;
    public const SUGGESTED_FORMATS  = 4;

    /**
     * @param array<string, list<string>> $input keyed by CountryNamingContentProvider::CATEGORIES
     */
    public function validate(array $input): ValidatedNamingContent
    {
        $accepted = [];
        $rejected = [];
        $notes    = [];

        foreach (CountryNamingContentProvider::CATEGORIES as $category) {
            $raw = $input[$category] ?? [];

            if (!\is_array($raw)) {
                $rejected[$category] = 'this is not a list of lines';

                continue;
            }

            $categoryNotes = [];
            $maxLength     = $category === 'stadiumFormats' ? self::FORMAT_MAX_LENGTH : self::TOKEN_MAX_LENGTH;
            $kept          = $this->cleanTokens($raw, $maxLength, $categoryNotes);

            if ($category === 'stadiumFormats') {
                $complaint = $this->firstBadFormat($kept);

                if ($complaint !== null) {
                    $rejected[$category] = $complaint;
                    $notes[$category]    = $categoryNotes;

                    continue;
                }
            }

            $floor = $category === 'stadiumFormats' ? self::MIN_STADIUM_FORMATS : self::MIN_SUFFIXES;

            // An empty category is "not supplied", not "supplied badly". It leaves whatever is
            // stored alone, which is what lets the form be saved one field at a time.
            if ($kept === [] && $raw === []) {
                $notes[$category] = $categoryNotes;

                continue;
            }

            if (\count($kept) < $floor) {
                $rejected[$category] = sprintf(
                    'only %d usable %s — at least %d are needed, or %s',
                    \count($kept),
                    $category === 'stadiumFormats' ? 'format(s)' : 'token(s)',
                    $floor,
                    $category === 'stadiumFormats'
                        ? 'every ground in the country reads the same'
                        : 'every club in a size band reads the same',
                );
                $notes[$category] = $categoryNotes;

                continue;
            }

            $accepted[$category] = $kept;
            $notes[$category]    = $categoryNotes;
        }

        $this->noteCrossListOverlap($accepted, $notes);

        return new ValidatedNamingContent($accepted, $rejected, array_filter($notes));
    }

    /**
     * Trim, drop the unusable, dedupe, cap.
     *
     * Deduped through ClubNameNormalizer::normalize(), the same comparison
     * TownSuggestionValidator uses — a repeated token is not a second chance at a name, it
     * just weights array_rand() towards it.
     *
     * @param  list<mixed>  $raw
     * @param  list<string> $notes
     * @return list<string>
     */
    private function cleanTokens(array $raw, int $maxLength, array &$notes): array
    {
        $kept = [];
        $seen = [];

        foreach ($raw as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $token = trim($value);

            if ($token === '') {
                continue;
            }

            if (mb_strlen($token) > $maxLength) {
                $notes[] = sprintf("'%s…' is longer than %d characters and was dropped", mb_substr($token, 0, 20), $maxLength);

                continue;
            }

            $key = ClubNameNormalizer::normalize($token);

            if (isset($seen[$key])) {
                $notes[] = sprintf("'%s' repeats '%s' and was dropped", $token, $seen[$key]);

                continue;
            }

            $seen[$key] = $token;
            $kept[]     = $token;
        }

        if (\count($kept) > self::MAX_PER_LIST) {
            $notes[] = sprintf('kept the first %d of %d', self::MAX_PER_LIST, \count($kept));
            $kept    = \array_slice($kept, 0, self::MAX_PER_LIST);
        }

        return $kept;
    }

    /**
     * The first thing wrong with a stadium format, or null.
     *
     * Order matters: each rule's message has to name the thing the operator actually typed, so
     * the most specific complaint has to come first.
     *
     * @param list<string> $formats
     */
    private function firstBadFormat(array $formats): ?string
    {
        foreach ($formats as $format) {
            if (substr_count($format, '%s') !== 1) {
                return sprintf(
                    "'%s' must contain %%s exactly once — that is where the town name goes",
                    $format,
                );
            }

            // Exactly one percent sign, and it is the one in %s. One rule catching %d, %1\$s,
            // %% and a plain '50%% Stadium' — every one of which is a sprintf() fatal or a
            // silently wrong name.
            if (substr_count($format, '%') !== 1) {
                return sprintf(
                    "'%s' has a percent sign other than the one in %%s. Write a literal percent as a word.",
                    $format,
                );
            }

            if (trim($format) === '%s') {
                return 'a format of just %s would name the ground after the town and nothing else';
            }

            // Belt and braces: run the thing. PHP 8's sprintf THROWS on a malformed format, so
            // this validator must never be weaker than the function it is protecting.
            try {
                sprintf($format, 'Testville');
            } catch (\Throwable $e) {
                return sprintf("'%s' is not a usable template: %s", $format, $e->getMessage());
            }
        }

        return null;
    }

    /**
     * A token in both suffix lists is a NOTE, not a rejection.
     *
     * PolandContentTest asserts the shipped lists do not overlap, but the effect is cosmetic —
     * pickSuffixForCitySize() still works, it just blurs the big/small-city distinction a
     * little. Refusing a save over it would be a surprise out of proportion to the harm.
     *
     * @param array<string, list<string>>  $accepted
     * @param array<string, list<string>>  $notes
     */
    private function noteCrossListOverlap(array $accepted, array &$notes): void
    {
        $prestige = $accepted['prestigeSuffixes'] ?? [];
        $generic  = $accepted['genericSuffixes'] ?? [];

        if ($prestige === [] || $generic === []) {
            return;
        }

        $genericKeys = array_flip(array_map(
            static fn (string $t): string => ClubNameNormalizer::normalize($t),
            $generic,
        ));

        foreach ($prestige as $token) {
            if (isset($genericKeys[ClubNameNormalizer::normalize($token)])) {
                $notes['prestigeSuffixes'][] = sprintf(
                    "'%s' is in both lists, which blurs the big/small-city distinction",
                    $token,
                );
            }
        }
    }
}
