<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * What PlayedCupFixtureTrait::playedCup() hands back.
 *
 * Scalars rather than entities, deliberately: the helper clears the entity manager before
 * returning, so anything with identity would come back detached and a test holding several
 * cups at once would be holding several traps.
 */
final readonly class PlayedCup
{
    public function __construct(
        public string $templateId,
        public string $templateName,
        public string $instanceId,
        public string $winnerClubName,
        public string $runnerUpClubName,
        public ?\DateTimeImmutable $completedAt,
    ) {}
}
