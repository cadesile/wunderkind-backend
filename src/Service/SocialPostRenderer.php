<?php

namespace App\Service;

use App\Entity\SocialPostTemplate;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;

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
            '{{clubName}}'       => $top['clubName'],
            '{{value}}'          => (string) $top['value'],
            '{{statValue}}'      => $this->formatStatValue($template->getCategory(), $top['rawValue']),
            '{{secondaryValue}}' => $top['secondaryValue'] !== null ? (string) (int) round($top['secondaryValue']) : '',
            '{{playerName}}'     => $top['playerName'] ?? '',
            '{{rank}}'           => (string) $top['rank'],
            '{{period}}'         => $this->periodLabel($template->getPeriod()),
            '{{categoryLabel}}'  => $this->categoryLabel($template->getCategory()),
        ]);
    }

    private function fetchResultsFor(StatCategory $category, StatsPeriod $period): array
    {
        return match ($category) {
            StatCategory::MOST_TRANSFERS    => $this->statsService->getMostTransfers($period, 1),
            StatCategory::MOST_DEVELOPMENT  => $this->statsService->getMostDevelopment($period, 1),
            StatCategory::MOST_SEASONS      => $this->statsService->getMostSeasons($period, 1),
            StatCategory::MOST_TROPHIES     => $this->statsService->getMostTrophies($period, 1),
            StatCategory::BEST_FORM         => $this->statsService->getBestForm($period, 1),
            StatCategory::BIGGEST_ROUT      => $this->statsService->getBiggestRout($period, 1),
            StatCategory::SUPER_STRIKER     => $this->statsService->getSuperStriker($period, 1),
            StatCategory::FORTRESS_DEFENCE  => $this->statsService->getFortressDefence($period, 1),
            StatCategory::TRANSFER_SPLURGE  => $this->statsService->getTransferSplurge($period, 1),
        };
    }

    private function categoryLabel(StatCategory $category): string
    {
        return match ($category) {
            StatCategory::MOST_TRANSFERS    => 'most transfers',
            StatCategory::MOST_DEVELOPMENT  => 'most player development',
            StatCategory::MOST_SEASONS      => 'most seasons played',
            StatCategory::MOST_TROPHIES     => 'most trophies won',
            StatCategory::BEST_FORM         => 'best current form',
            StatCategory::BIGGEST_ROUT      => 'biggest win margin',
            StatCategory::SUPER_STRIKER     => 'top goalscorer',
            StatCategory::FORTRESS_DEFENCE  => 'best defensive record',
            StatCategory::TRANSFER_SPLURGE  => 'biggest transfer spend',
        };
    }

    private function periodLabel(StatsPeriod $period): string
    {
        return match ($period) {
            StatsPeriod::LAST_6_HOURS  => 'in the last 6 hours',
            StatsPeriod::LAST_24_HOURS => 'over the last 24 hours',
            StatsPeriod::WEEK          => 'this week',
            StatsPeriod::MONTH         => 'this month',
            StatsPeriod::SEASON        => 'this season',
            StatsPeriod::ALL           => 'of all time',
        };
    }

    /**
     * Per-category display formatting for {{statValue}} — {{value}} stays a raw,
     * unformatted int for backward compatibility with existing templates.
     */
    private function formatStatValue(StatCategory $category, int|float|string $rawValue): string
    {
        return match ($category) {
            // Transfer.fee is stored in pence/cents — convert to whole pounds. The
            // template text itself supplies the "£" prefix, so no currency symbol here.
            StatCategory::TRANSFER_SPLURGE => number_format((int) round(((float) $rawValue) / 100)),
            // AVG(goalsAgainst) is genuinely fractional — 1 decimal place, not rounded
            // to a whole number, or "0.7 goals per game" would misleadingly read "1".
            StatCategory::FORTRESS_DEFENCE => number_format((float) $rawValue, 1),
            default => number_format((int) round((float) $rawValue)),
        };
    }
}
