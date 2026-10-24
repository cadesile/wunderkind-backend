<?php

namespace App\Service;

use App\Entity\MatchResult;
use App\Repository\LiveTelemetrySnapshotRepository;
use App\Repository\MatchResultRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Entity\LiveTelemetrySnapshot;

/**
 * Aggregates SyncRecord.payload JSON from the last 24h, plus recent promotion/
 * relegation/title events from SeasonRecord, into the cached LiveTelemetrySnapshot
 * singleton for the landing page's "Boardroom Incident & Consequence Feed" widget
 * (formerly the plainer "Chairman's Terminal"). All macro counters and every feed
 * category are backed by real data — see the aggregate() docblock and each build*
 * method for exactly how. The feed shows real events only: the template does not
 * pad it with illustrative filler — a prior product decision, still binding.
 *
 * Nine categories feed the merged, newest-first ticker: pyramid movement
 * (buildEvents), boardroom outlay (buildLedgerEvents), attendance
 * (buildAttendanceEvents), dressing-room fallout (buildDressingRoomEvents),
 * boardroom equity dilution (buildDilutionEvents), commercial covenant risk
 * (buildCovenantEvents), talisman/community highlights (buildTalismanEvents,
 * buildCommunityEvents), transfer-market record fees (buildTopTransferEvents),
 * and top goalscorers (buildTopScorerEvents). Every build* method is a pure
 * static function over already-fetched payload arrays (no DB inside), so each
 * is independently unit-testable — see LiveTelemetryServiceTest.
 *
 * Final assembly does NOT just concatenate all sources and keep the globally most
 * recent TOTAL_FEED_LIMIT — see selectBalanced()'s docblock for why a flat recency
 * cut let frequent categories (outlay recurs almost every sync) silently crowd out
 * rarer-but-real ones (dilution, covenant, dressing-room fallout) entirely.
 *
 * `excursions[]`, `relationships[]`, `promises[]`, `fixtures[]` are loose
 * `array<string, mixed>` fields on SyncRequest — archived verbatim into
 * SyncRecord.payload with no validation or sub-shape typing. Every read below is
 * defensive (`?? null` / `?? []` at each level), matching the posture
 * buildLedgerEvents()/buildAttendanceEvents() already take toward
 * payload['ledger']/payload['attendance'].
 *
 * Ledger unit convention: `ledger[].amount` (the on-device game engine's own
 * financial-activity log, archived verbatim into SyncRecord.payload — see the
 * class docblock above) is sent by the client at **100x true pence**, i.e.
 * `amount / 100` is real pence and `amount / 10_000` is real pounds. Confirmed
 * by cross-referencing `excursions[].costPence` (an exact, non-suspect field)
 * against its matching `fan_initiative` ledger line for the same booking: the
 * ledger amount is always exactly 100x the costPence value. Every consumer of
 * `ledger[].amount` in this class must go through `ledgerAmountToPence()`
 * below rather than treating the raw value as pence directly — see that
 * method's docblock. `promises[].offer.amountPence` is a separate, correctly-
 * scaled field and needs no correction (buildDilutionEvents() and
 * buildCovenantEvents() already source their exact figures from it instead of
 * from `ledger[].amount`, for this reason).
 */
class LiveTelemetryService
{
    private const WINDOW_HOURS = 24;

    /** Season conclusions are much rarer than syncs, so look back further for events. */
    private const EVENTS_WINDOW_DAYS = 7;
    private const EVENTS_LIMIT       = 8;

    private const LEDGER_EVENTS_LIMIT       = 5;
    private const ATTENDANCE_EVENTS_LIMIT   = 5;
    private const DRESSING_ROOM_EVENTS_LIMIT = 5;
    private const DILUTION_EVENTS_LIMIT      = 5;
    private const COVENANT_EVENTS_LIMIT      = 5;
    private const TALISMAN_EVENTS_LIMIT      = 5;
    private const COMMUNITY_EVENTS_LIMIT     = 5;
    private const TOP_TRANSFER_EVENTS_LIMIT  = 5;
    private const TOP_SCORER_EVENTS_LIMIT    = 5;

    /**
     * Cap on the merged feed after all sources are combined — without this, adding
     * more sources over time could push the ticker (rotating one item every 4.5s,
     * see landing.js) well past two minutes to cycle once. Selection across sources
     * is round-robin (see selectBalanced()), not a flat recency cut, so raising this
     * mainly gives every category more room rather than being load-bearing for fairness.
     */
    private const TOTAL_FEED_LIMIT = 20;

    /** How many of a club's latest results the boardroom overlay's dossier carries. */
    private const DOSSIER_FIXTURES_LIMIT = 5;

    /** Each sync represents ~4 weeks of on-device ticks — used only for the footer's "weeks played" figure. */
    private const WEEKS_PER_SYNC = 4;

    /**
     * Floor that refresh() raises memory_limit to for the rest of the current process
     * (or, under php-fpm, the rest of the current request).
     *
     * refresh() fetches the whole 24h window up front and holds it for the entire
     * pipeline — a dozen build* passes each re-read the same already-decoded payload
     * arrays — so peak usage scales with how much JSON that window holds, and a decoded
     * PHP array costs several times its stored JSON. php-fpm's 128M default is not
     * enough at production volume: the admin rebuild button died with "Allowed memory
     * size of 134217728 bytes exhausted" inside DBAL's own json conversion
     * (JsonTypeConvert.php:54), i.e. still decoding payloads, before any aggregation
     * had run. Raising it here rather than in docker/telemetry-generate.sh covers BOTH
     * callers from one place — the cron command (app:telemetry:generate) and the admin
     * POST /admin/telemetry-config/rebuild button, which runs in a php-fpm worker whose
     * limit that script never touches.
     *
     * Deliberately NOT restored afterwards: php-fpm resets ini_set() values at request
     * shutdown and the CLI process exits straight after, so there is nothing to leak —
     * whereas lowering the limit back mid-request, while the window's arrays may not yet
     * be fully collected, would make the very next allocation fatal for no gain.
     */
    private const REFRESH_MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    /**
     * The only top-level SyncRecord.payload keys this class reads. Passed to
     * SyncRecordRepository::findValidPayloadsSince(), which projects the window down to
     * them in SQL so nothing else is ever fetched or decoded — see that method for the
     * mechanism, and REFRESH_MEMORY_LIMIT_BYTES above for why this matters so much:
     * the whole window is held at once and a decoded payload costs ~4.8x its stored
     * JSON. `relationships[]` was ~60% of all payload bytes and is read by nothing
     * here; `facilityLevels[]`, `signings[]` and the scalar club-aggregate keys
     * (balance, reputation, squadSize, ...) are likewise unread — SyncService already
     * folds those into the Club row at sync time.
     *
     * **A build* method that starts reading a new key MUST add it here**, or that key
     * arrives absent and the feature silently does nothing rather than erroring — the
     * same failure shape CLAUDE.md warns about for Import/Export field coverage.
     * LiveTelemetryPayloadProjectionTest reads the source of this class and fails if a
     * `$payload['x']` / `$row['payload']['x']` read has no entry here.
     */
    public const PAYLOAD_KEYS = [
        'attendance',
        'excursions',
        'fixtures',
        'form',
        'ledger',
        'playerStats',
        'promises',
        'seasonRecord',
        'transfers',
    ];

