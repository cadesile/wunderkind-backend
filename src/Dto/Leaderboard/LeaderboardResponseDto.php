<?php

namespace App\Dto\Leaderboard;

final class LeaderboardResponseDto
{
    /** @param LeaderboardItemDto[] $entries */
    public function __construct(
        public readonly string $category,
        public readonly string $period,
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly bool $hasNextPage,
    ) {}

    public function toArray(): array
    {
        return [
            'category'    => $this->category,
            'period'      => $this->period,
            'entries'     => array_map(static fn (LeaderboardItemDto $i): array => $i->toArray(), $this->entries),
            'total'       => $this->total,
            'page'        => $this->page,
            'pageSize'    => $this->pageSize,
            'hasNextPage' => $this->hasNextPage,
        ];
    }
}
