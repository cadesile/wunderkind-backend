<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\Academy;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\SeasonRecord;
use App\Entity\SeasonSnapshot;
use App\Repository\LeagueRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeagueService
{
    public function __construct(
        private readonly LeagueRepository       $leagueRepository,
        private readonly EntityManagerInterface $em,
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
     * Assigns a new Academy to the tier-8 league for its country.
     * Call this from AcademyInitService after academy creation.
     */
    public function assignAcademyToStarterLeague(Academy $academy, string $country): void
    {
        $league = $this->leagueRepository->findByCountryAndTier($country, 8);
        if ($league !== null) {
            $academy->setCurrentLeague($league);
        }
    }

    /**
     * Concludes the current season for an academy:
     * - Persists SeasonRecord + SeasonSnapshot
     * - Moves academy to new league if promoted/relegated
     * - Increments academy.currentSeason
     *
     * @return array{seasonRecordId: string, newLeague: array{id: string, tier: int, name: string}|null}
     */
    public function concludeSeason(Academy $academy, ConcludeSeasonRequest $dto): array
    {
        $currentLeague = $academy->getCurrentLeague();
        if ($currentLeague === null) {
            throw new \RuntimeException('Academy has no current league assigned.');
        }

        $record = new SeasonRecord(
            academy:       $academy,
            league:        $currentLeague,
            season:        $academy->getCurrentSeason(),
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
            academy:      $academy,
            season:       $academy->getCurrentSeason(),
            country:      $currentLeague->getCountry(),
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

        $newLeague = null;
        if ($dto->promoted && $currentLeague->getTier() > 1) {
            $newLeague = $this->leagueRepository->findByCountryAndTier(
                $currentLeague->getCountry(),
                $currentLeague->getTier() - 1
            );
        } elseif ($dto->relegated && $currentLeague->getTier() < 8) {
            $newLeague = $this->leagueRepository->findByCountryAndTier(
                $currentLeague->getCountry(),
                $currentLeague->getTier() + 1
            );
        }

        if ($newLeague !== null) {
            $academy->setCurrentLeague($newLeague);
        }

        $academy->setCurrentSeason($academy->getCurrentSeason() + 1);
        $this->em->flush();

        return [
            'seasonRecordId' => (string) $record->getId(),
            'newLeague'      => $newLeague !== null ? [
                'id'   => (string) $newLeague->getId(),
                'tier' => $newLeague->getTier(),
                'name' => $newLeague->getName(),
            ] : null,
        ];
    }
}