    /** Ledger categories that represent money leaving the club (spend, not revenue). */
    private const SPEND_LEDGER_CATEGORIES = ['upkeep', 'wages'];

    /** Transfer types that represent money paid OUT to acquire a player. */
    private const SPEND_TRANSFER_TYPES = ['signing', 'agent_assisted'];

    /** Transfer types that carry a real, newsworthy fee — loan/free_release/guardian_withdrawal don't. */
    private const FEE_BEARING_TRANSFER_TYPES = ['signing', 'sale', 'agent_assisted'];

    /**
     * Ledger categories now surfaced under their own dedicated category (dilution,
     * community) — excluded from the generic top-spend ranking so the same line
     * doesn't appear twice under two labels.
     */
    private const LEDGER_CATEGORY_EXCLUSIONS = [self::DILUTION_LEDGER_CATEGORY, self::COMMUNITY_LEDGER_CATEGORY];

    private const DILUTION_LEDGER_CATEGORY  = 'investor_income';
    private const COMMUNITY_LEDGER_CATEGORY = 'fan_initiative';

    private const COVENANT_CONDITION_TYPES = ['league_position', 'tier_reached'];

    private const TALISMAN_RATING_THRESHOLD      = 8.0;
    private const TALISMAN_PEAK_RATING_THRESHOLD = 9.0;

    /** Minimum goals this window before a scorer is marquee-worthy, not just a one-off strike. */
    private const TOP_SCORER_GOALS_THRESHOLD = 3;

    private const COMMUNITY_SENTIMENT_EXTREME_HIGH = 85;
    private const COMMUNITY_SENTIMENT_EXTREME_LOW  = 25;

    /**
     * Converts a raw `ledger[].amount` value to real pence — see the class
     * docblock's "Ledger unit convention" note. Integer division (not float)
     * to avoid rounding drift when summing many entries (capitalDeployedPence).
     * The single point every consumer of `ledger[].amount` must go through;
     * never read `entry['amount']` directly elsewhere in this class.
     *
     * Deliberately NOT reused by UserLedgerService's 'dividend_draw' category — that one is
     * sent as real pence, not the 100x-inflated value every other category carries. See
     * UserLedgerService's class docblock.
     */
    private static function ledgerAmountToPence(mixed $rawAmount): int
    {
        return intdiv((int) $rawAmount, 100);
    }

    public function __construct(
        private readonly SyncRecordRepository $syncRecordRepository,
        private readonly SeasonRecordRepository $seasonRecordRepository,
        private readonly LiveTelemetrySnapshotRepository $snapshotRepository,
        private readonly MatchResultRepository $matchResultRepository,
    ) {}

