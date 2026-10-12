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
        public readonly ?array $homeKitConfig = null,
        public readonly ?array $awayKitConfig = null,
        public readonly ?array $badgeConfig = null,
        /** ISO-ish 2-letter club country code (App\Enum\Country), for the nationality chip. */
        public readonly ?string $country = null,
    ) {}

    public static function fromEntry(LeaderboardEntry $entry, int $rank): self
    {
        $club = $entry->getClub();

        return new self(
            rank: $rank,
            clubId: (string) $club->getId(),
            clubName: $club->getName(),
            score: $entry->getScore(),
            displayLabel: $entry->getDisplayLabel(),
            homeKitConfig: $club->getHomeKitConfig(),
            awayKitConfig: $club->getAwayKitConfig(),
            badgeConfig: $club->getBadgeConfig(),
            country: $club->getCountry(),
        );
    }

    /**
     * @return array{rank: int, clubId: string, clubName: string, score: int,
     *     displayLabel: ?string, homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array,
     *     country: ?string}
     */
    public function toArray(): array
    {
        return [
            'rank'          => $this->rank,
            'clubId'        => $this->clubId,
            'clubName'      => $this->clubName,
            'score'         => $this->score,
            'displayLabel'  => $this->displayLabel,
            'homeKitConfig' => $this->homeKitConfig,
            'awayKitConfig' => $this->awayKitConfig,
            'badgeConfig'   => $this->badgeConfig,
            'country'       => $this->country,
        ];
    }
}
