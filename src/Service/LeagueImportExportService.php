<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Enum\Formation;
use App\Enum\ReputationTier;
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
            if (isset($data['leagues'])) {
                $this->importLeagues($data['leagues']);
            }
            if (isset($data['clubs'])) {
                $this->importClubs($data['clubs']);
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

    private function importLeagues(array $rows): void
    {
        foreach ($rows as $row) {
            $league = $this->leagueRepository->findByCountryAndTier($row['country'], (int) $row['tier']);

            if (!$league) {
                $league = new League($row['country'], (int) $row['tier'], $row['name']);
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
        }
    }

    private function importClubs(array $rows): void
    {
        foreach ($rows as $row) {
            $club = $this->npcClubRepository->findOneBy([
                'country' => $row['country'],
                'tier'    => (int) $row['tier'],
                'name'    => $row['name']
            ]);

            if (!$club) {
                $club = new NpcClub(
                    $row['name'],
                    $row['country'],
                    (int) $row['tier'],
                    (int) $row['reputation'],
                    $row['primaryColor'],
                    $row['secondaryColor'],
                    (int) $row['balance'],
                    (array) $row['facilities']
                );
                $this->em->persist($club);
            }

            $club->setReputation((int) $row['reputation']);
            $club->setPrimaryColor($row['primaryColor']);
            $club->setSecondaryColor($row['secondaryColor']);
            if (isset($row['stadiumName']))        $club->setStadiumName($row['stadiumName']);
            $club->setBalance((int) $row['balance']);
            if (isset($row['playingStyle']))       $club->setPlayingStyle($row['playingStyle']);
            if (isset($row['financialApproach']))  $club->setFinancialApproach($row['financialApproach']);
            if (isset($row['managerTemperament'])) $club->setManagerTemperament((int) $row['managerTemperament']);
            $club->setFacilities((array) $row['facilities']);
            if (isset($row['formation']))          $club->setFormation(Formation::from((string) $row['formation']));
        }
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
