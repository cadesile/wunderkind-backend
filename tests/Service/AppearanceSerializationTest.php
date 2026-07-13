<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Player;
use App\Entity\Scout;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

class AppearanceSerializationTest extends TestCase
{
    public function testBuildScoutSnapshotIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'skinTone' => '#c68642', 'hairStyle' => 'short', 'hairColor' => 'black',
            'accessory' => 'glasses', 'kitTrim' => '#1a1a1a', 'facialHair' => 'beard',
            'faceShape' => 'square', 'eyeShape' => 'round', 'noseType' => 'wide', 'jerseyVariant' => 1,
        ];
        $scout = new Scout('Test Scout');
        $scout->setAppearance($appearance);

        // buildScoutSnapshot is a pure mapper — it reads only the passed entity,
        // not any constructor-injected collaborators. Instantiate the service
        // without invoking its constructor to avoid unrelated DI setup.
        $svc = static::buildService();
        $snap = $svc->buildScoutSnapshot($scout);

        $this->assertArrayHasKey('appearance', $snap);
        $this->assertSame($appearance, $snap['appearance']);
    }

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
