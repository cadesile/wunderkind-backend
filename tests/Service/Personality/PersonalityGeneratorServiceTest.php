<?php
namespace App\Tests\Service\Personality;

use App\Entity\PersonalityProfile;
use App\Service\Personality\PersonalityGeneratorService;
use PHPUnit\Framework\TestCase;

class PersonalityGeneratorServiceTest extends TestCase
{
    private PersonalityGeneratorService $gen;

    protected function setUp(): void
    {
        $this->gen = new PersonalityGeneratorService();
    }

    public function testRollsAllEightTraits(): void
    {
        $traits = $this->gen->rollTraits(70);
        $this->assertSame(PersonalityGeneratorService::TRAITS, array_keys($traits));
    }

    public function testEveryTraitStaysInTheOneToTwentyScale(): void
    {
        foreach ([0, 1, 40, 75, 100] as $anchor) {
            for ($i = 0; $i < 200; $i++) {
                foreach ($this->gen->rollTraits($anchor) as $name => $value) {
                    $this->assertGreaterThanOrEqual(1, $value, $name);
                    $this->assertLessThanOrEqual(20, $value, $name);
                }
            }
        }
    }

    public function testTraitsSitInsideTheThirtyPointWindowBelowTheAnchor(): void
    {
        // Mirrors the player formula: window is [anchor-30, anchor] as a percentage of 20.
        $anchor = 80;
        $lo = max(1, (int) ceil(20.0 * (($anchor - 30) / 100.0)));
        $hi = (int) ceil(20.0 * ($anchor / 100.0));

        for ($i = 0; $i < 200; $i++) {
            foreach ($this->gen->rollTraits($anchor) as $name => $value) {
                $this->assertGreaterThanOrEqual($lo, $value, $name);
                $this->assertLessThanOrEqual($hi, $value, $name);
            }
        }
    }

    public function testHigherAnchorProducesHigherTraitsOnAverage(): void
    {
        $mean = function (int $anchor): float {
            $total = 0;
            for ($i = 0; $i < 300; $i++) {
                $total += array_sum($this->gen->rollTraits($anchor));
            }
            return $total / (300 * 8);
        };

        $this->assertGreaterThan($mean(40), $mean(90));
    }

    public function testAnchorIsClampedToZeroAndOneHundred(): void
    {
        foreach ([-50, 500] as $anchor) {
            foreach ($this->gen->rollTraits($anchor) as $value) {
                $this->assertGreaterThanOrEqual(1, $value);
                $this->assertLessThanOrEqual(20, $value);
            }
        }
    }

    public function testApplyWritesEveryTraitOntoTheProfile(): void
    {
        $profile = new PersonalityProfile();
        $this->assertTrue($profile->isDefault());

        $this->gen->apply($profile, 90);

        // Anchor 90 ⇒ window [12, 18]; a default profile (all 10) cannot survive.
        $this->assertFalse($profile->isDefault());
        foreach ($profile->toArray() as $name => $value) {
            $this->assertGreaterThanOrEqual(12, $value, $name);
            $this->assertLessThanOrEqual(18, $value, $name);
        }
    }
}
