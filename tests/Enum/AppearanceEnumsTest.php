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
        $this->assertSame('#1a1a1a', KitColor::BLACK->value);
        $this->assertSame('sleeves', KitStyle::SLEEVES->value);
        $this->assertSame('secondary', KitPart::SECONDARY->value);
        $this->assertSame('track', Outfit::TRACK->value);
        $this->assertSame('SCOUT', AppearanceRole::SCOUT->value);
    }

    public function testCaseCounts(): void
    {
        $this->assertCount(6, SkinId::cases());
        $this->assertCount(9, HairStyle::cases());
        $this->assertCount(6, HairColor::cases());
        $this->assertCount(9, Face::cases());
        $this->assertCount(3, FacialHair::cases());
        $this->assertCount(6, LipColor::cases());
        $this->assertCount(12, KitColor::cases());
        $this->assertCount(7, KitStyle::cases());
        $this->assertCount(4, KitPart::cases());
        $this->assertCount(4, Outfit::cases());
    }

    public function testSkinIdShades(): void
    {
        $this->assertSame('#f6d3b3', SkinId::S1->baseColor());
        $this->assertSame('#e0b28c', SkinId::S1->shadeColor());
        $this->assertSame('#5c3822', SkinId::S6->baseColor());
        $this->assertSame('#472a18', SkinId::S6->shadeColor());
    }
}
