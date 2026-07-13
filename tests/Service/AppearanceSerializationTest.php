<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Player;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

class AppearanceSerializationTest extends TestCase
{
    public function testBuildPlayerSnapshotIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'skinTone' => '#dfaa80', 'hairStyle' => 'messy', 'hairColor' => 'dark_brown',
            'accessory' => null, 'kitTrim' => '#3a8fd4', 'facialHair' => 'none',
            'faceShape' => 'oval', 'eyeShape' => 'narrow', 'noseType' => 'normal', 'jerseyVariant' => 2,
        ];
        $player = new Player('Test', 'Player');
        $player->setAppearance($appearance);

        // buildPlayerSnapshot is a pure mapper — it reads only the passed entity,
        // not any constructor-injected collaborators. Instantiate the service
        // without invoking its constructor to avoid unrelated DI setup.
        $svc = static::buildService();
        $snap = $svc->buildPlayerSnapshot($player);

        $this->assertArrayHasKey('appearance', $snap);
        $this->assertSame($appearance, $snap['appearance']);
    }

    private static function buildService(): WorldInitializationService
    {
        return (new \ReflectionClass(WorldInitializationService::class))
            ->newInstanceWithoutConstructor();
    }
}
