<?php
// src/Service/PlayerGenerationService.php
namespace App\Service;

use App\Dto\PlayerBlueprint;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;

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
        private readonly NameGeneratorService $nameGenerator,
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

        $age       = random_int(16, 33);
        $potential = random_int(1, 100);

        // Height: base range 163–203 cm; GKs receive an additional 3–8 cm upward bias
        $baseHeight = random_int(163, 203);
        $height     = ($position === PlayerPosition::GOALKEEPER)
            ? min(211, $baseHeight + random_int(3, 8))
            : $baseHeight;

        // Weight: base 60–82 kg, correlated upward with height (up to +15 kg)
        $baseWeight  = random_int(60, 82);
        $heightBonus = (int) floor($this->normalise($height, 163, 211) * 15);
        $weight      = min(97, $baseWeight + $heightBonus);

        $year = (int) date('Y') - $age;
        $dob  = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, random_int(1, 12), random_int(1, 28)));

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
        return new PlayerBlueprint(...array_replace((array) $bp, ['abilityTarget' => 0.5, 'isProdigy' => false]));
    }

    private function buildPersonality(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...array_replace((array) $bp, [
            'determination'  => 1,
            'professionalism' => 1,
            'ambition'        => 1,
            'loyalty'         => 1,
            'adaptability'    => 1,
            'pressure'        => 1,
            'temperament'     => 1,
            'consistency'     => 1,
        ]));
    }

    private function buildAttributes(PlayerBlueprint $bp): PlayerBlueprint
    {
        return new PlayerBlueprint(...array_replace((array) $bp, [
            'pace'           => 1,
            'technical'      => 1,
            'vision'         => 1,
            'power'          => 1,
            'stamina'        => 1,
            'heart'          => 1,
            'currentAbility' => 1,
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
            club:              null,
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

    private function normalise(int $value, int $min, int $max): float
    {
        return ($value - $min) / ($max - $min);
    }

    private function randFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function randTrait(float $minPct, float $maxPct): int
    {
        $pct = $this->randFloat($minPct, $maxPct);
        return max(1, min(20, (int) ceil(20.0 * $pct)));
    }

    private function randInCap(int $cap, float $minFrac, float $maxFrac): int
    {
        $lo = max(1, (int) floor($cap * $minFrac));
        $hi = max($lo, (int) ceil($cap * $maxFrac));
        return random_int($lo, $hi);
    }
}
