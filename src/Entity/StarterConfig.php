<?php

namespace App\Entity;

use App\Enum\ReputationTier;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row configuration entity for new club initialisation.
 * The primary key is fixed at 1 — only one row ever exists.
 * Edit via EasyAdmin or the seeder migration; never create a second row.
 */
#[ORM\Entity(repositoryClass: StarterConfigRepository::class)]
class StarterConfig
{
    /** Fixed primary key — enforces single-row constraint. */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    /** Starting balance in pence. Default: £50,000 = 5,000,000p */
    #[ORM\Column(type: 'integer')]
    private int $startingBalance = 5_000_000;

    /** Number of players assigned to a starter club. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $starterPlayerCount = 5;

    /** Number of coaches assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterCoachCount = 1;

    /** Number of scouts assigned to a starter club. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterScoutCount = 1;

    /**
     * Sponsor company-size tier assigned at club creation.
     * Matches CompanySize enum value. Default: 'SMALL'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterSponsorTier = 'SMALL';

    /**
     * Starting tier of the club. Matches Tier enum value. Default: 'local'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterClubTier = 'local';

    #[ORM\Column(type: 'json')]
    private array $defaultFacilities = [];

    #[ORM\Column(type: 'string', length: 20, enumType: ReputationTier::class)]
    private ReputationTier $starterReputationTier = ReputationTier::LOCAL;

    /**
     * Country codes (ClubCountryCode values) available to players at club creation.
     * Default: ['EN'] — England only.
     */
    #[ORM\Column(type: 'json')]
    private array $enabledCountries = ['EN'];

    /**
     * Global ability ranges per country and league tier.
     * Format: { "EN": { "1": { "min": 75, "max": 100 } } }
     */
    #[ORM\Column(type: 'json')]
    private array $leagueAbilityRanges = [
        'EN' => [
            '1' => ['min' => 75, 'max' => 95],
            '2' => ['min' => 65, 'max' => 85],
            '3' => ['min' => 55, 'max' => 75],
            '4' => ['min' => 45, 'max' => 65],
            '5' => ['min' => 35, 'max' => 55],
            '6' => ['min' => 25, 'max' => 45],
            '7' => ['min' => 15, 'max' => 35],
            '8' => ['min' => 10, 'max' => 25],
        ]
    ];

    /**
     * Per-tier NPC squad and staff config. Keyed by tier number (1–8).
     * Each entry: playerMin, playerMax, managerCount, coachCount, chairmanCount, foreignPercent
     */
    #[ORM\Column(type: 'json')]
    private array $npcSquadConfig = [
        '1' => ['playerMin' => 20, 'playerMax' => 24, 'managerCount' => 1, 'coachCount' => 5, 'chairmanCount' => 1, 'foreignPercent' => 60],
        '2' => ['playerMin' => 18, 'playerMax' => 22, 'managerCount' => 1, 'coachCount' => 4, 'chairmanCount' => 1, 'foreignPercent' => 45],
        '3' => ['playerMin' => 16, 'playerMax' => 20, 'managerCount' => 1, 'coachCount' => 3, 'chairmanCount' => 1, 'foreignPercent' => 30],
        '4' => ['playerMin' => 15, 'playerMax' => 18, 'managerCount' => 1, 'coachCount' => 2, 'chairmanCount' => 1, 'foreignPercent' => 20],
        '5' => ['playerMin' => 14, 'playerMax' => 17, 'managerCount' => 1, 'coachCount' => 2, 'chairmanCount' => 1, 'foreignPercent' => 15],
        '6' => ['playerMin' => 13, 'playerMax' => 16, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 10],
        '7' => ['playerMin' => 12, 'playerMax' => 15, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 5],
        '8' => ['playerMin' => 11, 'playerMax' => 14, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 3],
    ];

    /** Returns a new instance pre-populated with all defaults. */
    public static function defaults(): self
    {
        return new self();
    }

    public function getId(): int { return $this->id; }

    public function getStartingBalance(): int { return $this->startingBalance; }
    public function setStartingBalance(int $v): static { $this->startingBalance = $v; return $this; }

    /** Virtual property for the admin form — accepts/returns pounds; storage remains pence. */
    public function getStartingBalancePounds(): int { return (int) round($this->startingBalance / 100); }
    public function setStartingBalancePounds(int $pounds): static { $this->startingBalance = $pounds * 100; return $this; }

    public function getStarterPlayerCount(): int { return $this->starterPlayerCount; }
    public function setStarterPlayerCount(int $v): static { $this->starterPlayerCount = $v; return $this; }

    public function getStarterCoachCount(): int { return $this->starterCoachCount; }
    public function setStarterCoachCount(int $v): static { $this->starterCoachCount = $v; return $this; }

    public function getStarterScoutCount(): int { return $this->starterScoutCount; }
    public function setStarterScoutCount(int $v): static { $this->starterScoutCount = $v; return $this; }

    public function getStarterSponsorTier(): string { return $this->starterSponsorTier; }
    public function setStarterSponsorTier(string $v): static { $this->starterSponsorTier = $v; return $this; }

    public function getStarterClubTier(): string { return $this->starterClubTier; }
    public function setStarterClubTier(string $v): static { $this->starterClubTier = $v; return $this; }

    public function getDefaultFacilities(): array { return $this->defaultFacilities; }
    public function setDefaultFacilities(array $v): static { $this->defaultFacilities = $v; return $this; }

    public function getDefaultFacilitiesJson(): string
    {
        if (empty($this->defaultFacilities)) {
            return '{}';
        }
        return json_encode($this->defaultFacilities, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setDefaultFacilitiesJson(string $v): static
    {
        $this->defaultFacilities = json_decode($v, true) ?? [];
        return $this;
    }

    public function getStarterReputationTier(): ReputationTier { return $this->starterReputationTier; }
    public function setStarterReputationTier(ReputationTier $v): static { $this->starterReputationTier = $v; return $this; }

    public function getNpcSquadConfig(): array { return $this->npcSquadConfig; }
    public function setNpcSquadConfig(array $v): static { $this->npcSquadConfig = $v; return $this; }

    public function getNpcSquadConfigJson(): string
    {
        return json_encode($this->npcSquadConfig, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setNpcSquadConfigJson(string $v): static
    {
        $this->npcSquadConfig = json_decode($v, true) ?? [];
        return $this;
    }

    public function getEnabledCountries(): array { return $this->enabledCountries; }
    public function setEnabledCountries(array $v): static { $this->enabledCountries = $v; return $this; }

    public function getLeagueAbilityRanges(): array { return $this->leagueAbilityRanges; }
    public function setLeagueAbilityRanges(array $v): static { $this->leagueAbilityRanges = $v; return $this; }
}
