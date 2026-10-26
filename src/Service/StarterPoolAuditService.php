<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\StaffRole;
use App\Message\Pool\TopUpStarterPoolMessage;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sweeps every enabled country's starter-pack check and queues the pool generation that
 * would fix what it finds — the unattended counterpart to an admin reading
 * /admin?routeName=admin_starter_pack_check and pressing Warm.
 *
 * Three things make this safe to leave running on a cron:
 *
 * 1. **It only acts on a country that cannot currently serve.** The trigger is the
 *    StarterPackDiagnosticService verdict, not raw pool counts, so a well-stocked
 *    country queues nothing however far its pool has drifted from the warm targets.
 *    This is a starter-pack remedy, not a general pool warmer — `app:pool:warm` remains
 *    the thing that tops the pool up for its own sake.
 *
 * 2. **It never queues work that generation cannot finish.** The diagnostic's own
 *    docblock describes the trap: the draw filters on ability band AND first-team age at
 *    once, and under the default PoolConfig (players generated at 12–13, first-team floor
 *    19) a freshly generated player can never clear the age floor. Queueing players
 *    against that shortfall would generate hundreds an hour, forever, and never move the
 *    verdict. planFor() reports it as a blocker instead — a config problem an operator
 *    has to decide about, not a supply problem a worker can grind out.
 *
 * 3. **It will not stack.** queueIsBusy() refuses to plan a second sweep while the
 *    previous one's chunks are still undrained, so a cron firing faster than the
 *    consumer drains cannot pile up duplicate deficits (the counts the next sweep would
 *    read have not caught up yet). The handler re-checks its own target on top of that.
 *
 * Targets are per **nationality**, not per country, because that is the unit generation
 * actually addresses — and they default to the same floors `app:pool:warm` uses, so a
 * queued top-up and a hand-run warm converge on the same steady state rather than
 * fighting each other.
 */
final class StarterPoolAuditService
{
    /** Same floors as WarmPoolCommand's own defaults — see the class docblock. */
    public const DEFAULT_MIN_PLAYERS        = 500;
    public const DEFAULT_MIN_STAFF_PER_ROLE = 50;
    public const DEFAULT_MIN_SCOUTS         = 50;

    /** Queue name of the `pool` transport in config/packages/messenger.yaml. */
    public const TRANSPORT_QUEUE = 'pool';

    /**
     * Entities per queued message. MarketPoolService flushes and clears every 50, so a
     * 100-entity chunk is two of its own batches — small enough that a consumer's peak
     * memory is flat regardless of the deficit, large enough that a 500-player top-up is
     * five messages rather than fifty.
     */
    public const DEFAULT_CHUNK = 100;

    public function __construct(
        private readonly StarterPackDiagnosticService $diagnostics,
        private readonly StarterConfigRepository      $starterConfigRepository,
        private readonly PoolConfigRepository         $poolConfigRepository,
        private readonly PlayerRepository             $playerRepository,
        private readonly StaffRepository              $staffRepository,
        private readonly ScoutRepository              $scoutRepository,
        private readonly MessageBusInterface          $messageBus,
        private readonly Connection                   $connection,
    ) {}

    /**
     * The countries a sweep covers: the ones a client can actually start a club in.
     *
     * @return string[]
     */
    public function activeCountries(): array
    {
        return array_values(array_filter(
            $this->starterConfigRepository->getConfig()->getEnabledCountries(),
            static fn (mixed $code): bool => is_string($code) && $code !== '',
        ));
    }

    /**
     * Diagnose one country and work out what to queue for it. Read-only.
     *
     * @param array{players?: int, staff?: int, scouts?: int} $minimums
     *
     * @return array<string, mixed> see planFor()
     */
    public function auditCountry(string $countryCode, array $minimums = [], int $chunk = self::DEFAULT_CHUNK): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $diagnosis   = $this->diagnostics->diagnose($countryCode);
        $poolConfig  = $this->poolConfigRepository->getConfig();

