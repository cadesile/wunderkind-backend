<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Player;
use App\Entity\Scout;
use App\Service\WorldPackSnapshotBuilder;
use PHPUnit\Framework\TestCase;

class AppearanceSerializationTest extends TestCase
{
    public function testBuildScoutSnapshotIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'hair' => 'mohawk', 'hairColor' => '#1c1410', 'headband' => false, 'skin' => 's5',
            'face' => 'cool', 'facial' => 'beard', 'lip' => '#8a4a3a',
            'primary' => '#1a1a1a', 'secondary' => '#f4f3ee',
            'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
            'outfit' => 'suit', 'trousers' => 'black', 'glasses' => true,
        ];
        $scout = new Scout('Test Scout');
        $scout->setAppearance($appearance);

        $svc = static::buildService();
        $snap = $svc->buildScoutSnapshot($scout);

        $this->assertArrayHasKey('appearance', $snap);
        $this->assertSame($appearance, $snap['appearance']);
    }

    public function testBuildPlayerSnapshotIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'hair' => 'quiff', 'hairColor' => '#4a2c1a', 'headband' => false, 'skin' => 's3',
            'face' => 'focused', 'facial' => 'none', 'lip' => '#c9575e',
            'primary' => '#c8202f', 'secondary' => '#f4f3ee',
            'kit' => 'hoops', 'shorts' => 'white', 'socks' => 'secondary',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        ];
        $player = new Player('Test', 'Player');
        $player->setAppearance($appearance);

        $svc = static::buildService();
        $snap = $svc->buildPlayerSnapshot($player);

        $this->assertArrayHasKey('appearance', $snap);
        $this->assertSame($appearance, $snap['appearance']);
    }

    private static function buildService(): WorldPackSnapshotBuilder
    {
        return new WorldPackSnapshotBuilder();
    }
}