    public function refresh(): void
    {
        self::raiseMemoryLimit();

        $now   = new \DateTimeImmutable();
        $since = $now->modify('-' . self::WINDOW_HOURS . ' hours');

        $syncRows = $this->syncRecordRepository->findValidPayloadsSince($since, self::PAYLOAD_KEYS);
        $result   = self::aggregate(array_column($syncRows, 'payload'));

        $pyramidRows = $this->seasonRecordRepository->findRecentPyramidEvents(
            $now->modify('-' . self::EVENTS_WINDOW_DAYS . ' days'),
            self::EVENTS_LIMIT,
        );
        $attendanceRows = $this->syncRecordRepository->findTopAttendanceSince($since, self::ATTENDANCE_EVENTS_LIMIT);

        $dressingRoomEvents = self::buildDressingRoomEvents($syncRows, $now);
        $dilutionEvents     = self::buildDilutionEvents($syncRows, $now);
        $covenantEvents     = self::buildCovenantEvents($syncRows, $now);
        $talismanEvents     = self::buildTalismanEvents($syncRows, $now);
        $communityEvents    = self::buildCommunityEvents($syncRows, $now);
        $topScorerEvents    = self::buildTopScorerEvents($syncRows, $now);

        $events = self::mergeEventsByRecency(self::selectBalanced([
            self::buildEvents($pyramidRows, $now),
            self::buildLedgerEvents($syncRows, $now, self::LEDGER_EVENTS_LIMIT),
            self::buildAttendanceEvents($attendanceRows, $now),
            array_slice($dressingRoomEvents, 0, self::DRESSING_ROOM_EVENTS_LIMIT),
            array_slice($dilutionEvents, 0, self::DILUTION_EVENTS_LIMIT),
            array_slice($covenantEvents, 0, self::COVENANT_EVENTS_LIMIT),
            array_slice($talismanEvents, 0, self::TALISMAN_EVENTS_LIMIT),
            array_slice($communityEvents, 0, self::COMMUNITY_EVENTS_LIMIT),
            self::buildTopTransferEvents($syncRows, $now, self::TOP_TRANSFER_EVENTS_LIMIT),
            array_slice($topScorerEvents, 0, self::TOP_SCORER_EVENTS_LIMIT),
        ], self::TOTAL_FEED_LIMIT));

        // The boardroom overlay opens from any feed item, so the dossier is attached
        // after selection rather than inside each build* method: only the ~20 clubs
        // that actually made the cut are worth a results lookup.
        $events = $this->attachClubDossiers($events, $syncRows);

        $activeClubs = $this->syncRecordRepository->countActiveClubsSince($since);
        $weeksPlayed = count($syncRows) * self::WEEKS_PER_SYNC;

        $moraleDelta = self::computeCommunityMoraleDelta($syncRows);

        // Table Turnover reads the FEED's 7-day window, not the 24h stats window: a
        // promotion/relegation only lands when a season concludes, which is far rarer than
        // a sync, so a 24h count would read 0 almost permanently. The HUD header says 24H
        // PYRAMID FEED and this one box is wider than that — called out here because the
        // discrepancy is deliberate, not an oversight.
        $tableTurnover = $this->seasonRecordRepository->countPromotionsAndRelegationsSince(
            $now->modify('-' . self::EVENTS_WINDOW_DAYS . ' days'),
        );

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
            $moraleDelta,
            $result['totalGoals'],
            $result['totalMatches'],
            $tableTurnover,
        );
        $this->snapshotRepository->getEntityManager()->flush();
    }

    /**
     * Raises memory_limit to REFRESH_MEMORY_LIMIT_BYTES unless it is already at least
     * that high, or unlimited (-1). See that constant for why, and for why this is
     * one-way with no restore.
     */
    private static function raiseMemoryLimit(): void
    {
        $current = self::memoryLimitToBytes((string) ini_get('memory_limit'));

        if ($current === -1 || $current >= self::REFRESH_MEMORY_LIMIT_BYTES) {
            return;
        }

        ini_set('memory_limit', (string) self::REFRESH_MEMORY_LIMIT_BYTES);
    }

    /**
     * Resolves a memory_limit ini value — which may use PHP's K/M/G shorthand notation,
     * so it cannot just be cast to int — to bytes. -1 (unlimited) is returned as-is.
     */
    private static function memoryLimitToBytes(string $limit): int
    {
        $limit = strtolower(trim($limit));

        if ($limit === '' || $limit === '-1') {
            return $limit === '-1' ? -1 : 0;
        }

        $value = (int) $limit;

        return match (substr($limit, -1)) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    /**
     * Decorates already-selected feed items with their club's dossier (latest
     * results + best-rated player) for the boardroom overlay.
     *
     * The only non-static step in the feed pipeline — it needs MatchResult rows,
     * which no payload carries. Results come from the persisted MatchResult table
     * rather than the payload's own `fixtures[]`, because that array holds the whole
     * division's round, not the syncing club's own matches, and would have to be
     * disambiguated by club name (which is not unique).
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $syncRows
     * @return array<int, array<string, mixed>>
     */
    private function attachClubDossiers(array $events, array $syncRows): array
    {
        $clubIds = array_values(array_unique(array_filter(array_column($events, 'clubId'))));
        if ($clubIds === []) {
            return $events;
        }

        $resultsByClub = $this->matchResultRepository->findRecentByClubIds($clubIds, self::DOSSIER_FIXTURES_LIMIT);

        $fixturesByClubId = [];
        foreach ($resultsByClub as $clubId => $results) {
            $fixturesByClubId[$clubId] = array_map(static function (MatchResult $m): array {
                return [
                    'opponent'     => $m->getOpponentClubName() ?? 'Unknown',
                    'scoreFor'     => $m->getGoalsFor(),
                    'scoreAgainst' => $m->getGoalsAgainst(),
                    'week'         => $m->getWeek(),
                    'result'       => match (true) {
                        $m->getGoalsFor() > $m->getGoalsAgainst() => 'W',
                        $m->getGoalsFor() < $m->getGoalsAgainst() => 'L',
                        default => 'D',
                    },
                ];
            }, $results);
        }

        // Narrowed to the clubs on the feed so an inactive club's payload is never
        // parsed for a dossier nobody can open.
        $relevantRows = array_values(array_filter(
            $syncRows,
            static fn (array $row): bool => in_array($row['clubId'] ?? null, $clubIds, true),
        ));

        $dossiers = self::buildClubDossiers($relevantRows, $fixturesByClubId);

        return array_map(
            static fn (array $event): array => $event + ['dossier' => $dossiers[$event['clubId'] ?? ''] ?? null],
            $events,
        );
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
     * Ledger amounts are corrected via ledgerAmountToPence() (see class docblock);
     * transfers[].grossFee needs no correction — it's a separate, correctly-scaled field.
     *
     * WINDOWING, for all figures here: these are the payloads of syncs that ARRIVED in the
     * window (server_timestamp, via findValidPayloadsSince()) — not events that OCCURRED in
     * it. fixtures[] carries everything since that club's previous sync, so a payload landing
     * today can report matches played days earlier; measured on real data, ~3.5% of fixtures
     * were played more than 24h before the sync that reported them. This is deliberate and
     * consistent across every stat box: arrival is server time and cannot be gamed, whereas
     * fixtures[].playedAt is the device's own clock, client-supplied and unvalidated. Do not
     * "fix" this by filtering on playedAt.
     *
     * totalGoals/totalMatches: summed from fixtures[], which carries real per-fixture
     * homeGoals/awayGoals, and DEDUPLICATED BY fixtureId. Deduplication is load-bearing, not
     * tidiness: fixtures[] carries the syncing club's WHOLE DIVISION's round (see
     * attachClubDossiers()'s docblock), so every player-owned club in a division reports the
     * same fixtures, and a club syncing twice in the window resends them again. Counting those
     * would inflate the goal total and leave goals-per-match roughly right but the headline
     * wrong. This is the same double-counting the wins/draws/losses tally above knowingly
     * accepts — form[] carries no fixture identity to dedupe on, whereas fixtures[] does.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array{fixturesSimulated: int, capitalDeployedPence: int, wins: int, draws: int, losses: int, totalGoals: int, totalMatches: int}
     */
    public static function aggregate(array $payloads): array
    {
        $capitalDeployedPence = 0;
        $wins = 0;
        $draws = 0;
        $losses = 0;

        /** @var array<string, int> fixtureId => goals in that fixture */
        $goalsByFixture = [];
        $unidentifiedFixtureGoals  = 0;
        $unidentifiedFixtureCount  = 0;

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
                    $capitalDeployedPence += abs(self::ledgerAmountToPence($entry['amount'] ?? 0));
                }
            }

            foreach ($payload['transfers'] ?? [] as $transfer) {
                if (in_array($transfer['type'] ?? null, self::SPEND_TRANSFER_TYPES, true)) {
                    $capitalDeployedPence += (int) ($transfer['grossFee'] ?? 0);
                }
            }

            foreach ($payload['fixtures'] ?? [] as $fixture) {
                if (!is_array($fixture)) {
                    continue;
                }

                $goals = (int) ($fixture['homeGoals'] ?? 0) + (int) ($fixture['awayGoals'] ?? 0);

                // A fixture with no id can't be deduplicated, so it is counted once per
                // occurrence rather than dropped — losing real matches would understate the
                // figure worse than the rare double-count it risks.
                $fixtureId = $fixture['fixtureId'] ?? null;
                if (!is_string($fixtureId) || $fixtureId === '') {
                    $unidentifiedFixtureGoals += $goals;
                    $unidentifiedFixtureCount++;
                    continue;
                }

                $goalsByFixture[$fixtureId] = $goals;
            }
        }

        return [
            'fixturesSimulated'    => $wins + $draws + $losses,
            'capitalDeployedPence' => $capitalDeployedPence,
            'wins'                 => $wins,
            'draws'                => $draws,
            'losses'               => $losses,
            'totalGoals'           => array_sum($goalsByFixture) + $unidentifiedFixtureGoals,
            'totalMatches'         => count($goalsByFixture) + $unidentifiedFixtureCount,
        ];
    }

    /**
     * Turns SeasonRecordRepository::findRecentPyramidEvents() rows into feed-ready
     * enriched event items. Pure function (given $now) — unit-testable without a clock
     * or a database. Names the real club (see findRecentPyramidEvents()'s docblock
     * for why that carries no moderation risk).
     *
     * @param array<int, array{tier: int, promoted: bool, relegated: bool, finalPosition: int, createdAt: \DateTimeImmutable, clubName: string}> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function buildEvents(array $rows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($rows as $row) {
            $clubName = (string) $row['clubName'];
            $text = self::describeOutcome($clubName, (int) $row['tier'], (bool) $row['promoted'], (bool) $row['relegated'], (int) $row['finalPosition']);
            if ($text === null) {
                continue;
            }

            $events[] = self::event($row['createdAt'], $now, 'PYRAMID', '[PYRAMID // MOVEMENT]', $clubName, $text, clubBadge: self::clubBadge($row), clubId: $row['clubId'] ?? null);
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
     * generated fiction) — see findValidPayloadsSince() for the row shape. Excludes
     * categories now surfaced under their own dedicated category (see
     * LEDGER_CATEGORY_EXCLUSIONS) to avoid the same line appearing twice.
     * amountPence is corrected via ledgerAmountToPence() (see class docblock).
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildLedgerEvents(array $syncRows, \DateTimeImmutable $now, int $limit): array
    {
        $entries = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['ledger'] ?? [] as $entry) {
                if (in_array($entry['category'] ?? null, self::LEDGER_CATEGORY_EXCLUSIONS, true)) {
                    continue;
                }

                $amountPence = self::ledgerAmountToPence($entry['amount'] ?? 0);
                $description = trim((string) ($entry['description'] ?? ''));
                if ($amountPence >= 0 || $description === '') {
                    continue;
                }

                $entries[] = [
                    'amountPence'     => $amountPence,
                    'description'     => $description,
                    'clubName'        => $row['clubName'],
                    'clubId'          => $row['clubId'] ?? null,
                    'serverTimestamp' => $row['serverTimestamp'],
                    'clubBadge'       => self::clubBadge($row),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a['amountPence'] <=> $b['amountPence']);

        return array_map(
            static fn (array $entry): array => self::event(
                $entry['serverTimestamp'],
                $now,
                'OUTLAY',
                '[BOARDROOM // OUTLAY]',
                $entry['clubName'],
                sprintf('%s spent %s: %s', $entry['clubName'], LiveTelemetrySnapshot::formatPence(abs($entry['amountPence'])), $entry['description']),
                amountExact: LiveTelemetrySnapshot::formatPenceExact(abs($entry['amountPence'])),
                clubBadge: $entry['clubBadge'],
                clubId: $entry['clubId'],
            ),
            array_slice($entries, 0, $limit),
        );
    }

    /**
     * Turns SyncRecordRepository::findTopAttendanceSince() rows into feed-ready
     * enriched event items. Unlike buildEvents()/buildLedgerEvents(), this names the
     * real club — see findTopAttendanceSince()'s docblock for why that's not a
     * moderation risk here (curated name-options, not free text).
     *
     * @param array<int, array{clubName: string, weeklyAttendance: int, serverTimestamp: \DateTimeImmutable}> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function buildAttendanceEvents(array $rows, \DateTimeImmutable $now): array
    {
        return array_map(
            static fn (array $row): array => self::event(
                $row['serverTimestamp'],
                $now,
                'ATTENDANCE',
                '[TURNSTILES // ATTENDANCE]',
                $row['clubName'],
                sprintf('%s recorded attendance of %s!', $row['clubName'], number_format($row['weeklyAttendance'])),
                clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
            ),
            $rows,
        );
    }

    /**
     * Dressing-room fallout from resolved excursions[] whose outcome carries real
     * friction (frictionCount > 0). Reuses outcome.summary verbatim — it's already
     * clean, on-brand, client-generated narrative text (same "safe to show, may name
     * a player, generated fiction" precedent as buildLedgerEvents()'s descriptions),
     * so this doesn't hand-compose new phrasing. Surfaces the real friction/bond pairs
     * in `meta` for the incident modal's "affected player bonds" section. Ranked by
     * recency (narrative drama, not magnitude).
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildDressingRoomEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['excursions'] ?? [] as $excursion) {
                if (!is_array($excursion)) {
                    continue;
                }

                $outcome = $excursion['outcome'] ?? null;
                if (!is_array($outcome) || (int) ($outcome['frictionCount'] ?? 0) <= 0) {
                    continue;
                }

                $summary = trim((string) ($outcome['summary'] ?? ''));
                if ($summary === '') {
                    continue;
                }

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'DRESSING_ROOM',
                    '[DRESSING ROOM // RIFT]',
                    $row['clubName'],
                    sprintf('%s — %s', $row['clubName'], $summary),
                    meta: [
                        'frictionCount' => (int) $outcome['frictionCount'],
                        'attendeeCount' => (int) ($outcome['attendeeCount'] ?? 0),
                        'moraleDelta'   => (int) ($outcome['moraleDelta'] ?? 0),
                        'frictions'     => self::sanitizePairs($outcome['frictions'] ?? []),
                        'bonds'         => self::sanitizePairs($outcome['bonds'] ?? []),
                    ],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $events;
    }

    /**
     * Boardroom equity dilution from ledger[] entries categorised investor_income —
     * the equity percentage is only ever present as free text in the entry's own
     * description (e.g. "Bert's Fencing — season 1 payment (5% equity)"), so it's
     * parsed via regex rather than read from a structured field (none exists).
     * Deliberately does NOT use the ledger entry's own `amount` (see class docblock —
     * suspect ~100x inflation relative to description text in real payloads);
     * instead cross-references promises[] for a matching investment_contract by
     * counterparty name to source an exact, trustworthy amountPence. No match means
     * no amountExact is shown, rather than displaying a guessed figure.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildDilutionEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['ledger'] ?? [] as $entry) {
                if (($entry['category'] ?? null) !== self::DILUTION_LEDGER_CATEGORY) {
                    continue;
                }

                $description = trim((string) ($entry['description'] ?? ''));
                if ($description === '') {
                    continue;
                }

                $equityPercent = null;
                if (preg_match('/\((\d+)%\s*(?:equity)?\)/i', $description, $matches) === 1) {
                    $equityPercent = (int) $matches[1];
                }

                $counterparty = self::parseCounterpartyName($description);
                $amountExact  = self::matchInvestmentContractAmount($row['payload']['promises'] ?? [], $counterparty);

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'DILUTION',
                    '[BOARDROOM // DILUTION]',
                    $row['clubName'],
                    sprintf('%s — %s', $row['clubName'], $description),
                    amountExact: $amountExact,
                    meta: [
                        'equityPercent' => $equityPercent,
                        'counterparty'  => $counterparty,
                    ],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $events;
    }

    /**
     * Commercial covenant risk from active promises[] whose termination condition is
     * league_position or tier_reached — the "guillotine clause" the brief describes.
     * amountExact sources from offer.amountPence (never ledger[].amount — see class
     * docblock), multiplied by repeatSeasons for a seasonal_payment total, or shown
     * as a per-week figure for weekly_payment (deliberately not conflated into one
     * misleading total across two different payment cadences).
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildCovenantEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['promises'] ?? [] as $promise) {
                if (!is_array($promise) || ($promise['status'] ?? null) !== 'active') {
                    continue;
                }

                $conditionType = $promise['condition']['type'] ?? null;
                if (!in_array($conditionType, self::COVENANT_CONDITION_TYPES, true)) {
                    continue;
                }

                $partyAName = (string) ($promise['partyA']['name'] ?? 'A commercial partner');
                $target     = $promise['condition']['target'] ?? null;

                $riskText = $conditionType === 'league_position'
                    ? sprintf('voids if league position slips below %s', $target)
                    : sprintf('voids if the club fails to reach tier %s', $target);

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'COVENANT',
                    '[COMMERCIAL COVENANT]',
                    $row['clubName'],
                    sprintf('%s: %s covenant %s', $row['clubName'], $partyAName, $riskText),
                    amountExact: self::formatCovenantAmount($promise['offer'] ?? null),
                    meta: [
                        'conditionType'   => $conditionType,
                        'conditionTarget' => $target,
                        'offerType'       => $promise['offer']['type'] ?? null,
                        'partyAName'      => $partyAName,
                    ],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $events;
    }

    /**
     * Standout squad performers, two independent sources: (a) each club's single
     * highest-averageRating playerStats[] entry this window, rating ≥ threshold (one
     * line per club, not one per qualifying player — avoids flooding the feed); (b)
     * goalkeeper single-match peak ratings from fixtures[] ≥ a higher threshold,
     * paired with the top-level seasonRecord.goalsAgainst for a real "N conceded"
     * figure (there is no per-player goalsConceded field, but the club-level tally
     * is real and present on every payload). Ranked by rating descending.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildTalismanEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            $best = null;
            foreach ($row['payload']['playerStats'] ?? [] as $stat) {
                if (!is_array($stat)) {
                    continue;
                }

                $rating = (float) ($stat['averageRating'] ?? 0);
                if ($rating < self::TALISMAN_RATING_THRESHOLD) {
                    continue;
                }

                if ($best === null || $rating > (float) $best['averageRating']) {
                    $best = $stat;
                }
            }

            if ($best !== null) {
                $name        = (string) ($best['playerName'] ?? 'A player');
                $rating      = round((float) $best['averageRating'], 2);
                $goals       = (int) ($best['goals'] ?? 0);
                $assists     = (int) ($best['assists'] ?? 0);
                $appearances = (int) ($best['appearances'] ?? 0);

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'TALISMAN',
                    '[TALISMAN WATCH]',
                    $row['clubName'],
                    sprintf('%s: %s rated %s — %dg/%da in %d apps.', $row['clubName'], $name, $rating, $goals, $assists, $appearances),
                    meta: [
                        'playerName'   => $name,
                        'playerAge'    => $best['playerAge'] ?? null,
                        'rating'       => $rating,
                        'goals'        => $goals,
                        'assists'      => $assists,
                        'appearances'  => $appearances,
                    ],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }

            $peakGk = self::findPeakGoalkeeperRating($row['payload']['fixtures'] ?? []);
            if ($peakGk !== null) {
                $goalsAgainst = $row['payload']['seasonRecord']['goalsAgainst'] ?? null;
                $concededText = is_int($goalsAgainst) ? sprintf(', %d conceded this window', $goalsAgainst) : '';

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'TALISMAN',
                    '[TALISMAN WATCH]',
                    $row['clubName'],
                    sprintf('%s: %s peak rated %s%s.', $row['clubName'], $peakGk['playerName'], round($peakGk['rating'], 1), $concededText),
                    meta: [
                        'playerName'   => $peakGk['playerName'],
                        'peakRating'   => $peakGk['rating'],
                        'goalsAgainst' => $goalsAgainst,
                    ],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }
        }

        usort(
            $events,
            static fn (array $a, array $b): int => (($b['meta']['rating'] ?? $b['meta']['peakRating'] ?? 0.0) <=> ($a['meta']['rating'] ?? $a['meta']['peakRating'] ?? 0.0)),
        );

        return $events;
    }

    /**
     * Community sentiment from two sources: (a) fan_initiative ledger entries
     * (real community spend — description-only, no amountExact, same
     * inflation-avoidance reasoning as buildDilutionEvents()); (b) rows where
     * attendance.fanSentiment/fanMorale crosses an extreme high/low threshold.
     * Ranked by recency.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildCommunityEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['ledger'] ?? [] as $entry) {
                if (($entry['category'] ?? null) !== self::COMMUNITY_LEDGER_CATEGORY) {
                    continue;
                }

                $description = trim((string) ($entry['description'] ?? ''));
                if ($description === '') {
                    continue;
                }

                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'COMMUNITY',
                    '[COMMUNITY // SENTIMENT]',
                    $row['clubName'],
                    sprintf('%s invested in fan initiative: %s', $row['clubName'], $description),
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }

            $attendance = $row['payload']['attendance'] ?? null;
            if (!is_array($attendance)) {
                continue;
            }

            $sentiment = $attendance['fanSentiment'] ?? null;
            $morale    = $attendance['fanMorale'] ?? null;
            $isExtreme = self::isExtremeSentimentValue($sentiment) || self::isExtremeSentimentValue($morale);

            if ($isExtreme) {
                $events[] = self::event(
                    $row['serverTimestamp'],
                    $now,
                    'COMMUNITY',
                    '[COMMUNITY // SENTIMENT]',
                    $row['clubName'],
                    sprintf('%s boardroom mood: fan morale %d/100, sentiment %d/100.', $row['clubName'], (int) $morale, (int) $sentiment),
                    meta: ['fanMorale' => $morale, 'fanSentiment' => $sentiment],
                    clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
                );
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $events;
    }

    /**
     * The highest-fee transfers[] entries across the batch, ranked by grossFee
     * regardless of which club's payload reported them or of transfer direction —
     * same "rank regardless of category, let the biggest number win" approach as
     * buildLedgerEvents(). Restricted to FEE_BEARING_TRANSFER_TYPES: loan/
     * free_release/guardian_withdrawal movements carry no real fee and would only
     * pollute the top-N with £0 entries. grossFee needs no ledgerAmountToPence()
     * correction — it's a separate, correctly-scaled field (see class docblock).
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildTopTransferEvents(array $syncRows, \DateTimeImmutable $now, int $limit): array
    {
        $entries = [];

        foreach ($syncRows as $row) {
            foreach ($row['payload']['transfers'] ?? [] as $transfer) {
                if (!is_array($transfer) || !in_array($transfer['type'] ?? null, self::FEE_BEARING_TRANSFER_TYPES, true)) {
                    continue;
                }

                $grossFee   = (int) ($transfer['grossFee'] ?? 0);
                $playerName = trim((string) ($transfer['playerName'] ?? ''));
                if ($grossFee <= 0 || $playerName === '') {
                    continue;
                }

                $destinationClub = trim((string) ($transfer['destinationClub'] ?? ''));
                $startingClub    = $transfer['startingClub'] ?? null;
                $startingClub    = is_string($startingClub) && trim($startingClub) !== '' ? trim($startingClub) : null;

                $entries[] = [
                    'grossFee'        => $grossFee,
                    'playerName'      => $playerName,
                    'playerPosition'  => trim((string) ($transfer['playerPosition'] ?? '')),
                    'startingClub'    => $startingClub,
                    'destinationClub' => $destinationClub,
                    'serverTimestamp' => $row['serverTimestamp'],
                    'clubBadge'       => self::clubBadge($row),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b['grossFee'] <=> $a['grossFee']);

        return array_map(
            static fn (array $entry): array => self::event(
                $entry['serverTimestamp'],
                $now,
                'TOP_TRANSFER',
                '[TRANSFER MARKET // RECORD FEE]',
                $entry['destinationClub'] !== '' ? $entry['destinationClub'] : null,
                sprintf(
                    '%s sign %s%s for %s.',
                    $entry['destinationClub'] !== '' ? $entry['destinationClub'] : 'A club',
                    $entry['playerName'],
                    $entry['startingClub'] !== null ? sprintf(' from %s', $entry['startingClub']) : '',
                    LiveTelemetrySnapshot::formatPence($entry['grossFee']),
                ),
                amountExact: LiveTelemetrySnapshot::formatPenceExact($entry['grossFee']),
                meta: [
                    'playerName'      => $entry['playerName'],
                    'playerPosition'  => $entry['playerPosition'],
                    'startingClub'    => $entry['startingClub'],
                    'destinationClub' => $entry['destinationClub'],
                    'grossFeePence'   => $entry['grossFee'],
                ],
                clubBadge: $entry['clubBadge'],
            ),
            array_slice($entries, 0, $limit),
        );
    }

    /**
     * Each club's leading goalscorer this window from playerStats[] — one line per
     * club (same "avoid flooding the feed" reasoning as buildTalismanEvents()'s
     * per-club cap), ranked by goals rather than averageRating so a prolific-but-
     * inconsistent striker surfaces here even when their rating average never clears
     * the talisman threshold. Requires goals >= TOP_SCORER_GOALS_THRESHOLD to keep a
     * single-goal appearance out of a "marquee" feed.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array<int, array<string, mixed>>
     */
    public static function buildTopScorerEvents(array $syncRows, \DateTimeImmutable $now): array
    {
        $events = [];

        foreach ($syncRows as $row) {
            $best = null;
            foreach ($row['payload']['playerStats'] ?? [] as $stat) {
                if (!is_array($stat)) {
                    continue;
                }

                $goals = (int) ($stat['goals'] ?? 0);
                if ($goals < self::TOP_SCORER_GOALS_THRESHOLD) {
                    continue;
                }

                if ($best === null || $goals > (int) ($best['goals'] ?? 0)) {
                    $best = $stat;
                }
            }

            if ($best === null) {
                continue;
            }

            $name        = (string) ($best['playerName'] ?? 'A player');
            $goals       = (int) ($best['goals'] ?? 0);
            $assists     = (int) ($best['assists'] ?? 0);
            $appearances = (int) ($best['appearances'] ?? 0);

            $events[] = self::event(
                $row['serverTimestamp'],
                $now,
                'TOP_SCORER',
                '[GOAL MACHINE // TOP SCORER]',
                $row['clubName'],
                sprintf('%s: %s has %d goals (%d assists) in %d apps this window.', $row['clubName'], $name, $goals, $assists, $appearances),
                meta: [
                    'playerName'  => $name,
                    'goals'       => $goals,
                    'assists'     => $assists,
                    'appearances' => $appearances,
                ],
                clubBadge: self::clubBadge($row),
                clubId: $row['clubId'] ?? null,
            );
        }

        usort($events, static fn (array $a, array $b): int => ($b['meta']['goals'] ?? 0) <=> ($a['meta']['goals'] ?? 0));

        return $events;
    }

    /**
     * Signed average fan-morale delta (latest reading minus earliest, per club) across
     * clubs with ≥2 readings in the window; null if no club qualifies. Separate from
     * buildCommunityEvents() so it's independently unit-testable.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     */
    public static function computeCommunityMoraleDelta(array $syncRows): ?int
    {
        $byClub = [];
        foreach ($syncRows as $row) {
            $morale = $row['payload']['attendance']['fanMorale'] ?? null;
            if (!is_int($morale)) {
                continue;
            }

            $byClub[$row['clubName']][] = ['morale' => $morale, 'at' => $row['serverTimestamp']];
        }

        $deltas = [];
        foreach ($byClub as $readings) {
            if (count($readings) < 2) {
                continue;
            }

            usort($readings, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $deltas[] = end($readings)['morale'] - $readings[0]['morale'];
        }

        if ($deltas === []) {
            return null;
        }

        return (int) round(array_sum($deltas) / count($deltas));
    }

    /**
     * Merges feed lines from all sources into true descending-date order (newest
     * first), rather than the source-grouped order plain concatenation would leave
     * them in. Each source array is internally ordered by its own ranking (recency,
     * or magnitude for ledger/talisman events) — this is what makes them appear
     * grouped by type if just concatenated. Strips the 'at' sort key before
     * returning, since recentEvents' persisted/rendered shape doesn't carry it.
     *
     * @param array<int, array<string, mixed>> ...$eventLists
     * @return array<int, array<string, mixed>>
     */
    public static function mergeEventsByRecency(array ...$eventLists): array
    {
        $events = array_merge(...$eventLists);

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(
            static fn (array $event): array => [
                'time'          => $event['time'],
                'text'          => $event['text'],
                'category'      => $event['category'],
                'categoryLabel' => $event['categoryLabel'],
                'club'          => $event['club'],
                'detail'        => $event['detail'],
                'amountExact'   => $event['amountExact'],
                'timestampIso'  => $event['timestampIso'],
                'meta'          => $event['meta'],
                'clubBadge'     => $event['clubBadge'],
                // Must stay in this whitelist: it is rebuilt key-by-key (to drop the
                // internal 'at' sort key), so anything omitted here is silently
                // dropped before attachClubDossiers() can key the boardroom overlay's
                // dossier off it — which reads as "no dossier anywhere", with no error.
                'clubId'        => $event['clubId'] ?? null,
            ],
            $events,
        );
    }

    /**
     * Picks up to $limit events from $sourceLists — one list per category, each
     * already ranked internally by its own criteria (recency or magnitude) — in
     * round-robin order across sources, rather than concatenating everything and
     * keeping only the globally most recent/highest-ranked $limit.
     *
     * A flat recency cut lets categories that fire on nearly every sync (boardroom
     * outlay, attendance) crowd out categories that are real but comparatively rare
     * (equity dilution, commercial covenant risk, dressing-room fallout) out of the
     * feed entirely once total events exceed TOTAL_FEED_LIMIT — the summary stat
     * boxes would show real dilution/fallout figures while the ticker never once
     * displayed a line for them. Round-robin guarantees every non-empty source keeps
     * at least one slot before any source gets a second.
     *
     * The caller still re-sorts the selected subset by recency afterwards
     * (mergeEventsByRecency), so display order stays newest-first — this only
     * changes *which* events survive the cap, not their final ordering.
     *
     * Public (like mergeEventsByRecency()) so the fairness guarantee can be asserted
     * directly rather than inferred from sampled refresh() output.
     *
     * @param array<int, array<int, array<string, mixed>>> $sourceLists
     * @return array<int, array<string, mixed>>
     */
    public static function selectBalanced(array $sourceLists, int $limit): array
    {
        $selected = [];
        $cursors  = array_fill(0, count($sourceLists), 0);

        while (count($selected) < $limit) {
            $tookAny = false;

            foreach ($sourceLists as $i => $list) {
                if (count($selected) >= $limit) {
                    break;
                }
                if ($cursors[$i] >= count($list)) {
                    continue;
                }

                $selected[] = $list[$cursors[$i]];
                $cursors[$i]++;
                $tookAny = true;
            }

            if (!$tookAny) {
                break;
            }
        }

        return $selected;
    }

    /**
     * Per-club "dossier" for the boardroom overlay: the club's latest results and
     * its best-rated player. Attached to every feed item whose clubId resolves, so
     * clicking any line opens a report about that club rather than only about the
     * one incident.
     *
     * Keyed on clubId, never clubName — see event()'s `clubId` note.
     *
     * Only the club's NEWEST sync row in the window is read. playerStats[] is a
     * cumulative season-to-date tally (not a per-sync delta), so merging several
     * syncs would mean picking a winner between stale and fresh copies of the same
     * player; the latest row already contains everything the earlier ones did.
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubId?: string, clubName: string, homeKitConfig?: ?array}> $syncRows
     * @param array<string, array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}>> $fixturesByClubId
     * @return array<string, array{topPlayer: ?array<string, mixed>, fixtures: array<int, array<string, mixed>>}>
     */
    public static function buildClubDossiers(array $syncRows, array $fixturesByClubId): array
    {
        $newestByClub = [];
        foreach ($syncRows as $row) {
            $clubId = $row['clubId'] ?? null;
            if ($clubId === null) {
                continue;
            }

            $incumbent = $newestByClub[$clubId] ?? null;
            if ($incumbent === null || $row['serverTimestamp'] > $incumbent['serverTimestamp']) {
                $newestByClub[$clubId] = $row;
            }
        }

        $dossiers = [];
        foreach ($newestByClub as $clubId => $row) {
            $dossiers[$clubId] = [
                'topPlayer' => self::findBestRatedPlayer($row),
                'fixtures'  => $fixturesByClubId[$clubId] ?? [],
            ];
        }

        // A club with no sync in the window can still appear in the feed via a
        // SeasonRecord pyramid event. It has no playerStats to rank, but its
        // persisted results are still real and worth showing.
        foreach ($fixturesByClubId as $clubId => $fixtures) {
            if (!isset($dossiers[$clubId])) {
                $dossiers[$clubId] = ['topPlayer' => null, 'fixtures' => $fixtures];
            }
        }

        return $dossiers;
    }

    /**
     * The highest-averageRating entry in a sync row's playerStats[], enriched with
     * the position and sprite config needed to render them.
     *
     * Unlike buildTalismanEvents(), this applies NO rating threshold: the overlay
     * always wants a club's best player, even a mediocre one. The talisman ticker
     * line is a *newsworthiness* judgement; this is a roster fact.
     *
     * Two fields are recovered rather than read straight off the stat entry:
     *  - `position` is not in playerStats[] at all. It IS in each fixture's
     *    home/awayPlayerRatings[], keyed by the same playerId, so it is looked up
     *    there. Null when the player didn't feature in any fixture on this payload.
     *  - `appearanceConfig` is the personal-traits-only subset (hair/skin/face/...,
     *    no kit colours — see CLAUDE.md's Sync v2 section). The club's own
     *    homeKitConfig supplies kit/shorts/socks/primary/secondary, exactly as
     *    PlayerCareerStat::toFullAppearanceConfig() merges them. Null when the
     *    client did not report one, which is still the common case — the overlay
     *    renders the player without a sprite rather than inventing a likeness.
     *
     * averageRating is rounded to one decimal here, not at render time: it arrives
     * as a raw mean (8.044444444444443) and every consumer wants the same rounding.
     *
     * @param array{payload: array<string, mixed>, homeKitConfig?: ?array} $row
     * @return array{playerId: ?string, playerName: string, position: ?string, age: ?int,
     *     averageRating: float, goals: int, assists: int, appearances: int,
     *     appearanceConfig: ?array<string, mixed>}|null
     */
    public static function findBestRatedPlayer(array $row): ?array
    {
        $best = null;
        foreach ($row['payload']['playerStats'] ?? [] as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            if ($best === null || (float) ($stat['averageRating'] ?? 0) > (float) ($best['averageRating'] ?? 0)) {
                $best = $stat;
            }
        }

        if ($best === null) {
            return null;
        }

        $playerId = isset($best['playerId']) ? (string) $best['playerId'] : null;

        return [
            'playerId'      => $playerId,
            'playerName'    => (string) ($best['playerName'] ?? 'A player'),
            'position'      => $playerId !== null
                ? self::findPlayerPosition($row['payload']['fixtures'] ?? [], $playerId)
                : null,
            'age'           => isset($best['playerAge']) ? (int) $best['playerAge'] : null,
            'averageRating' => round((float) ($best['averageRating'] ?? 0), 1),
            'goals'         => (int) ($best['goals'] ?? 0),
            'assists'       => (int) ($best['assists'] ?? 0),
            'appearances'   => (int) ($best['appearances'] ?? 0),
            'appearanceConfig' => self::mergePlayerAppearance(
                is_array($best['appearanceConfig'] ?? null) ? $best['appearanceConfig'] : null,
                $row['homeKitConfig'] ?? null,
            ),
        ];
    }

    /**
     * A player's position from any fixture's home/away player-rating rows. The same
     * player appears once per fixture they played, always with the same position, so
     * the first hit wins.
     *
     * @param array<int, mixed> $fixtures
     */
    private static function findPlayerPosition(array $fixtures, string $playerId): ?string
    {
        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }
            foreach (['homePlayerRatings', 'awayPlayerRatings'] as $key) {
                foreach ($fixture[$key] ?? [] as $ratingRow) {
                    if (!is_array($ratingRow) || (string) ($ratingRow['playerId'] ?? '') !== $playerId) {
                        continue;
                    }
                    $position = $ratingRow['position'] ?? null;
                    if (is_string($position) && $position !== '') {
                        return $position;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Merges a client-reported personal-traits appearance with the club's kit colours
     * into one complete sprite config composePlayerSpriteSvg() can draw.
     *
     * Mirrors PlayerCareerStat::toFullAppearanceConfig() — a player's kit is always
     * club-derived and never reported per-player. Returns null when no personal traits
     * were reported: a kit with no body is not a renderable player, and a generated
     * stand-in would be a likeness nobody in the game has ever seen.
     *
     * @param  array<string, mixed>|null $personal
     * @param  array<string, mixed>|null $homeKitConfig
     * @return array<string, mixed>|null
     */
    private static function mergePlayerAppearance(?array $personal, ?array $homeKitConfig): ?array
    {
        if ($personal === null || $personal === []) {
            return null;
        }

        $kit = [];
        foreach (['kit', 'shorts', 'socks', 'primary', 'secondary'] as $key) {
            if (isset($homeKitConfig[$key])) {
                $kit[$key] = $homeKitConfig[$key];
            }
        }

        return $personal + $kit;
    }

    /** Builds one enriched feed item in the shared shape every category emits. */
    private static function event(
        \DateTimeImmutable $at,
        \DateTimeImmutable $now,
        string $category,
        string $categoryLabel,
        ?string $club,
        string $text,
        ?string $detail = null,
        ?string $amountExact = null,
        array $meta = [],
        ?array $clubBadge = null,
        ?string $clubId = null,
    ): array {
        return [
            'time'          => self::relativeTime($at, $now),
            'text'          => $text,
            'category'      => $category,
            'categoryLabel' => $categoryLabel,
            'club'          => $club,
            'detail'        => $detail ?? $text,
            'amountExact'   => $amountExact,
            'timestampIso'  => $at->format(\DateTimeInterface::ATOM),
            'meta'          => $meta,
            'clubBadge'     => $clubBadge,
            // The real Club row behind this item, when there is one. Club names are
            // not unique (production has six "London FC"), so the boardroom overlay's
            // per-club dossier is keyed on this, never on `club`. Null for items whose
            // club is a name only — a transfer's destination club may be an NPC side
            // with no Club row at all.
            'clubId'        => $clubId,
            'at'            => $at,
        ];
    }

    /**
     * Bundles a row's home/away kit + badge config into the event shape's `clubBadge`
     * field — same {home, away, badge} grouping consumed by kit-compositor.js's
     * composeKitSvg() client-side (see docs/api/club-kit-identity.md). Every row
     * fetched by findValidPayloadsSince()/findTopAttendanceSince()/
     * findRecentPyramidEvents() carries these three keys.
     *
     * @param array<string, mixed> $row
     * @return array{home: ?array, away: ?array, badge: ?array}
     */
    private static function clubBadge(array $row): array
    {
        return [
            'home'  => $row['homeKitConfig'] ?? null,
            'away'  => $row['awayKitConfig'] ?? null,
            'badge' => $row['badgeConfig'] ?? null,
        ];
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

    /**
     * Extracts {a, b, delta} pairs from raw excursions[].outcome.frictions[]/bonds[]
     * entries, which use either {playerName, otherName} (player-player) or
     * {aName, bName} (bond) key conventions in real payloads — accept either.
     *
     * @param array<int, mixed> $pairs
     * @return array<int, array{a: string, b: string, delta: int}>
     */
    private static function sanitizePairs(array $pairs): array
    {
        $result = [];

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $a = (string) ($pair['playerName'] ?? $pair['aName'] ?? '');
            $b = (string) ($pair['otherName'] ?? $pair['bName'] ?? '');
            if ($a === '' || $b === '') {
                continue;
            }

            $result[] = ['a' => $a, 'b' => $b, 'delta' => (int) ($pair['delta'] ?? 0)];
        }

        return $result;
    }

    /** The counterparty name is the text before " — " in an investor_income description. */
    private static function parseCounterpartyName(string $description): ?string
    {
        $name = trim(explode(' — ', $description, 2)[0]);

        return $name === '' ? null : $name;
    }

    /**
     * Looks up a matching active investment_contract promise by counterparty name to
     * source an exact amountPence — never the suspect ledger[].amount (see class
     * docblock). Returns null (no guess) if nothing matches.
     *
     * @param array<int, mixed> $promises
     */
    private static function matchInvestmentContractAmount(array $promises, ?string $counterparty): ?string
    {
        if ($counterparty === null) {
            return null;
        }

        foreach ($promises as $promise) {
            if (!is_array($promise) || ($promise['type'] ?? null) !== 'investment_contract') {
                continue;
            }

            if (($promise['partyA']['name'] ?? null) !== $counterparty) {
                continue;
            }

            $amountPence = $promise['offer']['amountPence'] ?? null;
            if (is_int($amountPence) || is_float($amountPence)) {
                return LiveTelemetrySnapshot::formatPenceExact((int) $amountPence);
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $offer */
    private static function formatCovenantAmount(?array $offer): ?string
    {
        if ($offer === null) {
            return null;
        }

        $valuePence = self::covenantValuePence($offer);
        if ($valuePence === 0) {
            return null;
        }

        return $offer['type'] === 'weekly_payment'
            ? LiveTelemetrySnapshot::formatPenceExact($valuePence) . '/week'
            : LiveTelemetrySnapshot::formatPenceExact($valuePence) . ' total';
    }

    /**
     * A promise's seasonal-equivalent value: amountPence × repeatSeasons for a
     * seasonal_payment offer, or the raw weekly amountPence for a weekly_payment
     * offer — the two cadences are deliberately not summed together into one
     * conflated figure.
     *
     * @param array<string, mixed>|null $offer
     */
    private static function covenantValuePence(?array $offer): int
    {
        if ($offer === null) {
            return 0;
        }

        $amountPence = $offer['amountPence'] ?? null;
        if (!is_int($amountPence) && !is_float($amountPence)) {
            return 0;
        }
        $amountPence = (int) $amountPence;

        if (($offer['type'] ?? null) === 'seasonal_payment') {
            $repeatSeasons = $offer['repeatSeasons'] ?? null;
            if (is_int($repeatSeasons) && $repeatSeasons > 0) {
                return $amountPence * $repeatSeasons;
            }
        }

        return $amountPence;
    }

    /**
     * The highest goalkeeper rating (position === 'GK') at or above the peak
     * threshold across every fixture's home/away player ratings.
     *
     * @param array<int, mixed> $fixtures
     * @return array{playerName: string, rating: float}|null
     */
    private static function findPeakGoalkeeperRating(array $fixtures): ?array
    {
        $peak = null;

        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            foreach (['homePlayerRatings', 'awayPlayerRatings'] as $key) {
                foreach ($fixture[$key] ?? [] as $ratingRow) {
                    if (!is_array($ratingRow) || ($ratingRow['position'] ?? null) !== 'GK') {
                        continue;
                    }

                    $rating = (float) ($ratingRow['rating'] ?? 0);
                    if ($rating < self::TALISMAN_PEAK_RATING_THRESHOLD) {
                        continue;
                    }

                    if ($peak === null || $rating > $peak['rating']) {
                        $peak = ['playerName' => (string) ($ratingRow['playerName'] ?? 'The keeper'), 'rating' => $rating];
                    }
                }
            }
        }

        return $peak;
    }

    private static function isExtremeSentimentValue(mixed $value): bool
    {
        return is_int($value) && ($value >= self::COMMUNITY_SENTIMENT_EXTREME_HIGH || $value <= self::COMMUNITY_SENTIMENT_EXTREME_LOW);
    }
}
