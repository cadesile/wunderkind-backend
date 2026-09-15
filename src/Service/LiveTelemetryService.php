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
 * widget. All 3 macro counters are backed by real data — see the aggregate()
 * docblock for exactly how each is computed. Only the feed's remaining
 * illustrative lines (sackings, contract disputes, youth intake) have no
 * backing data anywhere in this codebase.
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
        $now = new \DateTimeImmutable();

        $payloads = $this->syncRecordRepository->findValidPayloadsSince(
            $now->modify('-' . self::WINDOW_HOURS . ' hours'),
        );
        $result = self::aggregate($payloads);

        $rows   = $this->seasonRecordRepository->findRecentPyramidEvents(
            $now->modify('-' . self::EVENTS_WINDOW_DAYS . ' days'),
            self::EVENTS_LIMIT,
        );
        $events = self::buildEvents($rows, $now);

        $snapshot = $this->snapshotRepository->getSnapshot();
        $snapshot->update($result['fixturesSimulated'], $result['capitalDeployedPence'], $result['goalsScored'], $events);
        $this->snapshotRepository->getEntityManager()->flush();
    }

    public function getSnapshot(): LiveTelemetrySnapshot
    {
        return $this->snapshotRepository->getSnapshot();
    }

    /**
     * Pure aggregation over a batch of SyncRecord payloads — kept separate from the
     * repository fetch so it's unit-testable without a database.
     *
     * fixturesSimulated: count of matchResults[] entries across all payloads. A fixture
     * between two human-controlled clubs double-counts (once per side's sync) — accepted
     * simplification, since most fixtures are against NPC clubs.
     *
     * capitalDeployedPence: sum of |ledger[] entries| in the "upkeep"/"wages" categories
     * (always-negative spend) plus transfers[].grossFee for incoming "signing"/
     * "agent_assisted" transfers (money paid to acquire a player). Deliberately excludes
     * revenue categories (matchday_income, sponsor_payment) and "sale" transfers (money in).
     *
     * goalsScored: sum of goals scored BY the syncing club across matchResults[]. Prefers
     * the v2 shape (homeGoals/awayGoals + isHome) when present, since that's unambiguous
     * even on a 0-0 draw; falls back to the legacy goalsFor field only when the payload has
     * neither homeGoals nor awayGoals at all (a true pre-v2 client). Like fixturesSimulated,
     * a match between two human-controlled clubs contributes goals from both sides' syncs.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array{fixturesSimulated: int, capitalDeployedPence: int, goalsScored: int}
     */
    public static function aggregate(array $payloads): array
    {
        $fixturesSimulated    = 0;
        $capitalDeployedPence = 0;
        $goalsScored          = 0;

        foreach ($payloads as $payload) {
            $matches = $payload['matchResults'] ?? [];
            $fixturesSimulated += count($matches);

            foreach ($matches as $match) {
                $goalsScored += self::goalsScoredFor($match);
            }

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

        return [
            'fixturesSimulated'    => $fixturesSimulated,
            'capitalDeployedPence' => $capitalDeployedPence,
            'goalsScored'          => $goalsScored,
        ];
    }

    /** @param array<string, mixed> $match */
    private static function goalsScoredFor(array $match): int
    {
        if (array_key_exists('homeGoals', $match) || array_key_exists('awayGoals', $match)) {
            return (int) (($match['isHome'] ?? false) ? ($match['homeGoals'] ?? 0) : ($match['awayGoals'] ?? 0));
        }

        return (int) ($match['goalsFor'] ?? 0);
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
