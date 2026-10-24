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
use App\Enum\CompanySize;
use App\Enum\LeaderboardCategory;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SeasonSnapshotRepository;
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
    ) {}

    /** @return League[] newly created leagues (skips tiers that already exist) */
    public function generateLeaguesForCountry(string $country): array
    {
        $created = [];
        for ($tier = 1; $tier <= 8; $tier++) {
            if ($this->leagueRepository->findByCountryAndTier($country, $tier) !== null) {
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
            $this->em->persist($league);
            $created[] = $league;
        }
        $this->em->flush();
        return $created;
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
     * Assigns a new Club to the tier-8 league for its country.
     * Call this from ClubInitService after club creation.
     */
    public function assignClubToStarterLeague(Club $club, string $country): void
    {
        $league = $this->leagueRepository->findByCountryAndTier($country, 8);
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
        $total = 0;
        foreach ($league->getLeagueSponsors() as $ls) {
            [$min, $max] = match ($ls->getSponsor()->getSize()) {
                CompanySize::SMALL  => [$config->getSmallSponsorMin(),  $config->getSmallSponsorMax()],
                CompanySize::MEDIUM => [$config->getMediumSponsorMin(), $config->getMediumSponsorMax()],
                CompanySize::LARGE  => [$config->getLargeSponsorMin(),  $config->getLargeSponsorMax()],
            };
            $value = $max > $min ? random_int($min, $max) : $min;
            $ls->setRolledValue($value);
            $total += $value;
        }
        return $total;
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
                'leaguePositionDecreasePercent' => $gameConfig->getLeaguePositionDecreasePercent(),
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
     * **Idempotent per club + season.** The client queues this call and retries it on the
     * next good connection, and a cancelled request can never be proven not to have reached
     * the server — so a duplicate, or one arriving after the next season has already
     * started, must be a no-op that answers exactly what the first call answered. That is
     * why `$dto->season` exists: keying on the club's *current* season would let a late
     * retry conclude the following season by accident. Three guards together:
     *
     *  1. `SeasonRecord` is unique on (club, season) in the database, so a duplicate row is
     *     impossible even if two requests race past the check below.
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
     * @return array{seasonRecordId: string, newLeague: array|null, leagues: array}
     */
    public function concludeSeason(Club $club, ConcludeSeasonRequest $dto): array
    {
        $currentSeason = $club->getCurrentSeason();
        $targetSeason  = $dto->season ?? $currentSeason;

        $alreadyConcluded = $this->seasonRecordRepository->findOneByClubAndSeason($club, $targetSeason);
        if ($alreadyConcluded !== null) {
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
            snapshotData: [
                'amp' => [
                    'leagueTier'    => $currentLeague->getTier(),
                    'leagueName'    => $currentLeague->getName(),
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
            ],
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
     * The league a club moves to given the one it just finished in. Shared by the fresh
     * conclude and the replay rebuild so both can only ever agree; tier 1 cannot promote
     * and tier 8 cannot relegate.
     */
    private function resolveNewLeague(League $from, bool $promoted, bool $relegated): ?League
    {
        if ($promoted && $from->getTier() > 1) {
            return $this->leagueRepository->findByCountryAndTier($from->getCountry(), $from->getTier() - 1);
        }

        if ($relegated && $from->getTier() < 8) {
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
