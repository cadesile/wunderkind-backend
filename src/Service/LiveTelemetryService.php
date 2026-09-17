<?php

namespace App\Service;

use App\Repository\LiveTelemetrySnapshotRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Entity\LiveTelemetrySnapshot;

/**
 * Aggregates SyncRecord.payload JSON from the last 24h, plus recent promotion/
 * relegation/title events from SeasonRecord and real high-cost ledger/attendance
 * lines — every line names the real club — into the cached LiveTelemetrySnapshot
 * singleton for the landing page's "Chairman's Terminal" widget. All 3 macro
 * counters are backed by real data — see the aggregate() docblock for exactly how
 * each is computed. The feed shows real events only: the template no longer pads
 * it with illustrative filler (sackings, contract disputes, youth intake) — those
 * had no backing data anywhere in this codebase.
 */
class LiveTelemetryService
{
    private const WINDOW_HOURS = 24;

    /** Season conclusions are much rarer than syncs, so look back further for events. */
    private const EVENTS_WINDOW_DAYS = 7;
    private const EVENTS_LIMIT       = 8;

    private const LEDGER_EVENTS_LIMIT     = 5;
    private const ATTENDANCE_EVENTS_LIMIT = 5;

    /** Each sync represents ~4 weeks of on-device ticks — used only for the footer's "weeks played" figure. */
    private const WEEKS_PER_SYNC = 4;

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

        $syncRows = $this->syncRecordRepository->findValidPayloadsSince($since);
        $result   = self::aggregate(array_column($syncRows, 'payload'));

        $pyramidRows = $this->seasonRecordRepository->findRecentPyramidEvents(
            $now->modify('-' . self::EVENTS_WINDOW_DAYS . ' days'),
            self::EVENTS_LIMIT,
        );
        $attendanceRows = $this->syncRecordRepository->findTopAttendanceSince($since, self::ATTENDANCE_EVENTS_LIMIT);

        $events = [
            ...self::buildEvents($pyramidRows, $now),
            ...self::buildLedgerEvents($syncRows, $now, self::LEDGER_EVENTS_LIMIT),
            ...self::buildAttendanceEvents($attendanceRows, $now),
        ];

        $activeClubs = $this->syncRecordRepository->countActiveClubsSince($since);
        $weeksPlayed = count($syncRows) * self::WEEKS_PER_SYNC;

