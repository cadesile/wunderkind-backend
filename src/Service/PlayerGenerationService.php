<?php
// src/Service/PlayerGenerationService.php
namespace App\Service;

use App\Dto\PlayerBlueprint;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Repository\PoolConfigRepository;
use App\Service\Personality\PersonalityContext;
use App\Service\Personality\PersonalityGeneratorService;

class PlayerGenerationService
{
    // Position-attribute fraction ranges: [minFraction, maxFraction] of the ability cap
    private const POSITION_ATTRIBUTE_FRACTIONS = [
        'GK'  => ['pace' => [0.0, 0.30], 'technical' => [0.30, 0.70], 'vision' => [0.60, 1.0]],
        'DEF' => ['pace' => [0.50, 1.0],  'technical' => [0.0,  0.40], 'vision' => [0.0,  0.30]],
        'MID' => ['pace' => [0.30, 0.70], 'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
        'ATT' => ['pace' => [0.40, 1.0],  'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
    ];

    public function __construct(
        private readonly NameGeneratorService  $nameGenerator,
        private readonly PoolConfigRepository  $poolConfigRepo,
        private readonly PersonalityGeneratorService $personalityGenerator = new PersonalityGeneratorService(),
    ) {}

    /**
     * @param array{min: int, max: int}|null $potentialRange Overrides PoolConfig's flat
     *        potential range when given — e.g. a world-pack tier's ability band, so
     *        higher tiers generate genuinely stronger players. Mean is the range's
     *        midpoint in that case rather than PoolConfig's own configured mean, since
     *        that mean may fall outside a narrower override range.
     * @param array{min: int, max: int}|null $ageRange Overrides PoolConfig's playerAgeMin/Max
     *        when given — NPC first-team squads pass PoolConfig::firstTeamAgeRange() so a
     *        senior side is never generated out of the pool's youth age band. Same
     *        optional-override shape as $potentialRange; null keeps the pool default, so
     *        existing callers are unaffected.
     */
    public function generate(PlayerPosition $position, RecruitmentSource $source, ?string $nationality = null, ?array $potentialRange = null, ?array $ageRange = null): Player
    {
        $blueprint = $this->buildAnchors($position, $source, $nationality, $potentialRange, $ageRange);
        $blueprint = $this->buildAbilityTarget($blueprint);
        $blueprint = $this->buildPersonality($blueprint);
        $blueprint = $this->buildAttributes($blueprint);
        return $this->buildEntity($blueprint);
    }

    // ── Stub implementations (replaced in subsequent tasks) ──────────────────

    /**
     * @param array{min: int, max: int}|null $potentialRange
     * @param array{min: int, max: int}|null $ageRange
     */
    private function buildAnchors(PlayerPosition $position, RecruitmentSource $source, ?string $nationality, ?array $potentialRange = null, ?array $ageRange = null): PlayerBlueprint
    {
        $nat = $nationality ?? $this->nameGenerator->getRandomNationality();
        ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

        $cfg           = $this->poolConfigRepo->getConfig();
        $ageMin        = $ageRange['min'] ?? $cfg->getPlayerAgeMin();
        $ageMax        = max($ageMin, $ageRange['max'] ?? $cfg->getPlayerAgeMax());
        $age           = random_int($ageMin, $ageMax);
        $potentialMin  = $potentialRange['min'] ?? $cfg->getPlayerPotentialMin();
        $potentialMax  = $potentialRange['max'] ?? $cfg->getPlayerPotentialMax();
        $potentialMean = $potentialRange !== null ? (int) round(($potentialMin + $potentialMax) / 2) : $cfg->getPlayerPotentialMean();
        $potential     = $this->bellCurveInt($potentialMin, $potentialMax, $potentialMean);

        // Height: base range 163–203 cm; GKs receive an additional 3–8 cm upward bias
        $baseHeight = random_int(163, 203);
        $height     = ($position === PlayerPosition::GOALKEEPER)
            ? min(211, $baseHeight + random_int(3, 8))
            : $baseHeight;

        // Weight: base 60–82 kg, correlated upward with height (up to +15 kg)
        $baseWeight  = random_int(60, 82);
        $heightBonus = (int) floor($this->normalise($height, 163, 211) * 15);
        $weight      = min(97, $baseWeight + $heightBonus);

        // Birthday: uniformly random within the 12-month window that yields
        // exactly $age today. Capped at 364 days so a leap year can't tip the
        // player into the next year of age.
        $daysSinceBirthday = random_int(0, 364);
        $dob = (new \DateTimeImmutable('today'))
            ->modify("-{$age} years")
            ->modify("-{$daysSinceBirthday} days");

        return new PlayerBlueprint(
            firstName:   $firstName,
            lastName:    $lastName,
            nationality: $nat,
            age:         $age,
            dateOfBirth: $dob,
            height:      $height,
            weight:      $weight,
            position:    $position,
            potential:   $potential,
            source:      $source,
        );
    }

    /**
     * How much of a player's potential is already realised at generation time.
     *
     * abilityTarget x potential becomes the cap every attribute is drawn under
     * (buildAttributes()), and currentAbility is the mean of those attributes — so this
     * fraction is an upper bound on the realised ability/potential ratio, never a target
     * the result can overshoot.
     *
     * Players below the configured youth ages are held to a ceiling from PoolConfig
     * (abilityCeilingFractionForAge()), because an academy intake arriving at ~40% of
     * potential has most of its development already spent: the point of signing a 13 year
     * old is the decade of growth ahead of him, and that only exists if he starts low.
     * Within a band the draw still spans half the ceiling to the ceiling, so an intake is
     * a spread of prospects rather than a row of identical ones.
     *
     * The ceiling is applied as a final min() over whichever branch ran, prodigy included,
     * so the configured rule cannot be bypassed by a branch added later. A prodigy young
     * enough to be capped keeps the flag and simply lands at the top of his band — the
     * standout of his age group rather than a first-team player in waiting.
     */
    private function buildAbilityTarget(PlayerBlueprint $bp): PlayerBlueprint
    {
        $age       = $bp->age;
        $isProdigy = false;

        if ($age >= 18 && $age <= 23 && random_int(1, 100) <= 5) {
            $isProdigy     = true;
            $abilityTarget = $this->randFloat(0.85, 0.95);
        } elseif ($age <= 21) {
            $abilityTarget = $this->randFloat(0.30, 0.60);
        } elseif ($age <= 25) {
            $abilityTarget = $this->randFloat(0.60, 0.85);
        } else {
            $abilityTarget = $this->randFloat(0.85, 1.00);
        }

        $ceiling = $this->poolConfigRepo->getConfig()->abilityCeilingFractionForAge($age);
        if ($ceiling !== null) {
            $abilityTarget = min($abilityTarget, $this->randFloat($ceiling / 2, $ceiling));
        }

        return new PlayerBlueprint(...array_replace((array) $bp, ['abilityTarget' => $abilityTarget, 'isProdigy' => $isProdigy]));
    }

    /**
     * Rolls the Personality Matrix. Shares PersonalityGeneratorService with Staff
     * and Scout so the three entity types cannot drift apart — they differ only in
     * the context they pass. Personality is independent of potential: how good a
     * player might become says nothing about what he is like.
     */
    private function buildPersonality(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...array_replace(
            (array) $bp,
            $this->personalityGenerator->rollTraits(PersonalityContext::forPlayer($bp->age)),
        ));
    }

    private function buildAttributes(PlayerBlueprint $bp): PlayerBlueprint
    {
        $cap    = max(1, (int) floor($bp->abilityTarget * $bp->potential));
        $posKey = $bp->position->value;
        $fracs  = self::POSITION_ATTRIBUTE_FRACTIONS[$posKey] ?? self::POSITION_ATTRIBUTE_FRACTIONS['MID'];

        $pace      = $this->randInCap($cap, $fracs['pace'][0], $fracs['pace'][1]);
        $technical = $this->randInCap($cap, $fracs['technical'][0], $fracs['technical'][1]);
        $vision    = $this->randInCap($cap, $fracs['vision'][0], $fracs['vision'][1]);

        // Power: physical anchor (height + weight) + personality uplift (determination + professionalism)
        $physBase   = ($this->normalise($bp->height, 163, 211) + $this->normalise($bp->weight, 60, 97)) / 2.0;
        $physMod    = ($bp->determination + $bp->professionalism) / 40.0;
        $power      = min($cap, max(1, (int) ceil(($physBase * 0.6 + $physMod * 0.4) * 100)));

        // Stamina: lean/fit bias + mental fortitude (determination + pressure + temperament)
        $stamPhys   = ((1.0 - $this->normalise($bp->weight, 60, 97)) * 0.5 + $this->normalise($bp->height, 163, 211) * 0.5);
        $stamMod    = ($bp->determination + $bp->pressure + $bp->temperament) / 60.0;
        $stamina    = min($cap, max(1, (int) ceil(($stamPhys * 0.5 + $stamMod * 0.5) * 100)));

        // Heart: loyalty + determination + pressure scaled to 100, capped
        $heart = min($cap, max(1, (int) round(($bp->loyalty + $bp->determination + $bp->pressure) / 60.0 * 100)));

        $currentAbility = min($bp->potential, (int) round(($pace + $technical + $vision + $power + $stamina + $heart) / 6));

        return new PlayerBlueprint(...array_replace((array) $bp, [
            'pace'           => $pace,
            'technical'      => $technical,
            'vision'         => $vision,
            'power'          => $power,
            'stamina'        => $stamina,
            'heart'          => $heart,
            'currentAbility' => $currentAbility,
        ]));
    }

    // ── Fully implemented: pure mapping, no randomness ───────────────────────

    private function buildEntity(PlayerBlueprint $bp): Player
    {
        $player = new Player(
            firstName:         $bp->firstName,
            lastName:          $bp->lastName,
            dateOfBirth:       $bp->dateOfBirth,
            nationality:       $bp->nationality,
            position:          $bp->position,
            recruitmentSource: $bp->source,
            potential:         $bp->potential,
            currentAbility:    $bp->currentAbility,
        );

        $player->setHeight($bp->height);
        $player->setWeight($bp->weight);
        $player->setPace($bp->pace);
        $player->setTechnical($bp->technical);
        $player->setVision($bp->vision);
        $player->setPower($bp->power);
        $player->setStamina($bp->stamina);
        $player->setHeart($bp->heart);

        $p = $player->getPersonality();
        $p->setDetermination($bp->determination);
        $p->setProfessionalism($bp->professionalism);
        $p->setAmbition($bp->ambition);
        $p->setLoyalty($bp->loyalty);
        $p->setAdaptability($bp->adaptability);
        $p->setPressure($bp->pressure);
        $p->setTemperament($bp->temperament);
        $p->setConsistency($bp->consistency);

        return $player;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Box-Muller normal distribution clamped to [min, max], centred on mean.
     * σ is set so ~95% of raw draws fall within the configured range.
     */
    private function bellCurveInt(int $min, int $max, int $mean): int
    {
        return $this->personalityGenerator->gaussianInt($mean, ($max - $min) / 4.0, $min, $max);
    }

    private function normalise(int $value, int $min, int $max): float
    {
        return ($value - $min) / ($max - $min);
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function randInCap(int $cap, float $minFrac, float $maxFrac): int
    {
        $lo = max(1, (int) floor($cap * $minFrac));
        $hi = max($lo, (int) ceil($cap * $maxFrac));
        return random_int($lo, $hi);
    }
}
