<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\CompetitionResponseCache;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use Doctrine\DBAL\Connection;

/**
 * One read-only snapshot of whether competition processing is keeping up.
 *
 * This exists because every bound in the competition pipeline — the claim lease, the
 * consumer's time and memory limits, the one-minute cron cadence — was set from a
 * benchmark on one box, and nothing in production reports whether those settings still
 * hold. Before this, a mis-tuning surfaced first as a player noticing a late draw.
 *
 * **Everything here is DBAL and read-only**, for the same two reasons the response cache is
 * (see CompetitionResponseCacheRepository): a failed ORM flush closes the shared
 * EntityManager, and this is meant to be safe to run from cron, from the admin, or by hand
 * against production while rounds are resolving. It takes no locks and writes nothing, so it
 * can never be the reason a round fails to process.
 *
 * It deliberately reports **state**, not counters. Queue depth, consumer lag, stale claims
 * and overdue rounds are all derivable from rows that already exist, so there is no metrics
 * store to keep and nothing to drift. The one thing state cannot give is cache hit *rate* —
 * a ratio over requests, not a fact about the present — which is why
 * CompetitionResponseCacheService logs each build instead; see its docblock.
 */
class CompetitionHealthService
{
    /** The transport round processing is drained from — docker/competition-consume.sh. */
    public const TRANSPORT_QUEUE = 'competition';

    /**
     * How late a due round may be before it is called overdue.
     *
     * Three cron ticks. The dispatch commands and the consumer both run every minute, so a
     * round is normally queued within 60s and drained on the following tick; one tick of
     * slack absorbs a skipped run (flock) and a tick absorbs a long message. Past that, the
     * pipeline is genuinely behind rather than merely between ticks.
     */
    public const OVERDUE_GRACE_SECONDS = 180;

    /** Consumer lag past which the queue is not being drained at the cadence it assumes. */
    public const QUEUE_LAG_WARN_SECONDS = 120;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array{
     *     at: string,
     *     queue: array{transport: string, pending: int, oldestPendingSeconds: ?int, failed: int, unavailable?: string},
     *     rounds: array{claimed: int, staleClaims: int, overdueDraw: int, overdueResolve: int, worstOverdueSeconds: ?int},
     *     cache: array{rows: int, openCompetitions: int, openWithoutRow: int, stalePayloadVersion: int, oldestGeneratedSeconds: ?int},
     *     warnings: list<string>,
     *     healthy: bool
     * }
     */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        // UTC explicitly. messenger_messages and every timestamp here is `timestamp without
        // time zone` holding UTC, so comparing against a local-time `now()` would silently
        // read lag as hours off in either direction depending on the box's zone.
        $now    = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
        $nowSql = $now->format('Y-m-d H:i:s');

        $queue  = $this->queueSnapshot($nowSql);
        $rounds = $this->roundSnapshot($nowSql);
        $cache  = $this->cacheSnapshot($nowSql);

        $warnings = [];

        if ($rounds['staleClaims'] > 0) {
            $warnings[] = sprintf(
                '%d round claim(s) are past the %ds lease — a consumer died mid-round. They are reclaimable, so they should clear on the next tick; if the count persists, a round is failing repeatedly.',
                $rounds['staleClaims'],
                CompetitionRound::CLAIM_LEASE_SECONDS,
            );
        }

        if ($rounds['overdueDraw'] > 0 || $rounds['overdueResolve'] > 0) {
            $warnings[] = sprintf(
                '%d round(s) are due and unclaimed by more than %ds (%d draw, %d resolve; worst %ds). Either the dispatch cron is not running or the consumer is not keeping up.',
                $rounds['overdueDraw'] + $rounds['overdueResolve'],
                self::OVERDUE_GRACE_SECONDS,
                $rounds['overdueDraw'],
                $rounds['overdueResolve'],
                $rounds['worstOverdueSeconds'] ?? 0,
            );
        }

        if ($queue['oldestPendingSeconds'] !== null && $queue['oldestPendingSeconds'] > self::QUEUE_LAG_WARN_SECONDS) {
            $warnings[] = sprintf(
                'Oldest undrained `%s` message is %ds old (>%ds) with %d pending — competition-consume.sh is behind its one-minute cadence.',
                self::TRANSPORT_QUEUE,
                $queue['oldestPendingSeconds'],
                self::QUEUE_LAG_WARN_SECONDS,
                $queue['pending'],
            );
        }

