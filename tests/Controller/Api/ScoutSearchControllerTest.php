<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ScoutSearchController;
use App\Entity\Player;
use PHPUnit\Framework\TestCase;

class ScoutSearchControllerTest extends TestCase
{
    public function testSerializePlayerIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'skinTone' => '#e0ac69', 'hairStyle' => 'curly', 'hairColor' => 'blonde',
            'accessory' => 'headband', 'kitTrim' => '#d43a3a', 'facialHair' => 'stubble',
            'faceShape' => 'round', 'eyeShape' => 'wide', 'noseType' => 'small', 'jerseyVariant' => 3,
        ];
        $player = new Player('A', 'B');
        $player->setAppearance($appearance);

        // serializePlayer is a private, pure mapper — it reads only the passed
        // Player entity and does not reference any constructor-injected
        // collaborators. Instantiate the controller without invoking its
        // constructor to avoid unrelated DI setup.
        $controller = (new \ReflectionClass(ScoutSearchController::class))
            ->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(ScoutSearchController::class, 'serializePlayer');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $player);

        $this->assertArrayHasKey('appearance', $result);
        $this->assertSame($appearance, $result['appearance']);
    }
}
