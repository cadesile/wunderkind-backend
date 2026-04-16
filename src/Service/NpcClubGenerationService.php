<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FacilityTemplate;
use App\Entity\NpcClub;
use App\Repository\FacilityTemplateRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;

class NpcClubGenerationService
{
    // ── Place names by ISO country code ──────────────────────────────────────
    private const PLACE_NAMES_BY_COUNTRY = [
        'ES' => ['Sevilla', 'Córdoba', 'Granada', 'Murcia', 'Valencia', 'Bilbao', 'Zaragoza', 'Málaga', 'Alicante', 'Valladolid'],
        'EN' => ['Norwich', 'Bristol', 'Derby', 'Bolton', 'Preston', 'Crewe', 'Carlisle', 'Exeter', 'Shrewsbury', 'Grimsby'],
        'DE' => ['Dortmund', 'Düsseldorf', 'Hannover', 'Nürnberg', 'Augsburg', 'Bielefeld', 'Mainz', 'Kaiserslautern', 'Karlsruhe', 'Freiburg'],
        'IT' => ['Palermo', 'Catania', 'Bari', 'Brescia', 'Verona', 'Perugia', 'Livorno', 'Modena', 'Reggio', 'Pescara'],
        'FR' => ['Nantes', 'Bordeaux', 'Strasbourg', 'Lille', 'Rennes', 'Reims', 'Metz', 'Caen', 'Dijon', 'Grenoble'],
        'BR' => ['Santos', 'Recife', 'Fortaleza', 'Manaus', 'Curitiba', 'Goiânia', 'Campinas', 'Belém', 'Vitória', 'Maceió'],
        'AR' => ['Córdoba', 'Rosario', 'Mendoza', 'Tucumán', 'Mar del Plata', 'Salta', 'Lanús', 'Quilmes', 'Banfield', 'Platense'],
        'NL' => ['Utrecht', 'Groningen', 'Eindhoven', 'Tilburg', 'Breda', 'Almere', 'Nijmegen', 'Arnhem', 'Zwolle', 'Heerenveen'],
        'PT' => ['Braga', 'Guimarães', 'Setúbal', 'Coimbra', 'Faro', 'Évora', 'Aveiro', 'Leiria', 'Funchal', 'Viseu'],
    ];

    private const SUFFIXES = ['FC', 'CF', 'Athletic', 'United', 'City', 'Rovers', 'Town', 'SC', 'Deportivo', 'Wanderers'];

    private const STADIUM_FORMATS_BY_COUNTRY = [
        'ES' => ['Estadio %s', 'Estadio de %s', 'Campo de %s'],
        'EN' => ['%s Park', '%s Ground', 'The %s Stadium', '%s Arena'],
        'DE' => ['%s Arena', '%s Stadion', 'Arena %s'],
        'IT' => ['Stadio %s', 'Stadio Comunale %s', 'Arena %s'],
        'FR' => ['Stade %s', 'Stade Municipal de %s', 'Stade de %s'],
        'BR' => ['Estádio %s', 'Arena %s', 'Estádio Municipal de %s'],
        'AR' => ['Estadio %s', 'Estadio Municipal %s', 'Cancha %s'],
        'NL' => ['%s Stadion', 'Stadion %s', '%s Arena'],
        'PT' => ['Estádio %s', 'Estádio Municipal de %s', 'Estádio do %s'],
    ];

    private const COLORS = [
        '#c0392b', '#2980b9', '#27ae60', '#8e44ad', '#f39c12',
        '#16a085', '#d35400', '#2c3e50', '#e74c3c', '#1abc9c',
        '#3498db', '#9b59b6', '#e67e22', '#1a252f', '#ffffff',
        '#2ecc71', '#e8d44d', '#34495e', '#922b21', '#1f618d',
    ];

    /**
     * Facility level ranges by tier band.
     * Each entry: [min, max, training range, stands range, other range]
     */
    private const FACILITY_LEVELS = [
        ['min' => 1, 'max' => 2, 'training' => [7, 9], 'stands' => [4, 5], 'other' => [3, 5]],
        ['min' => 3, 'max' => 4, 'training' => [5, 6], 'stands' => [3, 4], 'other' => [2, 3]],
        ['min' => 5, 'max' => 6, 'training' => [3, 4], 'stands' => [2, 3], 'other' => [1, 2]],
        ['min' => 7, 'max' => 8, 'training' => [1, 2], 'stands' => [0, 1], 'other' => [0, 1]],
    ];

