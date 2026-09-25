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
            'hair' => 'quiff', 'hairColor' => '#4a2c1a', 'headband' => false, 'skin' => 's3',
            'face' => 'focused', 'facial' => 'none', 'lip' => '#c9575e',
            'primary' => '#c8202f', 'secondary' => '#f4f3ee',
            'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
            'outfit' => 'coat', 'trousers' => 'black', 'glasses' => false,
        ];

        foreach ([new Player(), new Staff(), new Scout(), new Agent('A')] as $entity) {
            $this->assertNull($entity->getAppearance());
            $entity->setAppearance($appearance);
            $this->assertSame($appearance, $entity->getAppearance());
        }
    }
}
