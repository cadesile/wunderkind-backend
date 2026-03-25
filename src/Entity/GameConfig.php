<?php

namespace App\Entity;

use App\Repository\GameConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameConfigRepository::class)]
class GameConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ── Clique System ─────────────────────────────────────────────────────

    /**
     * Minimum pairwise relationship value (−100 to +100) required
     * for two players to be eligible for the same clique.
     * Default: 20
     */
    #[ORM\Column(type: 'integer')]
    private int $cliqueRelationshipThreshold = 20;

    /**
     * Maximum percentage of the active squad that can be in cliques
     * combined across ALL cliques. Default: 30
     */
    #[ORM\Column(type: 'integer')]
    private int $cliqueSquadCapPercent = 30;

    /**
     * Minimum weeks a player must have been at the academy
     * before they can form or join a clique. Default: 3
     */
    #[ORM\Column(type: 'integer')]
    private int $cliqueMinTenureWeeks = 3;

    // ── Engine Constants ──────────────────────────────────────────────────

    /** Base XP awarded per player per week before facility/coach multipliers. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $baseXP = 10;

    /**
     * Base probability of injury per player per week (float fraction).
     * Default: 0.05 = 5%
     */
    #[ORM\Column(type: 'float')]
    private float $baseInjuryProbability = 0.05;

    /**
     * Trait value above which regression-to-mean applies downward pressure.
     * Default: 14 (on 1–20 scale)
     */
    #[ORM\Column(type: 'integer')]
    private int $regressionUpperThreshold = 14;

    /**
     * Trait value below which regression-to-mean applies upward pressure.
     * Default: 7 (on 1–20 scale)
     */
    #[ORM\Column(type: 'integer')]
    private int $regressionLowerThreshold = 7;

    /** Base reputation delta per week before facility multiplier. Default: 0.5 */
    #[ORM\Column(type: 'float')]
    private float $reputationDeltaBase = 0.5;

    /** Per-level facility multiplier applied to reputation delta. Default: 1.2 */
    #[ORM\Column(type: 'float')]
    private float $reputationDeltaFacilityMultiplier = 1.2;

    /** Injury severity weight for minor injuries. Default: 60 */
    #[ORM\Column(type: 'integer')]
    private int $injuryMinorWeight = 60;

    /** Injury severity weight for moderate injuries. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $injuryModerateWeight = 30;

    /** Injury severity weight for serious injuries. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $injurySeriousWeight = 10;

    // ── Getters / Setters ─────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCliqueRelationshipThreshold(): int { return $this->cliqueRelationshipThreshold; }
    public function setCliqueRelationshipThreshold(int $v): static { $this->cliqueRelationshipThreshold = $v; return $this; }

    public function getCliqueSquadCapPercent(): int { return $this->cliqueSquadCapPercent; }
    public function setCliqueSquadCapPercent(int $v): static { $this->cliqueSquadCapPercent = $v; return $this; }

    public function getCliqueMinTenureWeeks(): int { return $this->cliqueMinTenureWeeks; }
    public function setCliqueMinTenureWeeks(int $v): static { $this->cliqueMinTenureWeeks = $v; return $this; }

    public function getBaseXP(): int { return $this->baseXP; }
    public function setBaseXP(int $v): static { $this->baseXP = $v; return $this; }

    public function getBaseInjuryProbability(): float { return $this->baseInjuryProbability; }
    public function setBaseInjuryProbability(float $v): static { $this->baseInjuryProbability = $v; return $this; }

    public function getRegressionUpperThreshold(): int { return $this->regressionUpperThreshold; }
    public function setRegressionUpperThreshold(int $v): static { $this->regressionUpperThreshold = $v; return $this; }

    public function getRegressionLowerThreshold(): int { return $this->regressionLowerThreshold; }
    public function setRegressionLowerThreshold(int $v): static { $this->regressionLowerThreshold = $v; return $this; }

    public function getReputationDeltaBase(): float { return $this->reputationDeltaBase; }
    public function setReputationDeltaBase(float $v): static { $this->reputationDeltaBase = $v; return $this; }

    public function getReputationDeltaFacilityMultiplier(): float { return $this->reputationDeltaFacilityMultiplier; }
    public function setReputationDeltaFacilityMultiplier(float $v): static { $this->reputationDeltaFacilityMultiplier = $v; return $this; }

    public function getInjuryMinorWeight(): int { return $this->injuryMinorWeight; }
    public function setInjuryMinorWeight(int $v): static { $this->injuryMinorWeight = $v; return $this; }

    public function getInjuryModerateWeight(): int { return $this->injuryModerateWeight; }
    public function setInjuryModerateWeight(int $v): static { $this->injuryModerateWeight = $v; return $this; }

    public function getInjurySeriousWeight(): int { return $this->injurySeriousWeight; }
    public function setInjurySeriousWeight(int $v): static { $this->injurySeriousWeight = $v; return $this; }
}
