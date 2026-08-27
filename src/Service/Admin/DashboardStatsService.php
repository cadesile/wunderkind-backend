<?php

namespace App\Service\Admin;

use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Repository\AgentRepository;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\LeaderboardCalculationService;
use App\Service\WorldInitializationService;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Every aggregate behind the admin home dashboard.
 *
 * The panels are fetched lazily by the page, so each one is cached
 * independently under `admin_stats.<panel>` — a slow panel never blocks the
 * shell from painting, and a Refresh only re-runs the panel it belongs to.
 *
 * All raw SQL in here is PostgreSQL-specific (`date_trunc`). That is
 * deliberately confined to this class: the project is Postgres-only, but the
 * dialect should not leak back out into controllers the way it did before.
 */
class DashboardStatsService
{
    public const TTL = 300;

    public const PANELS = ['growth', 'leaderboards', 'pool.players', 'pool.staff', 'pool.scouts', 'pool.agents', 'pool.world'];

    /** Window label => interval, in the order they are rendered on a KPI tile. */
    private const WINDOWS = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'];

    private const TREND_DAYS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly PlayerRepository $playerRepository,
        private readonly StaffRepository $staffRepository,
        private readonly ScoutRepository $scoutRepository,
        private readonly AgentRepository $agentRepository,
        private readonly NpcClubRepository $npcClubRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly CountryWorldPackCacheRepository $worldPackCacheRepository,
        private readonly LeaderboardCalculationService $leaderboardCalculationService,
        private readonly CacheInterface $cache,
    ) {}

    // ───────────────────────────────────────────────────────────── Growth ──

    /**
     * Window counts plus a 30-day daily series for every headline metric.
     *
     * @return array<string, mixed>
     */
    public function getGrowth(): array
    {
        return $this->cached('growth', function (): array {
            $guest = User::GUEST_EMAIL_DOMAIN;

            $users = $this->windowed('"user"', 'created_at');
            $users['guest'] = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM "user" WHERE email LIKE :d',
                ['d' => '%' . $guest]
            );
            $users['registered'] = $users['all'] - $users['guest'];

            $syncs   = $this->windowed('sync_record', 'server_timestamp');
            $invalid = $this->windowed('sync_record', 'server_timestamp', 'is_valid = false');

            return [
                'windows' => array_keys(self::WINDOWS),
                'metrics' => [
                    'users'        => $users,
                    'clubs'        => $this->windowed('club', 'created_at'),
                    'syncs'        => $syncs,
                    'invalidSyncs' => $invalid,
                    'activeClubs'  => $this->activeClubs(),
                ],
                'trend' => [
                    'days'   => $this->trendDayLabels(),
                    // Every headline metric gets a series so all four activity
                    // tiles are the same height — a tile missing its sparkline
                    // leaves a ragged row.
                    'series' => [
                        'users'        => $this->dailySeries('"user"', 'created_at'),
                        'clubs'        => $this->dailySeries('club', 'created_at'),
                        'syncs'        => $this->dailySeries('sync_record', 'server_timestamp'),
                        'invalidSyncs' => $this->dailySeries('sync_record', 'server_timestamp', 'is_valid = false'),
                        'activeClubs'  => $this->dailyDistinctClubs(),
                    ],
                ],
                'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];
        });
    }

    /**
     * `all` plus one count per rolling window.
     *
     * @return array{all: int, '24h': int, '7d': int, '30d': int}
     */
    private function windowed(string $table, string $column, ?string $extraWhere = null): array
    {
        $and = $extraWhere !== null ? " AND {$extraWhere}" : '';
        $where = $extraWhere !== null ? " WHERE {$extraWhere}" : '';

        $out = ['all' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$table}{$where}")];

        foreach (self::WINDOWS as $label => $interval) {
            $out[$label] = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$table} WHERE {$column} >= NOW() - INTERVAL '{$interval}'{$and}"
            );
        }

        return $out;
    }

    /**
     * Distinct clubs that have synced inside each window — a DAU/WAU proxy.
     * `all` is every club that has ever synced, not every club that exists.
     *
     * @return array{all: int, '24h': int, '7d': int, '30d': int}
     */
    private function activeClubs(): array
    {
        $out = ['all' => (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT club_id) FROM sync_record')];

        foreach (self::WINDOWS as $label => $interval) {
            $out[$label] = (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT club_id) FROM sync_record WHERE server_timestamp >= NOW() - INTERVAL '{$interval}'"
            );
        }

        return $out;
    }

    /** @return list<string> ISO dates, oldest first. */
    private function trendDayLabels(): array
    {
        $labels = [];
        $day    = (new \DateTimeImmutable('today'))->modify('-' . (self::TREND_DAYS - 1) . ' days');

        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $labels[] = $day->format('Y-m-d');
            $day      = $day->modify('+1 day');
        }

        return $labels;
    }

    /**
     * Daily counts over the trend window, gap-filled in PHP so the chart has
     * one point per day even on days with no rows.
     *
     * @return list<int>
     */
    private function dailySeries(string $table, string $column, ?string $extraWhere = null): array
    {
        $and = $extraWhere !== null ? " AND {$extraWhere}" : '';

        $rows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', {$column}), 'YYYY-MM-DD') AS d, COUNT(*) AS cnt
             FROM {$table}
             WHERE {$column} >= date_trunc('day', NOW()) - INTERVAL '" . (self::TREND_DAYS - 1) . " days'{$and}
             GROUP BY 1 ORDER BY 1"
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = (int) $row['cnt'];
        }

        return array_map(static fn (string $day): int => $byDay[$day] ?? 0, $this->trendDayLabels());
    }

    /**
     * Distinct clubs syncing per day — the daily counterpart to activeClubs().
     * Deliberately not a sum: a club syncing twice in a day counts once.
     *
     * @return list<int>
     */
    private function dailyDistinctClubs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', server_timestamp), 'YYYY-MM-DD') AS d, COUNT(DISTINCT club_id) AS cnt
             FROM sync_record
             WHERE server_timestamp >= date_trunc('day', NOW()) - INTERVAL '" . (self::TREND_DAYS - 1) . " days'
             GROUP BY 1 ORDER BY 1"
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = (int) $row['cnt'];
        }

        return array_map(static fn (string $day): int => $byDay[$day] ?? 0, $this->trendDayLabels());
    }

    // ─────────────────────────────────────────────────────── Leaderboards ──

    /**
     * Top 10 per category on the all-time board.
     *
     * Deliberately routed through the same service the public API uses, so the
     * dashboard shows exactly what clients see — including the
     * `currentSeason > 1` filter. A hand-rolled admin query that quietly
     * disagreed with the live board would be worse than no panel at all.
     *
     * @return array<string, mixed>
     */
    public function getLeaderboards(): array
    {
        return $this->cached('leaderboards', function (): array {
            $boards = [];

            foreach (LeaderboardCategory::cases() as $category) {
                $dto = $this->leaderboardCalculationService->getLeaderboard($category, 'all-time', 1, 10);

                $boards[] = [
                    'category'    => $category->value,
                    'label'       => ucwords(str_replace('_', ' ', $category->value)),
                    'isAggregate' => $category->isAggregate(),
                    'total'       => $dto->total,
                    'entries'     => array_map(static fn ($item): array => $item->toArray(), $dto->entries),
                ];
            }

            return [
                'period'      => 'all-time',
                'boards'      => $boards,
                'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ];
        });
    }

    // ────────────────────────────────────────────────────── Pool panels ──

    /** @return array<string, mixed> */
    public function getPool(string $entity): array
    {
        return match ($entity) {
            'players' => $this->cached('pool.players', fn (): array => ['entity' => 'players', 'label' => 'Players'] + $this->playerRepository->getPoolBreakdown()),
            'staff'   => $this->cached('pool.staff',   fn (): array => ['entity' => 'staff',   'label' => 'Staff']   + $this->staffRepository->getPoolBreakdown()),
            'scouts'  => $this->cached('pool.scouts',  fn (): array => ['entity' => 'scouts',  'label' => 'Scouts']  + $this->scoutRepository->getPoolBreakdown()),
            'agents'  => $this->cached('pool.agents',  fn (): array => ['entity' => 'agents',  'label' => 'Agents']  + $this->agentRepository->getPoolBreakdown()),
            'world'   => $this->cached('pool.world',   fn (): array => $this->buildWorld()),
            default   => throw new \InvalidArgumentException("Unknown dashboard pool entity: {$entity}"),
        };
    }

    /**
     * NPC clubs and league coverage, nested country => tier, plus worldpack
     * cache freshness — a stale pack is invisible in every other admin screen.
     *
     * @return array<string, mixed>
     */
    private function buildWorld(): array
    {
        $byCountryTier = $this->npcClubRepository->getCountsByCountryAndTier();

        $countries = [];
        $tiers     = [];
        $nested    = [];
        $total     = 0;

        foreach ($byCountryTier as $country => $tierCounts) {
            $countryTotal = 0;
            foreach ($tierCounts as $tier => $count) {
                $count = (int) $count;
                $countryTotal        += $count;
                $tiers['Tier ' . $tier] = ($tiers['Tier ' . $tier] ?? 0) + $count;
                $nested[(string) $country]['tier']['Tier ' . $tier] = $count;
            }
            $countries[(string) $country] = $countryTotal;
            $total += $countryTotal;
        }

        arsort($countries);
        ksort($tiers);

        $leagues     = $this->leagueRepository->count([]);
        $cachedPacks = $this->worldPackCacheRepository->count([]);
        $stalePacks  = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM country_world_pack_cache WHERE payload_version <> :v',
            ['v' => WorldInitializationService::WORLD_PACK_VERSION]
        );

        return [
            'entity' => 'world',
            'label'  => 'NPC Clubs & World',
            'total'  => $total,
            'facets' => [
                'country' => StatBuckets::facet($countries),
                'tier'    => StatBuckets::facet($tiers),
            ],
            'nested'  => StatBuckets::nested('country', $countries, $nested, ['tier']),
            'summary' => [
                'leagues'          => $leagues,
                'cachedWorldPacks' => $cachedPacks,
                'staleWorldPacks'  => $stalePacks,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────── Cache ──

    /** Clears one panel, or every panel when `$panel` is null. */
    public function clear(?string $panel = null): void
    {
        foreach ($panel !== null ? [$panel] : self::PANELS as $key) {
            $this->cache->delete($this->key($key));
        }
    }

    /**
     * @template T
     * @param  callable(): T $callback
     * @return T
     */
    private function cached(string $panel, callable $callback): mixed
    {
        return $this->cache->get($this->key($panel), function (ItemInterface $item) use ($callback): mixed {
            $item->expiresAfter(self::TTL);

            return $callback();
        });
    }

    private function key(string $panel): string
    {
        // Cache keys may not contain reserved characters — `.` is fine, but keep it explicit.
        return 'admin_stats.' . str_replace('.', '_', $panel);
    }
}
