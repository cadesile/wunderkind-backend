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

    // ── Scouting System ───────────────────────────────────────────────────

    /**
     * Morale value below which a scout makes no weekly scouting progress.
     * Default: 40
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutMoraleThreshold = 40;

    /**
     * Number of weekly ticks required before an assigned player is revealed.
     * Default: 2
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutRevealWeeks = 2;

    /**
     * Maximum ±variance applied to perceived ability, scaled by successRate.
     * errorMargin = (100 - successRate) / 100; actual error = randomInt(-range, +range) * errorMargin
     * Default: 30
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutAbilityErrorRange = 30;

    /**
     * Maximum number of market players a single scout can be assigned to simultaneously.
     * Default: 5
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutMaxAssignments = 5;

    /**
     * Ascending probability breakpoints [p0, p1, p2, p3] for the weekly mission gem roll.
     * roll < p0 → 0 players; roll < p1 → 1; roll < p2 → 2; roll < p3 → 3; else → 4
     * Default: [0.25, 0.75, 0.85, 0.94]
     *
     * @var float[]
     */
    #[ORM\Column(type: 'json')]
    private array $missionGemRollThresholds = [0.25, 0.75, 0.85, 0.94];

    // ── Morale ────────────────────────────────────────────────────────────

    /**
     * Minimum morale assigned to a newly created/recruited entity.
     * Default: 50
     */
    #[ORM\Column(type: 'integer')]
    private int $defaultMoraleMin = 50;

    /**
     * Maximum morale assigned to a newly created/recruited entity.
     * Default: 80
     */
    #[ORM\Column(type: 'integer')]
    private int $defaultMoraleMax = 80;

    // ── Incidents ─────────────────────────────────────────────────────────

    /** Professionalism trait value below which late-training incidents can fire. Default: 6 */
    #[ORM\Column(type: 'integer')]
    private int $incidentLowProfessionalismThreshold = 6;

    /** Weekly probability of a late-training incident when professionalism is below threshold. Default: 0.3 */
    #[ORM\Column(type: 'float')]
    private float $incidentLowProfessionalismChance = 0.3;

    /** Determination trait value above which extra-effort incidents can fire. Default: 15 */
    #[ORM\Column(type: 'integer')]
    private int $incidentHighDeterminationThreshold = 15;

    /** Weekly probability of an extra-effort incident when determination is above threshold. Default: 0.25 */
    #[ORM\Column(type: 'float')]
    private float $incidentHighDeterminationChance = 0.25;

    /** Base weekly probability that any player pair produces an altercation incident. Default: 0.10 */
    #[ORM\Column(type: 'float')]
    private float $incidentAltercationBaseChance = 0.10;

    /** Floor probability for a serious escalation on an altercation. Default: 0.2 */
    #[ORM\Column(type: 'float')]
    private float $incidentAltercationSeriousBase = 0.2;

    /** How much temperament amplifies the serious escalation probability. Default: 0.5 */
    #[ORM\Column(type: 'float')]
    private float $incidentAltercationSeriousTemperamentScale = 0.5;

    // ── Transfer Market ───────────────────────────────────────────────────

    /**
     * Global multiplier applied to player transfer fee calculations.
     * Replaces the hardcoded × 100 in the agent offer formula on the frontend.
     * Default: 1000.0
     */
    #[ORM\Column(type: 'float')]
    private float $playerFeeMultiplier = 1000.0;

    // ── Guardian Complaints ───────────────────────────────────────────────

    /** Player morale boost when manager convinces a guardian. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $guardianConvinceMoraleBoost = 5;

    /** Guardian loyalty increase when manager convinces. Default: 8 */
    #[ORM\Column(type: 'integer')]
    private int $guardianConvinceGuardianLoyaltyBoost = 8;

    /** Guardian demand level increase when manager convinces. Default: 1 */
    #[ORM\Column(type: 'integer')]
    private int $guardianConvinceGuardianDemandIncrease = 1;

    /** Player morale penalty when manager ignores a guardian complaint. Default: 8 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreMoralePenalty = 8;

    /** Player loyalty trait penalty when manager ignores. Default: 3 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreLoyaltyTraitPenalty = 3;

    /** Guardian loyalty decrease when manager ignores. Default: 12 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreGuardianLoyaltyPenalty = 12;

    /** Guardian demand level increase when manager ignores. Default: 2 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreGuardianDemandIncrease = 2;

    /** Morale penalty applied to surname-matching siblings when manager ignores. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreSiblingMoralePenalty = 5;

    /** Loyalty trait penalty applied to siblings when manager ignores. Default: 2 */
    #[ORM\Column(type: 'integer')]
    private int $guardianIgnoreSiblingLoyaltyTraitPenalty = 2;

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

    public function getScoutMoraleThreshold(): int { return $this->scoutMoraleThreshold; }
    public function setScoutMoraleThreshold(int $v): static { $this->scoutMoraleThreshold = $v; return $this; }

    public function getScoutRevealWeeks(): int { return $this->scoutRevealWeeks; }
    public function setScoutRevealWeeks(int $v): static { $this->scoutRevealWeeks = $v; return $this; }

    public function getScoutAbilityErrorRange(): int { return $this->scoutAbilityErrorRange; }
    public function setScoutAbilityErrorRange(int $v): static { $this->scoutAbilityErrorRange = $v; return $this; }

    public function getScoutMaxAssignments(): int { return $this->scoutMaxAssignments; }
    public function setScoutMaxAssignments(int $v): static { $this->scoutMaxAssignments = $v; return $this; }

    /** @return float[] */
    public function getMissionGemRollThresholds(): array { return $this->missionGemRollThresholds; }
    /** @param float[] $v */
    public function setMissionGemRollThresholds(array $v): static { $this->missionGemRollThresholds = $v; return $this; }

    public function getIncidentLowProfessionalismThreshold(): int { return $this->incidentLowProfessionalismThreshold; }
    public function setIncidentLowProfessionalismThreshold(int $v): static { $this->incidentLowProfessionalismThreshold = $v; return $this; }

    public function getIncidentLowProfessionalismChance(): float { return $this->incidentLowProfessionalismChance; }
    public function setIncidentLowProfessionalismChance(float $v): static { $this->incidentLowProfessionalismChance = $v; return $this; }

    public function getIncidentHighDeterminationThreshold(): int { return $this->incidentHighDeterminationThreshold; }
    public function setIncidentHighDeterminationThreshold(int $v): static { $this->incidentHighDeterminationThreshold = $v; return $this; }

    public function getIncidentHighDeterminationChance(): float { return $this->incidentHighDeterminationChance; }
    public function setIncidentHighDeterminationChance(float $v): static { $this->incidentHighDeterminationChance = $v; return $this; }

    public function getIncidentAltercationBaseChance(): float { return $this->incidentAltercationBaseChance; }
    public function setIncidentAltercationBaseChance(float $v): static { $this->incidentAltercationBaseChance = $v; return $this; }

    public function getIncidentAltercationSeriousBase(): float { return $this->incidentAltercationSeriousBase; }
    public function setIncidentAltercationSeriousBase(float $v): static { $this->incidentAltercationSeriousBase = $v; return $this; }

    public function getIncidentAltercationSeriousTemperamentScale(): float { return $this->incidentAltercationSeriousTemperamentScale; }
    public function setIncidentAltercationSeriousTemperamentScale(float $v): static { $this->incidentAltercationSeriousTemperamentScale = $v; return $this; }

    public function getPlayerFeeMultiplier(): float { return $this->playerFeeMultiplier; }
    public function setPlayerFeeMultiplier(float $v): static { $this->playerFeeMultiplier = $v; return $this; }

    public function getDefaultMoraleMin(): int { return $this->defaultMoraleMin; }
    public function setDefaultMoraleMin(int $v): static { $this->defaultMoraleMin = $v; return $this; }

    public function getDefaultMoraleMax(): int { return $this->defaultMoraleMax; }
    public function setDefaultMoraleMax(int $v): static { $this->defaultMoraleMax = $v; return $this; }

    public function getGuardianConvinceMoraleBoost(): int { return $this->guardianConvinceMoraleBoost; }
    public function setGuardianConvinceMoraleBoost(int $v): static { $this->guardianConvinceMoraleBoost = $v; return $this; }

    public function getGuardianConvinceGuardianLoyaltyBoost(): int { return $this->guardianConvinceGuardianLoyaltyBoost; }
    public function setGuardianConvinceGuardianLoyaltyBoost(int $v): static { $this->guardianConvinceGuardianLoyaltyBoost = $v; return $this; }

    public function getGuardianConvinceGuardianDemandIncrease(): int { return $this->guardianConvinceGuardianDemandIncrease; }
    public function setGuardianConvinceGuardianDemandIncrease(int $v): static { $this->guardianConvinceGuardianDemandIncrease = $v; return $this; }

    public function getGuardianIgnoreMoralePenalty(): int { return $this->guardianIgnoreMoralePenalty; }
    public function setGuardianIgnoreMoralePenalty(int $v): static { $this->guardianIgnoreMoralePenalty = $v; return $this; }

    public function getGuardianIgnoreLoyaltyTraitPenalty(): int { return $this->guardianIgnoreLoyaltyTraitPenalty; }
    public function setGuardianIgnoreLoyaltyTraitPenalty(int $v): static { $this->guardianIgnoreLoyaltyTraitPenalty = $v; return $this; }

    public function getGuardianIgnoreGuardianLoyaltyPenalty(): int { return $this->guardianIgnoreGuardianLoyaltyPenalty; }
    public function setGuardianIgnoreGuardianLoyaltyPenalty(int $v): static { $this->guardianIgnoreGuardianLoyaltyPenalty = $v; return $this; }

    public function getGuardianIgnoreGuardianDemandIncrease(): int { return $this->guardianIgnoreGuardianDemandIncrease; }
    public function setGuardianIgnoreGuardianDemandIncrease(int $v): static { $this->guardianIgnoreGuardianDemandIncrease = $v; return $this; }

    public function getGuardianIgnoreSiblingMoralePenalty(): int { return $this->guardianIgnoreSiblingMoralePenalty; }
    public function setGuardianIgnoreSiblingMoralePenalty(int $v): static { $this->guardianIgnoreSiblingMoralePenalty = $v; return $this; }

    public function getGuardianIgnoreSiblingLoyaltyTraitPenalty(): int { return $this->guardianIgnoreSiblingLoyaltyTraitPenalty; }
    public function setGuardianIgnoreSiblingLoyaltyTraitPenalty(int $v): static { $this->guardianIgnoreSiblingLoyaltyTraitPenalty = $v; return $this; }

    // ── Developer / Debug ─────────────────────────────────────────────────

    /** When true, the in-app debug log panel is visible to users. Default: false */
    #[ORM\Column(type: 'boolean')]
    private bool $debugLoggingEnabled = false;

    public function isDebugLoggingEnabled(): bool { return $this->debugLoggingEnabled; }
    public function setDebugLoggingEnabled(bool $v): static { $this->debugLoggingEnabled = $v; return $this; }
}
