<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Enum\LeaderboardCategory;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SeasonSnapshotRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

class LeagueService
{
    public function __construct(
        private readonly LeagueRepository         $leagueRepository,
        private readonly EntityManagerInterface   $em,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly HallOfFameScoreService   $hallOfFameScoreService,
        private readonly LeaderboardCalculationService $leaderboardCalculationService,
        private readonly SeasonRecordRepository   $seasonRecordRepository,
        private readonly SeasonSnapshotRepository $seasonSnapshotRepository,
        private readonly \App\Service\Economy\LeagueSizeResolver $leagueSizeResolver,
        private readonly \App\Service\Economy\SponsorBandResolver $sponsorBandResolver,
    ) {}

    /** The deepest pyramid the per-tier config tables describe. */
    public const MAX_TIERS = 8;

    /**
     * Create a country's league pyramid, and remove any tier below the depth asked for.
     *
     * $tiers DEFAULTS TO 8 so every existing caller keeps its behaviour unchanged.
     *
     * Additive per tier, as it always was — an existing tier is skipped, never rewritten,
     * because these rows are hand-corrected and then referenced by generated clubs.
     *
     * Shrinking IS destructive, and deliberately so. A league row is pool data: once a
     * world pack is consumed it lives as a client-side snapshot, so removing a tier changes
     * only what future packs are built from, not anybody's running game. Nothing cascades
     * at the database level, so the order below matters.
     *
     * @return array{created: League[], skipped: int, removedTiers: int, removedClubs: int}
     */
    public function generateLeaguesForCountry(string $country, int $tiers = self::MAX_TIERS): array
    {
        $tiers   = max(1, min(self::MAX_TIERS, $tiers));
        $created = [];
        $skipped = 0;

        for ($tier = 1; $tier <= $tiers; $tier++) {
            if ($this->leagueRepository->findByCountryAndTier($country, $tier) !== null) {
                ++$skipped;
                continue;
            }
            $defaults = $this->gameConfigRepository->getConfig()->getLeagueTierDefaultsForTier($tier);
            $league   = new League($country, $tier, sprintf('League %d', $tier));
            $league->setPromotionSpots($defaults['promotionSpots']);
            $league->setTvDeal($defaults['tvDeal']);
            $league->setPrizeMoney($defaults['prizeMoney']);
            $league->setLeaguePositionPot($defaults['leaguePositionPot']);
            $league->setSponsorCount($defaults['sponsorCount']);
            $league->setTrophy($defaults['trophy']);
            $league->setTvPoolPence($defaults['tvPoolPence']);
            $league->setLeaguePositionDecreasePercent($defaults['leaguePositionDecreasePercent']);
            // NO syncTvDeal() HERE, DELIBERATELY. This runs BEFORE any NPC club exists — the
            // bootstrap creates leagues first and fills them afterwards — so a league's size is
            // 0 (or 1, for the bottom tier) at this moment and a derived share would be the
            // whole pool. The tier default's own tvDeal stands, which is exactly what this
            // method has always written; the share starts being derived once the clubs are
            // there and an operator applies the defaults or sets `slots`.
            $this->em->persist($league);
            $created[] = $league;
        }
        $this->em->flush();

        $removed = $this->removeTiersBelow($country, $tiers);

        return [
            'created'      => $created,
            'skipped'      => $skipped,
            'removedTiers' => $removed['tiers'],
            'removedClubs' => $removed['clubs'],
        ];
    }