        if ($queue['failed'] > 0) {
            $warnings[] = sprintf('%d message(s) in the failed transport — inspect with messenger:failed:show.', $queue['failed']);
        }

        if ($cache['stalePayloadVersion'] > 0) {
            $warnings[] = sprintf(
                '%d cached document(s) predate payload version %d — serve correctly but rebuild on first read; `app:competition:cache:warm --stale-only` clears it.',
                $cache['stalePayloadVersion'],
                CompetitionResponseCache::PAYLOAD_VERSION,
            );
        }

        return [
            'at'       => $now->format(DATE_ATOM),
            'queue'    => $queue,
            'rounds'   => $rounds,
            'cache'    => $cache,
            'warnings' => $warnings,
            'healthy'  => $warnings === [],
        ];
    }

    /**
     * @return array{transport: string, pending: int, oldestPendingSeconds: ?int, failed: int, unavailable?: string}
     */
    private function queueSnapshot(string $nowSql): array
    {
        // `messenger_messages` is created lazily by the Doctrine transport's auto-setup, so
        // it genuinely does not exist until something has been dispatched — on a fresh
        // database, or a brand-new deploy before the first cron tick. Querying it there
        // raises "relation does not exist" and would make a read-only health check the thing
        // that fails. StarterPoolAuditService::queueIsBusy() swallows the same error for the
        // same reason; this reports the absence instead of pretending the queue is empty,
        // because "no table" and "nothing queued" are different facts to an operator.
        if (!$this->messengerTableExists()) {
            return [
                'transport'            => self::TRANSPORT_QUEUE,
                'pending'              => 0,
                'oldestPendingSeconds' => null,
                'failed'               => 0,
                'unavailable'          => 'messenger_messages does not exist yet — nothing has been dispatched on this database',
            ];
        }

        // `delivered_at IS NULL` is the same undrained test StarterPoolAuditService::queueIsBusy()
        // uses. Lag is measured from available_at rather than created_at so a deliberately
        // delayed or retried message is not reported as backlog it is not.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS pending,
                   MAX(EXTRACT(EPOCH FROM (CAST(:now AS timestamp) - available_at))) AS oldest_seconds
              FROM messenger_messages
             WHERE queue_name = :queue
               AND delivered_at IS NULL
               AND available_at <= CAST(:now AS timestamp)
            SQL,
            ['now' => $nowSql, 'queue' => self::TRANSPORT_QUEUE],
        ) ?: [];

        $failed = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );

        $oldest = $row['oldest_seconds'] ?? null;

        return [
            'transport'            => self::TRANSPORT_QUEUE,
            'pending'              => (int) ($row['pending'] ?? 0),
            'oldestPendingSeconds' => $oldest === null ? null : (int) $oldest,
            'failed'               => $failed,
        ];
    }

    /**
     * @return array{claimed: int, staleClaims: int, overdueDraw: int, overdueResolve: int, worstOverdueSeconds: ?int}
     */
    private function roundSnapshot(string $nowSql): array
    {
        // Scoped to live competitions throughout: a cancelled or completed competition's
        // rounds are not due for anything, and counting them would make the overdue figure
        // grow forever instead of reporting the present.
        $row = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COUNT(*) FILTER (
                    WHERE r.draw_locked_at IS NOT NULL OR r.resolve_locked_at IS NOT NULL
                ) AS claimed,
                COUNT(*) FILTER (
                    WHERE (r.draw_locked_at IS NOT NULL AND r.draw_locked_at < CAST(:leaseBefore AS timestamp))
                       OR (r.resolve_locked_at IS NOT NULL AND r.resolve_locked_at < CAST(:leaseBefore AS timestamp))
                ) AS stale_claims,
                COUNT(*) FILTER (
                    WHERE r.status = :drawPending
                      AND r.draw_locked_at IS NULL
                      AND r.scheduled_at IS NOT NULL
                      AND r.scheduled_at < CAST(:overdueBefore AS timestamp)
                ) AS overdue_draw,
                COUNT(*) FILTER (
                    WHERE r.status = :drawn
                      AND r.resolve_locked_at IS NULL
                      AND r.matches_resolve_at IS NOT NULL
                      AND r.matches_resolve_at < CAST(:overdueBefore AS timestamp)
                ) AS overdue_resolve,
                MAX(
                    CASE
                        WHEN r.status = :drawPending AND r.draw_locked_at IS NULL AND r.scheduled_at < CAST(:overdueBefore AS timestamp)
                            THEN EXTRACT(EPOCH FROM (CAST(:now AS timestamp) - r.scheduled_at))
                        WHEN r.status = :drawn AND r.resolve_locked_at IS NULL AND r.matches_resolve_at < CAST(:overdueBefore AS timestamp)
                            THEN EXTRACT(EPOCH FROM (CAST(:now AS timestamp) - r.matches_resolve_at))
                        ELSE NULL
                    END
                ) AS worst_overdue_seconds
              FROM competition_round r
              JOIN active_competition c ON c.id = r.active_competition_id
             WHERE c.status IN (:liveStatuses)
            SQL,
            [
                'now'           => $nowSql,
                'leaseBefore'   => $this->shift($nowSql, CompetitionRound::CLAIM_LEASE_SECONDS),
                'overdueBefore' => $this->shift($nowSql, self::OVERDUE_GRACE_SECONDS),
                'drawPending'   => CompetitionRoundStatus::DRAW_PENDING->value,
                'drawn'         => CompetitionRoundStatus::DRAWN->value,
                'liveStatuses'  => [
                    ActiveCompetitionStatus::SCHEDULED->value,
                    ActiveCompetitionStatus::RUNNING->value,
                ],
            ],
            ['liveStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ) ?: [];

        $worst = $row['worst_overdue_seconds'] ?? null;

        return [
            'claimed'             => (int) ($row['claimed'] ?? 0),
            'staleClaims'         => (int) ($row['stale_claims'] ?? 0),
            'overdueDraw'         => (int) ($row['overdue_draw'] ?? 0),
            'overdueResolve'      => (int) ($row['overdue_resolve'] ?? 0),
            'worstOverdueSeconds' => $worst === null ? null : (int) $worst,
        ];
    }

    /**
     * @return array{rows: int, openCompetitions: int, openWithoutRow: int, stalePayloadVersion: int, oldestGeneratedSeconds: ?int}
     */
    private function cacheSnapshot(string $nowSql): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS rows_total,
                   COUNT(*) FILTER (WHERE payload_version <> :currentVersion) AS stale_version,
                   MAX(EXTRACT(EPOCH FROM (CAST(:now AS timestamp) - generated_at))) AS oldest_seconds
              FROM competition_response_cache
            SQL,
            ['now' => $nowSql, 'currentVersion' => CompetitionResponseCache::PAYLOAD_VERSION],
        ) ?: [];

        // "Open" is every status a client can still fetch a bracket for. A missing row is not
        // an error — the cache is built on demand and self-validating — so this is reported
        // as coverage, never as a warning.
        $open = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT COUNT(*) AS open_total,
                   COUNT(*) FILTER (WHERE rc.active_competition_id IS NULL) AS without_row
              FROM active_competition c
              LEFT JOIN competition_response_cache rc ON rc.active_competition_id = c.id
             WHERE c.status IN (:liveStatuses)
            SQL,
            [
                'liveStatuses' => [
                    ActiveCompetitionStatus::REGISTERING->value,
                    ActiveCompetitionStatus::SCHEDULED->value,
                    ActiveCompetitionStatus::RUNNING->value,
                ],
            ],
            ['liveStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING],
        ) ?: [];

        $oldest = $row['oldest_seconds'] ?? null;

        return [
            'rows'                   => (int) ($row['rows_total'] ?? 0),
            'openCompetitions'       => (int) ($open['open_total'] ?? 0),
            'openWithoutRow'         => (int) ($open['without_row'] ?? 0),
            'stalePayloadVersion'    => (int) ($row['stale_version'] ?? 0),
            'oldestGeneratedSeconds' => $oldest === null ? null : (int) $oldest,
        ];
    }

    private function messengerTableExists(): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist(['messenger_messages']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** `$nowSql` moved back by `$seconds`, as a SQL timestamp string. */
    private function shift(string $nowSql, int $seconds): string
    {
        return (new \DateTimeImmutable($nowSql, new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', $seconds))
            ->format('Y-m-d H:i:s');
    }
}
