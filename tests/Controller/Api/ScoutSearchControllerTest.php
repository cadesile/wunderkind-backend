<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\ScoutSearchController;
use App\Entity\Agent;
use App\Entity\Player;
use PHPUnit\Framework\TestCase;

class ScoutSearchControllerTest extends TestCase
{
    private function serialize(Player $player): array
    {
        $controller = (new \ReflectionClass(ScoutSearchController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ScoutSearchController::class, 'serializePlayer');
        $method->setAccessible(true);
        return $method->invoke($controller, $player);
    }

    public function testSerializePlayerNestsAgentUsingSharedShape(): void
    {
        $agent = new Agent('Jorge Mendes');
        $agent->setCommissionRate('9.50');
        $player = new Player('A', 'B');
        $player->setAgent($agent);

        $result = $this->serialize($player);

        $this->assertArrayHasKey('agent', $result);
        $this->assertSame($agent->toSnapshotArray(), $result['agent']);
    }

    public function testSerializePlayerAgentIsNullWhenNone(): void
    {
        $result = $this->serialize(new Player('A', 'B'));

        $this->assertArrayHasKey('agent', $result);
        $this->assertNull($result['agent']);
    }

    public function testSerializePlayerIncludesAppearanceVerbatim(): void
    {
        $appearance = [
            'hair' => 'afro', 'hairColor' => '#e6bd55', 'headband' => true, 'skin' => 's2',
            'face' => 'happy', 'facial' => 'none', 'lip' => '#d98a7e',
            'primary' => '#d94040', 'secondary' => '#f2c230',
            'kit' => 'sash', 'shorts' => 'primary', 'socks' => 'black',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
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