    /**
     * Drop every tier deeper than $keepTiers for a country.
     *
     * Three steps, in this order, because none of the foreign keys cascade:
     *
     *   1. Detach player clubs. club.current_league_id is a nullable join column with no
     *      ON DELETE rule, so the delete would simply fail with one pointing at it. Nulling
     *      it costs the player nothing — SyncService::maybeAutoAssignLeague() re-assigns
     *      from the lowest remaining tier on their next sync.
     *   2. Delete the NPC clubs. They are pool data for a division that no longer exists;
     *      leaving them would strand rows that no world pack can ever reach while they
     *      still count in every admin total.
     *   3. Delete the leagues.
     *
     * @return array{tiers: int, clubs: int}
     */
    private function removeTiersBelow(string $country, int $keepTiers): array
    {
        $surplus = array_values(array_filter(
            $this->leagueRepository->findByCountry($country),
            static fn (League $l): bool => $l->getTier() > $keepTiers,
        ));

        if ($surplus === []) {
            return ['tiers' => 0, 'clubs' => 0];
        }

        $ids   = array_map(static fn (League $l) => $l->getId(), $surplus);
        $conn  = $this->em->getConnection();
        $clubs = 0;

        $conn->executeStatement(
            'UPDATE club SET current_league_id = NULL WHERE current_league_id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );

        $clubs = (int) $conn->executeStatement(
            'DELETE FROM npc_club WHERE league_id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );

        foreach ($surplus as $league) {
            $this->em->remove($league);
        }
        $this->em->flush();

        return ['tiers' => \count($surplus), 'clubs' => $clubs];
    }

    /** Assigns the NpcClub to its matching League if one exists (country + tier). */
    public function assignClubToLeague(NpcClub $club): void
    {
        $league = $this->leagueRepository->findByCountryAndTier($club->getCountry(), $club->getTier());
        if ($league !== null) {
            $club->setLeague($league);
        }
    }

    /**
     * Assigns a new Club to the bottom division of its country.
     *
     * Resolved as the LOWEST tier that actually exists, not tier 8. A country may have a
     * shallower pyramid, and looking up a hardcoded 8 there finds nothing and silently
     * leaves the club with no league at all. SyncService::maybeAutoAssignLeague() has
     * always done it this way; this method had drifted.
     */
    public function assignClubToStarterLeague(Club $club, string $country): void
    {
        $league = $this->leagueRepository->findLowestTierForCountry($country);
        if ($league !== null) {
            $club->setCurrentLeague($league);
        }
    }


    /**
     * Re-rolls the income for every sponsor on the given league.
     * Each sponsor's rolledValue is set to a random integer within the
     * GameConfig range for that sponsor's CompanySize.
     *
     * @return int Total sponsor pot (sum of all rolled values)
     */
    public function rollLeagueSponsors(League $league, GameConfig $config): int
    {
        // DELEGATED SINCE 2026-11-12. This loop existed byte-identically in both
        // LeagueService and WorldInitializationService; SponsorBandResolver is now the one
        // implementation, and it scales the band by the league's COUNTRY — which the flat
        // columns never did, so a club in a country factored to 20% used to earn full English
        // sponsorship while paying 20% wages. Kept as a public method because external callers
        // type-hint it.
        return $this->sponsorBandResolver->rollLeagueSponsors($league, $config);
    }

    /**
     * Computes new league membership for the next season by applying the
     * promoted/relegated flags from the pyramid snapshot.
     *
     * For each club in a league's standings:
     *   - promoted=true  → moves to tier-1 league
     *   - relegated=true → moves to tier+1 league
     *   - otherwise      → stays in the same league
     *
     * @param  array<string, mixed> $pyramidSnapshot  The `pyramidSnapshot` from ConcludeSeasonRequest
     * @return array<string, string[]>  leagueId (string UUID) → array of club IDs
     */
    private function computeNewLeagueMembership(array $pyramidSnapshot, string $country): array
    {
        $leagues     = $this->leagueRepository->findByCountry($country);
        $leagueById  = [];
        $leagueByTier = [];
        foreach ($leagues as $league) {
            $id = (string) $league->getId();
            $leagueById[$id]                   = $league;
            $leagueByTier[$league->getTier()]   = $id;
        }

        // Initialise empty buckets for every league.
        $membership = array_fill_keys(array_keys($leagueById), []);

        foreach ($pyramidSnapshot['leagues'] ?? [] as $leagueEntry) {
            $leagueId = $leagueEntry['leagueId'] ?? null;
            if ($leagueId === null || !isset($leagueById[$leagueId])) {
                continue;
            }

            $currentTier = $leagueById[$leagueId]->getTier();

            foreach ($leagueEntry['standings'] ?? [] as $standing) {
                $clubId = $standing['clubId'] ?? null;
                if ($clubId === null) {
                    continue;
                }

                if ($standing['promoted'] ?? false) {
                    $targetId = $leagueByTier[$currentTier - 1] ?? null;
                } elseif ($standing['relegated'] ?? false) {
                    $targetId = $leagueByTier[$currentTier + 1] ?? null;
                } else {
                    $targetId = null;
                }

                // Fall back to same league if target tier doesn't exist.
                $destinationId = $targetId ?? $leagueId;
                $membership[$destinationId][] = [
                    'clubId'    => $clubId,
                    'isAmp'     => $standing['isAmp'] ?? false,
                    'promoted'  => $standing['promoted'] ?? false,
                    'relegated' => $standing['relegated'] ?? false,
                ];
            }
        }

        return $membership;
    }

    /**
     * Builds the full league pyramid for a country with only the data that can
     * change server-side between seasons: league financials, freshly rolled sponsor
     * pots, new fixture schedules, and post-movement club lists.
     *
     * @param  array<string, string[]> $newLeagueMembership  leagueId → clubIds after promotion/relegation
     * @return array[]
     */
    private function buildSeasonPyramid(string $country, GameConfig $gameConfig, array $newLeagueMembership): array
    {
        $leagues = $this->leagueRepository->findByCountry($country);
        $result  = [];

        foreach ($leagues as $league) {
            $leagueId   = (string) $league->getId();
            $clubs      = $newLeagueMembership[$leagueId] ?? [];
            $clubIds    = array_column($clubs, 'clubId');

            $sponsorPot = $this->rollLeagueSponsors($league, $gameConfig);
            $fixtures   = $this->fixtureGenerationService->generate($clubIds);

            $result[] = [
                'id'                            => $leagueId,
                'tier'                          => $league->getTier(),
                'name'                          => $league->getName(),
                'country'                       => $league->getCountry(),
                'promotionSpots'                => $league->getPromotionSpots(),
                'reputationTier'                => $league->getLeagueReputationTier()?->value,
                'tvDeal'                        => $league->getTvDeal(),
                'sponsorPot'                    => $sponsorPot,
                'prizeMoney'                    => $league->getPrizeMoney(),
                'leaguePositionPot'             => $league->getLeaguePositionPot(),
                // RESOLVED `league ?? gameConfig`. The key keeps its name, its type and — for
                // every league with no override, which is all of them until an operator sets
                // one — its exact value.
                'leaguePositionDecreasePercent' => $league->getLeaguePositionDecreasePercent()
                    ?? $gameConfig->getLeaguePositionDecreasePercent(),
                // ADDITIVE. The pool is the authored figure and `clubCount` is what it was
                // divided by, so a client can check the arithmetic rather than trusting it —
                // and can pay NPC clubs their share, which the backend cannot do (no income
                // loop here; the device runs the economy).
                'tvPoolPence'                   => $league->getTvPoolPence(),
                'clubCount'                     => $this->leagueSizeResolver->sizeOf($league),
                // RESOLVED WHOLE-VALUE, `league ?? gameConfig`. Not merged key by key — that
                // would need the backend to understand the keys, which is exactly what a
                // verbatim passthrough declines to do.
                'parachute'                     => $league->getParachute() ?? $gameConfig->getLeagueParachute(),
                'clubs'                         => $clubs,
                'fixtures'                      => $fixtures,
            ];
        }

        return $result;
    }

    /**
     * Concludes one season for a club:
     * - Persists SeasonRecord + SeasonSnapshot
     * - Moves AMP club to new league based on dto->promoted/relegated
     * - Increments club.currentSeason
     * - Returns the full league pyramid with re-rolled financials and new fixtures
     *
     * NPC club/league structure is static pool data and is never mutated here.
     * Promotion/relegation of NPC clubs is managed entirely on the client.
     *
     * **Idempotent per club + season for a RETRY; a CORRECTION overwrites.** The client
     * queues this call and retries it on the next good connection, and a cancelled request
     * can never be proven not to have reached the server — so a duplicate, or one arriving
     * after the next season has already started, must be a no-op that answers exactly what
     * the first call answered. That is why `$dto->season` exists: keying on the club's
     * *current* season would let a late retry conclude the following season by accident.
     * Three guards together:
     *
     *  1. `SeasonRecord` is unique on (club, season) in the database, so a duplicate *row*
     *     is impossible even if two requests race past the check below.
     *  2. The first call's response body is stored on `SeasonSnapshot::$responseJson` and
     *     replayed verbatim, rather than recomputed — see that property's docblock for why
     *     recomputing would be wrong.
     *  3. `currentSeason` moves to `max(current, season + 1)` — never backwards, and never
     *     past what the payload itself claims. Concluding an older season late leaves the
     *     club where it is; a season *ahead* of the server's count pulls the count up to it.
     *
     * That last case is deliberately **accepted rather than rejected**. It means the server
     * missed the seasons in between, which is exactly when keeping the archive you did
     * receive matters most — and the client's outbox drops a 4xx rather than retrying it, so
     * a 422 here would turn a recoverable gap into permanent, silent data loss, cascading
     * across every later season behind it. The client is authoritative for gameplay anyway.
     * An absurd season number is caught by `ConcludeSeasonRequest`'s own bound instead,
     * where a 422 is unambiguously right.
     *
     * **A resubmission carrying different figures is a correction, not a retry**, and
     * overwrites the archive rather than being swallowed. "Different" means any of the ten
     * result fields in `SeasonRecord::resultFields()` — the archival blobs are excluded on
     * purpose, because a reordered 52KB fixtures array is a retry. Identical figures still
     * write nothing, so a network retry can never inflate `overwrite_count`; the superseded
     * position and W/D/L are filed on `SeasonRecord::$overwriteAudit`. The overwrite is
     * narrow by design — see `overwriteConcludedSeason()` for exactly what it does not touch
     * and why re-running the league transition here would double-promote the club.
     *
     * @return array{seasonRecordId: string, newLeague: array|null, leagues: array}
     */
    public function concludeSeason(Club $club, ConcludeSeasonRequest $dto): array
    {
        $currentSeason = $club->getCurrentSeason();
        $targetSeason  = $dto->season ?? $currentSeason;

        $alreadyConcluded = $this->seasonRecordRepository->findOneByClubAndSeason($club, $targetSeason);
        if ($alreadyConcluded !== null) {
            // A retry says the same thing and must write nothing; a correction says
            // something different and overwrites the archive. Either way the reply is the
            // body the first conclude committed — see replayConcludedSeason().
            if (!$alreadyConcluded->matchesResult(self::resultFieldsFrom($dto))) {
                $this->overwriteConcludedSeason($alreadyConcluded, $dto);
            }

            return $this->replayConcludedSeason($club, $alreadyConcluded, $dto, $targetSeason);
        }

        $currentLeague = $club->getCurrentLeague();
        if ($currentLeague === null) {
            throw new \RuntimeException('Club has no current league assigned.');
        }

        $country = $currentLeague->getCountry();

        $record = new SeasonRecord(
            club:          $club,
            league:        $currentLeague,
            season:        $targetSeason,
            finalPosition: $dto->finalPosition,
            gamesPlayed:   $dto->gamesPlayed,
            wins:          $dto->wins,
            draws:         $dto->draws,
            losses:        $dto->losses,
            goalsFor:      $dto->goalsFor,
            goalsAgainst:  $dto->goalsAgainst,
            points:        $dto->points,
            promoted:      $dto->promoted,
            relegated:     $dto->relegated,
        );
        $this->em->persist($record);

        $snapshot = new SeasonSnapshot(
            club:         $club,
            season:       $targetSeason,
            country:      $country,
            snapshotData: $this->buildSnapshotData($currentLeague, $dto),
        );
        $this->em->persist($snapshot);

        // Move AMP club
        $newLeague = $this->resolveNewLeague($currentLeague, $dto->promoted, $dto->relegated);
        if ($newLeague !== null) {
            $club->setCurrentLeague($newLeague);
        }

        // Never backwards, and never past where this payload says the club has got to.
        // A late retry for an *older* season archives what was missing and leaves the club
        // where it is; a payload for a season ahead of the server's count catches the count
        // up, because the client is authoritative for gameplay (the server is not the game
        // engine — see The Hybrid Model in CLAUDE.md) and the gap means the server simply
        // never received the seasons in between.
        $club->setCurrentSeason(max($currentSeason, $targetSeason + 1));

        $gameConfig = $this->gameConfigRepository->getConfig();

        // Flush all movements before buildSeasonPyramid() queries clubs by league
        $this->em->flush();

        // A league title may have just landed — recompute the derived Hall of Fame score from
        // the club's SeasonRecords so the board reflects it immediately rather than at the next
        // cache expiry. Must run *after* the flush above: the score is read back with DQL, which
        // does not see the SeasonRecord persisted earlier in this method until it hits the DB.
        $hallOfFamePoints = $this->hallOfFameScoreService->syncClub($club);
        $this->leaderboardCalculationService->invalidate(LeaderboardCategory::HALL_OF_FAME);
        $this->em->flush();

        $response = $this->buildConcludeResponse(
            $record,
            $newLeague,
            $hallOfFamePoints,
            $country,
            $gameConfig,
            $dto->pyramidSnapshot,
        );

        $this->storeConcludeResponse($snapshot, $response);

        return $response;
    }

    /**
     * Corrects an already-concluded season from a resubmission carrying different figures.
     *
     * Rewrites exactly two rows — the SeasonRecord's result (plus its overwrite counter and
     * audit) and the SeasonSnapshot's archived season data — and **nothing else**. Not the
     * club's current league, not its currentSeason, not league sponsors, not fixtures, and
     * not Club::$hallOfFamePoints. That narrowness is a deliberate decision, not an
     * oversight:
     *
     *  - The client owns the league-standing transition now; this payload is purely backend
     *    data. Re-running resolveNewLeague() here would read the club's *already moved*
     *    league and promote it a second tier.
     *  - hallOfFamePoints is derived from `finalPosition = 1` rows, so a correction into or
     *    out of first place does leave the stored mirror stale until the club's next
     *    conclude or the next `app:leaderboards:generate`. Accepted: a dispute is settled by
     *    hand from $overwriteAudit, which is what it is for.
     *
     * Both writes are raw SQL, for the reason storeConcludeResponse() documents — Doctrine's
     * dirty check silently skips a `json` column holding mixed types — and the increment is
     * then atomic rather than read-modify-write. One transaction, so a corrected record can
     * never commit without its matching snapshot. **No flush on this path at all**, which
     * also means the unpersisted LeagueSponsor re-rolls that forced raw SQL elsewhere cannot
     * arise here; replayConcludedSeason() may roll sponsors, so it runs after this returns.
     */
    private function overwriteConcludedSeason(SeasonRecord $record, ConcludeSeasonRequest $dto): void
    {
        $record->overwriteWith(self::resultFieldsFrom($dto), new \DateTimeImmutable());

        // Both read off the record rather than off the club or the payload: the club has
        // moved on, and the record is what says which season and which league this is.
        $snapshot     = $this->seasonSnapshotRepository->findByClubAndSeason($record->getClub(), $record->getSeason());
        $snapshotData = $this->buildSnapshotData($record->getLeague(), $dto);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                <<<'SQL'
                UPDATE season_record
                   SET final_position  = :finalPosition,
                       games_played    = :gamesPlayed,
                       wins            = :wins,
                       draws           = :draws,
                       losses          = :losses,
                       goals_for       = :goalsFor,
                       goals_against   = :goalsAgainst,
                       points          = :points,
                       promoted        = :promoted,
                       relegated       = :relegated,
                       overwrite_count = overwrite_count + 1,
                       overwrite_audit = :audit
                 WHERE id = :id
                SQL,
                [
                    'finalPosition' => $dto->finalPosition,
                    'gamesPlayed'   => $dto->gamesPlayed,
                    'wins'          => $dto->wins,
                    'draws'         => $dto->draws,
                    'losses'        => $dto->losses,
                    'goalsFor'      => $dto->goalsFor,
                    'goalsAgainst'  => $dto->goalsAgainst,
                    'points'        => $dto->points,
                    'promoted'      => $dto->promoted,
                    'relegated'     => $dto->relegated,
                    'audit'         => json_encode($record->getOverwriteAudit(), JSON_THROW_ON_ERROR),
                    'id'            => (string) $record->getId(),
                ],
                [
                    'promoted'  => ParameterType::BOOLEAN,
                    'relegated' => ParameterType::BOOLEAN,
                ],
            );

            if ($snapshot !== null) {
                $snapshot->setSnapshotData($snapshotData);

                $connection->executeStatement(
                    'UPDATE season_snapshot SET snapshot_data = :json WHERE id = :id',
                    [
                        'json' => json_encode($snapshotData, JSON_THROW_ON_ERROR),
                        'id'   => (string) $snapshot->getId(),
                    ],
                );
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * The ten fields that define "the result" of a season, lifted off a request payload in
     * the shape SeasonRecord::matchesResult()/overwriteWith() expect.
     *
     * Written out longhand rather than derived from the property names, so renaming either
     * side is a type error here instead of a silently-skipped comparison that would make
     * every correction look like a retry.
     *
     * @return array{finalPosition: int, gamesPlayed: int, wins: int, draws: int, losses: int, goalsFor: int, goalsAgainst: int, points: int, promoted: bool, relegated: bool}
     */
    private static function resultFieldsFrom(ConcludeSeasonRequest $dto): array
    {
        return [
            'finalPosition' => $dto->finalPosition,
            'gamesPlayed'   => $dto->gamesPlayed,
            'wins'          => $dto->wins,
            'draws'         => $dto->draws,
            'losses'        => $dto->losses,
            'goalsFor'      => $dto->goalsFor,
            'goalsAgainst'  => $dto->goalsAgainst,
            'points'        => $dto->points,
            'promoted'      => $dto->promoted,
            'relegated'     => $dto->relegated,
        ];
    }

    /**
     * Answers a duplicate or late conclude for a season this club has already finished,
     * without writing anything a second time.
     *
     * @return array{seasonRecordId: string, newLeague: array|null, leagues: array}
     */
    private function replayConcludedSeason(
        Club                 $club,
        SeasonRecord         $record,
        ConcludeSeasonRequest $dto,
        int                  $season,
    ): array {
        $snapshot = $this->seasonSnapshotRepository->findByClubAndSeason($club, $season);

        $stored = $snapshot?->getResponseJson();
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        // Concluded, but with no stored body to replay: either a row written before
        // $responseJson existed, or the narrow window where the first attempt committed the
        // archive and then died before it finished building the pyramid. Rebuild it once from
        // the record — which already carries the league and the promoted/relegated flags — and
        // store it, so every later retry takes the cheap path above.
        $league  = $record->getLeague();
        $pyramid = $dto->pyramidSnapshot !== []
            ? $dto->pyramidSnapshot
            : (is_array($snapshot?->getSnapshotData()['pyramid'] ?? null) ? $snapshot->getSnapshotData()['pyramid'] : []);

        $response = $this->buildConcludeResponse(
            $record,
            $this->resolveNewLeague($league, $record->isPromoted(), $record->isRelegated()),
            $club->getHallOfFamePoints(),
            $league->getCountry(),
            $this->gameConfigRepository->getConfig(),
            $pyramid,
        );

        if ($snapshot !== null) {
            $this->storeConcludeResponse($snapshot, $response);
        }

        return $response;
    }

    /**
     * The archived season data: the club's own result, the client's pyramid, and the two
     * verbatim archival blobs.
     *
     * Shared by the fresh conclude and by overwriteConcludedSeason(), so a correction can
     * only ever archive the same shape the first conclude did. $league is a parameter
     * rather than read off the club because an overwrite must use the league the season
     * was *played* in (SeasonRecord::getLeague()), not wherever the club has since moved.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshotData(League $league, ConcludeSeasonRequest $dto): array
    {
        return [
            'amp' => [
                'leagueTier'    => $league->getTier(),
                'leagueName'    => $league->getName(),
                'finalPosition' => $dto->finalPosition,
                'gamesPlayed'   => $dto->gamesPlayed,
                'wins'          => $dto->wins,
                'draws'         => $dto->draws,
                'losses'        => $dto->losses,
                'goalsFor'      => $dto->goalsFor,
                'goalsAgainst'  => $dto->goalsAgainst,
                'points'        => $dto->points,
                'promoted'      => $dto->promoted,
                'relegated'     => $dto->relegated,
            ],
            'pyramid' => $dto->pyramidSnapshot,
            // Archival only — no structured storage/processing yet (see ConcludeSeasonRequest).
            'seasonLeaders'  => $dto->seasonLeaders,
            'seasonFixtures' => $dto->seasonFixtures,
        ];
    }

    /**
     * The league a club moves to given the one it just finished in. Shared by the fresh
     * conclude and the replay rebuild so both can only ever agree; tier 1 cannot promote
     * and the country's bottom tier cannot relegate.
     */
    private function resolveNewLeague(League $from, bool $promoted, bool $relegated): ?League
    {
        if ($promoted && $from->getTier() > 1) {
            return $this->leagueRepository->findByCountryAndTier($from->getCountry(), $from->getTier() - 1);
        }

        // Compared against the country's real bottom tier, not a hardcoded 8. With a
        // shallower pyramid the old guard let a bottom-tier relegation query a division
        // that cannot exist; it returned null and read as "no move", so it was harmless by
        // luck rather than by design.
        $bottom = $this->leagueRepository->findLowestTierForCountry($from->getCountry())?->getTier() ?? $from->getTier();

        if ($relegated && $from->getTier() < $bottom) {
            return $this->leagueRepository->findByCountryAndTier($from->getCountry(), $from->getTier() + 1);
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $pyramidSnapshot
     * @return array{seasonRecordId: string, newLeague: array|null, leagues: array}
     */
    private function buildConcludeResponse(
        SeasonRecord $record,
        ?League      $newLeague,
        int          $hallOfFamePoints,
        string       $country,
        GameConfig   $gameConfig,
        array        $pyramidSnapshot,
    ): array {
        // Compute new league membership from the snapshot's promotion/relegation flags,
        // then build the full pyramid: financials, clubs per league, and new fixtures.
        $newLeagueMembership = $this->computeNewLeagueMembership($pyramidSnapshot, $country);

        return [
            'seasonRecordId'   => (string) $record->getId(),
            'hallOfFamePoints' => $hallOfFamePoints,
            'newLeague'      => $newLeague !== null ? [
                'id'   => (string) $newLeague->getId(),
                'tier' => $newLeague->getTier(),
                'name' => $newLeague->getName(),
            ] : null,
            'leagues'        => $this->buildSeasonPyramid($country, $gameConfig, $newLeagueMembership),
        ];
    }

    /**
     * Writes the replayable body with raw SQL rather than an ORM flush, for two reasons that
     * both matter here: `json` columns holding mixed types are silently skipped by Doctrine's
     * dirty check (see CLAUDE.md), and more importantly buildSeasonPyramid() has just called
     * rollLeagueSponsors(), which mutates managed LeagueSponsor entities. Those re-rolls are
     * deliberately *not* persisted today — they only ever appear in the response — and a
     * flush here would start committing them as a side effect of adding this column.
     *
     * @param array<string, mixed> $response
     */
    private function storeConcludeResponse(SeasonSnapshot $snapshot, array $response): void
    {
        $snapshot->setResponseJson($response);

        $this->em->getConnection()->executeStatement(
            'UPDATE season_snapshot SET response_json = :json WHERE id = :id',
            [
                'json' => json_encode($response, JSON_THROW_ON_ERROR),
                'id'   => (string) $snapshot->getId(),
            ],
        );
    }
}
