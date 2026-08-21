<?php

namespace App\Service;

use App\Entity\AudienceGroup;
use App\Entity\Club;
use App\Enum\AudienceCriteriaType;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a Club falls inside a DYNAMIC AudienceGroup, live at poll time.
 *
 * The key whitelist is closed and fails CLOSED: an unrecognised key makes the group match
 * nothing. A typo in admin-authored JSON should under-deliver a message, never broadcast it
 * to the whole player base.
 */
class AudienceCriteriaEvaluator
{
    /**
     * Every criteria key this evaluator understands. Adding one here without adding a matching
     * branch in matches() would make it silently permissive, so the two are checked together
     * by AudienceCriteriaEvaluatorTest.
     */
    public const SUPPORTED_KEYS = [
        'minReputation',
        'maxReputation',
        'country',
        'leagueTier',
        'minWeek',
        'maxWeek',
        'tutorialCompleted',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function matches(AudienceGroup $group, Club $club): bool
    {
        if ($group->getCriteriaType() !== AudienceCriteriaType::DYNAMIC) {
            return false;
        }

        $criteria = $group->getCriteriaPayload();

        // An empty dynamic group is a configuration mistake, not "everyone".
        if ($criteria === null || $criteria === []) {
            return false;
        }

        foreach ($criteria as $key => $value) {
            if (!in_array($key, self::SUPPORTED_KEYS, true)) {
                $this->logger->warning('Unknown audience criteria key; group matches nothing.', [
                    'group' => $group->getSlug(),
                    'key'   => $key,
                ]);

                return false;
            }

            if (!$this->matchesCriterion($key, $value, $club)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCriterion(string $key, mixed $value, Club $club): bool
    {
        return match ($key) {
            'minReputation' => $club->getReputation() >= (int) $value,
            'maxReputation' => $club->getReputation() <= (int) $value,
            'minWeek'       => $club->getLastSyncedWeek() >= (int) $value,
            'maxWeek'       => $club->getLastSyncedWeek() <= (int) $value,

            'tutorialCompleted' => ($club->getTutorialCompletedAt() !== null) === (bool) $value,

            'country' => $club->getCountry() !== null
                && in_array($club->getCountry(), $this->toList($value), true),

            // NOTE: tier is INVERTED — 1 is the top division, 8 is where new clubs start
            // (LeagueService::LEAGUE_TIER_DEFAULTS). `leagueTier: 8` targets beginners.
            // currentLeague is nullable for any club without a country
            // (SyncService::maybeAutoAssignLeague), so those must fall out rather than throw.
            'leagueTier' => $club->getCurrentLeague() !== null
                && in_array(
                    $club->getCurrentLeague()->getTier(),
                    array_map('intval', $this->toList($value)),
                    true,
                ),

            default => false,
        };
    }

    /** @return list<mixed> */
    private function toList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }
}
