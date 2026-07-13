<?php
namespace App\Tests\Enum;

use App\Enum\Appearance\AppearanceRole;
use App\Enum\Appearance\SkinTone;
use App\Enum\Appearance\HairStyle;
use App\Enum\Appearance\HairColor;
use App\Enum\Appearance\AvatarAccessory;
use App\Enum\Appearance\FacialHair;
use App\Enum\Appearance\FaceShape;
use App\Enum\Appearance\EyeShape;
use App\Enum\Appearance\NoseType;
use PHPUnit\Framework\TestCase;

class AppearanceEnumsTest extends TestCase
{
    public function testFrontendValueStrings(): void
    {
        $this->assertSame('messy', HairStyle::MESSY->value);
        $this->assertSame('dark_brown', HairColor::DARK_BROWN->value);
        $this->assertSame('#dfaa80', SkinTone::MEDIUM->value);
        $this->assertSame('french_smile', FacialHair::FRENCH_SMILE->value);
        $this->assertSame('neck_tattoo', AvatarAccessory::NECK_TATTOO->value);
        $this->assertSame('downside_large', NoseType::DOWNSIDE_LARGE->value);
        $this->assertSame('square', FaceShape::SQUARE->value);
        $this->assertSame('round', EyeShape::ROUND->value);
        $this->assertSame('SCOUT', AppearanceRole::SCOUT->value);
    }

    public function testCaseCounts(): void
    {
        $this->assertCount(6, SkinTone::cases());
        $this->assertCount(7, HairStyle::cases());
        $this->assertCount(5, HairColor::cases());
        $this->assertCount(7, AvatarAccessory::cases());
        $this->assertCount(7, FacialHair::cases());
        $this->assertCount(3, FaceShape::cases());
        $this->assertCount(2, EyeShape::cases());
        $this->assertCount(5, NoseType::cases());
    }
}