        return self::planFor(
            $diagnosis,
            $this->nativeCounts($diagnosis),
            $poolConfig->getPlayerAgeMax(),
            $poolConfig->getPlayerFirstTeamAgeMin(),
            $minimums,
            $chunk,
        );
    }

    /**
     * @param string[] $countries
     * @param array{players?: int, staff?: int, scouts?: int} $minimums
     *
     * @return array<int, array<string, mixed>> one plan per country
     */
    public function audit(array $countries, array $minimums = [], int $chunk = self::DEFAULT_CHUNK): array
    {
        return array_map(
            fn (string $code): array => $this->auditCountry($code, $minimums, $chunk),
            $countries,
        );
    }

    /**
     * Dispatch one plan's chunks. Returns the number of messages queued.
     *
     * @param array<string, mixed> $plan
     */
    public function queue(array $plan): int
    {
        $queued = 0;

        foreach ($plan['needs'] as $need) {
            foreach ($need['chunks'] as $count) {
                $this->messageBus->dispatch(new TopUpStarterPoolMessage(
                    country:     $plan['country'],
                    nationality: $plan['nationality'],
                    kind:        $need['kind'],
                    count:       $count,
                    target:      $need['target'],
                    staffRole:   $need['role'],
                ));
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * True while a previous sweep's chunks are still waiting to be drained.
     *
     * Reads the Doctrine transport table directly rather than going through Messenger:
     * there is no transport-agnostic "how deep is this queue" API, and the alternative —
     * tracking dispatches in a table of our own — would be a second source of truth that
     * can disagree with the queue it is meant to describe.
     *
     * Returns false if the table does not exist yet: `auto_setup` creates it on first
     * dispatch, so before anything has ever been queued an empty queue is the truth.
     */
    public function queueIsBusy(): bool
    {
        try {
            $pending = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NULL',
                ['queue' => self::TRANSPORT_QUEUE],
            );
        } catch (\Throwable) {
            return false;
        }

        return $pending > 0;
    }

    /**
     * Current pool stock for the nationality the diagnosis is about, in the shape
     * planFor() expects. Native rather than pool-wide because generation is
     * nationality-targeted: auditing against the shared pool-wide count would have all
     * 25 enabled countries read the same zero and each queue a full deficit for it.
     *
     * @param array<string, mixed> $diagnosis
     *
     * @return array{players: int, staff: array<string, int>, scouts: int}
     */
    private function nativeCounts(array $diagnosis): array
    {
        $nationality = $diagnosis['nationality'];

        if ($nationality === null) {
            return ['players' => 0, 'staff' => [], 'scouts' => 0];
        }

        $staff = [];
        foreach ($diagnosis['staff'] as $row) {
            $staff[$row['role']] = $this->staffRepository->countInPoolByNationalityAndRole(
                $nationality,
                StaffRole::from($row['role']),
            );
        }

        return [
            'players' => $this->playerRepository->countInPoolByNationality($nationality),
            'staff'   => $staff,
            'scouts'  => $this->scoutRepository->countInPool($nationality),
        ];
    }

    // ── The decision, as a pure function ──────────────────────────────────────

    /**
     * Turn one diagnosis plus the current native stock into "queue this, and tell a human
     * about that".
     *
     * Static and dependency-free so the precedence that matters — short but unfixable
     * becomes a blocker, never a chunk — can be asserted directly against hand-built
     * input, rather than inferred from what a mocked repository happened to return.
     *
     * @param array<string, mixed> $diagnosis StarterPackDiagnosticService::diagnose()
     * @param array{players: int, staff: array<string, int>, scouts: int} $native
     * @param int $generatedPlayerAgeMax Oldest age MarketPoolService will generate (PoolConfig::playerAgeMax)
     * @param int $firstTeamAgeMin       Age floor the starter draw filters on (PoolConfig::playerFirstTeamAgeMin)
     * @param array{players?: int, staff?: int, scouts?: int} $minimums
     *
     * @return array{
     *     country: string, countryLabel: string, nationality: ?string, verdict: string,
     *     needs: array<int, array{kind: string, role: ?string, label: string, current: int, target: int, deficit: int, chunks: int[]}>,
     *     blockers: string[], entities: int, messages: int
     * }
     */
    public static function planFor(
        array $diagnosis,
        array $native,
        int   $generatedPlayerAgeMax,
        int   $firstTeamAgeMin,
        array $minimums = [],
        int   $chunk = self::DEFAULT_CHUNK,
    ): array {
        $chunk       = max(1, $chunk);
        $minPlayers  = $minimums['players'] ?? self::DEFAULT_MIN_PLAYERS;
        $minStaff    = $minimums['staff']   ?? self::DEFAULT_MIN_STAFF_PER_ROLE;
        $minScouts   = $minimums['scouts']  ?? self::DEFAULT_MIN_SCOUTS;
        $nationality = $diagnosis['nationality'] ?? null;

        $plan = [
            'country'      => $diagnosis['country'],
            'countryLabel' => $diagnosis['countryLabel'],
            'nationality'  => $nationality,
            'verdict'      => $diagnosis['verdict'],
            'needs'        => [],
            'blockers'     => [],
            'entities'     => 0,
            'messages'     => 0,
        ];

        if ($diagnosis['verdict'] === 'ok') {
            return $plan;
        }

        if ($nationality === null) {
            $plan['blockers'][] = sprintf(
                '%s is enabled for play but is not a known Country case, so no nationality can be generated for it. Add the case to App\Enum\Country or drop it from StarterConfig::enabledCountries.',
                $diagnosis['country'],
            );

            return $plan;
        }

        // ── Players ───────────────────────────────────────────────────────────
        if ($diagnosis['playersAvailable'] < $diagnosis['playersRequested']) {
            if ($generatedPlayerAgeMax < $firstTeamAgeMin) {
                $plan['blockers'][] = sprintf(
                    'Player shortfall (%d of %d) cannot be fixed by generating more: PoolConfig generates players up to age %d, and the starter draw only considers players aged %d or over, so no newly generated player is ever eligible. Lower playerFirstTeamAgeMin, raise playerAgeMax, or widen the tier ability band.',
                    $diagnosis['playersAvailable'],
                    $diagnosis['playersRequested'],
                    $generatedPlayerAgeMax,
                    $firstTeamAgeMin,
                );
            } elseif (($diagnosis['bandHoldsOnlyKids'] ?? false) === true) {
                $plan['blockers'][] = sprintf(
                    'Player shortfall (%d of %d) is an ability-band problem, not a supply one: the band %d–%d holds players, but every one of them is under %d. Generating more would land in the same band at the same ages. Widen the band for this tier instead.',
                    $diagnosis['playersAvailable'],
                    $diagnosis['playersRequested'],
                    $diagnosis['ability']['min'],
                    $diagnosis['ability']['max'],
                    $firstTeamAgeMin,
                );
            } else {
                self::addNeed(
                    $plan,
                    kind:    TopUpStarterPoolMessage::KIND_PLAYERS,
                    role:    null,
                    label:   'players',
                    current: $native['players'],
                    target:  max($minPlayers, $diagnosis['playersRequested']),
                    chunk:   $chunk,
                );
            }
        }

        // ── Staff ─────────────────────────────────────────────────────────────
        // Driven by the starter pack's own requirements, which cover all five roles —
        // app:pool:warm only ever warms MANAGER/COACH/CHAIRMAN, so a country short on
        // DIRECTOR_OF_FOOTBALL or FACILITY_MANAGER stays short no matter how often it is
        // warmed by hand. The audit has no such blind spot.
        foreach ($diagnosis['staff'] as $row) {
            if ($row['ok']) {
                continue;
            }

            self::addNeed(
                $plan,
                kind:    TopUpStarterPoolMessage::KIND_STAFF,
                role:    $row['role'],
                label:   $row['role'],
                current: $native['staff'][$row['role']] ?? 0,
                target:  max($minStaff, $row['requested']),
                chunk:   $chunk,
            );
        }

        // ── Scouts ────────────────────────────────────────────────────────────
        if (!$diagnosis['scouts']['ok']) {
            self::addNeed(
                $plan,
                kind:    TopUpStarterPoolMessage::KIND_SCOUTS,
                role:    null,
                label:   'scouts',
                current: $native['scouts'],
                target:  max($minScouts, $diagnosis['scouts']['requested']),
                chunk:   $chunk,
            );
        }

        // A verdict that is not 'ok' but produced neither a chunk nor a blocker means the
        // shortfall is pool-wide while this nationality is already at its floor — real,
        // but not this country's to fix. Say so rather than leaving a silent no-op.
        if ($plan['needs'] === [] && $plan['blockers'] === []) {
            $plan['blockers'][] = sprintf(
                'Verdict is "%s", but the %s pool is already at or above every top-up floor — the shortfall is in the shared pool, which another country\'s sweep will fill.',
                $diagnosis['verdict'],
                $nationality,
            );
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function addNeed(
        array   &$plan,
        string   $kind,
        ?string  $role,
        string   $label,
        int      $current,
        int      $target,
        int      $chunk,
    ): void {
        $deficit = $target - $current;

        if ($deficit <= 0) {
            return;
        }

        $chunks = [];
        for ($remaining = $deficit; $remaining > 0; $remaining -= $chunk) {
            $chunks[] = min($chunk, $remaining);
        }

        $plan['needs'][] = [
            'kind'    => $kind,
            'role'    => $role,
            'label'   => $label,
            'current' => $current,
            'target'  => $target,
            'deficit' => $deficit,
            'chunks'  => $chunks,
        ];
        $plan['entities'] += $deficit;
        $plan['messages'] += count($chunks);
    }
}
