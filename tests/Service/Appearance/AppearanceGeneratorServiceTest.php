<?php
namespace App\Tests\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

class AppearanceGeneratorServiceTest extends TestCase
{
    private AppearanceGeneratorService $svc;

    protected function setUp(): void
    {
        $this->svc = new AppearanceGeneratorService();
    }

    public function testDeterministic(): void
    {
        $a = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18);
        $b = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18);
        $this->assertSame($a, $b);
    }

    public function testDifferentIdsDiffer(): void
    {
        $a = $this->svc->generate('id-one', AppearanceRole::PLAYER, 18);
        $b = $this->svc->generate('id-two', AppearanceRole::PLAYER, 18);
        $this->assertNotSame($a, $b);
    }

    public function testShapeAndKeys(): void
    {
        $a = $this->svc->generate('shape-id', AppearanceRole::PLAYER, 20);
        $this->assertSame(
            ['skinTone','hairStyle','hairColor','accessory','kitTrim','facialHair','faceShape','eyeShape','noseType','jerseyVariant'],
            array_keys($a),
        );
        $this->assertSame('oval', $a['faceShape']);
        $this->assertSame('narrow', $a['eyeShape']);
        $this->assertIsInt($a['jerseyVariant']);
        $this->assertGreaterThanOrEqual(1, $a['jerseyVariant']);
        $this->assertLessThanOrEqual(3, $a['jerseyVariant']);
    }

    public function testPlayerNeverHasFacialHairOrStaffAccessories(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $a = $this->svc->generate("player-$i", AppearanceRole::PLAYER, 22);
            $this->assertSame('none', $a['facialHair']);
            $this->assertNotContains($a['accessory'], ['whistle', 'headset', 'beanie']);
        }
    }

    public function testValuesAreInAllowedSets(): void
    {
        $skins = ['#f5dcc8','#e8c49a','#dfaa80','#c47d4a','#8b4c1e','#5c2d0a'];
        $playerTrims = ['#f5c842','#e8852a','#3a8fd4','#d94040','#2eab5a','#9b59b6'];
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("v-$i", AppearanceRole::PLAYER, 21);
            $this->assertContains($a['skinTone'], $skins);
            $this->assertContains($a['kitTrim'], $playerTrims);
        }
    }

    public function testStaffCanHaveFacialHairAndMutedTrim(): void
    {
        $staffTrims = ['#4a5568','#2d3748','#374151','#1e3a5f'];
        $sawFacialHair = false;
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("coach-$i", AppearanceRole::COACH, 50);
            $this->assertContains($a['kitTrim'], $staffTrims);
            if ($a['facialHair'] !== 'none') { $sawFacialHair = true; }
        }
        $this->assertTrue($sawFacialHair);
    }
}
