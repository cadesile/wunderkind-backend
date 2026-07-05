<?php

namespace App\Entity;

use App\Enum\StatCategory;
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
     * Minimum weeks a player must have been at the club
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
    private float $reputationDeltaBase = 0.15;

    /** Per-level facility multiplier applied to reputation delta. Default: 1.2 */
    #[ORM\Column(type: 'float')]
    private float $reputationDeltaFacilityMultiplier = 0.15;

    /** Injury severity weight for minor injuries. Default: 60 */
    #[ORM\Column(type: 'integer')]
    private int $injuryMinorWeight = 60;

    /** Injury severity weight for moderate injuries. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $injuryModerateWeight = 30;

    /** Injury severity weight for serious injuries. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $injurySeriousWeight = 10;

    // ── Development / Potential ceiling ──────────────────────────────────

    /**
     * Maximum percentage a player's overall rating can temporarily exceed
     * their potential ceiling.
     * e.g. 0.05 = player with potential 70 can peak at 73.5 temporarily.
     * Default: 0.05
     */
    #[ORM\Column(type: 'float')]
    private float $potentialOvershootMax = 0.05;

    /**
     * Weekly decay rate applied to attributes when overall rating exceeds
     * potential ceiling. Applied as a downward nudge to the most recently
     * boosted attribute each tick.
     * e.g. 0.5 = 0.5 attribute points nudged down per week above ceiling.
     * Default: 0.5
     */
    #[ORM\Column(type: 'float')]
    private float $potentialDecayRate = 0.5;

    // ── Coach Development Influence ───────────────────────────────────────

    /**
     * Maximum XP multiplier a coach can apply to a player attribute
     * they specialise in. A coach with specialism value 100 applies
     * this multiplier; lower values scale linearly.
     * coachMultiplier = 1.0 + (coachSpecialismValue / 100) *
     *                   (coachDevelopmentMaxMultiplier - 1.0)
     * Default: 2.0 (coach at 100 doubles XP gain for that attribute)
     */
    #[ORM\Column(type: 'float')]
    private float $coachDevelopmentMaxMultiplier = 2.0;

    /**
     * Minimum coach specialism value (0-100) required before any
     * development bonus is applied. Below this threshold the coach
     * has no influence on that attribute category.
     * Default: 20
     */
    #[ORM\Column(type: 'integer')]
    private int $coachDevelopmentMinSpecialism = 20;

    /**
     * When multiple coaches cover the same specialism, this controls
     * how additional coaches stack.
     * 0.0 = no stacking (only best coach counts)
     * 1.0 = full linear stacking
     * Default: 0.3 (diminishing returns — each additional coach adds 30%
     * of their individual contribution)
     */
    #[ORM\Column(type: 'float')]
    private float $coachDevelopmentStackingFactor = 0.3;

    /**
     * Scales how strongly a coach's morale affects their development
     * contribution. At morale 0 the coach contributes nothing; at 100
     * they contribute fully.
     * effectiveSpecialism = specialism * (coachMorale / 100) ^
     *                       coachMoraleInfluence
     * Default: 0.5 (square root — morale 25 = 50% contribution)
     */
    #[ORM\Column(type: 'float')]
    private float $coachMoraleInfluence = 0.5;

    // ── Physical Degradation & Form ──────────────────────────────────────────

    /**
     * Maximum value any individual player attribute can reach.
     * Applies to all 6 attributes: pace, technical, vision, power,
     * stamina, heart.
     * Default: 98
     */
    #[ORM\Column(type: 'integer')]
    private int $attributeHardCap = 98;

    /**
     * Age at which physical attribute degradation begins.
     * Players at or below this age experience no degradation.
     * Default: 30
     */
    #[ORM\Column(type: 'integer')]
    private int $physicalDegradationAgeThreshold = 30;

    /**
     * Weekly degradation applied to pace and stamina for players
     * aged (threshold+1) to (threshold+3). e.g. age 31-33.
     * Default: 0.1
     */
    #[ORM\Column(type: 'float')]
    private float $physicalDegradationRateMild = 0.1;

    /**
     * Weekly degradation for players aged (threshold+4) to
     * (threshold+6). e.g. age 34-36.
     * Default: 0.2
     */
    #[ORM\Column(type: 'float')]
    private float $physicalDegradationRateModerate = 0.2;

    /**
     * Weekly degradation for players aged (threshold+7) and above.
     * e.g. age 37+.
     * Default: 0.4
     */
    #[ORM\Column(type: 'float')]
    private float $physicalDegradationRateSevere = 0.4;

    /**
     * Scales how strongly a personality trait (heart or determination)
     * reduces physical degradation. 0.0 = no reduction.
     * effectiveDegradation = rate * (1 - traitValue/20 *
     *                         physicalDegradationPersonalityScale)
     * Default: 0.2
     */
    #[ORM\Column(type: 'float')]
    private float $physicalDegradationPersonalityScale = 0.2;

    /**
     * Number of recent matches used to calculate form average
     * for development multiplier.
     * Default: 5
     */
    #[ORM\Column(type: 'integer')]
    private int $formDevelopmentMatchCount = 5;

    /**
     * Average match rating threshold above which full development
     * applies (1.0× multiplier).
     * Default: 7.0
     */
    #[ORM\Column(type: 'float')]
    private float $formDevelopmentThreshold = 7.0;

    /**
     * Development multiplier when form average is between
     * formDevelopmentThresholdLow and formDevelopmentThreshold.
     * e.g. average 5.0–6.9.
     * Default: 0.6
     */
    #[ORM\Column(type: 'float')]
    private float $formDevelopmentMultiplierMid = 0.6;

    /**
     * Development multiplier when form average is below
     * formDevelopmentThresholdLow.
     * e.g. average < 5.0.
     * Default: 0.25
     */
    #[ORM\Column(type: 'float')]
    private float $formDevelopmentMultiplierLow = 0.25;

    /**
     * Average match rating below which the low development
     * multiplier applies (formDevelopmentMultiplierLow).
     * Default: 5.0
     */
    #[ORM\Column(type: 'float')]
    private float $formDevelopmentThresholdLow = 5.0;

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
     * Number of weeks a full NPC scout report remains fully accurate
     * before attribute decay begins. Default: 26 (half a season).
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutReportValidWeeks = 26;

    /**
     * Amount of noise (0-100 scale) added to each attribute display
     * per week of decay after scoutReportValidWeeks has passed.
     * Default: 2 (each attribute drifts ±2 per week of decay).
     */
    #[ORM\Column(type: 'integer')]
    private int $scoutReportDecayRatePerWeek = 2;

    /**
     * Weeks before a rejected NPC player scouting approach
     * can be retried. Default: 52 (one full season).
     */
    #[ORM\Column(type: 'integer')]
    private int $npcScoutRejectionCooldownWeeks = 52;

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

    /**
     * Global multiplier applied to player wage calculations.
     * Applied on top of the base wage derived from ability/position.
     * Default: 1.0
     */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $playerWageMultiplier = 1.0;

    /**
     * Number of free (zero-fee) transfers a club may make per season.
     * Default: 3
     */
    #[ORM\Column(type: 'integer', options: ['default' => 3])]
    private int $freeTransfersPerSeason = 3;

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

    public function getPotentialOvershootMax(): float { return $this->potentialOvershootMax; }
    public function setPotentialOvershootMax(float $v): static { $this->potentialOvershootMax = max(0.0, $v); return $this; }

    public function getPotentialDecayRate(): float { return $this->potentialDecayRate; }
    public function setPotentialDecayRate(float $v): static { $this->potentialDecayRate = max(0.0, $v); return $this; }

    public function getCoachDevelopmentMaxMultiplier(): float { return $this->coachDevelopmentMaxMultiplier; }
    public function setCoachDevelopmentMaxMultiplier(float $v): static { $this->coachDevelopmentMaxMultiplier = max(0.0, $v); return $this; }

    public function getCoachDevelopmentMinSpecialism(): int { return $this->coachDevelopmentMinSpecialism; }
    public function setCoachDevelopmentMinSpecialism(int $v): static { $this->coachDevelopmentMinSpecialism = max(0, $v); return $this; }

    public function getCoachDevelopmentStackingFactor(): float { return $this->coachDevelopmentStackingFactor; }
    public function setCoachDevelopmentStackingFactor(float $v): static { $this->coachDevelopmentStackingFactor = max(0.0, $v); return $this; }

    public function getCoachMoraleInfluence(): float { return $this->coachMoraleInfluence; }
    public function setCoachMoraleInfluence(float $v): static { $this->coachMoraleInfluence = max(0.0, $v); return $this; }

    public function getAttributeHardCap(): int { return $this->attributeHardCap; }
    public function setAttributeHardCap(int $v): static { $this->attributeHardCap = max(0, $v); return $this; }

    public function getPhysicalDegradationAgeThreshold(): int { return $this->physicalDegradationAgeThreshold; }
    public function setPhysicalDegradationAgeThreshold(int $v): static { $this->physicalDegradationAgeThreshold = max(0, $v); return $this; }

    public function getPhysicalDegradationRateMild(): float { return $this->physicalDegradationRateMild; }
    public function setPhysicalDegradationRateMild(float $v): static { $this->physicalDegradationRateMild = max(0.0, $v); return $this; }

    public function getPhysicalDegradationRateModerate(): float { return $this->physicalDegradationRateModerate; }
    public function setPhysicalDegradationRateModerate(float $v): static { $this->physicalDegradationRateModerate = max(0.0, $v); return $this; }

    public function getPhysicalDegradationRateSevere(): float { return $this->physicalDegradationRateSevere; }
    public function setPhysicalDegradationRateSevere(float $v): static { $this->physicalDegradationRateSevere = max(0.0, $v); return $this; }

    public function getPhysicalDegradationPersonalityScale(): float { return $this->physicalDegradationPersonalityScale; }
    public function setPhysicalDegradationPersonalityScale(float $v): static { $this->physicalDegradationPersonalityScale = max(0.0, $v); return $this; }

    public function getFormDevelopmentMatchCount(): int { return $this->formDevelopmentMatchCount; }
    public function setFormDevelopmentMatchCount(int $v): static { $this->formDevelopmentMatchCount = max(0, $v); return $this; }

    public function getFormDevelopmentThreshold(): float { return $this->formDevelopmentThreshold; }
    public function setFormDevelopmentThreshold(float $v): static { $this->formDevelopmentThreshold = max(0.0, $v); return $this; }

    public function getFormDevelopmentMultiplierMid(): float { return $this->formDevelopmentMultiplierMid; }
    public function setFormDevelopmentMultiplierMid(float $v): static { $this->formDevelopmentMultiplierMid = max(0.0, $v); return $this; }

    public function getFormDevelopmentMultiplierLow(): float { return $this->formDevelopmentMultiplierLow; }
    public function setFormDevelopmentMultiplierLow(float $v): static { $this->formDevelopmentMultiplierLow = max(0.0, $v); return $this; }

    public function getFormDevelopmentThresholdLow(): float { return $this->formDevelopmentThresholdLow; }
    public function setFormDevelopmentThresholdLow(float $v): static { $this->formDevelopmentThresholdLow = max(0.0, $v); return $this; }

    public function getScoutMoraleThreshold(): int { return $this->scoutMoraleThreshold; }
    public function setScoutMoraleThreshold(int $v): static { $this->scoutMoraleThreshold = $v; return $this; }

    public function getScoutRevealWeeks(): int { return $this->scoutRevealWeeks; }
    public function setScoutRevealWeeks(int $v): static { $this->scoutRevealWeeks = $v; return $this; }

    public function getScoutAbilityErrorRange(): int { return $this->scoutAbilityErrorRange; }
    public function setScoutAbilityErrorRange(int $v): static { $this->scoutAbilityErrorRange = $v; return $this; }

    public function getScoutMaxAssignments(): int { return $this->scoutMaxAssignments; }
    public function setScoutMaxAssignments(int $v): static { $this->scoutMaxAssignments = $v; return $this; }

    public function getScoutReportValidWeeks(): int { return $this->scoutReportValidWeeks; }
    public function setScoutReportValidWeeks(int $v): void { $this->scoutReportValidWeeks = $v; }

    public function getScoutReportDecayRatePerWeek(): int { return $this->scoutReportDecayRatePerWeek; }
    public function setScoutReportDecayRatePerWeek(int $v): void { $this->scoutReportDecayRatePerWeek = $v; }

    public function getNpcScoutRejectionCooldownWeeks(): int { return $this->npcScoutRejectionCooldownWeeks; }
    public function setNpcScoutRejectionCooldownWeeks(int $v): void { $this->npcScoutRejectionCooldownWeeks = $v; }

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

    public function getPlayerWageMultiplier(): float { return $this->playerWageMultiplier; }
    public function setPlayerWageMultiplier(float $v): static { $this->playerWageMultiplier = max(0.0, $v); return $this; }

    public function getFreeTransfersPerSeason(): int { return $this->freeTransfersPerSeason; }
    public function setFreeTransfersPerSeason(int $v): static { $this->freeTransfersPerSeason = max(0, $v); return $this; }

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

    // ── League Finances ───────────────────────────────────────────────────

    /** Per-season income range for SMALL company sponsors (pence). Default: 0 */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $smallSponsorMin = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $smallSponsorMax = 0;

    /** Per-season income range for MEDIUM company sponsors (pence). Default: 0 */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $mediumSponsorMin = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $mediumSponsorMax = 0;

    /** Per-season income range for LARGE company sponsors (pence). Default: 0 */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $largeSponsorMin = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $largeSponsorMax = 0;

    /**
     * % reduction per finishing position for leaguePositionPot distribution.
     * e.g. 5 = each position gets 5% less than the one above. Default: 5
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 5])]
    private int $leaguePositionDecreasePercent = 5;

    public function getSmallSponsorMin(): int { return $this->smallSponsorMin; }
    public function setSmallSponsorMin(int $v): static { $this->smallSponsorMin = $v; return $this; }

    public function getSmallSponsorMax(): int { return $this->smallSponsorMax; }
    public function setSmallSponsorMax(int $v): static { $this->smallSponsorMax = $v; return $this; }

    public function getMediumSponsorMin(): int { return $this->mediumSponsorMin; }
    public function setMediumSponsorMin(int $v): static { $this->mediumSponsorMin = $v; return $this; }

    public function getMediumSponsorMax(): int { return $this->mediumSponsorMax; }
    public function setMediumSponsorMax(int $v): static { $this->mediumSponsorMax = $v; return $this; }

    public function getLargeSponsorMin(): int { return $this->largeSponsorMin; }
    public function setLargeSponsorMin(int $v): static { $this->largeSponsorMin = $v; return $this; }

    public function getLargeSponsorMax(): int { return $this->largeSponsorMax; }
    public function setLargeSponsorMax(int $v): static { $this->largeSponsorMax = $v; return $this; }

    public function getLeaguePositionDecreasePercent(): int { return $this->leaguePositionDecreasePercent; }
    public function setLeaguePositionDecreasePercent(int $v): static { $this->leaguePositionDecreasePercent = $v; return $this; }

    // ── Financial Penalties ───────────────────────────────────────────────────

    /**
     * JSON array of deficit tiers for points deduction at season end.
     * Each tier: { "deficitMin": int, "deficitMax": int|null, "points": int }
     * deficitMin/Max in pence. deficitMax null = no upper bound.
     * Evaluated in order — first matching tier wins.
     * Default: 3 tiers (small/medium/large deficit)
     */
    #[ORM\Column(type: 'json')]
    private array $bankruptcyDeductionTiers = [
        ['deficitMin' => 1,       'deficitMax' => 200000,  'points' => 3],
        ['deficitMin' => 200001,  'deficitMax' => 500000,  'points' => 6],
        ['deficitMin' => 500001,  'deficitMax' => null,    'points' => 10],
    ];

    /**
     * Whether points deductions are enabled. Set to false to disable
     * the mechanic entirely without removing config.
     * Default: true
     */
    #[ORM\Column(type: 'boolean')]
    private bool $bankruptcyDeductionsEnabled = true;

    /** @return array<array{deficitMin: int, deficitMax: int|null, points: int}> */
    public function getBankruptcyDeductionTiers(): array { return $this->bankruptcyDeductionTiers; }
    /** @param array<array{deficitMin: int, deficitMax: int|null, points: int}> $v */
    public function setBankruptcyDeductionTiers(array $v): static { $this->bankruptcyDeductionTiers = $v; return $this; }

    public function isBankruptcyDeductionsEnabled(): bool { return $this->bankruptcyDeductionsEnabled; }
    public function setBankruptcyDeductionsEnabled(bool $v): static { $this->bankruptcyDeductionsEnabled = $v; return $this; }

    // ── Sponsor & Investor Offer Probabilities ────────────────────────────

    /** Weekly probability of receiving a LOCAL-tier sponsor offer. Default: 0.30 */
    #[ORM\Column(type: 'float', options: ['default' => 0.30])]
    private float $sponsorProbabilityLocal = 0.30;

    /** Weekly probability of receiving a REGIONAL-tier sponsor offer. Default: 0.20 */
    #[ORM\Column(type: 'float', options: ['default' => 0.20])]
    private float $sponsorProbabilityRegional = 0.20;

    /** Weekly probability of receiving a NATIONAL-tier sponsor offer. Default: 0.10 */
    #[ORM\Column(type: 'float', options: ['default' => 0.10])]
    private float $sponsorProbabilityNational = 0.10;

    /** Weekly probability of receiving an ELITE-tier sponsor offer. Default: 0.05 */
    #[ORM\Column(type: 'float', options: ['default' => 0.05])]
    private float $sponsorProbabilityElite = 0.05;

    /** Weekly probability of receiving a LOCAL-tier investor offer. Default: 0.20 */
    #[ORM\Column(type: 'float', options: ['default' => 0.20])]
    private float $investorProbabilityLocal = 0.20;

    /** Weekly probability of receiving a REGIONAL-tier investor offer. Default: 0.12 */
    #[ORM\Column(type: 'float', options: ['default' => 0.12])]
    private float $investorProbabilityRegional = 0.12;

    /** Weekly probability of receiving a NATIONAL-tier investor offer. Default: 0.06 */
    #[ORM\Column(type: 'float', options: ['default' => 0.06])]
    private float $investorProbabilityNational = 0.06;

    /** Weekly probability of receiving an ELITE-tier investor offer. Default: 0.02 */
    #[ORM\Column(type: 'float', options: ['default' => 0.02])]
    private float $investorProbabilityElite = 0.02;

    public function getSponsorProbabilityLocal(): float { return $this->sponsorProbabilityLocal; }
    public function setSponsorProbabilityLocal(float $v): static { $this->sponsorProbabilityLocal = $v; return $this; }

    public function getSponsorProbabilityRegional(): float { return $this->sponsorProbabilityRegional; }
    public function setSponsorProbabilityRegional(float $v): static { $this->sponsorProbabilityRegional = $v; return $this; }

    public function getSponsorProbabilityNational(): float { return $this->sponsorProbabilityNational; }
    public function setSponsorProbabilityNational(float $v): static { $this->sponsorProbabilityNational = $v; return $this; }

    public function getSponsorProbabilityElite(): float { return $this->sponsorProbabilityElite; }
    public function setSponsorProbabilityElite(float $v): static { $this->sponsorProbabilityElite = $v; return $this; }

    public function getInvestorProbabilityLocal(): float { return $this->investorProbabilityLocal; }
    public function setInvestorProbabilityLocal(float $v): static { $this->investorProbabilityLocal = $v; return $this; }

    public function getInvestorProbabilityRegional(): float { return $this->investorProbabilityRegional; }
    public function setInvestorProbabilityRegional(float $v): static { $this->investorProbabilityRegional = $v; return $this; }

    public function getInvestorProbabilityNational(): float { return $this->investorProbabilityNational; }
    public function setInvestorProbabilityNational(float $v): static { $this->investorProbabilityNational = $v; return $this; }

    public function getInvestorProbabilityElite(): float { return $this->investorProbabilityElite; }
    public function setInvestorProbabilityElite(float $v): static { $this->investorProbabilityElite = $v; return $this; }

    // ── Sponsor & Investor Limits ─────────────────────────────────────────

    /**
     * Maximum active sponsor contracts per reputation tier.
     * Keys match ReputationTier string values.
     * Default: local=3, regional=5, national=7, elite=10
     */
    #[ORM\Column(type: 'json')]
    private array $maxSponsorsByTier = [
        'local'    => 3,
        'regional' => 5,
        'national' => 7,
        'elite'    => 10,
    ];

    /**
     * Maximum active investors per reputation tier.
     * Keys match ReputationTier string values.
     * Default: local=3, regional=5, national=7, elite=10
     */
    #[ORM\Column(type: 'json')]
    private array $maxInvestorsByTier = [
        'local'    => 3,
        'regional' => 5,
        'national' => 7,
        'elite'    => 10,
    ];

    public function getMaxSponsorsByTier(): array { return $this->maxSponsorsByTier; }
    public function setMaxSponsorsByTier(array $v): void { $this->maxSponsorsByTier = $v; }

    public function getMaxInvestorsByTier(): array { return $this->maxInvestorsByTier; }
    public function setMaxInvestorsByTier(array $v): void { $this->maxInvestorsByTier = $v; }

    // ── Staff Limits Per Club ─────────────────────────────────────────────

    /** Maximum coaches (StaffRole::COACH) allowed at a single club. Default: 15 */
    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    private int $maxCoachesPerClub = 15;

    /** Maximum managers (StaffRole::MANAGER) allowed at a single club. Default: 1 */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $maxManagersPerClub = 1;

    /** Maximum directors of football (StaffRole::DIRECTOR_OF_FOOTBALL) allowed at a single club. Default: 1 */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $maxDirectorsOfFootballPerClub = 1;

    /** Maximum facility managers (StaffRole::FACILITY_MANAGER) allowed at a single club. Default: 1 */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $maxFacilityManagersPerClub = 1;

    /** Maximum chairmen (StaffRole::CHAIRMAN) allowed at a single club. Default: 1 */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $maxChairmensPerClub = 1;

    /** Maximum scouts (Scout entity) allowed at a single club. Default: 3 */
    #[ORM\Column(type: 'smallint', options: ['default' => 3])]
    private int $maxScoutsPerClub = 3;

    /**
     * Minimum sign-on fee as a % of the overall contract offer value. Default: 0
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0, 'unsigned' => true])]
    private int $signOnFeePercentMin = 0;

    /**
     * Maximum sign-on fee as a % of the overall contract offer value. Default: 10
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 10, 'unsigned' => true])]
    private int $signOnFeePercentMax = 10;

    public function getMaxCoachesPerClub(): int { return $this->maxCoachesPerClub; }
    public function setMaxCoachesPerClub(int $v): static { $this->maxCoachesPerClub = $v; return $this; }

    public function getMaxManagersPerClub(): int { return $this->maxManagersPerClub; }
    public function setMaxManagersPerClub(int $v): static { $this->maxManagersPerClub = $v; return $this; }

    public function getMaxDirectorsOfFootballPerClub(): int { return $this->maxDirectorsOfFootballPerClub; }
    public function setMaxDirectorsOfFootballPerClub(int $v): static { $this->maxDirectorsOfFootballPerClub = $v; return $this; }

    public function getMaxFacilityManagersPerClub(): int { return $this->maxFacilityManagersPerClub; }
    public function setMaxFacilityManagersPerClub(int $v): static { $this->maxFacilityManagersPerClub = $v; return $this; }

    public function getMaxChairmensPerClub(): int { return $this->maxChairmensPerClub; }
    public function setMaxChairmensPerClub(int $v): static { $this->maxChairmensPerClub = $v; return $this; }

    public function getMaxScoutsPerClub(): int { return $this->maxScoutsPerClub; }
    public function setMaxScoutsPerClub(int $v): static { $this->maxScoutsPerClub = $v; return $this; }

    public function getSignOnFeePercentMin(): int { return $this->signOnFeePercentMin; }
    public function setSignOnFeePercentMin(int $v): static { $this->signOnFeePercentMin = max(0, min(100, $v)); return $this; }

    public function getSignOnFeePercentMax(): int { return $this->signOnFeePercentMax; }
    public function setSignOnFeePercentMax(int $v): static { $this->signOnFeePercentMax = max(0, min(100, $v)); return $this; }

    // ── Facility Income ───────────────────────────────────────────────────

    /**
     * Percentage of a facility's matchday income that is also earned on non-match weeks.
     * Range 0–100. Default: 0
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0, 'unsigned' => true])]
    private int $nonMatchFacilityIncomePercent = 0;

    public function getNonMatchFacilityIncomePercent(): int { return $this->nonMatchFacilityIncomePercent; }
    public function setNonMatchFacilityIncomePercent(int $v): static { $this->nonMatchFacilityIncomePercent = max(0, min(100, $v)); return $this; }

    // ── Facility Construction ─────────────────────────────────────────────

    /**
     * Global multiplier applied to all facility construction times.
     * constructionWeeks = baseConstructionWeeks * level^decayBase * multiplier
     * Default: 1.0
     */
    #[ORM\Column(type: 'float')]
    private float $constructionTimeMultiplier = 1.0;

    /**
     * Probability (0.0–1.0) that a construction project encounters a
     * failure event during any given week.
     * Default: 0.05 (5%)
     */
    #[ORM\Column(type: 'float')]
    private float $facilityConstructionFailureChance = 0.05;

    /**
     * Minimum additional cost triggered by a construction failure, expressed as a
     * percentage of the total upgrade cost (e.g. 5.0 = 5% on top of the upgrade price).
     * Default: 5.0
     */
    #[ORM\Column(type: 'float')]
    private float $facilityConstructionFailureCostMin = 5.0;

    /**
     * Maximum additional cost triggered by a construction failure, expressed as a
     * percentage of the total upgrade cost (e.g. 20.0 = 20% on top of the upgrade price).
     * Default: 20.0
     */
    #[ORM\Column(type: 'float')]
    private float $facilityConstructionFailureCostMax = 20.0;

    /**
     * Minimum number of weeks added to construction time on failure.
     * Default: 2
     */
    #[ORM\Column(type: 'integer')]
    private int $facilityConstructionFailureTimeMin = 2;

    /**
     * Maximum number of weeks added to construction time on failure.
     * Default: 6
     */
    #[ORM\Column(type: 'integer')]
    private int $facilityConstructionFailureTimeMax = 6;

    public function getConstructionTimeMultiplier(): float { return $this->constructionTimeMultiplier; }
    public function setConstructionTimeMultiplier(float $v): static { $this->constructionTimeMultiplier = max(0.0, $v); return $this; }

    public function getFacilityConstructionFailureChance(): float { return $this->facilityConstructionFailureChance; }
    public function setFacilityConstructionFailureChance(float $v): static { $this->facilityConstructionFailureChance = max(0.0, $v); return $this; }

    public function getFacilityConstructionFailureCostMin(): float { return $this->facilityConstructionFailureCostMin; }
    public function setFacilityConstructionFailureCostMin(float $v): static { $this->facilityConstructionFailureCostMin = max(0.0, $v); return $this; }

    public function getFacilityConstructionFailureCostMax(): float { return $this->facilityConstructionFailureCostMax; }
    public function setFacilityConstructionFailureCostMax(float $v): static { $this->facilityConstructionFailureCostMax = max(0.0, $v); return $this; }

    public function getFacilityConstructionFailureTimeMin(): int { return $this->facilityConstructionFailureTimeMin; }
    public function setFacilityConstructionFailureTimeMin(int $v): static { $this->facilityConstructionFailureTimeMin = max(0, $v); return $this; }

    public function getFacilityConstructionFailureTimeMax(): int { return $this->facilityConstructionFailureTimeMax; }
    public function setFacilityConstructionFailureTimeMax(int $v): static { $this->facilityConstructionFailureTimeMax = max(0, $v); return $this; }

    // ── Playing Style Influence ───────────────────────────────────────────

    /**
     * Maps each playing style to the player attributes it emphasises during match simulation.
     * Keys: POSSESSION | DIRECT | COUNTER | HIGH_PRESS
     * Values: subset of [pace, technical, vision, power, stamina, heart]
     *
     * @var array<string, string[]>
     */
    #[ORM\Column(type: 'json')]
    private array $playingStyleInfluence = [
        'POSSESSION' => [],
        'DIRECT'     => [],
        'COUNTER'    => [],
        'HIGH_PRESS' => [],
    ];

    /** @return array<string, string[]> */
    public function getPlayingStyleInfluence(): array { return $this->playingStyleInfluence; }
    /** @param array<string, string[]> $v */
    public function setPlayingStyleInfluence(array $v): static { $this->playingStyleInfluence = $v; return $this; }

    // ── Player Retirement ─────────────────────────────────────────────────

    /** Minimum player age at which retirement becomes possible. Default: 16 */
    #[ORM\Column(type: 'smallint', options: ['default' => 16])]
    private int $retirementMinAge = 30;

    /** Maximum player age at which retirement is guaranteed. Default: 21 */
    #[ORM\Column(type: 'smallint', options: ['default' => 21])]
    private int $retirementMaxAge = 38;

    /**
     * Weekly probability that an eligible player (age >= retirementMinAge) retires.
     * Probability scales linearly from 0 at minAge to this value at maxAge. Default: 0.5
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.5])]
    private float $retirementChance = 0.35;

    public function getRetirementMinAge(): int { return $this->retirementMinAge; }
    public function setRetirementMinAge(int $v): static { $this->retirementMinAge = $v; return $this; }

    public function getRetirementMaxAge(): int { return $this->retirementMaxAge; }
    public function setRetirementMaxAge(int $v): static { $this->retirementMaxAge = $v; return $this; }

    public function getRetirementChance(): float { return $this->retirementChance; }
    public function setRetirementChance(float $v): static { $this->retirementChance = max(0.0, min(1.0, $v)); return $this; }

    // ── Attendance Ranges ─────────────────────────────────────────────────

    /**
     * Attendance as a % of stadium capacity per reputation tier (0–100).
     * The engine picks a random value in [min, max] each matchday.
     */

    #[ORM\Column(type: 'smallint', options: ['default' => 10])]
    private int $attendanceLocalMin = 10;

    #[ORM\Column(type: 'smallint', options: ['default' => 30])]
    private int $attendanceLocalMax = 30;

    #[ORM\Column(type: 'smallint', options: ['default' => 30])]
    private int $attendanceRegionalMin = 30;

    #[ORM\Column(type: 'smallint', options: ['default' => 55])]
    private int $attendanceRegionalMax = 55;

    #[ORM\Column(type: 'smallint', options: ['default' => 55])]
    private int $attendanceNationalMin = 55;

    #[ORM\Column(type: 'smallint', options: ['default' => 80])]
    private int $attendanceNationalMax = 80;

    #[ORM\Column(type: 'smallint', options: ['default' => 80])]
    private int $attendanceEliteMin = 80;

    #[ORM\Column(type: 'smallint', options: ['default' => 100])]
    private int $attendanceEliteMax = 100;

    public function getAttendanceLocalMin(): int { return $this->attendanceLocalMin; }
    public function setAttendanceLocalMin(int $v): static { $this->attendanceLocalMin = max(0, min(100, $v)); return $this; }

    public function getAttendanceLocalMax(): int { return $this->attendanceLocalMax; }
    public function setAttendanceLocalMax(int $v): static { $this->attendanceLocalMax = max(0, min(100, $v)); return $this; }

    public function getAttendanceRegionalMin(): int { return $this->attendanceRegionalMin; }
    public function setAttendanceRegionalMin(int $v): static { $this->attendanceRegionalMin = max(0, min(100, $v)); return $this; }

    public function getAttendanceRegionalMax(): int { return $this->attendanceRegionalMax; }
    public function setAttendanceRegionalMax(int $v): static { $this->attendanceRegionalMax = max(0, min(100, $v)); return $this; }

    public function getAttendanceNationalMin(): int { return $this->attendanceNationalMin; }
    public function setAttendanceNationalMin(int $v): static { $this->attendanceNationalMin = max(0, min(100, $v)); return $this; }

    public function getAttendanceNationalMax(): int { return $this->attendanceNationalMax; }
    public function setAttendanceNationalMax(int $v): static { $this->attendanceNationalMax = max(0, min(100, $v)); return $this; }

    public function getAttendanceEliteMin(): int { return $this->attendanceEliteMin; }
    public function setAttendanceEliteMin(int $v): static { $this->attendanceEliteMin = max(0, min(100, $v)); return $this; }

    public function getAttendanceEliteMax(): int { return $this->attendanceEliteMax; }
    public function setAttendanceEliteMax(int $v): static { $this->attendanceEliteMax = max(0, min(100, $v)); return $this; }

    // ── App Links ─────────────────────────────────────────────────────────

    /** Google Play / APK download URL for the Android build. Nullable — omit button if empty. */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $androidDownloadUrl = null;

    /** App Store URL for the iOS build. Nullable — omit button if empty. */
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $iosDownloadUrl = null;

    public function getAndroidDownloadUrl(): ?string { return $this->androidDownloadUrl; }
    public function setAndroidDownloadUrl(?string $v): static { $this->androidDownloadUrl = $v ?: null; return $this; }

    public function getIosDownloadUrl(): ?string { return $this->iosDownloadUrl; }
    public function setIosDownloadUrl(?string $v): static { $this->iosDownloadUrl = $v ?: null; return $this; }

    #[ORM\Column(nullable: true)]
    private ?string $facebookPageUrl = null;

    #[ORM\Column(nullable: true)]
    private ?string $xProfileUrl = null;

    public function getFacebookPageUrl(): ?string { return $this->facebookPageUrl; }
    public function setFacebookPageUrl(?string $v): static { $this->facebookPageUrl = $v ?: null; return $this; }

    public function getXProfileUrl(): ?string { return $this->xProfileUrl; }
    public function setXProfileUrl(?string $v): static { $this->xProfileUrl = $v ?: null; return $this; }

    /** Email address that receives beta access request notifications. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $betaRequestEmail = null;

    /** Google reCAPTCHA v2 site key (public — embedded in frontend HTML). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $recaptchaSiteKey = null;

    /** Google reCAPTCHA v2 secret key (private — used for server-side verification). */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $recaptchaSecretKey = null;

    public function getBetaRequestEmail(): ?string { return $this->betaRequestEmail; }
    public function setBetaRequestEmail(?string $v): static { $this->betaRequestEmail = $v ?: null; return $this; }

    public function getRecaptchaSiteKey(): ?string { return $this->recaptchaSiteKey; }
    public function setRecaptchaSiteKey(?string $v): static { $this->recaptchaSiteKey = $v ?: null; return $this; }

    public function getRecaptchaSecretKey(): ?string { return $this->recaptchaSecretKey; }
    public function setRecaptchaSecretKey(?string $v): static { $this->recaptchaSecretKey = $v ?: null; return $this; }

    // ── NPC Club Finances ─────────────────────────────────────────────────

    /**
     * Starting balance range per tier (tiers 1–8, index 0 = tier 1).
     * Each element: {min: <pence>, max: <pence>}.
     * Defaults match the legacy formula: tier-1 ~£40m–£60m, halving each tier down to tier-8 ~£312k–£469k.
     * @var array<array{min:int,max:int}>
     */
    #[ORM\Column(type: 'json')]
    private array $npcClubBalanceRanges = [
        ['min' => 4_000_000_000, 'max' => 6_000_000_000],
        ['min' => 2_000_000_000, 'max' => 3_000_000_000],
        ['min' => 1_000_000_000, 'max' => 1_500_000_000],
        ['min' =>   500_000_000, 'max' =>   750_000_000],
        ['min' =>   250_000_000, 'max' =>   375_000_000],
        ['min' =>   125_000_000, 'max' =>   187_500_000],
        ['min' =>    62_500_000, 'max' =>    93_750_000],
        ['min' =>    31_250_000, 'max' =>    46_875_000],
    ];

    /** @return array<array{min:int,max:int}> */
    public function getNpcClubBalanceRanges(): array { return $this->npcClubBalanceRanges; }

    /** @param array<array{min:int,max:int}> $ranges */
    public function setNpcClubBalanceRanges(array $ranges): static { $this->npcClubBalanceRanges = $ranges; return $this; }

    /**
     * Returns the {min, max} range for a given tier (1–8).
     * Falls back to the tier-8 entry if the tier is out of bounds.
     * @return array{min:int,max:int}
     */
    public function getNpcClubBalanceRangeForTier(int $tier): array
    {
        $idx = max(0, min(7, $tier - 1));
        return $this->npcClubBalanceRanges[$idx] ?? ['min' => 31_250_000, 'max' => 46_875_000];
    }

    // ── NPC Facility Level Ranges ─────────────────────────────────────────

    /**
     * Starting facility level range per tier band, per facility slug.
     * 4 bands (index 0–3): band 0 = tiers 1–2, band 1 = tiers 3–4, band 2 = tiers 5–6, band 3 = tiers 7–8.
     * Each band is a map of slug → {min: int, max: int}.
     * Empty array (default) = fall back to the hardcoded FACILITY_LEVELS constants in NpcClubGenerationService.
     *
     * @var array<int, array<string, array{min: int, max: int}>>
     */
    #[ORM\Column(type: 'json')]
    private array $npcFacilityLevelRanges = [];

    /** @return array<int, array<string, array{min: int, max: int}>> */
    public function getNpcFacilityLevelRanges(): array { return $this->npcFacilityLevelRanges; }

    /** @param array<int, array<string, array{min: int, max: int}>> $v */
    public function setNpcFacilityLevelRanges(array $v): static { $this->npcFacilityLevelRanges = $v; return $this; }

    /** Returns null when no override is configured (caller should use its own default). */
    public function getNpcFacilityLevelRangeForSlugAndBand(string $slug, int $bandIndex): ?array
    {
        return $this->npcFacilityLevelRanges[$bandIndex][$slug] ?? null;
    }

    // ── Squad Configuration ───────────────────────────────────────────────

    /** Minimum number of players required in a club's active squad. Default: 11 */
    #[ORM\Column(type: 'smallint', options: ['default' => 11])]
    private int $squadSizeMin = 11;

    /** Maximum number of players allowed in a club's active squad. Default: 25 */
    #[ORM\Column(type: 'smallint', options: ['default' => 25])]
    private int $squadSizeMax = 25;

    public function getSquadSizeMin(): int { return $this->squadSizeMin; }
    public function setSquadSizeMin(int $v): static { $this->squadSizeMin = max(1, $v); return $this; }

    public function getSquadSizeMax(): int { return $this->squadSizeMax; }
    public function setSquadSizeMax(int $v): static { $this->squadSizeMax = max(1, $v); return $this; }

    // ── Squad Roles ───────────────────────────────────────────────────────────

    /**
     * Minimum appearances per season expected for each squad role.
     * Used by the frontend to evaluate whether a player is getting
     * sufficient game time for their role, driving morale and
     * development consequences.
     * Keys must match the six role slugs exactly.
     * Default values represent a 38-game season.
     */
    #[ORM\Column(type: 'json')]
    private array $squadRoleAppearanceExpectations = [
        'star_player'  => 0.90,  // 90% of season matches
        'important'    => 0.75,  // 75%
        'first_team'   => 0.60,  // 60%
        'squad_player' => 0.40,  // 40%
        'backup'       => 0.20,  // 20%
        'prospect'     => 0.0,   // no expectation
    ];

    /**
     * Morale delta applied per week when a player is not meeting
     * their role's appearance expectation.
     * Negative value = morale decreases.
     * Keys match the six role slugs.
     */
    #[ORM\Column(type: 'json')]
    private array $squadRoleMoraleDecayPerWeek = [
        'star_player'  => -3,
        'important'    => -2,
        'first_team'   => -2,
        'squad_player' => -1,
        'backup'       => 0,
        'prospect'     => 0,
    ];

    /**
     * Morale delta applied per week when a player exceeds their
     * role's appearance expectation (overperforming their role).
     * Positive value = morale boost.
     */
    #[ORM\Column(type: 'json')]
    private array $squadRoleMoraleBoostPerWeek = [
        'star_player'  => 0,
        'important'    => 1,
        'first_team'   => 1,
        'squad_player' => 2,
        'backup'       => 2,
        'prospect'     => 3,
    ];

    /**
     * Overall rating thresholds used to auto-assign squad roles
     * during world pack generation. A player is assigned the
     * highest role whose threshold they meet.
     * Values are overallRating (0-100).
     */
    #[ORM\Column(type: 'json')]
    private array $squadRoleAutoAssignThresholds = [
        'star_player'  => 80,
        'important'    => 65,
        'first_team'   => 50,
        'squad_player' => 35,
        'backup'       => 20,
        'prospect'     => 0,
    ];

    /** @return array<string, int> */
    public function getSquadRoleAppearanceExpectations(): array { return $this->squadRoleAppearanceExpectations; }
    /** @param array<string, int> $v */
    public function setSquadRoleAppearanceExpectations(array $v): static { $this->squadRoleAppearanceExpectations = $v; return $this; }

    /** @return array<string, int> */
    public function getSquadRoleMoraleDecayPerWeek(): array { return $this->squadRoleMoraleDecayPerWeek; }
    /** @param array<string, int> $v */
    public function setSquadRoleMoraleDecayPerWeek(array $v): static { $this->squadRoleMoraleDecayPerWeek = $v; return $this; }

    /** @return array<string, int> */
    public function getSquadRoleMoraleBoostPerWeek(): array { return $this->squadRoleMoraleBoostPerWeek; }
    /** @param array<string, int> $v */
    public function setSquadRoleMoraleBoostPerWeek(array $v): static { $this->squadRoleMoraleBoostPerWeek = $v; return $this; }

    /** @return array<string, int> */
    public function getSquadRoleAutoAssignThresholds(): array { return $this->squadRoleAutoAssignThresholds; }
    /** @param array<string, int> $v */
    public function setSquadRoleAutoAssignThresholds(array $v): static { $this->squadRoleAutoAssignThresholds = $v; return $this; }

    // ── League Player Ability Ranges ──────────────────────────────────────

    /**
     * Min/max ability ranges for NPC players generated per league tier, grouped by country.
     * Structure: [{country: string, leagues: [{tier: int, min: int, max: int}]}]
     * Empty array = use engine tier-based defaults.
     *
     * @var array<array{country: string, leagues: array<array{tier: int, min: int, max: int}>}>
     */
    #[ORM\Column(type: 'json')]
    private array $leaguePlayerAbilityRanges = [];

    public function getLeaguePlayerAbilityRanges(): array { return $this->leaguePlayerAbilityRanges; }
    public function setLeaguePlayerAbilityRanges(array $v): static { $this->leaguePlayerAbilityRanges = $v; return $this; }

    // ── Inbox / Notification Frequencies ─────────────────────────────────

    /**
     * How often (in weeks) the system sends a facility maintenance reminder to the inbox.
     * Range 1–52. Default: 4
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 4])]
    private int $facilityMaintenanceFrequencyWeeks = 4;

    /**
     * How often (in weeks) the system sends general status / digest notifications to the inbox.
     * Range 1–52. Default: 8
     */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 8])]
    private int $systemNotificationFrequencyWeeks = 8;

    public function getFacilityMaintenanceFrequencyWeeks(): int { return $this->facilityMaintenanceFrequencyWeeks; }
    public function setFacilityMaintenanceFrequencyWeeks(int $v): static { $this->facilityMaintenanceFrequencyWeeks = max(1, min(52, $v)); return $this; }

    public function getSystemNotificationFrequencyWeeks(): int { return $this->systemNotificationFrequencyWeeks; }
    public function setSystemNotificationFrequencyWeeks(int $v): static { $this->systemNotificationFrequencyWeeks = max(1, min(52, $v)); return $this; }

    // ── Developer / Debug ─────────────────────────────────────────────────

    /** When true, the in-app debug log panel is visible to users. Default: false */
    #[ORM\Column(type: 'boolean')]
    private bool $debugLoggingEnabled = false;

    public function isDebugLoggingEnabled(): bool { return $this->debugLoggingEnabled; }
    public function setDebugLoggingEnabled(bool $v): static { $this->debugLoggingEnabled = $v; return $this; }

    // ── Wage Multiplier Tiers ─────────────────────────────────────────────

    /**
     * Ability-based wage multiplier tiers applied when generating player/staff contracts.
     * Tiers are evaluated in order; the first entry whose maxAbility > entity's currentAbility wins.
     * A null maxAbility means "all remaining" (i.e. the default/highest tier).
     *
     * contractValue = currentAbility × rand(contractValueRandMin, contractValueRandMax) × playerMultiplier
     *
     * @var array<array{maxAbility: int|null, playerMultiplier: float, staffMultiplier: float}>
     */
    #[ORM\Column(type: 'json')]
    private array $wageMultiplierTiers = [
        ['maxAbility' => 25,   'playerMultiplier' => 0.5, 'staffMultiplier' => 0.6],
        ['maxAbility' => 50,   'playerMultiplier' => 1.0, 'staffMultiplier' => 1.0],
        ['maxAbility' => 75,   'playerMultiplier' => 2.5, 'staffMultiplier' => 2.0],
        ['maxAbility' => null, 'playerMultiplier' => 5.0, 'staffMultiplier' => 4.0],
    ];

    /**
     * Minimum random multiplier in the contract value formula:
     * contractValue = currentAbility × rand(min, max) × wageMultiplier
     * Default: 10
     */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $contractValueRandMin = 10;

    /**
     * Maximum random multiplier in the contract value formula.
     * Default: 40
     */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $contractValueRandMax = 40;

    /** @return array<array{maxAbility: int|null, playerMultiplier: float, staffMultiplier: float}> */
    public function getWageMultiplierTiers(): array { return $this->wageMultiplierTiers; }
    /** @param array<array{maxAbility: int|null, playerMultiplier: float, staffMultiplier: float}> $v */
    public function setWageMultiplierTiers(array $v): static { $this->wageMultiplierTiers = $v; return $this; }

    public function getContractValueRandMin(): int { return $this->contractValueRandMin; }
    public function setContractValueRandMin(int $v): static { $this->contractValueRandMin = max(1, $v); return $this; }

    public function getContractValueRandMax(): int { return $this->contractValueRandMax; }
    public function setContractValueRandMax(int $v): static { $this->contractValueRandMax = max(1, $v); return $this; }

    // ── Capacity Calculation ──────────────────────────────────────────────

    /** Multiplier used when calculating stadium capacity from fan base. Default: 100 */
    #[ORM\Column(type: 'integer', options: ['default' => 100])]
    private int $capacityCalculation = 100;

    public function getCapacityCalculation(): int { return $this->capacityCalculation; }
    public function setCapacityCalculation(int $v): static { $this->capacityCalculation = max(1, $v); return $this; }

    // ── Manager Dividend & Transfer Budget ───────────────────────────────────

    /** Percentage of season profit paid to the manager as a personal dividend at financial year end. Default: 5.0 */
    #[ORM\Column(type: 'float', options: ['default' => 5.0])]
    private float $managerDividendPercent = 5.0;

    /** Transfer budget as a percentage of the club's current balance, recalculated each season. Default: 20.0 */
    #[ORM\Column(type: 'float', options: ['default' => 20.0])]
    private float $transferBudgetPercent = 20.0;

    public function getManagerDividendPercent(): float { return $this->managerDividendPercent; }
    public function setManagerDividendPercent(float $v): static { $this->managerDividendPercent = max(0.0, min(100.0, $v)); return $this; }

    public function getTransferBudgetPercent(): float { return $this->transferBudgetPercent; }
    public function setTransferBudgetPercent(float $v): static { $this->transferBudgetPercent = max(0.0, min(100.0, $v)); return $this; }

    // ── Manager Sacking ───────────────────────────────────────────────────

    /**
     * Win ratio below which fan sacking demand fires.
     * e.g. 0.20 = triggered when win% < 20%. Default: 0.20
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.20])]
    private float $managerSackingWinRatioTrigger = 0.20;

    /**
     * Win ratio above which an active sacking demand is resolved.
     * Must be >= managerSackingWinRatioTrigger. Default: 0.25
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.25])]
    private float $managerSackingWinRatioRecovery = 0.25;

    /**
     * Minimum games played before the sacking trigger can fire.
     * Prevents early-season noise. Default: 30
     */
    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $managerSackingMinGames = 30;

    /**
     * Weekly attendance multiplier penalty while a sacking demand is active.
     * e.g. 0.05 = 5% drop per week. Default: 0.05
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.05])]
    private float $managerSackingAttendancePenaltyPerWeek = 0.05;

    public function getManagerSackingWinRatioTrigger(): float { return $this->managerSackingWinRatioTrigger; }
    public function setManagerSackingWinRatioTrigger(float $v): static { $this->managerSackingWinRatioTrigger = max(0.0, min(1.0, $v)); return $this; }

    public function getManagerSackingWinRatioRecovery(): float { return $this->managerSackingWinRatioRecovery; }
    public function setManagerSackingWinRatioRecovery(float $v): static { $this->managerSackingWinRatioRecovery = max(0.0, min(1.0, $v)); return $this; }

    public function getManagerSackingMinGames(): int { return $this->managerSackingMinGames; }
    public function setManagerSackingMinGames(int $v): static { $this->managerSackingMinGames = max(0, $v); return $this; }

    public function getManagerSackingAttendancePenaltyPerWeek(): float { return $this->managerSackingAttendancePenaltyPerWeek; }
    public function setManagerSackingAttendancePenaltyPerWeek(float $v): static { $this->managerSackingAttendancePenaltyPerWeek = max(0.0, $v); return $this; }

    // ── Cards & Discipline ────────────────────────────────────────────────────

    /**
     * Base probability per player per match of receiving a yellow card.
     * Modified by player temperament trait on the frontend.
     * Default: 0.08 (8%)
     */
    #[ORM\Column(type: 'float')]
    private float $yellowCardBaseChance = 0.08;

    /**
     * Base probability per player per match of receiving a direct red card
     * (independent of yellows).
     * Modified by player temperament trait on the frontend.
     * Default: 0.01 (1%)
     */
    #[ORM\Column(type: 'float')]
    private float $redCardBaseChance = 0.01;

    /**
     * Number of yellow cards accumulated across a season that triggers
     * an automatic one-match suspension.
     * Default: 5
     */
    #[ORM\Column(type: 'integer')]
    private int $yellowCardAccumulationThreshold = 5;

    /**
     * Number of matches suspended for a direct red card.
     * Default: 1
     */
    #[ORM\Column(type: 'integer')]
    private int $redCardSuspensionMatches = 1;

    /**
     * Temperament trait scaling factor for card probability.
     * Higher = more volatile players are significantly more card-prone.
     * cardChance = baseChance * (1 + (20 - temperament) / 20 * temperamentCardScale)
     * Default: 1.0
     */
    #[ORM\Column(type: 'float')]
    private float $temperamentCardScale = 1.0;

    public function getYellowCardBaseChance(): float { return $this->yellowCardBaseChance; }
    public function setYellowCardBaseChance(float $v): static { $this->yellowCardBaseChance = max(0.0, $v); return $this; }

    public function getRedCardBaseChance(): float { return $this->redCardBaseChance; }
    public function setRedCardBaseChance(float $v): static { $this->redCardBaseChance = max(0.0, $v); return $this; }

    public function getYellowCardAccumulationThreshold(): int { return $this->yellowCardAccumulationThreshold; }
    public function setYellowCardAccumulationThreshold(int $v): static { $this->yellowCardAccumulationThreshold = max(0, $v); return $this; }

    public function getRedCardSuspensionMatches(): int { return $this->redCardSuspensionMatches; }
    public function setRedCardSuspensionMatches(int $v): static { $this->redCardSuspensionMatches = max(0, $v); return $this; }

    public function getTemperamentCardScale(): float { return $this->temperamentCardScale; }
    public function setTemperamentCardScale(float $v): static { $this->temperamentCardScale = max(0.0, $v); return $this; }

    // ── Competition History ───────────────────────────────────────────────────

    /**
     * Points awarded for winning each league tier (1–8), used to calculate
     * a club's all-time competition history score. Keyed by tier number (1 = top flight).
     * The 10× reduction per tier ensures one League 1 title outweighs any number
     * of lower-division wins (e.g. T1=10M > 2 × T2=2M).
     * Default: T1=10,000,000 … T8=1
     */
    #[ORM\Column(type: 'json')]
    private array $leagueWinPoints = [
        1 => 10000000,
        2 => 1000000,
        3 => 100000,
        4 => 10000,
        5 => 1000,
        6 => 100,
        7 => 10,
        8 => 1,
    ];

    public function getLeagueWinPoints(): array { return $this->leagueWinPoints; }
    public function setLeagueWinPoints(array $v): static { $this->leagueWinPoints = $v; return $this; }

    // ── Player Development Schedule ───────────────────────────────────────────

    /**
     * How many in-game weeks between each player development pass.
     * Default: 1 (every week)
     */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $developmentTickFrequencyWeeks = 1;

    // ── Feature Cooldowns ─────────────────────────────────────────────────────

    /** Weeks between Address Squad interactions. Default: 4 */
    #[ORM\Column(type: 'integer', options: ['default' => 4])]
    private int $addressSquadCooldownWeeks = 4;

    /** Weeks between fan event triggers. Default: 2 */
    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    private int $fanEventCooldownWeeks = 2;

    /** Weeks before another sponsor offer can be generated. Default: 8 */
    #[ORM\Column(type: 'integer', options: ['default' => 8])]
    private int $sponsorOfferCooldownWeeks = 8;

    /** Weeks before another investor offer can be generated. Default: 12 */
    #[ORM\Column(type: 'integer', options: ['default' => 12])]
    private int $investorOfferCooldownWeeks = 12;

    /** Weeks between scout report requests. Default: 2 */
    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    private int $scoutReportCooldownWeeks = 2;

    /** Weeks between youth intake windows. Default: 12 */
    #[ORM\Column(type: 'integer', options: ['default' => 12])]
    private int $youthIntakeCooldownWeeks = 12;

    /** Weeks between support/punish interactions per individual player. Default: 2 */
    #[ORM\Column(type: 'integer', options: ['default' => 2])]
    private int $playerInteractionCooldownWeeks = 2;

    /** Weeks between staff meeting interactions. Default: 4 */
    #[ORM\Column(type: 'integer', options: ['default' => 4])]
    private int $staffMeetingCooldownWeeks = 4;

    public function getDevelopmentTickFrequencyWeeks(): int { return $this->developmentTickFrequencyWeeks; }
    public function setDevelopmentTickFrequencyWeeks(int $v): static { $this->developmentTickFrequencyWeeks = max(1, $v); return $this; }

    public function getAddressSquadCooldownWeeks(): int { return $this->addressSquadCooldownWeeks; }
    public function setAddressSquadCooldownWeeks(int $v): static { $this->addressSquadCooldownWeeks = max(1, $v); return $this; }

    public function getFanEventCooldownWeeks(): int { return $this->fanEventCooldownWeeks; }
    public function setFanEventCooldownWeeks(int $v): static { $this->fanEventCooldownWeeks = max(1, $v); return $this; }

    public function getSponsorOfferCooldownWeeks(): int { return $this->sponsorOfferCooldownWeeks; }
    public function setSponsorOfferCooldownWeeks(int $v): static { $this->sponsorOfferCooldownWeeks = max(1, $v); return $this; }

    public function getInvestorOfferCooldownWeeks(): int { return $this->investorOfferCooldownWeeks; }
    public function setInvestorOfferCooldownWeeks(int $v): static { $this->investorOfferCooldownWeeks = max(1, $v); return $this; }

    public function getScoutReportCooldownWeeks(): int { return $this->scoutReportCooldownWeeks; }
    public function setScoutReportCooldownWeeks(int $v): static { $this->scoutReportCooldownWeeks = max(1, $v); return $this; }

    public function getYouthIntakeCooldownWeeks(): int { return $this->youthIntakeCooldownWeeks; }
    public function setYouthIntakeCooldownWeeks(int $v): static { $this->youthIntakeCooldownWeeks = max(1, $v); return $this; }

    public function getPlayerInteractionCooldownWeeks(): int { return $this->playerInteractionCooldownWeeks; }
    public function setPlayerInteractionCooldownWeeks(int $v): static { $this->playerInteractionCooldownWeeks = max(1, $v); return $this; }

    public function getStaffMeetingCooldownWeeks(): int { return $this->staffMeetingCooldownWeeks; }
    public function setStaffMeetingCooldownWeeks(int $v): static { $this->staffMeetingCooldownWeeks = max(1, $v); return $this; }

    // ── Pyramid News ──────────────────────────────────────────────────────────

    #[ORM\Column(type: 'integer')]
    private int $pyramidNewsFrequencyWeeks = 4;

    /**
     * Per-page-type config for pyramid news generation.
     * usedCacheClearAfterEditions — how many editions a club can appear in a
     * preventDuplicate page before becoming eligible again.
     */
    #[ORM\Column(type: 'json')]
    private array $pyramidNewsConfig = [
        'financial_turmoil'    => ['usedCacheClearAfterEditions' => 5],
        'top_goalscorer'       => ['usedCacheClearAfterEditions' => 10],
        'top_assists'          => ['usedCacheClearAfterEditions' => 10],
        'most_on_form_player'  => ['usedCacheClearAfterEditions' => 10],
        'best_game_most_goals' => ['usedCacheClearAfterEditions' => 10],
        'best_game_biggest_win'=> ['usedCacheClearAfterEditions' => 10],
        'biggest_upset'        => ['usedCacheClearAfterEditions' => 5],
        'on_the_rise'          => ['usedCacheClearAfterEditions' => 5],
        'outperforming_critics'=> ['usedCacheClearAfterEditions' => 3],
        'season_predictions'   => ['usedCacheClearAfterEditions' => 20],
    ];

    public function getPyramidNewsFrequencyWeeks(): int { return $this->pyramidNewsFrequencyWeeks; }
    public function setPyramidNewsFrequencyWeeks(int $v): static { $this->pyramidNewsFrequencyWeeks = max(1, $v); return $this; }

    public function getPyramidNewsConfig(): array { return $this->pyramidNewsConfig; }
    public function setPyramidNewsConfig(array $v): static { $this->pyramidNewsConfig = $v; return $this; }

    // ── Social Posting ───────────────────────────────────────────────────

    /** Last StatCategory posted by app:post-community-stat, for round-robin advancement. Null before the first run. */
    #[ORM\Column(type: 'string', enumType: StatCategory::class, nullable: true)]
    private ?StatCategory $lastPostedStatCategory = null;

    public function getLastPostedStatCategory(): ?StatCategory { return $this->lastPostedStatCategory; }
    public function setLastPostedStatCategory(?StatCategory $v): static { $this->lastPostedStatCategory = $v; return $this; }
}
