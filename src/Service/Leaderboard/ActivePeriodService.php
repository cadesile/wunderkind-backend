<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;

/**
 * Which periods should currently have leaderboard_entry rows written for them.
 *
 * Always 'all-time' and the current ISO week — unconditionally, so nothing about the
 * existing board changes — plus the current period key of every ENABLED award definition.
 * So monthly and quarterly rows only start being written once an admin enables such a
 * definition, and a category nobody awards costs nothing.
 *
 * That gating is not just thrift: it is what closes the disable-then-re-enable hazard
 * structurally rather than with a guard someone can delete. While a definition is
 * disabled, no key is emitted for it, so no rows exist; on re-enable, rows are created
 * fresh and growth is measured from the re-enable. A long-disabled definition therefore
 * cannot retro-award the all-time leader.
 *
 * Memoised for the lifetime of the service — the definition table holds a handful of rows
 * and SyncService asks once per sync, but the five-minute generate command asks once per
 * category.
 */
class ActivePeriodService
{
    /** @var array<string, list<string>>|null keyed by the memo bucket (a timestamp + category) */
    private ?array $memo = null;

    public function __construct(
        private readonly LeaderboardAwardDefinitionRepository $definitions,
    ) {}

    /**
     * Every period key that should be written for $category right now.
     *
     * @return list<string> 'all-time' first, then the current ISO week, then any extra
     *                      cadence keys — de-duplicated, because a weekly definition's key
     *                      IS the ISO-week key.
     */
    public function activePeriodsFor(LeaderboardCategory $category, ?\DateTimeImmutable $at = null): array
    {
        $at     = $at ?? new \DateTimeImmutable();
        $bucket = $category->value . '@' . $at->format('Y-m-d H:i');

        if (isset($this->memo[$bucket])) {
            return $this->memo[$bucket];
        }

        $periods = [LeaderboardPeriod::ALL_TIME, LeaderboardPeriod::currentWeekKey($at)];

        foreach ($this->definitions->findEnabled() as $definition) {
            if ($definition->getCategory() !== $category) {
                continue;
            }

            $periods[] = $definition->currentPeriodKey($at);
        }

        $periods = array_values(array_unique($periods));

        $this->memo[$bucket] = $periods;

        return $periods;
    }

    /**
     * Every period key that should be written across every category — the shape the
     * generate command's "both" option wants.
     *
     * @return list<string>
     */
    public function allActivePeriods(?\DateTimeImmutable $at = null): array
    {
        $at = $at ?? new \DateTimeImmutable();

        $periods = [LeaderboardPeriod::ALL_TIME, LeaderboardPeriod::currentWeekKey($at)];

        foreach ($this->definitions->findEnabled() as $definition) {
            $periods[] = $definition->currentPeriodKey($at);
        }

        return array_values(array_unique($periods));
    }

    /** Forget the memo — for a long-running worker, or a test that enables a definition mid-run. */
    public function reset(): void
    {
        $this->memo = null;
    }
}
