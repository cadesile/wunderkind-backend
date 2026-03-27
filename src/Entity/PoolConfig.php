<?php

namespace App\Entity;

use App\Repository\PoolConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-row configuration entity for market pool generation.
 * Controls all random ranges, probability weights, and pool size targets
 * used by MarketPoolService when generating players, staff, scouts, and agents.
 *
 * The primary key is fixed at 1 — only one row ever exists.
 */
#[ORM\Entity(repositoryClass: PoolConfigRepository::class)]
class PoolConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ── Player Generation ──────────────────────────────────────────────────

    /** Minimum age for newly generated players. Default: 12 */
    #[ORM\Column(type: 'integer')]
    private int $playerAgeMin = 12;

    /** Maximum age for newly generated players. Default: 13 */
    #[ORM\Column(type: 'integer')]
    private int $playerAgeMax = 13;

    /** Lower bound of the bell-curve potential range. Default: 40 */
    #[ORM\Column(type: 'integer')]
    private int $playerPotentialMin = 40;

    /** Upper bound of the bell-curve potential range. Default: 80 */
    #[ORM\Column(type: 'integer')]
    private int $playerPotentialMax = 80;

    /**
     * Mean for the bell-curve potential distribution.
     * Actual value is (bellCurveRaw + mean) / 2, biasing toward this centre.
     * Default: 60
     */
    #[ORM\Column(type: 'integer')]
    private int $playerPotentialMean = 60;

    /** Minimum starting current ability. Default: 3 */
    #[ORM\Column(type: 'integer')]
    private int $playerAbilityMin = 3;

    /** Maximum starting current ability. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $playerAbilityMax = 10;

    /** Minimum total attribute budget distributed across the 6 stats. Default: 6 */
    #[ORM\Column(type: 'integer')]
    private int $playerAttributeBudgetMin = 6;

    /** Maximum total attribute budget distributed across the 6 stats. Default: 20 */
    #[ORM\Column(type: 'integer')]
    private int $playerAttributeBudgetMax = 20;

    /** Percentage chance (0–100) that a generated player has an agent. Default: 40 */
    #[ORM\Column(type: 'integer')]
    private int $playerAgentChancePercent = 40;

    /** Minimum height in cm. Default: 145 */
    #[ORM\Column(type: 'integer')]
    private int $playerHeightMin = 145;

    /** Maximum height in cm. Default: 160 */
    #[ORM\Column(type: 'integer')]
    private int $playerHeightMax = 160;

    /** Minimum weight in kg. Default: 38 */
    #[ORM\Column(type: 'integer')]
    private int $playerWeightMin = 38;

    /** Maximum weight in kg. Default: 55 */
    #[ORM\Column(type: 'integer')]
    private int $playerWeightMax = 55;

    /** Min value applied to all 8 personality traits on generation. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $personalityTraitMin = 30;

    /** Max value applied to all 8 personality traits on generation. Default: 70 */
    #[ORM\Column(type: 'integer')]
    private int $personalityTraitMax = 70;

    // ── Position Weighting ─────────────────────────────────────────────────

    /**
     * Relative weight for Goalkeeper position.
     * Position is rolled against the sum of all four weights.
     * Default: 8 (8% of 100-total default)
     */
    #[ORM\Column(type: 'integer')]
    private int $positionWeightGk = 8;

    /** Relative weight for Defender position. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $positionWeightDef = 30;

    /** Relative weight for Midfielder position. Default: 38 */
    #[ORM\Column(type: 'integer')]
    private int $positionWeightMid = 38;

    /** Relative weight for Attacker position. Default: 24 */
    #[ORM\Column(type: 'integer')]
    private int $positionWeightAtt = 24;

    // ── Coach Generation ───────────────────────────────────────────────────

    /** Minimum age for generated coaches. Default: 28 */
    #[ORM\Column(type: 'integer')]
    private int $coachAgeMin = 28;

    /** Maximum age for generated coaches. Default: 60 */
    #[ORM\Column(type: 'integer')]
    private int $coachAgeMax = 60;

    /** Minimum coaching ability on generation. Default: 40 */
    #[ORM\Column(type: 'integer')]
    private int $coachAbilityMin = 40;

    /** Maximum coaching ability on generation. Default: 75 */
    #[ORM\Column(type: 'integer')]
    private int $coachAbilityMax = 75;

    // ── Scout Generation ───────────────────────────────────────────────────

    /** Minimum age for generated scouts. Default: 28 */
    #[ORM\Column(type: 'integer')]
    private int $scoutAgeMin = 28;

    /** Maximum age for generated scouts. Default: 40 */
    #[ORM\Column(type: 'integer')]
    private int $scoutAgeMax = 40;

    /** Minimum experience (years) for generated scouts. Default: 0 */
    #[ORM\Column(type: 'integer')]
    private int $scoutExperienceMin = 0;

    /** Maximum experience (years) for generated scouts. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $scoutExperienceMax = 10;

    /** Min value for all 5 scout judgement categories. Default: 40 */
    #[ORM\Column(type: 'integer')]
    private int $scoutJudgementMin = 40;

    /** Max value for all 5 scout judgement categories. Default: 80 */
    #[ORM\Column(type: 'integer')]
    private int $scoutJudgementMax = 80;

    // ── Agent Generation ───────────────────────────────────────────────────

    /** Minimum reputation for generated agents. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $agentReputationMin = 30;

    /** Maximum reputation for generated agents. Default: 70 */
    #[ORM\Column(type: 'integer')]
    private int $agentReputationMax = 70;

    /** Minimum age for generated agents. Default: 30 */
    #[ORM\Column(type: 'integer')]
    private int $agentAgeMin = 30;

    /** Maximum age for generated agents. Default: 60 */
    #[ORM\Column(type: 'integer')]
    private int $agentAgeMax = 60;

    // ── Pool Replenishment Targets ─────────────────────────────────────────

    /**
     * Minimum unassigned players before auto-replenishment triggers.
     * Also used as the batch size when generating players manually. Default: 50
     */
    #[ORM\Column(type: 'integer')]
    private int $playerPoolTarget = 50;

    /** Minimum unassigned coaches before auto-replenishment. Batch size for manual generate. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $coachPoolTarget = 10;

    /** Minimum scouts before auto-replenishment. Batch size for manual generate. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $scoutPoolTarget = 5;

    /** Minimum unassigned sponsors before auto-replenishment. Default: 10 */
    #[ORM\Column(type: 'integer')]
    private int $sponsorPoolTarget = 10;

    /** Minimum unassigned investors before auto-replenishment. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $investorPoolTarget = 5;

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPlayerAgeMin(): int { return $this->playerAgeMin; }
    public function setPlayerAgeMin(int $v): static { $this->playerAgeMin = $v; return $this; }

    public function getPlayerAgeMax(): int { return $this->playerAgeMax; }
    public function setPlayerAgeMax(int $v): static { $this->playerAgeMax = $v; return $this; }

    public function getPlayerPotentialMin(): int { return $this->playerPotentialMin; }
    public function setPlayerPotentialMin(int $v): static { $this->playerPotentialMin = $v; return $this; }

    public function getPlayerPotentialMax(): int { return $this->playerPotentialMax; }
    public function setPlayerPotentialMax(int $v): static { $this->playerPotentialMax = $v; return $this; }

    public function getPlayerPotentialMean(): int { return $this->playerPotentialMean; }
    public function setPlayerPotentialMean(int $v): static { $this->playerPotentialMean = $v; return $this; }

    public function getPlayerAbilityMin(): int { return $this->playerAbilityMin; }
    public function setPlayerAbilityMin(int $v): static { $this->playerAbilityMin = $v; return $this; }

    public function getPlayerAbilityMax(): int { return $this->playerAbilityMax; }
    public function setPlayerAbilityMax(int $v): static { $this->playerAbilityMax = $v; return $this; }

    public function getPlayerAttributeBudgetMin(): int { return $this->playerAttributeBudgetMin; }
    public function setPlayerAttributeBudgetMin(int $v): static { $this->playerAttributeBudgetMin = $v; return $this; }

    public function getPlayerAttributeBudgetMax(): int { return $this->playerAttributeBudgetMax; }
    public function setPlayerAttributeBudgetMax(int $v): static { $this->playerAttributeBudgetMax = $v; return $this; }

    public function getPlayerAgentChancePercent(): int { return $this->playerAgentChancePercent; }
    public function setPlayerAgentChancePercent(int $v): static { $this->playerAgentChancePercent = $v; return $this; }

    public function getPlayerHeightMin(): int { return $this->playerHeightMin; }
    public function setPlayerHeightMin(int $v): static { $this->playerHeightMin = $v; return $this; }

    public function getPlayerHeightMax(): int { return $this->playerHeightMax; }
    public function setPlayerHeightMax(int $v): static { $this->playerHeightMax = $v; return $this; }

    public function getPlayerWeightMin(): int { return $this->playerWeightMin; }
    public function setPlayerWeightMin(int $v): static { $this->playerWeightMin = $v; return $this; }

    public function getPlayerWeightMax(): int { return $this->playerWeightMax; }
    public function setPlayerWeightMax(int $v): static { $this->playerWeightMax = $v; return $this; }

    public function getPersonalityTraitMin(): int { return $this->personalityTraitMin; }
    public function setPersonalityTraitMin(int $v): static { $this->personalityTraitMin = $v; return $this; }

    public function getPersonalityTraitMax(): int { return $this->personalityTraitMax; }
    public function setPersonalityTraitMax(int $v): static { $this->personalityTraitMax = $v; return $this; }

    public function getPositionWeightGk(): int { return $this->positionWeightGk; }
    public function setPositionWeightGk(int $v): static { $this->positionWeightGk = $v; return $this; }

    public function getPositionWeightDef(): int { return $this->positionWeightDef; }
    public function setPositionWeightDef(int $v): static { $this->positionWeightDef = $v; return $this; }

    public function getPositionWeightMid(): int { return $this->positionWeightMid; }
    public function setPositionWeightMid(int $v): static { $this->positionWeightMid = $v; return $this; }

    public function getPositionWeightAtt(): int { return $this->positionWeightAtt; }
    public function setPositionWeightAtt(int $v): static { $this->positionWeightAtt = $v; return $this; }

    public function getCoachAgeMin(): int { return $this->coachAgeMin; }
    public function setCoachAgeMin(int $v): static { $this->coachAgeMin = $v; return $this; }

    public function getCoachAgeMax(): int { return $this->coachAgeMax; }
    public function setCoachAgeMax(int $v): static { $this->coachAgeMax = $v; return $this; }

    public function getCoachAbilityMin(): int { return $this->coachAbilityMin; }
    public function setCoachAbilityMin(int $v): static { $this->coachAbilityMin = $v; return $this; }

    public function getCoachAbilityMax(): int { return $this->coachAbilityMax; }
    public function setCoachAbilityMax(int $v): static { $this->coachAbilityMax = $v; return $this; }

    public function getScoutAgeMin(): int { return $this->scoutAgeMin; }
    public function setScoutAgeMin(int $v): static { $this->scoutAgeMin = $v; return $this; }

    public function getScoutAgeMax(): int { return $this->scoutAgeMax; }
    public function setScoutAgeMax(int $v): static { $this->scoutAgeMax = $v; return $this; }

    public function getScoutExperienceMin(): int { return $this->scoutExperienceMin; }
    public function setScoutExperienceMin(int $v): static { $this->scoutExperienceMin = $v; return $this; }

    public function getScoutExperienceMax(): int { return $this->scoutExperienceMax; }
    public function setScoutExperienceMax(int $v): static { $this->scoutExperienceMax = $v; return $this; }

    public function getScoutJudgementMin(): int { return $this->scoutJudgementMin; }
    public function setScoutJudgementMin(int $v): static { $this->scoutJudgementMin = $v; return $this; }

    public function getScoutJudgementMax(): int { return $this->scoutJudgementMax; }
    public function setScoutJudgementMax(int $v): static { $this->scoutJudgementMax = $v; return $this; }

    public function getAgentReputationMin(): int { return $this->agentReputationMin; }
    public function setAgentReputationMin(int $v): static { $this->agentReputationMin = $v; return $this; }

    public function getAgentReputationMax(): int { return $this->agentReputationMax; }
    public function setAgentReputationMax(int $v): static { $this->agentReputationMax = $v; return $this; }

    public function getAgentAgeMin(): int { return $this->agentAgeMin; }
    public function setAgentAgeMin(int $v): static { $this->agentAgeMin = $v; return $this; }

    public function getAgentAgeMax(): int { return $this->agentAgeMax; }
    public function setAgentAgeMax(int $v): static { $this->agentAgeMax = $v; return $this; }

    public function getPlayerPoolTarget(): int { return $this->playerPoolTarget; }
    public function setPlayerPoolTarget(int $v): static { $this->playerPoolTarget = $v; return $this; }

    public function getCoachPoolTarget(): int { return $this->coachPoolTarget; }
    public function setCoachPoolTarget(int $v): static { $this->coachPoolTarget = $v; return $this; }

    public function getScoutPoolTarget(): int { return $this->scoutPoolTarget; }
    public function setScoutPoolTarget(int $v): static { $this->scoutPoolTarget = $v; return $this; }

    public function getSponsorPoolTarget(): int { return $this->sponsorPoolTarget; }
    public function setSponsorPoolTarget(int $v): static { $this->sponsorPoolTarget = $v; return $this; }

    public function getInvestorPoolTarget(): int { return $this->investorPoolTarget; }
    public function setInvestorPoolTarget(int $v): static { $this->investorPoolTarget = $v; return $this; }
}
