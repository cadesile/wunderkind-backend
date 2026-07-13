<?php
namespace App\Tests\Service\Appearance;

use App\Entity\Agent;
use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\User;
use App\EventSubscriber\AppearanceLifecycleSubscriber;
use App\Service\Appearance\AppearanceGeneratorService;
use PHPUnit\Framework\TestCase;

class AppearanceLifecycleSubscriberTest extends TestCase
{
    private AppearanceLifecycleSubscriber $sub;

    protected function setUp(): void
    {
        $this->sub = new AppearanceLifecycleSubscriber(new AppearanceGeneratorService());
    }

    public function testFillsPlayerWithNullAppearance(): void
    {
        $player = new Player();
        $this->sub->fill($player);
        $this->assertNotNull($player->getAppearance());
        $this->assertSame('none', $player->getAppearance()['facialHair']); // player rule
    }

    public function testFillsStaffScoutAgent(): void
    {
        foreach ([new Staff(), new Scout('S'), new Agent('A')] as $e) {
            $this->sub->fill($e);
            $this->assertNotNull($e->getAppearance());
            $this->assertArrayHasKey('jerseyVariant', $e->getAppearance());
        }
    }

    public function testDoesNotOverwriteExisting(): void
    {
        $player = new Player();
        $player->setAppearance(['skinTone' => '#000000']);
        $this->sub->fill($player);
        $this->assertSame(['skinTone' => '#000000'], $player->getAppearance());
    }

    public function testIgnoresUnrelatedEntities(): void
    {
        // Club requires a name + owning User in its constructor (unlike the
        // brief's illustrative `new Club()`); construct a real one so this
        // still exercises the subscriber against an actual unrelated entity.
        $club = new Club('Test FC', new User('owner@example.com'));
        $this->sub->fill($club); // must not throw
        $this->assertTrue(true);
    }
}
