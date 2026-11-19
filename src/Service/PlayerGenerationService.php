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
    /**
     * How much of his potential a player of this age has already realised, as
     * [minFraction, maxFraction] — the maturation curve, keyed by the age it applies AT OR
     * BELOW. Ordered youngest first; the first matching band wins, so the last entry is the
     * open-ended "established professional" band and its key is the sentinel PHP_INT_MAX.
     *
     * A TABLE RATHER THAN AN if/elseif CHAIN because it now has TWO readers:
     * buildAbilityTarget() draws inside the band, and expectedAbilityTargetForAge() takes its
     * midpoint so EconomyScaleResolver can calibrate the wage scale against the ability a
     * player of a given age ACTUALLY has. The calibration was previously built from the
     * authored band — which is a POTENTIAL range — so the scale's denominator and the player's
     * numerator measured different quantities and every wage came out at roughly a third of
     * the budget it was nominally calibrated against. Re-deriving this curve inside the
     * economy service would have reinstated the same defect one level up: retune a band here
     * and the scale must move with it.
     */
    private const ABILITY_TARGET_BANDS = [
        21            => [0.30, 0.60],
        25            => [0.60, 0.85],
        \PHP_INT_MAX  => [0.85, 1.00],
    ];

    /** The standout of an age group: a rare early developer, inside the age window below. */
    private const PRODIGY_BAND        = [0.85, 0.95];
    private const PRODIGY_AGE_MIN     = 18;
    private const PRODIGY_AGE_MAX     = 23;
    private const PRODIGY_PERCENT     = 5;

    /** Physique draw bounds, shared with the deterministic expectation below. */
    private const HEIGHT_RANGE        = [163, 203];
    private const HEIGHT_SPAN         = [163, 211];
    private const GK_HEIGHT_BONUS     = [3, 8];
    private const WEIGHT_RANGE        = [60, 82];
    private const WEIGHT_SPAN         = [60, 97];
    private const WEIGHT_HEIGHT_BONUS = 15;

    // Position-attribute fraction ranges: [minFraction, maxFraction] of the ability cap
    private const POSITION_ATTRIBUTE_FRACTIONS = [
        'GK'  => ['pace' => [0.0, 0.30], 'technical' => [0.30, 0.70], 'vision' => [0.60, 1.0]],
        'DEF' => ['pace' => [0.50, 1.0],  'technical' => [0.0,  0.40], 'vision' => [0.0,  0.30]],
        'MID' => ['pace' => [0.30, 0.70], 'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
        'ATT' => ['pace' => [0.40, 1.0],  'technical' => [0.50, 1.0],  'vision' => [0.50, 1.0]],
    ];

    /**
     * Memoised per age: `expectedCurrentAbility()` is called once per (potential, age,
     * position) cell of the economy's reference squad — a few thousand times for a single
     * tier — and the trait expectation depends on nothing but the age. Safe to cache because
     * `expectedTraits()` is pure.
     *
     * @var array<int, array<string, float>>
     */
    private array $expectedTraitsByAge = [];

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
        $baseHeight = random_int(...self::HEIGHT_RANGE);
        $height     = ($position === PlayerPosition::GOALKEEPER)
            ? min(self::HEIGHT_SPAN[1], $baseHeight + random_int(...self::GK_HEIGHT_BONUS))
            : $baseHeight;

        // Weight: base 60–82 kg, correlated upward with height (up to +15 kg)
        $weight = (int) $this->weightFor(random_int(...self::WEIGHT_RANGE), $height);

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
        $isProdigy = $age >= self::PRODIGY_AGE_MIN
            && $age <= self::PRODIGY_AGE_MAX
            && random_int(1, 100) <= self::PRODIGY_PERCENT;

        $band          = $isProdigy ? self::PRODIGY_BAND : self::maturationBandForAge($age);
        $abilityTarget = $this->randFloat($band[0], $band[1]);

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

        $traits  = ['determination' => $bp->determination, 'professionalism' => $bp->professionalism,
                    'loyalty'       => $bp->loyalty,       'pressure'        => $bp->pressure,
                    'temperament'   => $bp->temperament];
        $power   = (int) $this->powerFor($cap, $bp->height, $bp->weight, $traits);
        $stamina = (int) $this->staminaFor($cap, $bp->height, $bp->weight, $traits);
        $heart   = (int) $this->heartFor($cap, $traits);

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

    private function normalise(float $value, int $min, int $max): float
    {
        return ($value - $min) / ($max - $min);
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function randInCap(int $cap, float $minFrac, float $maxFrac): int
    {
        [$lo, $hi] = self::capBounds($cap, $minFrac, $maxFrac);

        return random_int($lo, $hi);
    }

    /**
     * The inclusive integer bounds one attribute may be drawn between, given the ability cap
     * and the position's fraction range.
     *
     * EXTRACTED SO THE DRAW AND ITS EXPECTATION CANNOT DISAGREE. `max(1, floor(...))` and
     * `max($lo, ceil(...))` are not decoration: at the bottom of the pyramid the cap is a
     * single-digit integer, so the floor on the low end and the ceil on the high end move the
     * expected attribute by tens of percent. An expectation computed from the raw fractions
     * instead of these bounds is wrong exactly where the wage scale is most sensitive.
     *
     * @return array{0: int, 1: int}
     */
    private static function capBounds(int $cap, float $minFrac, float $maxFrac): array
    {
        $lo = max(1, (int) floor($cap * $minFrac));
        $hi = max($lo, (int) ceil($cap * $maxFrac));

        return [$lo, $hi];
    }

    /** The maturation band that applies at $age — first matching key wins. @return array{0: float, 1: float} */
    private static function maturationBandForAge(int $age): array
    {
        foreach (self::ABILITY_TARGET_BANDS as $upperAge => $band) {
            if ($age <= $upperAge) {
                return $band;
            }
        }

        return [0.85, 1.00];
    }

    /** Weight follows height: the base draw plus up to +15 kg, capped. Shared with the expectation. */
    private function weightFor(float $baseWeight, float $height): float
    {
        $bonus = floor($this->normalise($height, ...self::HEIGHT_SPAN) * self::WEIGHT_HEIGHT_BONUS);

        return min((float) self::WEIGHT_SPAN[1], $baseWeight + $bonus);
    }

    /** Power: physical anchor (height + weight) + personality uplift (determination + professionalism). */
    private function powerFor(int $cap, float $height, float $weight, array $traits): float
    {
        $physBase = ($this->normalise($height, ...self::HEIGHT_SPAN) + $this->normalise($weight, ...self::WEIGHT_SPAN)) / 2.0;
        $physMod  = ($traits['determination'] + $traits['professionalism']) / 40.0;

        return min((float) $cap, max(1.0, ceil(($physBase * 0.6 + $physMod * 0.4) * 100)));
    }

    /** Stamina: lean/fit bias + mental fortitude (determination + pressure + temperament). */
    private function staminaFor(int $cap, float $height, float $weight, array $traits): float
    {
        $stamPhys = (1.0 - $this->normalise($weight, ...self::WEIGHT_SPAN)) * 0.5
            + $this->normalise($height, ...self::HEIGHT_SPAN) * 0.5;
        $stamMod  = ($traits['determination'] + $traits['pressure'] + $traits['temperament']) / 60.0;

        return min((float) $cap, max(1.0, ceil(($stamPhys * 0.5 + $stamMod * 0.5) * 100)));
    }

    /** Heart: loyalty + determination + pressure scaled to 100, capped. */
    private function heartFor(int $cap, array $traits): float
    {
        return min((float) $cap, max(1.0, round(($traits['loyalty'] + $traits['determination'] + $traits['pressure']) / 60.0 * 100)));
    }

    // ── The deterministic expectation the economy calibrates against ───────────────────

    /**
     * What fraction of his potential a player of this age has realised ON AVERAGE. No RNG.
     *
     * Band midpoints, because buildAbilityTarget() draws uniformly inside the band. The
     * prodigy branch is a 5% coin flip and a deterministic figure cannot contain a coin flip,
     * so it is WEIGHTED rather than excluded: 5% of the prodigy midpoint plus 95% of the
     * ordinary one, inside the prodigy age window. Excluding it would understate ages 18-23 by
     * about 2% of potential, and the branch is a real part of the population the wage scale
     * has to pay for.
     *
     * The youth ceiling is applied the same way the generator applies it — as a final min()
     * over whichever branch ran, prodigy included — against the midpoint of the ceiling draw's
     * own `ceiling/2 .. ceiling` range. With the shipped first-team floor of 19 and a youth
     * ceiling band of "under 20", this binds on exactly one age in a senior squad, and it
     * binds hard: a 19 year old lands near 15% of his potential, not 45%.
     */
    public function expectedAbilityTargetForAge(int $age): float
    {
        $ordinary = self::midpointOf(self::maturationBandForAge($age));

        $target = ($age >= self::PRODIGY_AGE_MIN && $age <= self::PRODIGY_AGE_MAX)
            ? (self::PRODIGY_PERCENT / 100) * self::midpointOf(self::PRODIGY_BAND)
                + (1 - self::PRODIGY_PERCENT / 100) * $ordinary
            : $ordinary;

        $ceiling = $this->poolConfigRepo->getConfig()->abilityCeilingFractionForAge($age);

        return $ceiling !== null
            ? min($target, self::midpointOf([$ceiling / 2, $ceiling]))
            : $target;
    }

    /**
     * The current ability a player of this potential, age and position would have ON AVERAGE.
     *
     * THE ONE THING `EconomyScaleResolver` NEEDS, AND THE REASON IT LIVES HERE. A wage is
     * `S_tier x currentAbility^k`, and S_tier is calibrated so that a reference squad costs
     * the configured share of a tier's turnover. The reference squad used to be built from the
     * authored ability band, which is a POTENTIAL range — so the calibration priced players
     * who do not exist and real bills landed near a third of the budget. This answers the
     * question the calibration actually has to ask, and it answers it from the generator's own
     * tables rather than from a copy of them.
     *
     * It walks the same four steps buildAttributes() walks, through the same helpers, with
     * every draw replaced by its expectation: the maturation target becomes a band midpoint,
     * the three position attributes become the midpoint of `capBounds()`, the physique becomes
     * the midpoint of its own draw ranges through `weightFor()`, and the personality becomes
     * `PersonalityGeneratorService::expectedTraits()`. Nothing is tuned and nothing is fitted:
     * change a position fraction, a maturation band, a youth ceiling or a mould weight and
     * this moves with it.
     *
     * ⚠️ WHAT IT IS NOT. It is the expected ABILITY of a representative player, not the
     * expected COST of a real one. `ability^k` is convex, so `E[ability]^k < E[ability^k]` and
     * a squad of real players costs somewhat more than a squad of expected ones — most
     * visibly where the band is a handful of integers. That residual is a property of pricing
     * a representative squad and is deliberately not corrected for here: correcting it would
     * need the variance of the draw, which is a different model from the one the reference
     * squad is defined to be.
     */
    public function expectedCurrentAbility(int $potential, int $age, PlayerPosition $position): float
    {
        if ($potential <= 0) {
            return 0.0;
        }

        $cap   = max(1, (int) floor($this->expectedAbilityTargetForAge($age) * $potential));
        $fracs = self::POSITION_ATTRIBUTE_FRACTIONS[$position->value] ?? self::POSITION_ATTRIBUTE_FRACTIONS['MID'];

        $pace      = self::midpointOf(self::capBounds($cap, $fracs['pace'][0], $fracs['pace'][1]));
        $technical = self::midpointOf(self::capBounds($cap, $fracs['technical'][0], $fracs['technical'][1]));
        $vision    = self::midpointOf(self::capBounds($cap, $fracs['vision'][0], $fracs['vision'][1]));

        $height = self::midpointOf(self::HEIGHT_RANGE)
            + ($position === PlayerPosition::GOALKEEPER ? self::midpointOf(self::GK_HEIGHT_BONUS) : 0.0);
        $height = min((float) self::HEIGHT_SPAN[1], $height);
        $weight = $this->weightFor(self::midpointOf(self::WEIGHT_RANGE), $height);

        $traits  = $this->expectedTraitsByAge[$age]
            ??= $this->personalityGenerator->expectedTraits(PersonalityContext::forPlayer($age));
        $power   = $this->powerFor($cap, $height, $weight, $traits);
        $stamina = $this->staminaFor($cap, $height, $weight, $traits);
        $heart   = $this->heartFor($cap, $traits);

        // The same final clamp buildAttributes() applies: nobody is better than his ceiling.
        return min(
            (float) $potential,
            ($pace + $technical + $vision + $power + $stamina + $heart) / 6,
        );
    }

    /** @param array{0: int|float, 1: int|float} $range */
    private static function midpointOf(array $range): float
    {
        return ($range[0] + $range[1]) / 2;
    }
}
