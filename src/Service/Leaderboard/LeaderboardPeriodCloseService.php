<?php

declare(strict_types=1);

namespace App\Service\Leaderboard;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Service\LeaderboardCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Closes an ended period: freezes its board into an immutable archive, records the awards,
 * prunes the live rows, and advances the definition's monotonic anchor.
 *
 * ── Why this runs inline in a cron tick, with no Messenger transport ────────────────────
 * Per due definition the work is one indexed read, at most ARCHIVED_ENTRY_LIMIT entry
 * inserts and at most N inbox messages; the number of due definitions per tick is bounded
 * by the enabled count (at most 12 categories x 3 cadences, realistically a handful). That
 * is nothing like a 32-fixture match-engine round, which is what justified the
 * `competition` transport. If the archive write ever has to cover tens of thousands of
 * rows per definition per tick, split the entry write onto its own message with a re-read
 * clamp (the TopUpStarterPoolMessageHandler pattern).
 *
 * ── Idempotency, stated exactly ─────────────────────────────────────────────────────────
 * For a given (category, period_key) at most one leaderboard_period_archive row can exist,
 * and within one archive at most one entry can hold a given `place`. Therefore a re-run —
 * concurrent, retried, or replayed after a crash — produces no second archive, no second
 * award and no second inbox message. The only non-idempotent writes are the archive entry
 * rows, which are deleted and rewritten wholesale on reclaim. A crash before the final
 * flush leaves the archive `pending`, the live leaderboard_entry rows UNPRUNED, and the
 * period reclaimable after CLAIM_LEASE_SECONDS. The monotonic last_closed_period_key
 * anchor plus the lazy-baseline rule is what stops a long-disabled definition from
 * retro-awarding on re-enable.
 *
 * ── Order of operations, and why it is this order ───────────────────────────────────────
 *  1. due-check (two parts, both required — see dueKeyFor())
 *  2. claim, raw and auto-committed on its own, deliberately OUTSIDE any wrapping
 *     transaction, so the claim survives a later failure rather than silently re-opening
 *     the period
 *  3. final score + rank, then read the eligible ranked rows
 *  4. archive entries, freezing club identity and the display label
 *  5. award per configured place (LeaderboardAwardDeliveryService)
 *  6. ONE flush: archive status, every entry, every inbox message, and the definition's
 *     anchor — the CompetitionResultsService::finalizeRound() shape
 *  7. prune the live rows, only after that flush has committed
 *  8. invalidate the closed key and the new open key
 *
 * The claim deliberately does NOT set last_closed_period_key. Per CLAUDE.md's "never
 * commit a status ahead of the work it describes", the anchor is set on the entity in the
 * single flush at step 6. The claim row itself is the gate; the anchor is the record.
 */
class LeaderboardPeriodCloseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeaderboardAwardDefinitionRepository $definitions,
        private readonly LeaderboardPeriodArchiveRepository $archives,
        private readonly LeaderboardPeriodArchiveEntryRepository $archiveEntries,
        private readonly LeaderboardEntryRepository $leaderboardEntries,
        private readonly LeaderboardCalculationService $calculation,
        private readonly LeaderboardAwardDeliveryService $delivery,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Closes every due period across every enabled definition, oldest first per definition.
     *
     * @param  LeaderboardAwardDefinition[]|null $only Restrict to these definitions.
     * @return array<string, mixed> a per-definition report, for the command to print
     */
    public function closeDuePeriods(
        ?\DateTimeImmutable $now = null,
        ?array $only = null,
        bool $dryRun = false,
        bool $force = false,
    ): array {
        $now         = $now ?? new \DateTimeImmutable();
        $definitions = $only ?? $this->definitions->findEnabled();
        $report      = ['closed' => [], 'skipped' => [], 'dryRun' => $dryRun];

        foreach ($definitions as $definition) {
            $dueKey = $this->dueKeyFor($definition, $now, $force);

            if ($dueKey === null) {
                $report['skipped'][] = [
                    'definition' => (string) $definition,
                    'reason'     => 'nothing due',
                ];

                continue;
            }

            $outcome = $dryRun
                ? $this->describeDryRun($definition, $dueKey)
                : $this->closePeriod($definition, $dueKey, $now);

            $report['closed'][] = $outcome;
        }

        return $report;
    }

    /**
     * The period this definition should close next, or null.
     *
     * Two parts, BOTH required:
     *
     *  1. Catch-up first: if the anchor is set and the period after it has already ended,
     *     that is the one to close — oldest first, one per pass, so a cron outage settles
     *     each missed period in order rather than silently skipping two months. Otherwise
     *     the previous period relative to now.
     *  2. AND the chosen period must actually have ended. Without this, clock skew or a
     *     stray --force could close a period that is still open.
     *
     * --force relaxes part 2 only, to close the CURRENT open period early for ops and
     * local testing (there is no crontab under Lando).
     */
    public function dueKeyFor(LeaderboardAwardDefinition $definition, \DateTimeImmutable $now, bool $force = false): ?string
    {
        $currentKey = $definition->currentPeriodKey($now);
        $anchor     = $definition->getLastClosedPeriodKey();

        $candidate = null;

        if ($anchor !== null) {
            $next = LeaderboardPeriod::nextKey($anchor);

            if ($next !== null) {
                $nextBounds = LeaderboardPeriod::boundsOf($next);

                if ($nextBounds !== null && $nextBounds[1] <= $now) {
                    $candidate = $next;
                }
            }
        }

        $candidate ??= $force ? $currentKey : LeaderboardPeriod::previousKey($currentKey);

        if ($candidate === null) {
            return null;
        }

        // The anchor is monotonic: never re-close a period at or before it. This relies on
        // keys sorting lexicographically within a cadence, which the zero-padding
        // guarantees and LeaderboardPeriodTest pins.
        if ($anchor !== null && $candidate <= $anchor) {
            return null;
        }

        if (!$force) {
            $bounds = LeaderboardPeriod::boundsOf($candidate);

            if ($bounds === null || $bounds[1] > $now) {
                return null;
            }
        }

        return $candidate;
    }

    /**
     * Closes one period. Returns a summary.
     *
     * @return array<string, mixed>
     */
    public function closePeriod(LeaderboardAwardDefinition $definition, string $dueKey, \DateTimeImmutable $now): array
    {
        $archive = $this->claim($definition, $dueKey, $now);

        if ($archive === null) {
            return [
                'definition' => (string) $definition,
                'period'     => $dueKey,
                'result'     => 'claim not won',
            ];
        }

        // Final score and rank for the period being closed, then drop the cached document
        // for it so nothing serves a board that is about to be pruned.
        $this->calculation->recalculate($definition->getCategory(), $dueKey);
        $this->calculation->invalidate($definition->getCategory(), $dueKey);

        $eligibleCount = $this->leaderboardEntries->countEligible($definition->getCategory(), $dueKey);
        $depth         = $definition->getDepth();

        // Top N for display, and never fewer rows than there are prize places — an award
        // always needs its own row to point at.
        $limit = max(LeaderboardPeriodArchive::ARCHIVED_ENTRY_LIMIT, $depth);
        $ranked = $this->leaderboardEntries->findRankedForAward($definition->getCategory(), $dueKey, $limit);

        if ($ranked === []) {
            $archive->markSkipped($now);
            $definition->markClosed($dueKey, $now);
            $this->em->flush();

            $pruned = $this->leaderboardEntries->deleteForPeriod($definition->getCategory(), $dueKey);
            $this->invalidateBothKeys($definition, $dueKey, $now);

            return [
                'definition' => (string) $definition,
                'period'     => $dueKey,
                'result'     => 'skipped (no eligible club on the board)',
                'pruned'     => $pruned,
            ];
        }

        $entries = $this->freezeBoard($archive, $ranked);

        $awarded = $this->delivery->awardPlaces($definition, $archive, $entries, $now);

        $archive->markArchived($eligibleCount, count($entries), count($awarded), $now);
        $definition->markClosed($dueKey, $now);

        // ONE flush — archive status, every entry, every inbox message, each entry's
        // inbox_message_id, and the anchor. The CompetitionResultsService::finalizeRound()
        // shape: nothing describing this close is committed ahead of the rest of it.
        $this->em->flush();

        // Only now that the archive is committed is it safe to clear the live board. The
        // other way round, a failed flush would leave the period pruned with nothing
        // archived — the *_squad-prune precedent.
        $pruned = $this->leaderboardEntries->deleteForPeriod($definition->getCategory(), $dueKey);

        $this->invalidateBothKeys($definition, $dueKey, $now);

        // Pushes go out after the commit, never before: a push for an award that was rolled
        // back cannot be unsent.
        $this->delivery->notifyWinners($definition, $archive, $awarded);

        $this->logger->info('leaderboard.period.closed', [
            'definition'   => (string) $definition,
            'category'     => $definition->getCategory()->value,
            'period'       => $dueKey,
            'eligible'     => $eligibleCount,
            'archived'     => count($entries),
            'awarded'      => count($awarded),
            'prunedRows'   => $pruned,
        ]);

        return [
            'definition' => (string) $definition,
            'period'     => $dueKey,
            'result'     => 'archived',
            'eligible'   => $eligibleCount,
            'archived'   => count($entries),
            'awarded'    => count($awarded),
            'pruned'     => $pruned,
        ];
    }

    /**
     * Takes the close claim for (category, dueKey), or returns null if someone else holds
     * it.
     *
     * Raw DBAL with an un-targeted ON CONFLICT DO NOTHING, matching
     * RewardApplierService::recordClaim() — and raw specifically because a
     * UniqueConstraintViolationException out of flush() CLOSES the EntityManager, which in
     * a cron tick would strand every remaining definition. It is auto-committed on its own,
     * outside any wrapping transaction, so the claim survives a later failure rather than
     * silently re-opening the period.
     */
    private function claim(LeaderboardAwardDefinition $definition, string $dueKey, \DateTimeImmutable $now): ?LeaderboardPeriodArchive
    {
        $bounds = LeaderboardPeriod::boundsOf($dueKey);

        if ($bounds === null) {
            $this->logger->error('leaderboard.period.close.unparseable_key', ['period' => $dueKey]);

            return null;
        }

        $id = new UuidV7();

        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO leaderboard_period_archive
                (id, definition_id, category, cadence, definition_name, period_key,
                 period_starts_at, period_ends_at, baseline_period_key,
                 entry_count, archived_entry_count, placements_awarded, status, claimed_at)
            VALUES
                (:id, :definitionId, :category, :cadence, :definitionName, :periodKey,
                 :periodStartsAt, :periodEndsAt, :baselinePeriodKey,
                 0, 0, 0, :status, :claimedAt)
            ON CONFLICT DO NOTHING
            SQL,
            [
                'id'                => $id->toRfc4122(),
                'definitionId'      => $definition->getId()->toRfc4122(),
                'category'          => $definition->getCategory()->value,
                'cadence'           => $definition->getCadence()->value,
                'definitionName'    => $definition->getName(),
                'periodKey'         => $dueKey,
                'periodStartsAt'    => $bounds[0]->format('Y-m-d H:i:s'),
                'periodEndsAt'      => $bounds[1]->format('Y-m-d H:i:s'),
                'baselinePeriodKey' => $definition->getLastClosedPeriodKey(),
                'status'            => PeriodArchiveStatus::PENDING->value,
                'claimedAt'         => $now->format('Y-m-d H:i:s'),
            ],
        );

        if ($affected > 0) {
            // Hydrate the row this process just inserted, so the rest of the close works
            // through the ORM.
            return $this->archives->findOneForClaim($definition->getCategory(), $dueKey);
        }

        return $this->reclaimIfAbandoned($definition, $dueKey, $now);
    }

    /**
     * The claim was already taken. Skip unless its lease has expired, in which case take it
     * over and discard whatever partial entries the abandoned attempt left behind.
     */
    private function reclaimIfAbandoned(LeaderboardAwardDefinition $definition, string $dueKey, \DateTimeImmutable $now): ?LeaderboardPeriodArchive
    {
        $existing = $this->archives->findOneForClaim($definition->getCategory(), $dueKey);

        if ($existing === null) {
            return null;
        }

        if ($existing->getStatus()->isTerminal()) {
            return null;
        }

        if (!$existing->leaseHasExpired($now)) {
            return null;
        }

        // Conditional UPDATE so two processes cannot both reclaim. Raw, for the same
        // EntityManager-closing reason as the insert.
        $won = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE leaderboard_period_archive
               SET claimed_at = :now
             WHERE id = :id
               AND status = :pending
               AND claimed_at < :reclaimBefore
            SQL,
            [
                'now'           => $now->format('Y-m-d H:i:s'),
                'id'            => $existing->getId()->toRfc4122(),
                'pending'       => PeriodArchiveStatus::PENDING->value,
                'reclaimBefore' => $now->modify('-' . LeaderboardPeriodArchive::CLAIM_LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ],
        );

        if ($won === 0) {
            return null;
        }

        $this->em->refresh($existing);
        $existing->reclaim($now);

        // A pending archive's entries are by definition incomplete. Discarding them
        // wholesale is exactly what lets the entry write below use the plain ORM on a retry
        // without tripping uq_lpae_archive_rank.
        $discarded = $this->archiveEntries->deleteForArchiveId($existing->getId()->toRfc4122());

        $this->logger->warning('leaderboard.period.close.reclaimed', [
            'period'           => $dueKey,
            'category'         => $definition->getCategory()->value,
            'discardedEntries' => $discarded,
        ]);

        return $existing;
    }

    /**
     * Freezes the ranked board onto the archive.
     *
     * Ranks are assigned POSITIONALLY from this read, never copied from
     * leaderboard_entry.rank_position — that column is written by assignRanks() and read by
     * nothing, so trusting it would let the archive inherit a stale rank.
     *
     * @param  LeaderboardEntry[] $ranked
     * @return LeaderboardPeriodArchiveEntry[]
     */
    private function freezeBoard(LeaderboardPeriodArchive $archive, array $ranked): array
    {
        $entries = [];
        $rank    = 1;

        foreach ($ranked as $entry) {
            $club = $entry->getClub();

            $archiveEntry = new LeaderboardPeriodArchiveEntry(
                archive: $archive,
                rank: $rank,
                clubId: (string) $club->getId(),
                clubName: $club->getName(),
                userId: (string) $club->getUser()->getId(),
                score: $entry->getScore(),
                cumulativeScore: $entry->getCumulativeScore() ?? $entry->getScore(),
                baselineScore: $entry->getBaselineScore() ?? 0,
                baselineMissing: !$entry->hasBaseline(),
                awardEligible: $this->isAwardEligible($entry, $club, $archive),
                displayLabel: $entry->getDisplayLabel(),
                homeKitConfig: $club->getHomeKitConfig(),
                awayKitConfig: $club->getAwayKitConfig(),
                badgeConfig: $club->getBadgeConfig(),
                countryCode: $club->getCountry(),
            );

            $this->em->persist($archiveEntry);
            $entries[] = $archiveEntry;
            $rank++;
        }

        return $entries;
    }

    /**
     * THE baseline-missing rule, which the archive then records per row so it explains its
     * own exclusions:
     *
     *  | case                                              | baseline | board | eligible |
     *  |---------------------------------------------------|----------|-------|----------|
     *  | has a baseline                                    | its own  | yes   | yes      |
     *  | none, club created at/after the period start      | 0        | yes   | YES      |
     *  | none, club created before the period start        | 0        | yes   | no       |
     *
     * The second row did all of its accumulating inside the period, so its cumulative IS
     * its growth. The third covers a club crossing the currentSeason > 1 gate mid-period:
     * its cumulative is partly pre-period, so it is listed honestly but cannot win.
     */
    private function isAwardEligible(LeaderboardEntry $entry, Club $club, LeaderboardPeriodArchive $archive): bool
    {
        if ($entry->hasBaseline()) {
            return true;
        }

        return $club->getCreatedAt() >= $archive->getPeriodStartsAt();
    }

    /**
     * Invalidate the closed key AND the newly-open key: a cached page for the closed period
     * must not outlive its rows, and the new period's board starts at zeros.
     */
    private function invalidateBothKeys(LeaderboardAwardDefinition $definition, string $closedKey, \DateTimeImmutable $now): void
    {
        $this->calculation->invalidate($definition->getCategory(), $closedKey);
        $this->calculation->invalidate($definition->getCategory(), $definition->currentPeriodKey($now));
    }

    /**
     * --dry-run: scores and ranks nothing, claims nothing, writes nothing.
     *
     * @return array<string, mixed>
     */
    private function describeDryRun(LeaderboardAwardDefinition $definition, string $dueKey): array
    {
        $eligible = $this->leaderboardEntries->countEligible($definition->getCategory(), $dueKey);
        $top      = $this->leaderboardEntries->findRankedForAward($definition->getCategory(), $dueKey, max(1, $definition->getDepth()));

        $places = [];
        $rank   = 1;
        foreach ($top as $entry) {
            $places[] = [
                'rank'  => $rank,
                'club'  => $entry->getClub()->getName(),
                'score' => $entry->getScore(),
                'wouldAward' => $definition->getPlacementForPlace($rank) !== null
                    && $entry->getScore() >= $definition->getMinimumScore()
                    && ($entry->hasBaseline() || $entry->getClub()->getCreatedAt() >= (LeaderboardPeriod::boundsOf($dueKey)[0] ?? new \DateTimeImmutable())),
            ];
            $rank++;
        }

        return [
            'definition' => (string) $definition,
            'period'     => $dueKey,
            'result'     => 'dry run',
            'eligible'   => $eligible,
            'places'     => $places,
        ];
    }
}