    // Slugs classified by facility type for level band selection
    private const TRAINING_SLUGS = ['training_pitch', 'strength_suite'];
    private const STANDS_SLUGS   = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly FacilityTemplateRepository  $facilityTemplateRepo,
        private readonly NpcClubRepository           $npcClubRepo,
        private readonly LeagueService               $leagueService,
    ) {}

    /** @return NpcClub[] */
    public function generateClubs(int $count, int $tier, string $country, bool $deleteExisting = false): array
    {
        $tier       = max(1, min(8, $tier));
        $slugs      = $this->getActiveFacilitySlugs();
        $levelBand  = $this->getLevelBandForTier($tier);
        $placeNames = self::PLACE_NAMES_BY_COUNTRY[$country] ?? ['Capital', 'Northern', 'Southern', 'Eastern', 'Western', 'Central'];
        $usedNames  = [];
        $clubs      = [];

        if ($deleteExisting) {
            $this->npcClubRepo->deleteByCountryAndTier($country, $tier);
        }

        for ($i = 0; $i < $count; $i++) {
            [$name, $place] = $this->generateName($placeNames, $usedNames);
            $usedNames[]    = $name;
            $reputation     = $this->reputationForTier($tier);
            $balance        = $this->balanceForTier($tier);
            $facilities     = $this->buildFacilities($slugs, $levelBand);
            $colors         = $this->pickColorPair();
            $stadiumName    = $this->generateStadiumName($place, $country);

            $club = new NpcClub(
                name:           $name,
                country:        $country,
                tier:           $tier,
                reputation:     $reputation,
                primaryColor:   $colors[0],
                secondaryColor: $colors[1],
                balance:        $balance,
                facilities:     $facilities,
            );
            $club->setStadiumName($stadiumName);
            $club->setPlayingStyle($this->playingStyleForTier($tier));
            $club->setFinancialApproach($this->financialApproachForTier($tier));
            $club->setManagerTemperament(random_int(30, 80));

            $this->em->persist($club);
            $this->leagueService->assignClubToLeague($club);
            $clubs[] = $club;
        }

        $this->em->flush();
        return $clubs;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return string[] active facility slugs from DB */
    private function getActiveFacilitySlugs(): array
    {
        $templates = $this->facilityTemplateRepo->findBy(['isActive' => true]);
        return array_map(fn(FacilityTemplate $t) => $t->getSlug(), $templates);
    }

    private function getLevelBandForTier(int $tier): array
    {
        foreach (self::FACILITY_LEVELS as $band) {
            if ($tier >= $band['min'] && $tier <= $band['max']) {
                return $band;
            }
        }
        return self::FACILITY_LEVELS[3]; // fallback to tier 7–8
    }

    private function buildFacilities(array $slugs, array $band): array
    {
        $facilities = [];
        foreach ($slugs as $slug) {
            if (in_array($slug, self::TRAINING_SLUGS, true)) {
                $range = $band['training'];
            } elseif (in_array($slug, self::STANDS_SLUGS, true)) {
                $range = $band['stands'];
            } else {
                $range = $band['other'];
            }
            $facilities[$slug] = random_int($range[0], max($range[0], $range[1]));
        }
        return $facilities;
    }

    /** @return array{string, string} [name, place] */
    private function generateName(array $placeNames, array $usedNames): array
    {
        $attempts = 0;
        do {
            $place  = $placeNames[array_rand($placeNames)];
            $suffix = self::SUFFIXES[array_rand(self::SUFFIXES)];
            $name   = "{$place} {$suffix}";
            $attempts++;
        } while (in_array($name, $usedNames, true) && $attempts < 50);

        return [$name, $place];
    }

    private function generateStadiumName(string $place, string $country): string
    {
        $formats = self::STADIUM_FORMATS_BY_COUNTRY[$country] ?? ['%s Stadium', '%s Ground', 'The %s Arena'];
        $format  = $formats[array_rand($formats)];
        return sprintf($format, $place);
    }

    private function reputationForTier(int $tier): int
    {
        // tier 1 → 70–90, tier 8 → 5–20 (linear interpolation)
        $minRep = (int) round(70 - ($tier - 1) * (65 / 7));
        $maxRep = (int) round(90 - ($tier - 1) * (70 / 7));
        return random_int(max(1, $minRep), max(1, $maxRep));
    }

    private function balanceForTier(int $tier): int
    {
        // tier 1 → ~£50m (5_000_000_000 pence), tier 8 → ~£390k
        $base     = (int) (5_000_000_000 / pow(2, $tier - 1));
        $variance = (int) ($base * 0.2);
        return random_int(max(0, $base - $variance), $base + $variance);
    }

    /** @return string[] [primaryColor, secondaryColor] */
    private function pickColorPair(): array
    {
        $primary   = self::COLORS[array_rand(self::COLORS)];
        $secondary = self::COLORS[array_rand(self::COLORS)];
        return [$primary, $secondary];
    }

    private function playingStyleForTier(int $tier): string
    {
        $styles = ['POSSESSION', 'DIRECT', 'COUNTER', 'HIGH_PRESS'];
        return $styles[array_rand($styles)];
    }

    private function financialApproachForTier(int $tier): string
    {
        // Higher tiers lean SPECULATIVE, lower tiers lean CONSERVATIVE
        if ($tier <= 2) {
            $options = ['SPECULATIVE', 'SPECULATIVE', 'SPECULATIVE', 'BALANCED', 'BALANCED', 'CONSERVATIVE'];
        } elseif ($tier <= 5) {
            $options = ['SPECULATIVE', 'BALANCED', 'BALANCED', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE'];
        } else {
            $options = ['SPECULATIVE', 'BALANCED', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE', 'CONSERVATIVE'];
        }
        return $options[array_rand($options)];
    }
}
