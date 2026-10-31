<?php
namespace App\Tests\Enum;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\Face;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\KitColor;
use App\Enum\Appearance\KitPart;
use App\Enum\Appearance\KitStyle;
use App\Enum\Appearance\LipColor;
use App\Enum\Appearance\Outfit;
use App\Enum\Appearance\SkinId;
use PHPUnit\Framework\TestCase;

class AppearanceEnumsTest extends TestCase
{
    public function testFrontendValueStrings(): void
    {
        $this->assertSame('quiff', HairStyle::QUIFF->value);
        $this->assertSame('#4a2c1a', HairColor::DARK_BROWN->value);
        $this->assertSame('s3', SkinId::S3->value);
        $this->assertSame('shout', Face::SHOUT->value);
        $this->assertSame('beard', FacialHair::BEARD->value);
        $this->assertSame('#c9575e', LipColor::ROSE->value);
        $this->assertSame('#5c3822', LipColor::DEEP_BROWN->value);
        $this->assertSame('#1a1a1a', KitColor::BLACK->value);
        $this->assertSame('sleeves', KitStyle::SLEEVES->value);
        $this->assertSame('secondary', KitPart::SECONDARY->value);
        $this->assertSame('track', Outfit::TRACK->value);
        $this->assertSame('SCOUT', AppearanceRole::SCOUT->value);
        $this->assertSame('OWNER', AppearanceRole::OWNER->value);
    }

    /**
     * These counts are a change-detector, not a specification: the sprite is upstream,
     * so when a drop legitimately adds an option the fix is to update the number here
     * AND extend the matching weight table — never to loosen the assertion.
     *
     * HairStyle 9 -> 16, Face 9 -> 15 and Outfit 4 -> 8 with the sprite's V4 drop
     * (2026-10-31). AvatarSpriteOptionParityTest is the stronger guard: it reads the
     * sprite's own lists out of the generated compositor bundle and asserts membership
     * AND order, which is what these three enums actually have to satisfy.
     */
    public function testCaseCounts(): void
    {
        $this->assertCount(5, AppearanceRole::cases());
        $this->assertCount(6, SkinId::cases());
        $this->assertCount(16, HairStyle::cases());
        $this->assertCount(6, HairColor::cases());
        $this->assertCount(15, Face::cases());
        $this->assertCount(3, FacialHair::cases());
        $this->assertCount(7, LipColor::cases());
        $this->assertCount(12, KitColor::cases());
        $this->assertCount(7, KitStyle::cases());
        $this->assertCount(4, KitPart::cases());
        $this->assertCount(8, Outfit::cases());
    }

    /** The V4 additions, spot-checked the same way the originals are above. */
    public function testV4ValueStrings(): void
    {
        $this->assertSame('curtains', HairStyle::CURTAINS->value);
        $this->assertSame('locs', HairStyle::LOCS->value);
        $this->assertSame('bob', HairStyle::BOB->value);
        $this->assertSame('content', Face::CONTENT->value);
        $this->assertSame('distraught', Face::DISTRAUGHT->value);
        $this->assertSame('polo', Outfit::POLO->value);
        $this->assertSame('rain', Outfit::RAIN->value);
    }

    public function testSkinIdShades(): void
    {
        $this->assertSame('#f6d3b3', SkinId::S1->baseColor());
        $this->assertSame('#e0b28c', SkinId::S1->shadeColor());
        $this->assertSame('#5c3822', SkinId::S6->baseColor());
        $this->assertSame('#472a18', SkinId::S6->shadeColor());
    }
}
