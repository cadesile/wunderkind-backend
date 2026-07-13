<?php
namespace App\Tests\Entity;

use App\Entity\Player;
use App\Entity\Staff;
use App\Entity\Scout;
use App\Entity\Agent;
use PHPUnit\Framework\TestCase;

class AppearanceColumnTest extends TestCase
{
    public function testAllFourEntitiesRoundTripAppearance(): void
    {
        $appearance = [
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 2,
        ];

        foreach ([new Player(), new Staff(), new Scout(), new Agent('A')] as $entity) {
            $this->assertNull($entity->getAppearance());
            $entity->setAppearance($appearance);
            $this->assertSame($appearance, $entity->getAppearance());
        }
    }
}
