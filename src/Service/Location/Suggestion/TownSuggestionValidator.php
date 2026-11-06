<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Service\ClubNameNormalizer;

/**
 * Decides which suggested towns are worth trying to import, and in what order.
 *
 * DELIBERATELY NOT A PLAUSIBILITY CHECK. There is no "does this look like a town name" rule
 * here and there should not be: nine of the twenty-five countries write their place names in
 * a non-Latin script, and a regex tuned on English towns would reject real Japanese, Korean,
 * Arabic and Hindi ones while still admitting a confident English-looking invention. Whether
 * a town is REAL is settled downstream, by enrichment checking its coordinates against the
 * country's bounding box.
 *
 * What it does check is what this layer can actually know: that a name is non-empty, that it
 * fits the column, and that the reply does not repeat itself.
 *
 * It does NOT check whether a town already exists. LocationSeedService::importNames() holds
 * the database snapshot and does that properly; duplicating it here would mean two places
 * that must agree about normalisation.
 */
final class TownSuggestionValidator
{
    /** The `town` column is VARCHAR(120). A longer name is a sentence, not a town. */
    public const NAME_MAX_LENGTH = 120;

    /**
     * @param list<SuggestedTown> $towns
     *
     * @return array{accepted: list<SuggestedTown>, rejected: array<string, string>, duplicates: int}
     */
    public function validate(array $towns, int $max): array
    {
        $accepted   = [];
        $rejected   = [];
        $duplicates = 0;
        $seen       = [];

        foreach ($towns as $town) {
            $name       = trim($town->name);
            $normalised = ClubNameNormalizer::normalize($name);

            if ($name === '' || $normalised === '') {
                // Keyed by name so the operator sees which one; an empty name keys as the
                // placeholder rather than colliding with every other empty.
                $rejected[$name === '' ? '(blank)' : $name] = 'empty after trimming';
                continue;
            }

            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $rejected[mb_substr($name, 0, 60) . '…'] = 'longer than the town column allows';
                continue;
            }

            // Within one reply only. A model asked for 300 towns will occasionally say one
            // twice, and that is not the same event as a town we already hold.
            if (isset($seen[$normalised])) {
                ++$duplicates;
                continue;
            }

            $seen[$normalised] = true;
            $accepted[]        = new SuggestedTown($name, $town->population, $town->region);
        }

        // Largest first, unknown population last. This is what makes a cap mean "the biggest
        // ones" rather than "whichever the model happened to mention first" — and it is why
        // the population is asked for at all.
        usort($accepted, static fn (SuggestedTown $a, SuggestedTown $b): int => ($b->population ?? -1) <=> ($a->population ?? -1));

        return [
            'accepted'   => \array_slice($accepted, 0, max(0, $max)),
            'rejected'   => $rejected,
            'duplicates' => $duplicates,
        ];
    }
}
