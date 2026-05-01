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
use App\Repository\NpcClubRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeagueService
{
    public function __construct(
        private readonly LeagueRepository         $leagueRepository,
        private readonly EntityManagerInterface   $em,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly NpcClubRepository        $npcClubRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
    ) {}

    /** @return League[] newly created leagues (skips tiers that already exist) */
    public function generateLeaguesForCountry(string $country): array
    {
        $created = [];
        for ($tier = 1; $tier <= 8; $tier++) {
            if ($this->leagueRepository->findByCountryAndTier($country, $tier) !== null) {
                continue;
            }
            $league    = new League($country, $tier, sprintf('League %d', $tier));
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
     * Builds the full league pyramid for a country with only the data that can
     * change server-side between seasons: league financials, freshly rolled sponsor
     * pots, and new fixture schedules. NPC squad data is excluded — the client
     * already holds it and it does not change on conclude-season.
     *
     * @return array[]
     */
    private function buildSeasonPyramid(Club $club, string $country, GameConfig $gameConfig): array
    {
        $leagues = $this->leagueRepository->findByCountry($country);
        $result  = [];

        foreach ($leagues as $league) {
            $npcClubs   = $this->npcClubRepository->findByLeague($league);
            // Collect all participant IDs for fixture generation.
            // clubs[] is intentionally excluded: NPC tier assignments are static pool
            // data managed client-side. The client applies NPC promotion/relegation
            // movements itself using the previous season's standings.
            $allClubIds = [];

            if ($club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
                $allClubIds[] = (string) $club->getId();
            }

            foreach ($npcClubs as $npcClub) {
                $allClubIds[] = (string) $npcClub->getId();
            }

            $sponsorPot = $this->rollLeagueSponsors($league, $gameConfig);
            $fixtures   = $this->fixtureGenerationService->generate($allClubIds);

            $result[] = [
                'id'                            => (string) $league->getId(),
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

        // Build the full pyramid: league financials (with freshly rolled sponsor pots)
        // + club identifiers + new fixtures. No squad data — client holds that already.
        $leagues = $this->buildSeasonPyramid($club, $country, $gameConfig);

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
