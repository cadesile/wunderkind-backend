<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Formation;
use App\Enum\ReputationTier;
use App\Enum\TrophyColour;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeagueImportExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(
        private readonly LeagueRepository       $leagueRepository,
        private readonly NpcClubRepository      $npcClubRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        $leagues = $this->leagueRepository->findAll();
        $clubs   = $this->npcClubRepository->findAll();

        $leagueData = [];
        foreach ($leagues as $l) {
            $leagueData[] = [
                'country'              => $l->getCountry(),
                'tier'                 => $l->getTier(),
                'name'                 => $l->getName(),
                'promotionSpots'       => $l->getPromotionSpots(),
                'tvDeal'               => $l->getTvDeal(),
                'leagueReputationTier' => $l->getLeagueReputationTier()?->value,
                'prizeMoney'           => $l->getPrizeMoney(),
                'leaguePositionPot'    => $l->getLeaguePositionPot(),
                'sponsorCount'         => $l->getSponsorCount(),
                'trophyImage'          => $l->getTrophyImage(),
                'trophyColour'         => $l->getTrophyColour()?->value,
            ];
        }

        $clubData = [];
        foreach ($clubs as $c) {
            $clubData[] = [
                'name'              => $c->getName(),
                'country'           => $c->getCountry(),
                'tier'              => $c->getTier(),
                'reputation'        => $c->getReputation(),
                'primaryColor'      => $c->getPrimaryColor(),
                'secondaryColor'    => $c->getSecondaryColor(),
                'stadiumName'       => $c->getStadiumName(),
                'balance'           => $c->getBalance(),
                'playingStyle'      => $c->getPlayingStyle(),
                'financialApproach' => $c->getFinancialApproach(),
                'managerTemperament'=> $c->getManagerTemperament(),
                'facilities'        => $c->getFacilities(),
                'formation'         => $c->getFormation()->value,
                'abbreviation'      => $c->getAbbreviation(),
                'region'            => $c->getRegion(),
                'citySize'          => $c->getCitySize()->value,
                'populationSize'    => $c->getPopulationSize(),
                'isCapital'         => $c->isCapital(),
            ];
        }

        return [
            'version'    => self::EXPORT_VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'leagues'    => $leagueData,
            'clubs'      => $clubData,
        ];
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * @return array{applied: bool, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['applied' => false, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        try {
            if (is_array($data['leagues'] ?? null)) {
                $result['errors'] = array_merge($result['errors'], $this->importLeagues($data['leagues']));
            }
            if (is_array($data['clubs'] ?? null)) {
                $result['errors'] = array_merge($result['errors'], $this->importClubs($data['clubs']));
            }
            $this->em->flush();

            // After import, ensure correct league association
            $this->relinkClubsToLeagues();
            $this->em->flush();

            $result['applied'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Reports malformed rows rather than abandoning the file — a single hand-edited row
     * should not cost the operator the other few hundred.
     *
     * @return string[]
     */
    private function importLeagues(array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $created = null;
            try {
                $league = $this->leagueRepository->findByCountryAndTier($row['country'] ?? null, (int) ($row['tier'] ?? 0));

                if (!$league) {
                    $league = $created = new League($row['country'], (int) $row['tier'], $row['name']);
                    $this->em->persist($league);
                }

                $league->setName($row['name']);

                // Use array_key_exists (not isset) so explicit null values are applied correctly
                if (array_key_exists('promotionSpots', $row))
                    $league->setPromotionSpots($row['promotionSpots'] !== null ? (int) $row['promotionSpots'] : null);
                if (array_key_exists('tvDeal', $row))
                    $league->setTvDeal($row['tvDeal'] !== null ? (int) $row['tvDeal'] : null);
                if (array_key_exists('prizeMoney', $row))
                    $league->setPrizeMoney($row['prizeMoney'] !== null ? (int) $row['prizeMoney'] : null);
                if (array_key_exists('leaguePositionPot', $row))
                    $league->setLeaguePositionPot($row['leaguePositionPot'] !== null ? (int) $row['leaguePositionPot'] : null);
                if (array_key_exists('leagueReputationTier', $row))
                    $league->setLeagueReputationTier(
                        $row['leagueReputationTier'] !== null
                            ? ReputationTier::from((string) $row['leagueReputationTier'])
                            : null
                    );
                if (array_key_exists('sponsorCount', $row))
                    $league->setSponsorCount((int) $row['sponsorCount']);
                if (array_key_exists('trophyImage', $row))
                    $league->setTrophyImage($row['trophyImage'] !== null ? (string) $row['trophyImage'] : null);
                if (array_key_exists('trophyColour', $row))
                    $league->setTrophyColour(
                        $row['trophyColour'] !== null
                            ? TrophyColour::from((string) $row['trophyColour'])
                            : null
                    );
            } catch (\Throwable $e) {
                // Don't leave a half-built row behind: a rejected row must not reach the flush.
                if ($created !== null) {
                    $this->em->detach($created);
                }
                $errors[] = 'league[' . ($row['country'] ?? '?') . ' tier ' . ($row['tier'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function importClubs(array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $created = null;
            try {
                $club = $this->npcClubRepository->findOneBy([
                    'country' => $row['country'],
                    'tier'    => (int) $row['tier'],
                    'name'    => $row['name']
                ]);

                if (!$club) {
                    $club = $created = new NpcClub(
                        $row['name'],
                        $row['country'],
                        (int) $row['tier'],
                        (int) ($row['reputation'] ?? 0),
                        (string) ($row['primaryColor'] ?? '#000000'),
                        (string) ($row['secondaryColor'] ?? '#FFFFFF'),
                        (int) ($row['balance'] ?? 0),
                        (array) ($row['facilities'] ?? [])
                    );
                    $this->em->persist($club);
                }

                if (array_key_exists('reputation', $row))         $club->setReputation((int) $row['reputation']);
                if (array_key_exists('primaryColor', $row))       $club->setPrimaryColor((string) $row['primaryColor']);
                if (array_key_exists('secondaryColor', $row))     $club->setSecondaryColor((string) $row['secondaryColor']);
                if (array_key_exists('stadiumName', $row))        $club->setStadiumName($row['stadiumName']);
                if (array_key_exists('balance', $row))            $club->setBalance((int) $row['balance']);
                if (array_key_exists('playingStyle', $row))       $club->setPlayingStyle((string) $row['playingStyle']);
                if (array_key_exists('financialApproach', $row))  $club->setFinancialApproach((string) $row['financialApproach']);
                if (array_key_exists('managerTemperament', $row)) $club->setManagerTemperament((int) $row['managerTemperament']);
                if (array_key_exists('facilities', $row))         $club->setFacilities((array) $row['facilities']);
                if (isset($row['formation']))                     $club->setFormation(Formation::from((string) $row['formation']));
                if (array_key_exists('abbreviation', $row))       $club->setAbbreviation($row['abbreviation'] !== null ? (string) $row['abbreviation'] : null);
                if (array_key_exists('region', $row))             $club->setRegion($row['region'] !== null ? (string) $row['region'] : null);
                if (isset($row['citySize']))                      $club->setCitySize(CitySize::from((string) $row['citySize']));
                if (array_key_exists('populationSize', $row))     $club->setPopulationSize((int) $row['populationSize']);
                if (array_key_exists('isCapital', $row))          $club->setIsCapital((bool) $row['isCapital']);
            } catch (\Throwable $e) {
                if ($created !== null) {
                    $this->em->detach($created);
                }
                $errors[] = 'club[' . ($row['name'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    private function relinkClubsToLeagues(): void
    {
        $leagues = $this->leagueRepository->findAll();
        $leagueMap = [];
        foreach ($leagues as $l) {
            $leagueMap[$l->getCountry()][$l->getTier()] = $l;
        }

        $clubs = $this->npcClubRepository->findAll();
        foreach ($clubs as $c) {
            $c->setLeague($leagueMap[$c->getCountry()][$c->getTier()] ?? null);
        }
    }

    // ── Clear ─────────────────────────────────────────────────────────────────

    public function clearAll(): void
    {
        // Nullify Club.currentLeague references before deleting League rows,
        // otherwise the FK constraint (Club → League, no ON DELETE rule) will reject the delete.
        $this->em->createQuery('UPDATE ' . Club::class . ' c SET c.currentLeague = NULL')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\NpcClub')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\League')->execute();
        $this->em->flush();
    }
}
