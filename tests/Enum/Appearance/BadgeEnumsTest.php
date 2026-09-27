<?php
namespace App\Tests\Enum\Appearance;

use App\Enum\Appearance\BadgeCentre;
use App\Enum\Appearance\BadgePattern;
use App\Enum\Appearance\BadgeShape;
use PHPUnit\Framework\TestCase;

class BadgeEnumsTest extends TestCase
{
    public function testFrontendValueStrings(): void
    {
        $this->assertSame('round', BadgeShape::ROUND->value);
        $this->assertSame('chevron', BadgePattern::CHEVRON->value);
        $this->assertSame('none', BadgeCentre::NONE->value);
        $this->assertSame('initials', BadgeCentre::INITIALS->value);
    }

    public function testCaseCounts(): void
    {
        $this->assertCount(5, BadgeShape::cases());
        $this->assertCount(6, BadgePattern::cases());
        $this->assertCount(5, BadgeCentre::cases());
    }
}
