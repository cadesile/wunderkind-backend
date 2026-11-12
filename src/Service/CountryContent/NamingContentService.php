<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Service\CountryContent\Suggestion\NamingContentSuggesterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores, clears and reports operator-authored club-naming content.
 *
 * The write half of CountryNamingContentProvider. Kept out of the controller for the reason
 * every other operator job in this area is — the admin button and anything else that needs it
 * must run the same code.
 *
 * ONE RULE WORTH KNOWING: a list identical to the PHP const is stored as NULL rather than as
 * an override. Without that, opening the form on an authored country and pressing Save — which
 * does nothing and looks like it does nothing — would quietly convert twenty-five countries
 * from "follows the registry" to "pinned to a copy of the registry as it was that day".
 */
final class NamingContentService
{
    public function __construct(
        private readonly NamingContentValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly NamingContentSuggesterInterface $suggester,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ask the model for this country's content, and store what the validator accepts.
     *
     * WRITES STRAIGHT TO THE COLUMNS, behind an overwrite flag that is off by default. That is
     * the house pattern — suggestTowns writes rows, LocationEnrichmentService writes validated
     * fields, and neither prefills a form — and it is the only one that works here: every
     * custom admin write in this codebase POSTs and redirects, so a suggestion held for review
     * would need a session stash built for one button.
     *
     * The cost of a wrong answer is cosmetic and reversible three ways: the redirect lands back
     * on the same form with the stored values in it, so "stored" and "reviewed" are one scroll
     * apart; the operator can edit and re-save; or revert to the built-in lists. No money
     * moves, and nothing is player-visible until the country goes Live.
     *
     * OFF-BY-DEFAULT OVERWRITE is where the real protection sits: a generate can never clobber
     * content somebody authored by hand.
     *
     * @return array{verdict: ValidatedNamingContent, skipped: list<string>, inputTokens: ?int, outputTokens: ?int}
     */
    public function suggest(CountryProfile $country, bool $overwrite = false): array
    {
        $suggestion = $this->suggester->suggest($country);
        $verdict    = $this->validator->validate($suggestion->toInput());
        $enum       = Country::tryFrom($country->getCode());
        $skipped    = [];

        foreach ($verdict->accepted as $category => $values) {
            // READ OFF THE PROFILE, not through the enum-keyed provider. hasOverride() takes a
            // Country case, and a profile row can exist for a code that has none — at which
            // point the guard silently stopped applying and a suggestion overwrote content
            // somebody had authored by hand. The row is the authority on what the row holds.
            if (!$overwrite && $this->storedOverride($country, $category) !== null) {
                $skipped[] = $category;

                continue;
            }

            $this->write($country, $category, $this->asOverride($enum, $category, $values));
        }

        $this->em->flush();

        $this->logger->info('Club-naming content suggestion', [
            'country'       => $country->getCode(),
            'provider'      => $this->suggester->provider(),
            'model'         => $this->suggester->model(),
            'promptVersion' => $this->suggester->promptVersion(),
            'overwrite'     => $overwrite,
            'accepted'      => array_keys($verdict->accepted),
            'rejected'      => $verdict->rejected,
            'skipped'       => $skipped,
            'inputTokens'   => $suggestion->inputTokens,
            'outputTokens'  => $suggestion->outputTokens,
        ]);

        return [
            'verdict'      => $verdict,
            'skipped'      => $skipped,
            'inputTokens'  => $suggestion->inputTokens,
            'outputTokens' => $suggestion->outputTokens,
        ];
    }

    public function suggesterIsConfigured(): bool
    {
        return $this->suggester->isConfigured();
    }

    /**
     * Validate and store what the operator typed.
     *
     * A category the validator refused is left exactly as it was — the country keeps whatever
     * it had, which is what makes a bad paste harmless.
     *
     * @param array<string, list<string>> $input
     */
    public function save(CountryProfile $country, array $input): ValidatedNamingContent
    {
        $verdict = $this->validator->validate($input);
        $enum    = Country::tryFrom($country->getCode());

        foreach ($verdict->accepted as $category => $values) {
            $this->write($country, $category, $this->asOverride($enum, $category, $values));
        }

        $this->em->flush();

        return $verdict;
    }

    /** Put a country back on the built-in lists. */
    public function clear(CountryProfile $country): void
    {
        foreach (CountryNamingContentProvider::CATEGORIES as $category) {
            $this->write($country, $category, null);
        }

        $this->em->flush();
    }

    /**
     * Store an override, or null when the list is simply the registry's own.
     *
     * @param  list<string>      $values
     * @return list<string>|null
     */
    private function asOverride(?Country $enum, string $category, array $values): ?array
    {
        if ($enum === null) {
            return $values;
        }

        $registry = match ($category) {
            'prestigeSuffixes' => CountryContentRegistry::prestigeSuffixes($enum),
            'genericSuffixes'  => CountryContentRegistry::genericSuffixes($enum),
            'stadiumFormats'   => CountryContentRegistry::stadiumFormats($enum),
            default            => [],
        };

        return $values === $registry ? null : $values;
    }

    /** @return list<string>|null what this country already holds for a category, if anything. */
    private function storedOverride(CountryProfile $country, string $category): ?array
    {
        $stored = match ($category) {
            'prestigeSuffixes' => $country->getPrestigeSuffixes(),
            'genericSuffixes'  => $country->getGenericSuffixes(),
            'stadiumFormats'   => $country->getStadiumFormats(),
            default            => throw new \InvalidArgumentException(sprintf('Unknown naming category %s.', $category)),
        };

        return ($stored === null || $stored === []) ? null : $stored;
    }

    /** @param list<string>|null $values */
    private function write(CountryProfile $country, string $category, ?array $values): void
    {
        match ($category) {
            'prestigeSuffixes' => $country->setPrestigeSuffixes($values),
            'genericSuffixes'  => $country->setGenericSuffixes($values),
            'stadiumFormats'   => $country->setStadiumFormats($values),
            default            => throw new \InvalidArgumentException(sprintf('Unknown naming category %s.', $category)),
        };
    }
}
