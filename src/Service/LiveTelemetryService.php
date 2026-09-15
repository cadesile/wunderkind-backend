<?php

namespace App\Service;

use App\Repository\LiveTelemetrySnapshotRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Entity\LiveTelemetrySnapshot;

/**
 * Aggregates SyncRecord.payload JSON from the last 24h, plus recent anonymised
 * promotion/relegation/title events from SeasonRecord, into the cached
 * LiveTelemetrySnapshot singleton for the landing page's "Chairman's Terminal"
 * widget. All 3 macro counters are backed by real data — see
 * aggregateCapitalDeployed()/aggregateSeasonActivity() for exactly how each is
 * computed. Only the feed's remaining illustrative lines (sackings, contract
 * disputes, youth intake) have no backing data anywhere in this codebase.
 */
class LiveTelemetryService
{
    private const WINDOW_HOURS = 24;

    /** Season conclusions are much rarer than syncs, so look back further for events. */
    private const EVENTS_WINDOW_DAYS = 7;
    private const EVENTS_LIMIT       = 8;

    /** Ledger categories that represent money leaving the club (spend, not revenue). */
    private const SPEND_LEDGER_CATEGORIES = ['upkeep', 'wages'];

    /** Transfer types that represent money paid OUT to acquire a player. */
    private const SPEND_TRANSFER_TYPES = ['signing', 'agent_assisted'];

    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly LiveTelemetrySnapshotRepository $snapshotRepository,
    ) {}

    public function refresh(): void
    {
        $now   = new \DateTimeImmutable();
        $since = $now->modify('-' . self::WINDOW_HOURS . ' hours');

        $capitalDeployedPence = self::aggregateCapitalDeployed(
            $this->syncRecordRepository->findValidPayloadsSince($since),
        );

        $latestPerClub   = $this->syncRecordRepository->findLatestValidPayloadPerClubSince($since);
        $baselinePerClub = $this->syncRecordRepository->findLatestValidPayloadPerClubBefore($since, array_keys($latestPerClub));
        $activity        = self::aggregateSeasonActivity($latestPerClub, $baselinePerClub);

        $rows   = $this->seasonRecordRepository->findRecentPyramidEvents(
            $now->modify('-' . self::EVENTS_WINDOW_DAYS . ' days'),
            self::EVENTS_LIMIT,
        );
        $events = self::buildEvents($rows, $now);

        $snapshot = $this->snapshotRepository->getSnapshot();
        $snapshot->update($activity['fixturesSimulated'], $capitalDeployedPence, $activity['goalsScored'], $events);
        $this->snapshotRepository->getEntityManager()->flush();
    }

    public function getSnapshot(): LiveTelemetrySnapshot
    {
        return $this->snapshotRepository->getSnapshot();
    }

    /**
     * Sum of |ledger[] entries| in the "upkeep"/"wages" categories (always-negative
     * spend) plus transfers[].grossFee for incoming "signing"/"agent_assisted"
     * transfers (money paid to acquire a player). Deliberately excludes revenue
     * categories (matchday_income, sponsor_payment) and "sale" transfers (money in).
     * Each sync's ledger/transfers already represent just that tick's activity, so
     * this sums across every valid payload in the window — no per-club deltas needed.
     *
     * @param array<int, array<string, mixed>> $payloads
     */
    public static function aggregateCapitalDeployed(array $payloads): int
    {
        $capitalDeployedPence = 0;

        foreach ($payloads as $payload) {
            foreach ($payload['ledger'] ?? [] as $entry) {
                if (in_array($entry['category'] ?? null, self::SPEND_LEDGER_CATEGORIES, true)) {
                    $capitalDeployedPence += abs((int) ($entry['amount'] ?? 0));
                }
            }

            foreach ($payload['transfers'] ?? [] as $transfer) {
                if (in_array($transfer['type'] ?? null, self::SPEND_TRANSFER_TYPES, true)) {
                    $capitalDeployedPence += (int) ($transfer['grossFee'] ?? 0);
                }
            }
        }

        return $capitalDeployedPence;
    }

    /**
     * fixturesSimulated / goalsScored, derived from the CHANGE in each club's cumulative
     * seasonRecord (wins+draws+losses, goalsFor) between their latest sync in the window
     * and their latest sync before it.
     *
     * Real clients don't currently populate SyncRequest::$matchResults (confirmed against
     * production payloads — present fields are seasonRecord/form/ledger/transfers, never
     * matchResults), so that field can't be used despite SyncRequest defining and
     * validating it. seasonRecord is the one reliably-sent field that implies fixture
     * count, but it's a season-to-date running total, not a per-tick delta — hence diffing
     * against each club's own prior sync rather than summing it directly (which would
     * double-count a club's whole season history on every refresh).
     *
     * A club with no sync before the window contributes nothing (no baseline to diff
     * against — safer to undercount than fabricate a number). A negative delta (a season
     * rollover reset the cumulative totals between the two syncs) clamps to 0 rather than
     * going negative — this can undercount the handful of fixtures either side of a
     * rollover, an accepted simplification given seasonRecord carries no season number to
     * detect the boundary precisely.
     *
     * @param array<string, array<string, mixed>> $latestPerClub payload keyed by club id
     * @param array<string, array<string, mixed>> $baselinePerClub payload keyed by club id
     * @return array{fixturesSimulated: int, goalsScored: int}
     */
    public static function aggregateSeasonActivity(array $latestPerClub, array $baselinePerClub): array
    {
        $fixturesSimulated = 0;
        $goalsScored       = 0;

        foreach ($latestPerClub as $clubId => $latest) {
            $baseline = $baselinePerClub[$clubId] ?? null;
            if ($baseline === null) {
                continue;
            }

            $fixturesSimulated += max(0, self::gamesPlayed($latest) - self::gamesPlayed($baseline));
            $goalsScored       += max(0, self::goalsFor($latest) - self::goalsFor($baseline));
        }

        return [
            'fixturesSimulated' => $fixturesSimulated,
            'goalsScored'       => $goalsScored,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function gamesPlayed(array $payload): int
    {
        $sr = $payload['seasonRecord'] ?? [];

        return (int) ($sr['wins'] ?? 0) + (int) ($sr['draws'] ?? 0) + (int) ($sr['losses'] ?? 0);
    }

    /** @param array<string, mixed> $payload */
    private static function goalsFor(array $payload): int
    {
        return (int) ($payload['seasonRecord']['goalsFor'] ?? 0);
    }

    /**
     * Turns SeasonRecordRepository::findRecentPyramidEvents() rows into feed-ready
     * {time, text} lines. Pure function (given $now) — unit-testable without a clock
     * or a database. Never receives or emits a club identifier: the row shape itself
     * only carries tier + outcome, so there is nothing here that could leak a real
     * club's chosen name.
     *
     * @param array<int, array{tier: int, promoted: bool, relegated: bool, finalPosition: int, createdAt: \DateTimeImmutable}> $rows
     * @return array<int, array{time: string, text: string}>
     */
    public static function buildEvents(array $rows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($rows as $row) {
            $text = self::describeOutcome((int) $row['tier'], (bool) $row['promoted'], (bool) $row['relegated'], (int) $row['finalPosition']);
            if ($text === null) {
                continue;
            }

            $events[] = [
                'time' => self::relativeTime($row['createdAt'], $now),
                'text' => $text,
            ];
        }

        return $events;
    }

    private static function describeOutcome(int $tier, bool $promoted, bool $relegated, int $finalPosition): ?string
    {
        if ($finalPosition === 1) {
            return "A Tier {$tier} club lifted the title.";
        }
        if ($promoted) {
            return "A Tier {$tier} club won promotion.";
        }
        if ($relegated) {
            return "A Tier {$tier} club was relegated.";
        }

        return null;
    }

    private static function relativeTime(\DateTimeImmutable $when, \DateTimeImmutable $now): string
    {
        $minutes = intdiv($now->getTimestamp() - $when->getTimestamp(), 60);

        if ($minutes < 60) {
            return max(1, $minutes) . 'm ago';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . 'h ago';
        }

        return intdiv($hours, 24) . 'd ago';
    }
}
