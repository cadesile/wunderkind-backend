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
 *
 * That same property is why renaming a key needs LEGACY_KEYS rather than a find-and-replace:
 * a stored payload carrying a retired spelling would go dark silently, which is the failure
 * mode this class is built to prefer and therefore the one nobody notices.
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
        'setupCompleted',
    ];

    /**
     * Retired keys, mapped to their replacement, accepted on the way in.
     *
     * `tutorialCompleted` → `setupCompleted` (2026-11-02). The column behind it
     * (`Club::$tutorialCompletedAt`) records new-game SETUP completion, not a tutorial —
     * the 13-step walkthrough it was named after was deleted client-side on 2026-09-29,
     * and the criterion was silently false for every club created after that until the
     * client resumed sending the field.
     *
     * This alias exists because the whitelist above FAILS CLOSED. Renaming the key without
     * it would make any stored group still carrying the old spelling match **nobody** —
     * swapping one silent wrong answer for another, and a worse one, since
     * `tutorialCompleted: false` currently matches a great many clubs. `Version20261102...`
     * rewrites the stored payloads, so this is a safety net for an environment where that
     * migration has not run, not the mechanism. **Drop it once every environment is
     * migrated** — the deprecation warning below is how you find out whether any still
     * depends on it.
     */
    public const LEGACY_KEYS = [
        'tutorialCompleted' => 'setupCompleted',
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
            if (isset(self::LEGACY_KEYS[$key])) {
                $this->logger->warning('Deprecated audience criteria key; rewrite the group payload.', [
                    'group'      => $group->getSlug(),
                    'key'        => $key,
                    'replacedBy' => self::LEGACY_KEYS[$key],
                ]);

                $key = self::LEGACY_KEYS[$key];
            }

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

            // The column keeps the historical name — it is an API field the client sends and
            // two endpoints echo, so renaming it is a cross-repo break for no gain. What it
            // records is setup completion; see Club::$tutorialCompletedAt.
            'setupCompleted' => ($club->getTutorialCompletedAt() !== null) === (bool) $value,

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
