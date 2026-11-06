<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

/**
 * One town as the model offered it.
 *
 * ONLY `name` IS EVER PERSISTED. Population is here as a sort key — it is what lets the
 * server order by size and drop the smallest when a cap bites, rather than trusting the model
 * to have sorted. Region is here to make a near-duplicate report readable.
 *
 * Writing the population would be actively harmful, not merely redundant. EnrichmentValidator
 * keeps a seeded population when a later figure diverges by more than 3x, so a guess stored
 * now would permanently shield itself from the better-prompted enrichment call that follows.
 * LocationSeedService::importPlaceNames() makes the same point about the bare registry names:
 * writing a zero would be worse than writing nothing, because it would look like data.
 */
final readonly class SuggestedTown
{
    public function __construct(
        public string $name,
        public ?int $population = null,
        public ?string $region = null,
    ) {}
}
