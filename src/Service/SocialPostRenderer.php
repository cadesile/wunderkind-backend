<?php

namespace App\Service;

use App\Entity\SocialPostTemplate;
use App\Enum\StatCategory;

class SocialPostRenderer
{
    public function __construct(private readonly CommunityStatsService $statsService)
    {
    }

    /**
     * Renders $template's body against the current top-ranked club for its
     * own category and period. Returns null if there is no ranked data to
     * post (empty leaderboard) — callers must treat null as "nothing to
     * post this run", not an error.
     */
    public function render(SocialPostTemplate $template): ?string
    {
        $results = $this->fetchResultsFor($template->getCategory(), $template->getPeriod());
        if (empty($results)) {
            return null;
        }

        $top = $results[0];

        return strtr($template->getBodyTemplate(), [
            '{{clubName}}'      => $top['clubName'],
            '{{value}}'         => (string) $top['value'],
            '{{rank}}'          => (string) $top['rank'],
            '{{period}}'        => $template->getPeriod()->value,
            '{{categoryLabel}}' => $this->categoryLabel($template->getCategory()),
        ]);
    }

    private function fetchResultsFor(StatCategory $category, \App\Enum\StatsPeriod $period): array
    {
        return match ($category) {
            StatCategory::MOST_TRANSFERS   => $this->statsService->getMostTransfers($period, 1),
            StatCategory::MOST_DEVELOPMENT => $this->statsService->getMostDevelopment($period, 1),
            StatCategory::MOST_SEASONS     => $this->statsService->getMostSeasons($period, 1),
            StatCategory::MOST_TROPHIES    => $this->statsService->getMostTrophies($period, 1),
        };
    }

    private function categoryLabel(StatCategory $category): string
    {
        return match ($category) {
            StatCategory::MOST_TRANSFERS   => 'most transfers',
            StatCategory::MOST_DEVELOPMENT => 'most player development',
            StatCategory::MOST_SEASONS     => 'most seasons played',
            StatCategory::MOST_TROPHIES    => 'most trophies won',
        };
    }
}
