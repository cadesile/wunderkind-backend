<?php
namespace App\Tests\Service\Appearance;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

class AppearanceGeneratorServiceTest extends TestCase
{
    private AppearanceGeneratorService $svc;

    private const KEYS = [
        'hair', 'hairColor', 'headband', 'skin', 'face', 'facial', 'lip',
        'primary', 'secondary', 'kit', 'shorts', 'socks', 'outfit', 'trousers', 'glasses',
    ];

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
        $this->assertSame(self::KEYS, array_keys($a));
        $this->assertIsBool($a['headband']);
        $this->assertIsBool($a['glasses']);
    }

    public function testValuesAreInAllowedSets(): void
    {
        $hairStyles = array_map(static fn (HairStyle $h) => $h->value, HairStyle::cases());
        $hairColors = array_map(static fn (HairColor $c) => $c->value, HairColor::cases());
        $skins      = array_map(static fn (SkinId $s) => $s->value, SkinId::cases());
        $lips       = array_map(static fn (LipColor $c) => $c->value, LipColor::cases());
        $kitColors  = array_map(static fn (KitColor $c) => $c->value, KitColor::cases());
        $kitStyles  = array_map(static fn (KitStyle $s) => $s->value, KitStyle::cases());
        $kitParts   = array_map(static fn (KitPart $p) => $p->value, KitPart::cases());

        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("v-$i", AppearanceRole::PLAYER, 21);
            $this->assertContains($a['hair'], $hairStyles);
            $this->assertContains($a['hairColor'], $hairColors);
            $this->assertContains($a['skin'], $skins);
            $this->assertContains($a['lip'], $lips);
            $this->assertContains($a['primary'], $kitColors);
            $this->assertContains($a['secondary'], $kitColors);
            $this->assertNotSame($a['primary'], $a['secondary']);
            $this->assertContains($a['kit'], $kitStyles);
            $this->assertContains($a['shorts'], $kitParts);
            $this->assertContains($a['socks'], $kitParts);
        }
    }

    public function testPlayerUnder25NeverHasGlassesOrFacialHair(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("player-$i", AppearanceRole::PLAYER, 22);
            $this->assertSame('none', $a['facial']);
            $this->assertFalse($a['glasses']);
        }
    }

    public function testPlayerOver25CanHaveFacialHairButNeverGlasses(): void
    {
        $sawFacialHair = false;
        for ($i = 0; $i < 200; $i++) {
            $a = $this->svc->generate("player-over25-$i", AppearanceRole::PLAYER, 30);
            if ($a['facial'] !== 'none') {
                $sawFacialHair = true;
            }
            $this->assertFalse($a['glasses'], 'players never wear glasses, regardless of age');
        }
        $this->assertTrue($sawFacialHair, 'players over 25 should be able to roll facial hair');
    }

    public function testPlayerGetsFixedDefaultsForStaffOnlyFields(): void
    {
        $a = $this->svc->generate('player-defaults', AppearanceRole::PLAYER, 22);
        $this->assertSame('coat', $a['outfit']);
        $this->assertSame('black', $a['trousers']);
    }

    public function testNonPlayerGetsFixedDefaultsForPlayerOnlyFields(): void
    {
        $a = $this->svc->generate('coach-defaults', AppearanceRole::COACH, 40);
        $this->assertSame('stripes', $a['kit']);
        $this->assertSame('black', $a['shorts']);
        $this->assertSame('primary', $a['socks']);
    }

    public function testNonPlayerCanHaveFacialHairOutfitAndGlasses(): void
    {
        $outfits = array_map(static fn (Outfit $o) => $o->value, Outfit::cases());
        $sawFacialHair = false;
        $sawGlasses = false;
        for ($i = 0; $i < 200; $i++) {
            $a = $this->svc->generate("coach-$i", AppearanceRole::COACH, 50);
            $this->assertContains($a['outfit'], $outfits);
            if ($a['facial'] !== 'none') { $sawFacialHair = true; }
            if ($a['glasses']) { $sawGlasses = true; }
        }
        $this->assertTrue($sawFacialHair);
        $this->assertTrue($sawGlasses);
    }

    public function testPlayerAlwaysHasNeutralFace(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("player-face-$i", AppearanceRole::PLAYER, 25);
            $this->assertSame('neutral', $a['face']);
        }
    }

    public function testNonPlayerFaceVaries(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[$this->svc->generate("coach-face-$i", AppearanceRole::COACH, 40)['face']] = true;
        }
        $this->assertGreaterThan(1, count($seen), 'staff/scout/agent should still get a varied expression');
    }

    public function testPlayerHeadbandIsVanishinglyRare(): void
    {
        $n    = 5000;
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($this->svc->generate("headband-player-$i", AppearanceRole::PLAYER, 22)['headband']) {
                $hits++;
            }
        }
        // Expected ~5 hits at p=0.001/n=5000; generous ceiling to avoid flakiness.
        $this->assertLessThan(25, $hits, "expected ~1-in-1000 players to have a headband, got $hits/$n");
    }

    public function testNonPlayerHeadbandRateIsUnaffected(): void
    {
        $n    = 600;
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($this->svc->generate("headband-coach-$i", AppearanceRole::COACH, 40)['headband']) {
                $hits++;
            }
        }
        $percent = $hits / $n * 100;
        $this->assertGreaterThan(10, $percent, "staff headband rate should stay near the base 20%, got $percent%");
    }

    public function testHairStyleWeightsSumToOneHundredPerAgeBand(): void
    {
        foreach ([20, 40, 50] as $age) {
            $weights = $this->svc->hairStyleWeights($age);
            $this->assertSame(100, array_sum($weights), "hairStyleWeights($age) should sum to 100");
        }
    }

    public function testMohawkAfroAndCornrowsStayRareAcrossAllAges(): void
    {
        foreach ([20, 40, 50] as $age) {
            $n    = 600;
            $hits = 0;
            for ($i = 0; $i < $n; $i++) {
                $a = $this->svc->generate("hair-rarity-$age-$i", AppearanceRole::PLAYER, $age);
                if (in_array($a['hair'], ['mohawk', 'afro', 'cornrows'], true)) {
                    $hits++;
                }
            }
            $percent = $hits / $n * 100;
            $this->assertLessThan(20, $percent, "mohawk+afro+cornrows should stay rare at age $age, got $percent%");
        }
    }

    public function testFacialHairIsRestrictedToNoneStubbleBeard(): void
    {
        $allowed = array_map(static fn (FacialHair $f) => $f->value, FacialHair::cases());
        for ($i = 0; $i < 100; $i++) {
            $a = $this->svc->generate("facial-$i", AppearanceRole::SCOUT, 45);
            $this->assertContains($a['facial'], $allowed);
        }
    }

    // ── Regional skin distribution ──────────────────────────────────────────────

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
        $dark = $this->tally('Nigerian', ['s5', 's6']);
        $this->assertGreaterThanOrEqual(95, $dark, "Expected >=95% dark tones for Nigerian players, got $dark%");
    }

    public function testDarkSkinTonesAlwaysGetTheReservedDeepBrownLip(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $a = $this->svc->generate("dark-lip-$i", AppearanceRole::PLAYER, 21, 'Nigerian');
            if (in_array($a['skin'], ['s5', 's6'], true)) {
                $this->assertSame('#5c3822', $a['lip'], "dark-skinned ($a[skin]) avatars must get the deep-brown lip");
            } else {
                $this->assertNotSame('#5c3822', $a['lip'], "lighter-skinned ($a[skin]) avatars should not get the reserved deep-brown lip");
            }
        }
    }

    public function testGingerHairIsRare(): void
    {
        $n    = 600;
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            $a = $this->svc->generate("ginger-$i", AppearanceRole::PLAYER, 30);
            if ($a['hairColor'] === HairColor::GINGER->value) {
                $hits++;
            }
        }
        $percent = $hits / $n * 100;
        $this->assertLessThan(15, $percent, "ginger hair should be rare, got $percent%");
    }

    public function testNorthernEuropeanPlayersAreMajorityLightSkinned(): void
    {
        $light = $this->tally('Swedish', ['s1', 's2']);
        $this->assertGreaterThan(50, $light, "Expected >50% light tones for Swedish players, got $light%");
    }

    public function testBrazilianPlayersSpanTheWholeRange(): void
    {
        $seen = [];
        for ($i = 0; $i < 600; $i++) {
            $seen[$this->svc->generate("br-$i", AppearanceRole::PLAYER, 21, 'Brazilian')['skin']] = true;
        }
        $this->assertCount(6, $seen, 'Brazilian players should draw from all six tones');
    }

    /** Percentage of 600 generated players whose skin is in $ids. */
    private function tally(string $nationality, array $ids): float
    {
        $n = 600;
        $hits = 0;
        for ($i = 0; $i < $n; $i++) {
            $a = $this->svc->generate("$nationality-$i", AppearanceRole::PLAYER, 21, $nationality);
            if (in_array($a['skin'], $ids, true)) {
                $hits++;
            }
        }
        return $hits / $n * 100;
    }
}
