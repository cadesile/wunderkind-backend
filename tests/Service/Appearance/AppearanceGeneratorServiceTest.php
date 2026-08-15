<?php
namespace App\Tests\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\SkinTone;
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

    // ── Regional skin tone ────────────────────────────────────────────────────

    /**
     * Weights are applied positionally against SKIN_TONES, so the const and the
     * SkinTone enum must stay in lockstep — reordering either silently remaps
     * every region's distribution.
     */
    public function testSkinTonesConstMatchesSkinToneEnumOrder(): void
    {
        $const = (new \ReflectionClass(AppearanceGeneratorService::class))->getConstant('SKIN_TONES');
        $this->assertSame(
            array_map(static fn (SkinTone $t) => $t->value, SkinTone::cases()),
            $const,
        );
    }

    /**
     * The weighted skin-tone pick must consume exactly one RNG value, exactly
     * where the uniform pick did — so passing a nationality changes skinTone
     * and nothing else.
     */
    public function testNationalityChangesOnlySkinTone(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $plain    = $this->svc->generate("stream-$i", AppearanceRole::PLAYER, 22);
            $regional = $this->svc->generate("stream-$i", AppearanceRole::PLAYER, 22, 'Nigerian');

            unset($plain['skinTone'], $regional['skinTone']);
            $this->assertSame($plain, $regional, "RNG stream diverged for stream-$i");
        }
    }

    public function testStillDeterministicWithNationality(): void
    {
        $a = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18, 'Brazilian');
        $b = $this->svc->generate('abc-123', AppearanceRole::PLAYER, 18, 'Brazilian');
        $this->assertSame($a, $b);
    }

    public function testUnknownNationalityFallsBackToUniformPick(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $plain   = $this->svc->generate("fallback-$i", AppearanceRole::PLAYER, 20);
            $unknown = $this->svc->generate("fallback-$i", AppearanceRole::PLAYER, 20, 'Martian');
            $this->assertSame($plain, $unknown);
        }
    }

    public function testWestAfricanPlayersAreOverwhelminglyDarkSkinned(): void
    {
        $dark = $this->tally('Nigerian', ['#8b4c1e', '#5c2d0a']);
        $this->assertGreaterThanOrEqual(95, $dark, "Expected >=95% dark tones for Nigerian players, got $dark%");
    }

    public function testNorthernEuropeanPlayersAreMajorityLightSkinned(): void
    {
        $light = $this->tally('Swedish', ['#f5dcc8', '#e8c49a']);
        $this->assertGreaterThan(50, $light, "Expected >50% light tones for Swedish players, got $light%");
    }

    public function testBrazilianPlayersSpanTheWholeRange(): void
    {
        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $seen[$this->svc->generate("br-$i", AppearanceRole::PLAYER, 21, 'Brazilian')['skinTone']] = true;
        }
        $this->assertCount(6, $seen, 'Brazilian players should draw from all six tones');
    }

    /** Percentage of 600 generated players whose skinTone is in $tones. */
    private function tally(string $nationality, array $tones): float
    {
        $n = 600;
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            $a = $this->svc->generate("$nationality-$i", AppearanceRole::PLAYER, 21, $nationality);
            if (in_array($a['skinTone'], $tones, true)) {
                $hits++;
            }
        }
        return $hits / $n * 100;
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
