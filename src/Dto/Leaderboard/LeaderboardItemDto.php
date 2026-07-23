<?php

namespace App\Dto\Leaderboard;

use App\Entity\LeaderboardEntry;

final class LeaderboardItemDto
{
    public function __construct(
        public readonly int $rank,
        public readonly string $clubId,
        public readonly string $clubName,
        public readonly int $score,
        public readonly ?string $displayLabel = null,
    ) {}

    public static function fromEntry(LeaderboardEntry $entry, int $rank): self
    {
        return new self(
            rank: $rank,
            clubId: (string) $entry->getClub()->getId(),
            clubName: $entry->getClub()->getName(),
            score: $entry->getScore(),
            displayLabel: $entry->getDisplayLabel(),
        );
    }

    /** @return array{rank: int, clubId: string, clubName: string, score: int, displayLabel: ?string} */
    public function toArray(): array
    {
        return [
            'rank'         => $this->rank,
            'clubId'       => $this->clubId,
            'clubName'     => $this->clubName,
            'score'        => $this->score,
            'displayLabel' => $this->displayLabel,
        ];
    }
}
