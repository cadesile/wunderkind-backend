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
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeagueService
{
    public function __construct(
        private readonly LeagueRepository         $leagueRepository,
        private readonly EntityManagerInterface   $em,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
    ) {}

    /**
     * Tier-based financial defaults applied when generating new leagues.
     * Values are in pence. Promotion spots: null = no promotion (top tier).
     */
    private const LEAGUE_TIER_DEFAULTS = [
        1 => ['promotionSpots' => null, 'tvDeal' => 1_000_000_000, 'prizeMoney' => 1_000_000_000, 'leaguePositionPot' => 1_000_000_000],
        2 => ['promotionSpots' => 2,    'tvDeal' =>   100_000_000, 'prizeMoney' =>   100_000_000, 'leaguePositionPot' =>   100_000_000],
        3 => ['promotionSpots' => 2,    'tvDeal' =>    50_000_000, 'prizeMoney' =>    50_000_000, 'leaguePositionPot' =>    50_000_000],
        4 => ['promotionSpots' => 2,    'tvDeal' =>    30_000_000, 'prizeMoney' =>    30_000_000, 'leaguePositionPot' =>    30_000_000],
        5 => ['promotionSpots' => 2,    'tvDeal' =>    10_000_000, 'prizeMoney' =>    10_000_000, 'leaguePositionPot' =>    10_000_000],
        6 => ['promotionSpots' => 2,    'tvDeal' =>     3_000_000, 'prizeMoney' =>     3_000_000, 'leaguePositionPot' =>     3_000_000],
        7 => ['promotionSpots' => 2,    'tvDeal' =>     1_000_000, 'prizeMoney' =>     1_000_000, 'leaguePositionPot' =>     1_000_000],
        8 => ['promotionSpots' => 2,    'tvDeal' =>       500_000, 'prizeMoney' =>       500_000, 'leaguePositionPot' =>       500_000],
    ];

    /** @return League[] newly created leagues (skips tiers that already exist) */
    public function generateLeaguesForCountry(string $country): array
    {
        $created = [];
        for ($tier = 1; $tier <= 8; $tier++) {
            if ($this->leagueRepository->findByCountryAndTier($country, $tier) !== null) {
                continue;
            }
            $defaults = self::LEAGUE_TIER_DEFAULTS[$tier];
            $league   = new League($country, $tier, sprintf('League %d', $tier));
            $league->setPromotionSpots($defaults['promotionSpots']);
            $league->setTvDeal($defaults['tvDeal']);
            $league->setPrizeMoney($defaults['prizeMoney']);
            $league->setLeaguePositionPot($defaults['leaguePositionPot']);
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
     * Concludes the current season for a club:
     * - Persists SeasonRecord + SeasonSnapshot
     * - Moves AMP club to new league based on dto->promoted/relegated
     * - Increments club.currentSeason
     * - Returns the full league pyramid with re-rolled financials and new fixtures
     *
     * NPC club/league structure is static pool data and is never mutated here.
     * Promotion/relegation of NPC clubs is managed entirely on the client.
     *
     * @return array{seasonRecordId: string, newLeague: array|null, leagues: array}
     */
    public function concludeSeason(Club $club, ConcludeSeasonRequest $dto): array
    {
        $currentLeague = $club->getCurrentLeague();
        if ($currentLeague === null) {
            throw new \RuntimeException('Club has no current league assigned.');
        }

        $country = $currentLeague->getCountry();

        $record = new SeasonRecord(
            club:          $club,
            league:        $currentLeague,
            season:        $club->getCurrentSeason(),
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
            season:       $club->getCurrentSeason(),
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
            ],
        );
        $this->em->persist($snapshot);

        // Move AMP club
        $newLeague = null;
        if ($dto->promoted && $currentLeague->getTier() > 1) {
            $newLeague = $this->leagueRepository->findByCountryAndTier($country, $currentLeague->getTier() - 1);
        } elseif ($dto->relegated && $currentLeague->getTier() < 8) {
            $newLeague = $this->leagueRepository->findByCountryAndTier($country, $currentLeague->getTier() + 1);
        }
        if ($newLeague !== null) {
            $club->setCurrentLeague($newLeague);
        }

        $club->setCurrentSeason($club->getCurrentSeason() + 1);

        $gameConfig = $this->gameConfigRepository->getConfig();

        // Flush all movements before buildSeasonPyramid() queries clubs by league
        $this->em->flush();

        // Compute new league membership from the snapshot's promotion/relegation flags,
        // then build the full pyramid: financials, clubs per league, and new fixtures.
        $newLeagueMembership = $this->computeNewLeagueMembership($dto->pyramidSnapshot, $country);
        $leagues = $this->buildSeasonPyramid($country, $gameConfig, $newLeagueMembership);

        return [
            'seasonRecordId' => (string) $record->getId(),
            'newLeague'      => $newLeague !== null ? [
                'id'   => (string) $newLeague->getId(),
                'tier' => $newLeague->getTier(),
                'name' => $newLeague->getName(),
            ] : null,
            'leagues'        => $leagues,
        ];
    }

}
