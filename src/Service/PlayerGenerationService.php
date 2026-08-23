<?php
// src/Service/PlayerGenerationService.php
namespace App\Service;

use App\Dto\PlayerBlueprint;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Repository\PoolConfigRepository;
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

    public function generate(PlayerPosition $position, RecruitmentSource $source, ?string $nationality = null): Player
    {
        $blueprint = $this->buildAnchors($position, $source, $nationality);
        $blueprint = $this->buildAbilityTarget($blueprint);
        $blueprint = $this->buildPersonality($blueprint);
        $blueprint = $this->buildAttributes($blueprint);
        return $this->buildEntity($blueprint);
    }

    // ── Stub implementations (replaced in subsequent tasks) ──────────────────

    private function buildAnchors(PlayerPosition $position, RecruitmentSource $source, ?string $nationality): PlayerBlueprint
    {
        $nat = $nationality ?? $this->nameGenerator->getRandomNationality();
        ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

        $cfg       = $this->poolConfigRepo->getConfig();
        $age       = random_int($cfg->getPlayerAgeMin(), $cfg->getPlayerAgeMax());
        $potential = $this->bellCurveInt(
            $cfg->getPlayerPotentialMin(),
            $cfg->getPlayerPotentialMax(),
            $cfg->getPlayerPotentialMean(),
        );

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

        return new PlayerBlueprint(...array_replace((array) $bp, ['abilityTarget' => $abilityTarget, 'isProdigy' => $isProdigy]));
    }

    /**
     * Rolls the Personality Matrix anchored on the player's potential. Shares
     * PersonalityGeneratorService with Staff and Scout so the three entity types
     * cannot drift apart — they differ only in which stat anchors the window.
     */
    private function buildPersonality(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...array_replace(
            (array) $bp,
            $this->personalityGenerator->rollTraits($bp->potential),
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
        $sigma = ($max - $min) / 4.0;

        // Box-Muller: two independent uniform samples → one standard-normal value
        $u1 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $u2 = mt_rand(1, PHP_INT_MAX) / PHP_INT_MAX;
        $z  = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return max($min, min($max, (int) round($mean + $z * $sigma)));
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
