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
        $reasons           = [];
        $structuredReasons = [];

        $allowedTiers = $template->getAllowedTiers();
        if ($allowedTiers !== null && $allowedTiers !== []) {
            $normalizedAllowedTiers = array_values(array_map('intval', $allowedTiers));
            $league                 = $club->getCurrentLeague();
            if ($league === null) {
                // Expected for very new clubs, not a config error — info, not warning.
                $this->logger->info('Competition eligibility: club has no league assigned.', [
                    'clubId'     => (string) $club->getId(),
                    'templateId' => (string) $template->getId(),
                ]);
                $reasons[]           = 'no_league_assigned';
                $structuredReasons[] = [
                    'code'     => 'no_league_assigned',
                    'required' => $normalizedAllowedTiers,
                    'actual'   => null,
                ];
            } elseif (!in_array($league->getTier(), $normalizedAllowedTiers, true)) {
                // Tier semantics stay inverted (tier 1 = top division), matching
                // AudienceCriteriaEvaluator's leagueTier convention.
                $reasons[]           = 'tier_not_allowed';
                $structuredReasons[] = [
                    'code'     => 'tier_not_allowed',
                    'required' => $normalizedAllowedTiers,
                    'actual'   => $league->getTier(),
                ];
            }
        }

        if ($club->getReputation() < $template->getMinClubReputation()) {
            $reasons[]           = 'min_reputation';
            $structuredReasons[] = [
                'code'     => 'min_reputation_not_met',
                'required' => $template->getMinClubReputation(),
                'actual'   => $club->getReputation(),
            ];
        }

        // "Future/advanced" gate — inert unless an admin sets it above the default 0.
        if ($template->getMinClubAgeSeasons() > 0) {
            $seasonsCompleted = $this->seasonRecordRepository->countByClub($club);
            if ($seasonsCompleted < $template->getMinClubAgeSeasons()) {
                $reasons[]           = 'min_club_age';
                $structuredReasons[] = [
                    'code'     => 'min_age_not_met',
                    'required' => $template->getMinClubAgeSeasons(),
                    'actual'   => $seasonsCompleted,
                ];
            }
        }

        $entryFeePence = $template->getEntryFeePence();
        if ($entryFeePence > 0 && $club->getBalance() < $entryFeePence) {
            $reasons[]           = 'insufficient_funds';
            $structuredReasons[] = [
                'code'     => 'insufficient_funds',
                'required' => $entryFeePence,
                'actual'   => $club->getBalance(),
            ];
        }

        return new EligibilityResult(
            eligible: $reasons === [],
            reasons: $reasons,
            structuredReasons: $structuredReasons,
        );
    }
}
