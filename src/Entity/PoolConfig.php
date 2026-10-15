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
    #[ORM\Column(type: 'integer', options: ['default' => 12])]
    private int $playerAgeMin = 12;

    /** Maximum age for newly generated players. Default: 13 */
    #[ORM\Column(type: 'integer', options: ['default' => 13])]
    private int $playerAgeMax = 13;

    /**
     * Age-banded ceilings on how much of a generated player's potential is already
     * realised, as a percentage of potential — the "a 13-year-old should still have
     * everything in front of him" rule.
     *
     * Two bands, each an "under this age" threshold plus the percentage ceiling that
     * applies below it. The junior band is checked first, so with the defaults a 14
     * year old is capped at 10% and an 18 year old at 20%, while a 20 year old is
     * unaffected and keeps the ordinary age curve in
     * PlayerGenerationService::buildAbilityTarget().
     *
     * A ceiling caps the ability TARGET, which in turn caps every attribute, and
     * currentAbility is the mean of those attributes — so the realised ratio always
     * lands at or below the ceiling, never above it.
     *
     * Editable from the Player Pool admin screen rather than hardcoded so the youth
     * development curve can be retuned without a deploy.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 16])]
    private int $playerJuniorAbilityCeilingAge = 16;

    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $playerJuniorAbilityCeilingPercent = 10;

    #[ORM\Column(type: 'integer', options: ['default' => 20])]
    private int $playerYouthAbilityCeilingAge = 20;

    #[ORM\Column(type: 'integer', options: ['default' => 20])]
    private int $playerYouthAbilityCeilingPercent = 20;

    /** Lower bound of the bell-curve potential range. Default: 40 */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $playerPotentialMin = 40;

    /** Upper bound of the bell-curve potential range. Default: 80 */
    #[ORM\Column(type: 'integer', options: ['default' => 80])]
    private int $playerPotentialMax = 80;

    /**
     * Mean for the bell-curve potential distribution.
     * Actual value is (bellCurveRaw + mean) / 2, biasing toward this centre.
     * Default: 60
     */
    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $playerPotentialMean = 60;

    /** Minimum starting current ability. Default: 3 */
    #[ORM\Column(type: 'integer', options: ['default' => 3])]
    private int $playerAbilityMin = 3;

    /** Maximum starting current ability. Default: 10 */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $playerAbilityMax = 10;

    /** Minimum total attribute budget distributed across the 6 stats. Default: 6 */
    #[ORM\Column(type: 'integer', options: ['default' => 6])]
    private int $playerAttributeBudgetMin = 6;

    /** Maximum total attribute budget distributed across the 6 stats. Default: 20 */
    #[ORM\Column(type: 'integer', options: ['default' => 20])]
    private int $playerAttributeBudgetMax = 20;

    /** Percentage chance (0–100) that a generated player has an agent. Default: 40 */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $playerAgentChancePercent = 40;

    /** Minimum height in cm. Default: 145 */
    #[ORM\Column(type: 'integer', options: ['default' => 145])]
    private int $playerHeightMin = 145;

    /** Maximum height in cm. Default: 160 */
    #[ORM\Column(type: 'integer', options: ['default' => 160])]
    private int $playerHeightMax = 160;

    /** Minimum weight in kg. Default: 38 */
    #[ORM\Column(type: 'integer', options: ['default' => 38])]
    private int $playerWeightMin = 38;

    /** Maximum weight in kg. Default: 55 */
    #[ORM\Column(type: 'integer', options: ['default' => 55])]
    private int $playerWeightMax = 55;

    /** Min value applied to all 8 personality traits on generation. Default: 30 */
    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $personalityTraitMin = 30;

    /** Max value applied to all 8 personality traits on generation. Default: 70 */
    #[ORM\Column(type: 'integer', options: ['default' => 70])]
    private int $personalityTraitMax = 70;

    // ── Position Weighting ─────────────────────────────────────────────────

    /**
     * Relative weight for Goalkeeper position.
     * Position is rolled against the sum of all four weights.
     * Default: 8 (8% of 100-total default)
     */
    #[ORM\Column(type: 'integer', options: ['default' => 8])]
    private int $positionWeightGk = 8;

    /** Relative weight for Defender position. Default: 30 */
    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $positionWeightDef = 30;

    /** Relative weight for Midfielder position. Default: 38 */
    #[ORM\Column(type: 'integer', options: ['default' => 38])]
    private int $positionWeightMid = 38;

    /** Relative weight for Attacker position. Default: 24 */
    #[ORM\Column(type: 'integer', options: ['default' => 24])]
    private int $positionWeightAtt = 24;

    // ── Coach Generation ───────────────────────────────────────────────────

    /** Minimum age for generated coaches. Default: 28 */
    #[ORM\Column(type: 'integer', options: ['default' => 28])]
    private int $coachAgeMin = 28;

    /** Maximum age for generated coaches. Default: 60 */
    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $coachAgeMax = 60;

    /** Minimum coaching ability on generation. Default: 40 */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $coachAbilityMin = 40;

    /** Maximum coaching ability on generation. Default: 75 */
    #[ORM\Column(type: 'integer', options: ['default' => 75])]
    private int $coachAbilityMax = 75;

    // ── Scout Generation ───────────────────────────────────────────────────

    /** Minimum age for generated scouts. Default: 28 */
    #[ORM\Column(type: 'integer', options: ['default' => 28])]
    private int $scoutAgeMin = 28;

    /** Maximum age for generated scouts. Default: 40 */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $scoutAgeMax = 40;

    /** Minimum experience score (0–100) for generated scouts. Default: 10 */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $scoutExperienceMin = 10;

    /** Maximum experience score (0–100) for generated scouts. Default: 80 */
    #[ORM\Column(type: 'integer', options: ['default' => 80])]
    private int $scoutExperienceMax = 80;

    /** Min value for all 5 scout judgement categories. Default: 40 */
    #[ORM\Column(type: 'integer', options: ['default' => 40])]
    private int $scoutJudgementMin = 40;

    /** Max value for all 5 scout judgement categories. Default: 80 */
    #[ORM\Column(type: 'integer', options: ['default' => 80])]
    private int $scoutJudgementMax = 80;

    // ── Agent Generation ───────────────────────────────────────────────────

    /** Minimum reputation for generated agents. Default: 30 */
    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $agentReputationMin = 30;

    /** Maximum reputation for generated agents. Default: 70 */
    #[ORM\Column(type: 'integer', options: ['default' => 70])]
    private int $agentReputationMax = 70;

    /** Minimum age for generated agents. Default: 30 */
    #[ORM\Column(type: 'integer', options: ['default' => 30])]
    private int $agentAgeMin = 30;

    /** Maximum age for generated agents. Default: 60 */
    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $agentAgeMax = 60;

    // ── Pool Replenishment Targets ─────────────────────────────────────────

    /**
     * Minimum unassigned players before auto-replenishment triggers.
     * Also used as the batch size when generating players manually. Default: 50
     */
    #[ORM\Column(type: 'integer', options: ['default' => 50])]
    private int $playerPoolTarget = 50;

    /** Minimum unassigned coaches before auto-replenishment. Batch size for manual generate. Default: 10 */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $coachPoolTarget = 10;

    /** Minimum unassigned managers before auto-replenishment. Default: 5 */
    #[ORM\Column(type: 'integer')]
    private int $managerPoolTarget = 5;

    /** Minimum unassigned directors of football before auto-replenishment. Default: 2 */
    #[ORM\Column(type: 'integer')]
    private int $directorOfFootballPoolTarget = 2;

    /** Minimum unassigned facility managers before auto-replenishment. Default: 3 */
    #[ORM\Column(type: 'integer')]
    private int $facilityManagerPoolTarget = 3;

    /** Minimum unassigned chairmen before auto-replenishment. Default: 2 */
    #[ORM\Column(type: 'integer')]
    private int $chairmanPoolTarget = 2;

    /** Minimum scouts before auto-replenishment. Batch size for manual generate. Default: 5 */
    #[ORM\Column(type: 'integer', options: ['default' => 5])]
    private int $scoutPoolTarget = 5;

    /** Minimum unassigned sponsors before auto-replenishment. Default: 10 */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $sponsorPoolTarget = 10;

    /** Minimum unassigned investors before auto-replenishment. Default: 5 */
    #[ORM\Column(type: 'integer', options: ['default' => 5])]
    private int $investorPoolTarget = 5;

    /** Minimum agents before auto-replenishment. Batch size for manual generate. Default: 100 */
    #[ORM\Column(type: 'integer')]
    private int $agentPoolTarget = 100;

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getPlayerAgeMin(): int { return $this->playerAgeMin; }
    public function setPlayerAgeMin(int $v): static { $this->playerAgeMin = $v; return $this; }

    public function getPlayerAgeMax(): int { return $this->playerAgeMax; }
    public function setPlayerAgeMax(int $v): static { $this->playerAgeMax = $v; return $this; }

    public function getPlayerJuniorAbilityCeilingAge(): int { return $this->playerJuniorAbilityCeilingAge; }
    public function setPlayerJuniorAbilityCeilingAge(int $v): static { $this->playerJuniorAbilityCeilingAge = max(0, $v); return $this; }

    public function getPlayerJuniorAbilityCeilingPercent(): int { return $this->playerJuniorAbilityCeilingPercent; }
    public function setPlayerJuniorAbilityCeilingPercent(int $v): static { $this->playerJuniorAbilityCeilingPercent = max(1, min(100, $v)); return $this; }

    public function getPlayerYouthAbilityCeilingAge(): int { return $this->playerYouthAbilityCeilingAge; }
    public function setPlayerYouthAbilityCeilingAge(int $v): static { $this->playerYouthAbilityCeilingAge = max(0, $v); return $this; }

    public function getPlayerYouthAbilityCeilingPercent(): int { return $this->playerYouthAbilityCeilingPercent; }
    public function setPlayerYouthAbilityCeilingPercent(int $v): static { $this->playerYouthAbilityCeilingPercent = max(1, min(100, $v)); return $this; }

    /**
     * The ceiling that applies at $age as a 0..1 fraction, or null when the player is
     * old enough that no youth ceiling applies.
     *
     * Junior is tested first so the bands can't invert into a gap: if someone sets the
     * junior threshold above the youth one, juniors stay on the stricter junior ceiling
     * rather than falling through to a looser band.
     */
    public function abilityCeilingFractionForAge(int $age): ?float
    {
        if ($age < $this->playerJuniorAbilityCeilingAge) {
            return $this->playerJuniorAbilityCeilingPercent / 100;
        }

        if ($age < $this->playerYouthAbilityCeilingAge) {
            return $this->playerYouthAbilityCeilingPercent / 100;
        }

        return null;
    }

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


    public function getManagerPoolTarget(): int { return $this->managerPoolTarget; }
    public function setManagerPoolTarget(int $v): static { $this->managerPoolTarget = $v; return $this; }

    public function getDirectorOfFootballPoolTarget(): int { return $this->directorOfFootballPoolTarget; }
    public function setDirectorOfFootballPoolTarget(int $v): static { $this->directorOfFootballPoolTarget = $v; return $this; }

    public function getFacilityManagerPoolTarget(): int { return $this->facilityManagerPoolTarget; }
    public function setFacilityManagerPoolTarget(int $v): static { $this->facilityManagerPoolTarget = $v; return $this; }

    public function getChairmanPoolTarget(): int { return $this->chairmanPoolTarget; }
    public function setChairmanPoolTarget(int $v): static { $this->chairmanPoolTarget = $v; return $this; }

    public function getScoutPoolTarget(): int { return $this->scoutPoolTarget; }
    public function setScoutPoolTarget(int $v): static { $this->scoutPoolTarget = $v; return $this; }

    public function getSponsorPoolTarget(): int { return $this->sponsorPoolTarget; }
    public function setSponsorPoolTarget(int $v): static { $this->sponsorPoolTarget = $v; return $this; }

    public function getInvestorPoolTarget(): int { return $this->investorPoolTarget; }
    public function setInvestorPoolTarget(int $v): static { $this->investorPoolTarget = $v; return $this; }

    public function getAgentPoolTarget(): int { return $this->agentPoolTarget; }
    public function setAgentPoolTarget(int $v): static { $this->agentPoolTarget = $v; return $this; }

}
