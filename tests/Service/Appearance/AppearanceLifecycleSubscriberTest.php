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
        $this->assertSame('none', $player->getAppearance()['facial']); // player rule
    }

    public function testFillsStaffScoutAgent(): void
    {
        foreach ([new Staff(), new Scout('S'), new Agent('A')] as $e) {
            $this->sub->fill($e);
            $this->assertNotNull($e->getAppearance());
            $this->assertArrayHasKey('outfit', $e->getAppearance());
        }
    }

    public function testDoesNotOverwriteExisting(): void
    {
        $player = new Player();
        $player->setAppearance(['skin' => 's6']);
        $this->sub->fill($player);
        $this->assertSame(['skin' => 's6'], $player->getAppearance());
    }

    public function testFillPassesNationalityThroughToTheGenerator(): void
    {
        // West Africa's table gives 99% weight to the two darkest tones, so a
        // Nigerian player landing there proves nationality reached the generator.
        $player = new Player();
        $player->setNationality('Nigerian');
        $this->sub->fill($player);

        $this->assertContains($player->getAppearance()['skin'], ['s4', 's5', 's6']);
    }

    // ── regenerate ───────────────────────────────────────────────────────────

    public function testRegenerateOverwritesExistingAppearance(): void
    {
        $player = new Player();
        $player->setAppearance(['skin' => 's6']); // simulates a row under an old shape

        $this->assertTrue($this->sub->regenerate($player));

        $appearance = $player->getAppearance();
        $this->assertNotSame(['skin' => 's6'], $appearance);
        $this->assertArrayHasKey('hair', $appearance);
        $this->assertArrayHasKey('outfit', $appearance);
    }

    public function testRegenerateWorksEvenWithoutAnExistingAppearance(): void
    {
        $player = new Player();
        $this->assertTrue($this->sub->regenerate($player));
        $this->assertNotNull($player->getAppearance());
    }

    public function testRegenerateIgnoresUnrelatedEntities(): void
    {
        $club = new Club('Test FC', new User('owner@example.com'));
        $this->assertFalse($this->sub->regenerate($club));
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
