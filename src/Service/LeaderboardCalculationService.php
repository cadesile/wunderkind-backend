<?php

namespace App\Service;

use App\Dto\Leaderboard\LeaderboardItemDto;
use App\Dto\Leaderboard\LeaderboardResponseDto;
use App\Entity\Club;
use App\Enum\LeaderboardCategory;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Repository\PlayerCareerStatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class LeaderboardCalculationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeaderboardEntryRepository $leaderboardEntryRepository,
        private readonly ClubFacilityRepository $clubFacilityRepository,
        private readonly PlayerCareerStatRepository $playerCareerStatRepository,
        private readonly ClubRepository $clubRepository,
        private readonly HallOfFameScoreService $hallOfFameScoreService,
        private readonly TagAwareCacheInterface $leaderboardCache,
        private readonly int $leaderboardCacheTtl,
    ) {}

    /**
     * Cache-first read used by the controller. Computes fresh on a cold cache
     * (recalculating aggregate categories, or just re-ranking cheap ones),
     * then slices pagination out of the cached full result set.
     */
    public function getLeaderboard(LeaderboardCategory $category, string $period, int $page, int $pageSize): LeaderboardResponseDto
    {
        $key = $this->cacheKey($category, $period);

        /** @var LeaderboardItemDto[] $allItems */
        $allItems = $this->leaderboardCache->get($key, function (ItemInterface $item) use ($category, $period): array {
            $item->tag([$this->categoryTag($category), $this->periodTag($period)]);
            $item->expiresAfter($this->leaderboardCacheTtl);

            $this->recalculate($category, $period);

            return $this->hydrateAll($category, $period);
        });

        $total       = count($allItems);
        $offset      = ($page - 1) * $pageSize;
        $pagedItems  = array_slice($allItems, $offset, $pageSize);
        $hasNextPage = ($offset + $pageSize) < $total;

        return new LeaderboardResponseDto(
            category: $category->value,
            period: $period,
            entries: $pagedItems,
            total: $total,
            page: $page,
            pageSize: $pageSize,
            hasNextPage: $hasNextPage,
        );
    }

    /**
     * Recomputes score (for aggregate categories) and rank_position (for all categories)
     * for one category+period. Non-aggregate categories are assumed to already have an
     * up-to-date score (upserted per-sync by SyncService) — this just re-ranks them,
     * which is also how rank_position gets backfilled for the 3 original categories.
     *
     * @return int number of LeaderboardEntry rows written
     */
    public function recalculate(LeaderboardCategory $category, string $period): int
    {
        if ($category->isAggregate()) {
            $this->computeAggregateScores($category, $period);
        }

        return $this->assignRanks($category, $period);
    }

    public function invalidate(LeaderboardCategory $category, ?string $period = null): void
    {
        if ($period === null) {
            $this->leaderboardCache->invalidateTags([$this->categoryTag($category)]);
        } else {
            $this->leaderboardCache->invalidateTags([$this->categoryTag($category), $this->periodTag($period)]);
        }
    }

    /**
     * Computes and upserts LeaderboardEntry.score (and displayLabel where relevant)
     * for one of the aggregate categories, from ClubFacility / PlayerCareerStat / SeasonRecord.
     */
    private function computeAggregateScores(LeaderboardCategory $category, string $period): void
    {
        $rows = match ($category) {
            LeaderboardCategory::EMPIRE_INDEX => array_map(
                static fn (array $r): array => $r + ['displayLabel' => null],
                $this->clubFacilityRepository->sumLevelsByClub(),
            ),
            LeaderboardCategory::GOLDEN_BOOT => $this->playerCareerStatRepository->findTopPerformerByClub('goals'),
            LeaderboardCategory::PLAYMAKER   => $this->playerCareerStatRepository->findTopPerformerByClub('assists'),
            LeaderboardCategory::HALL_OF_FAME => $this->hallOfFameRows(),
            default => throw new \InvalidArgumentException("{$category->value} is not an aggregate category"),
        };

        foreach ($rows as $row) {
            $club = $this->clubRepository->find($row['clubId']);
            if ($club === null) {
                continue;
            }

            $entry = $this->leaderboardEntryRepository->findOrCreate($club, $category, $period);
            $entry->setScore($row['score']);
            $entry->setDisplayLabel($row['displayLabel'] ?? null);
        }

        $this->em->flush();
    }

    /**
     * Tier-weighted league titles per club, shaped like the other aggregate row sets.
     *
     * @return array<array{clubId: string, score: int, displayLabel: null}>
     */
    private function hallOfFameRows(): array
    {
        $rows = [];
        foreach ($this->hallOfFameScoreService->scoresByClub() as $clubId => $score) {
            $rows[] = ['clubId' => $clubId, 'score' => $score, 'displayLabel' => null];
        }

        return $rows;
    }

    /**
     * Re-sorts existing LeaderboardEntry rows for this category/period by score
     * and writes rank_position 1..N.
     */
    private function assignRanks(LeaderboardCategory $category, string $period): int
    {
        $entries = $this->leaderboardEntryRepository->findAllOrderedByScore($category, $period);

        $rank = 1;
        foreach ($entries as $entry) {
            $entry->setRank($rank);
            $rank++;
        }

        $this->em->flush();

        return count($entries);
    }

    /** @return LeaderboardItemDto[] */
    private function hydrateAll(LeaderboardCategory $category, string $period): array
    {
        $entries = $this->leaderboardEntryRepository->findAllOrderedByScore($category, $period);

        $items = [];
        $rank  = 1;
        foreach ($entries as $entry) {
            $items[] = LeaderboardItemDto::fromEntry($entry, $rank);
            $rank++;
        }

        return $items;
    }

    private function categoryTag(LeaderboardCategory $category): string
    {
        return 'leaderboard_category_' . $category->value;
    }

    private function periodTag(string $period): string
    {
        return 'leaderboard_period_' . preg_replace('/[^A-Za-z0-9_]/', '_', $period);
    }

    private function cacheKey(LeaderboardCategory $category, string $period): string
    {
        return sprintf('leaderboard.%s.%s', $category->value, preg_replace('/[^A-Za-z0-9_.-]/', '_', $period));
    }
}
