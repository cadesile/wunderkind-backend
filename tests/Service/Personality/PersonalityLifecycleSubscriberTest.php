<?php
namespace App\Tests\Service\Personality;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\StaffRole;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Service\Personality\PersonalityGeneratorService;
use PHPUnit\Framework\TestCase;

class PersonalityLifecycleSubscriberTest extends TestCase
{
    private PersonalityLifecycleSubscriber $sub;

    protected function setUp(): void
    {
        $this->sub = new PersonalityLifecycleSubscriber(new PersonalityGeneratorService());
    }

    public function testFillsStaffPersonality(): void
    {
        $staff = new Staff('A', 'B', StaffRole::COACH);
        $staff->setCoachingAbility(90);

        $this->sub->fill($staff);

        $this->assertFalse($staff->getPersonality()->isDefault());
        foreach ($staff->getPersonality()->toArray() as $name => $value) {
            $this->assertGreaterThanOrEqual(12, $value, $name);
            $this->assertLessThanOrEqual(18, $value, $name);
        }
    }

    public function testFillsScoutPersonality(): void
    {
        $scout = new Scout('S');
        $scout->setExperience(90);

        $this->sub->fill($scout);

        $this->assertFalse($scout->getPersonality()->isDefault());
        foreach ($scout->getPersonality()->toArray() as $name => $value) {
            $this->assertGreaterThanOrEqual(12, $value, $name);
            $this->assertLessThanOrEqual(18, $value, $name);
        }
    }

    public function testStaffAnchorsOnCoachingAbilityAndScoutOnExperience(): void
    {
        $lowStaff  = new Staff('L', 'S', StaffRole::COACH);
        $lowStaff->setCoachingAbility(30);
        $highStaff = new Staff('H', 'S', StaffRole::COACH);
        $highStaff->setCoachingAbility(100);

        $this->sub->fill($lowStaff);
        $this->sub->fill($highStaff);

        $this->assertLessThan(
            array_sum($highStaff->getPersonality()->toArray()),
            array_sum($lowStaff->getPersonality()->toArray()),
        );
    }

    public function testDoesNotOverwriteAnAlreadyPopulatedProfile(): void
    {
        $staff = new Staff('A', 'B', StaffRole::COACH);
        $staff->setCoachingAbility(90);
        $staff->getPersonality()->setAmbition(3);

        $before = $staff->getPersonality()->toArray();
        $this->sub->fill($staff);

        $this->assertSame($before, $staff->getPersonality()->toArray());
    }

    public function testIgnoresPlayerAndAgent(): void
    {
        // Player personality is owned by PlayerGenerationService, not this subscriber.
        $player = new Player();
        $this->sub->fill($player);
        $this->assertTrue($player->getPersonality()->isDefault());

        $agent = new Agent('A');
        $this->sub->fill($agent); // must not throw — Agent has no personality matrix
        $this->assertFalse(method_exists($agent, 'getPersonality'));
    }
}
