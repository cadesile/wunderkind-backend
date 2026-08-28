<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use App\Repository\PlayerArchetypeRepository;

/**
 * Picks a rotating sample of archetypes for the landing page.
 *
 * The page used to hard-code eight archetypes in the markup, which meant the
 * shop window drifted from the catalogue the moment anyone edited, renamed or
 * reworded one — and it only ever showed the same eight. This reads the real
 * catalogue and rotates the sample per page load, so a visitor who returns sees
 * different personalities and the copy can never go stale.
 *
 * The split is by polarity because that is how the game itself reasons about
 * archetypes: the client resolves one best-match positive and one best-match
 * negative per player, so showing both halves is an honest preview.
 */
class ArchetypeShowcaseService
{
    /** Fills one row of the four-column grid per polarity. */
    public const SAMPLE_SIZE = 4;

    public function __construct(
        private readonly PlayerArchetypeRepository $repository,
    ) {}

    /**
     * @return array{
     *     positive: PlayerArchetype[],
     *     negative: PlayerArchetype[],
     *     total: int,
     *     totalPositive: int,
     *     totalNegative: int
     * }
     */
    public function sample(int $perPolarity = self::SAMPLE_SIZE): array
    {
        $grouped  = $this->repository->findGroupedByPolarity();
        $positive = $grouped[ArchetypePolarity::POSITIVE->value] ?? [];
        $negative = $grouped[ArchetypePolarity::NEGATIVE->value] ?? [];

        return [
            'positive' => self::pick($positive, $perPolarity),
            'negative' => self::pick($negative, $perPolarity),
            // Catalogue sizes, not sample sizes — the page states how many exist,
            // and that number must come from the data rather than the copy.
            'total'         => count($positive) + count($negative),
            'totalPositive' => count($positive),
            'totalNegative' => count($negative),
        ];
    }

    /**
     * Random sample without replacement, tolerant of a short catalogue.
     *
     * `shuffle()` on the caller's copy rather than an SQL ORDER BY RAND(): the
     * catalogue is twenty static rows, so sampling in PHP costs nothing and
     * leaves the underlying query cacheable.
     *
     * @param  PlayerArchetype[] $archetypes
     * @return PlayerArchetype[]
     */
    private static function pick(array $archetypes, int $count): array
    {
        if ($count < 1 || $archetypes === []) {
            return [];
        }

        // Never fail because the catalogue is smaller than the grid — a seed run
        // that produced only two negatives should show two, not throw.
        if (count($archetypes) <= $count) {
            shuffle($archetypes);

            return $archetypes;
        }

        shuffle($archetypes);

        return array_slice($archetypes, 0, $count);
    }
}
