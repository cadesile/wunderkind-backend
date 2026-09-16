<?php

namespace App\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\CompetitionTemplate;
use App\Repository\SeasonRecordRepository;
use Psr\Log\LoggerInterface;

/**
 * Evaluates a Club against a CompetitionTemplate's entry constraints. Modeled on
 * AudienceCriteriaEvaluator's fail-closed shape, but the constraints here are fixed
 * template fields (not an arbitrary criteria bag) and every check runs — no
 * short-circuiting — so the caller gets every blocking reason at once.
 */
class EligibilityEvaluator
{
    public function __construct(
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function evaluate(CompetitionTemplate $template, Club $club): EligibilityResult
    {
        $reasons = [];

        $allowedTiers = $template->getAllowedTiers();
        if ($allowedTiers !== null && $allowedTiers !== []) {
            $league = $club->getCurrentLeague();
            if ($league === null) {
                // Expected for very new clubs, not a config error — info, not warning.
                $this->logger->info('Competition eligibility: club has no league assigned.', [
                    'clubId'     => (string) $club->getId(),
                    'templateId' => (string) $template->getId(),
                ]);
                $reasons[] = 'no_league_assigned';
            } elseif (!in_array($league->getTier(), array_map('intval', $allowedTiers), true)) {
                // Tier semantics stay inverted (tier 1 = top division), matching
                // AudienceCriteriaEvaluator's leagueTier convention.
                $reasons[] = 'tier_not_allowed';
            }
        }

        if ($club->getReputation() < $template->getMinClubReputation()) {
            $reasons[] = 'min_reputation';
        }

        // "Future/advanced" gate — inert unless an admin sets it above the default 0.
        if ($template->getMinClubAgeSeasons() > 0) {
            $seasonsCompleted = $this->seasonRecordRepository->countByClub($club);
            if ($seasonsCompleted < $template->getMinClubAgeSeasons()) {
                $reasons[] = 'min_club_age';
            }
        }

        return new EligibilityResult(eligible: $reasons === [], reasons: $reasons);
    }
}
