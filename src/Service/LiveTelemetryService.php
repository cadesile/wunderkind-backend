<?php

namespace App\Service;

use App\Repository\LiveTelemetrySnapshotRepository;
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
 * Seven categories feed the merged, newest-first ticker: pyramid movement
 * (buildEvents), boardroom outlay (buildLedgerEvents), attendance
 * (buildAttendanceEvents), dressing-room fallout (buildDressingRoomEvents),
 * boardroom equity dilution (buildDilutionEvents), commercial covenant risk
 * (buildCovenantEvents), and talisman/community highlights (buildTalismanEvents,
 * buildCommunityEvents). Every build* method is a pure static function over
 * already-fetched payload arrays (no DB inside), so each is independently
 * unit-testable — see LiveTelemetryServiceTest.
 *
 * `excursions[]`, `relationships[]`, `promises[]`, `fixtures[]` are loose
 * `array<string, mixed>` fields on SyncRequest — archived verbatim into
 * SyncRecord.payload with no validation or sub-shape typing. Every read below is
 * defensive (`?? null` / `?? []` at each level), matching the posture
 * buildLedgerEvents()/buildAttendanceEvents() already take toward
 * payload['ledger']/payload['attendance'].
 *
 * Known caveat, not fixed here: ledger[].amount values look ~100x inflated
 * relative to their own description text in real payloads (e.g. a "£4,000" fan
 * event's ledger amount implies £4,000,000). This service avoids building any
 * *new* monetary display on top of ledger[].amount — buildDilutionEvents() and
 * buildCovenantEvents() source exact figures from promises[].offer.amountPence
 * instead, which doesn't show the same discrepancy. Existing ledger-based figures
 * (capitalDeployedPence, buildLedgerEvents()'s own spend lines) are untouched.
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

    /**
     * Cap on the merged feed after all 7 sources are combined — without this, 4 new
     * sources on top of 3 existing ones could push the ticker (rotating one item
     * every 4.5s, see landing.js) well past two minutes to cycle once.
     */
    private const TOTAL_FEED_LIMIT = 18;

    /** Each sync represents ~4 weeks of on-device ticks — used only for the footer's "weeks played" figure. */
    private const WEEKS_PER_SYNC = 4;

    /** Ledger categories that represent money leaving the club (spend, not revenue). */
    private const SPEND_LEDGER_CATEGORIES = ['upkeep', 'wages'];

    /** Transfer types that represent money paid OUT to acquire a player. */
    private const SPEND_TRANSFER_TYPES = ['signing', 'agent_assisted'];

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

    private const COMMUNITY_SENTIMENT_EXTREME_HIGH = 85;
    private const COMMUNITY_SENTIMENT_EXTREME_LOW  = 25;

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

        $dressingRoomEvents = self::buildDressingRoomEvents($syncRows, $now);
        $dilutionEvents     = self::buildDilutionEvents($syncRows, $now);
        $covenantEvents     = self::buildCovenantEvents($syncRows, $now);
        $talismanEvents     = self::buildTalismanEvents($syncRows, $now);
        $communityEvents    = self::buildCommunityEvents($syncRows, $now);

        $events = array_slice(self::mergeEventsByRecency(
            self::buildEvents($pyramidRows, $now),
            self::buildLedgerEvents($syncRows, $now, self::LEDGER_EVENTS_LIMIT),
            self::buildAttendanceEvents($attendanceRows, $now),
            array_slice($dressingRoomEvents, 0, self::DRESSING_ROOM_EVENTS_LIMIT),
            array_slice($dilutionEvents, 0, self::DILUTION_EVENTS_LIMIT),
            array_slice($covenantEvents, 0, self::COVENANT_EVENTS_LIMIT),
            array_slice($talismanEvents, 0, self::TALISMAN_EVENTS_LIMIT),
            array_slice($communityEvents, 0, self::COMMUNITY_EVENTS_LIMIT),
        ), 0, self::TOTAL_FEED_LIMIT);

        $activeClubs = $this->syncRecordRepository->countActiveClubsSince($since);
        $weeksPlayed = count($syncRows) * self::WEEKS_PER_SYNC;

        $dilutionSummary = self::buildDilutionSummary($dilutionEvents);
        $covenantSummary = self::buildCovenantSummary($syncRows);
        $moraleDelta     = self::computeCommunityMoraleDelta($syncRows);

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
            $dilutionSummary['percent'] ?? null,
            $dilutionSummary['club'] ?? null,
            $dilutionSummary['counterparty'] ?? null,
            count($dressingRoomEvents),
            $moraleDelta,
            $covenantSummary['count'],
            $covenantSummary['valuePence'],
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

            $events[] = self::event($row['createdAt'], $now, 'PYRAMID', '[PYRAMID // MOVEMENT]', $clubName, $text);
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
            static fn (array $entry): array => self::event(
                $entry['serverTimestamp'],
                $now,
                'OUTLAY',
                '[BOARDROOM // OUTLAY]',
                $entry['clubName'],
                sprintf('%s spent %s: %s', $entry['clubName'], LiveTelemetrySnapshot::formatPence(abs($entry['amountPence'])), $entry['description']),
                amountExact: LiveTelemetrySnapshot::formatPenceExact(abs($entry['amountPence'])),
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
                );
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

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
     * The most notable dilution event this window (highest equity%), for the
     * redesigned stat box. Reads the already-built buildDilutionEvents() output
     * rather than re-scanning the payloads.
     *
     * @param array<int, array<string, mixed>> $dilutionEvents
     * @return array{percent: int, club: ?string, counterparty: ?string}|null
     */
    public static function buildDilutionSummary(array $dilutionEvents): ?array
    {
        $best = null;

        foreach ($dilutionEvents as $event) {
            $percent = $event['meta']['equityPercent'] ?? null;
            if ($percent === null) {
                continue;
            }

            if ($best === null || $percent > $best['percent']) {
                $best = [
                    'percent'      => $percent,
                    'club'         => $event['club'],
                    'counterparty' => $event['meta']['counterparty'] ?? null,
                ];
            }
        }

        return $best;
    }

    /**
     * Count and total seasonal value-at-risk of distinct active covenants (deduped
     * by promise id, since a club syncing more than once in the window would
     * otherwise double-count the same promise).
     *
     * @param array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}> $syncRows
     * @return array{count: int, valuePence: int}
     */
    public static function buildCovenantSummary(array $syncRows): array
    {
        $seenIds = [];
        $totalValuePence = 0;

        foreach ($syncRows as $row) {
            foreach ($row['payload']['promises'] ?? [] as $promise) {
                if (!is_array($promise) || ($promise['status'] ?? null) !== 'active') {
                    continue;
                }

                if (!in_array($promise['condition']['type'] ?? null, self::COVENANT_CONDITION_TYPES, true)) {
                    continue;
                }

                $id = $promise['id'] ?? null;
                if ($id === null || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;

                $totalValuePence += self::covenantValuePence($promise['offer'] ?? null);
            }
        }

        return ['count' => count($seenIds), 'valuePence' => $totalValuePence];
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
            ],
            $events,
        );
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
            'at'            => $at,
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
