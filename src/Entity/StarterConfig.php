<?php

namespace App\Entity;

use App\Repository\StarterConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row configuration entity for new academy initialisation.
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

    /** Number of players assigned to a starter academy. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $starterPlayerCount = 5;

    /** Number of coaches assigned to a starter academy. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterCoachCount = 1;

    /** Number of scouts assigned to a starter academy. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $starterScoutCount = 1;

    /**
     * Sponsor company-size tier assigned at academy creation.
     * Matches CompanySize enum value. Default: 'SMALL'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterSponsorTier = 'SMALL';

    /**
     * Starting tier of the academy. Matches Tier enum value. Default: 'local'
     */
    #[ORM\Column(type: 'string', length: 20)]
    private string $starterAcademyTier = 'local';

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

    public function getStarterAcademyTier(): string { return $this->starterAcademyTier; }
    public function setStarterAcademyTier(string $v): static { $this->starterAcademyTier = $v; return $this; }
}