        $snapshot = $this->snapshotRepository->getSnapshot();
        $snapshot->update(
            $result['fixturesSimulated'],
            $result['capitalDeployedPence'],
            $result['wins'],
            $result['draws'],
            $result['losses'],
            $activeClubs,
            $weeksPlayed,
            $events,
        );
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
     * wins/draws/losses/fixturesSimulated: tallied directly from each payload's
     * form[] (last ≤5 results, newest first) — no delta or baseline comparison against
     * a prior sync. This is a deliberate simplification: real clients don't currently
     * send matchResults[] or any other clean "games since last sync" delta, and a club
     * syncing more than once within the window (or with an unchanged form[] between
     * syncs) will have some results counted more than once. Revisit once the sync
     * payload carries better-suited data for this.
     *
     * capitalDeployedPence: sum of |ledger[] entries| in the "upkeep"/"wages" categories
     * (always-negative spend) plus transfers[].grossFee for incoming "signing"/
     * "agent_assisted" transfers (money paid to acquire a player). Deliberately excludes
     * revenue categories (matchday_income, sponsor_payment) and "sale" transfers (money in).
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array{fixturesSimulated: int, capitalDeployedPence: int, wins: int, draws: int, losses: int}
     */
    public static function aggregate(array $payloads): array
    {
        $capitalDeployedPence = 0;
        $wins = 0;
        $draws = 0;
        $losses = 0;

        foreach ($payloads as $payload) {
            foreach ($payload['form'] ?? [] as $result) {
                match ($result) {
                    'W'     => $wins++,
                    'D'     => $draws++,
                    'L'     => $losses++,
                    default => null,
                };
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
            'fixturesSimulated'    => $wins + $draws + $losses,
            'capitalDeployedPence' => $capitalDeployedPence,
            'wins'                 => $wins,
            'draws'                => $draws,
            'losses'               => $losses,
        ];
    }

    /**
     * Turns SeasonRecordRepository::findRecentPyramidEvents() rows into feed-ready
     * {time, text} lines. Pure function (given $now) — unit-testable without a clock
     * or a database. Names the real club (see findRecentPyramidEvents()'s docblock
     * for why that carries no moderation risk).
     *
     * @param array<int, array{tier: int, promoted: bool, relegated: bool, finalPosition: int, createdAt: \DateTimeImmutable, clubName: string}> $rows
     * @return array<int, array{time: string, text: string}>
     */
    public static function buildEvents(array $rows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($rows as $row) {
            $text = self::describeOutcome((string) $row['clubName'], (int) $row['tier'], (bool) $row['promoted'], (bool) $row['relegated'], (int) $row['finalPosition']);
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

    /**
     * The highest-magnitude ledger[] spend entries (negative amounts only — "especially
     * high cost", per the brief) across the batch of sync rows, ranked regardless of
     * category so one-off items (a scouting mission, a facility auto-repair) naturally
     * outrank routine weekly payroll/upkeep. Names the real spending club (see
     * SyncRecordRepository::findValidPayloadsSince()'s docblock for why that carries no
     * moderation risk) followed by the entry's own description verbatim — the
     * description itself is client-generated narrative text (may name a player,
     * generated fiction) — see findValidPayloadsSince() for the row shape.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array{time: string, text: string}>
     */
    public static function buildLedgerEvents(array $syncRows, \DateTimeImmutable $now, int $limit): array
    {
        $entries = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['ledger'] ?? [] as $entry) {
                $amountPence = (int) ($entry['amount'] ?? 0);
                $description = trim((string) ($entry['description'] ?? ''));
                if ($amountPence >= 0 || $description === '') {
                    continue;
                }

                $entries[] = [
                    'amountPence'     => $amountPence,
                    'description'     => $description,
                    'clubName'        => $row['clubName'],
                    'serverTimestamp' => $row['serverTimestamp'],
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['amountPence'] <=> $b['amountPence']);

        return array_map(
            static fn (array $entry): array => [
                'time' => self::relativeTime($entry['serverTimestamp'], $now),
                'text' => sprintf('%s spent %s: %s', $entry['clubName'], LiveTelemetrySnapshot::formatPence(abs($entry['amountPence'])), $entry['description']),
            ],
            array_slice($entries, 0, $limit),
        );
    }

    /**
     * Turns SyncRecordRepository::findTopAttendanceSince() rows into feed-ready
     * {time, text} lines. Unlike buildEvents()/buildLedgerEvents(), this names the real
     * club — see findTopAttendanceSince()'s docblock for why that's not a moderation risk
     * here (curated name-options, not free text).
     *
     * @param array<int, array{clubName: string, fanCount: int, serverTimestamp: \DateTimeImmutable}> $rows
     * @return array<int, array{time: string, text: string}>
     */
    public static function buildAttendanceEvents(array $rows, \DateTimeImmutable $now): array
    {
        return array_map(
            static fn (array $row): array => [
                'time' => self::relativeTime($row['serverTimestamp'], $now),
                'text' => sprintf('%s recorded attendance of %s!', $row['clubName'], number_format($row['fanCount'])),
            ],
            $rows,
        );
    }

    private static function describeOutcome(string $clubName, int $tier, bool $promoted, bool $relegated, int $finalPosition): ?string
    {
        if ($finalPosition === 1) {
            return "{$clubName} lifted the Tier {$tier} title.";
        }
        if ($promoted) {
            return "{$clubName} won promotion from Tier {$tier}.";
        }
        if ($relegated) {
            return "{$clubName} was relegated from Tier {$tier}.";
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
