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

        $this->sub->fill($staff);

        $this->assertFalse($staff->getPersonality()->isDefault());
        foreach ($staff->getPersonality()->toArray() as $name => $value) {
            $this->assertGreaterThanOrEqual(1, $value, $name);
            $this->assertLessThanOrEqual(20, $value, $name);
        }
    }

    public function testFillsScoutPersonality(): void
    {
        $scout = new Scout('S');

        $this->sub->fill($scout);

        $this->assertFalse($scout->getPersonality()->isDefault());
        $this->assertGreaterThanOrEqual(13, $scout->getPersonality()->getAdaptability());
        $this->assertGreaterThanOrEqual(12, $scout->getPersonality()->getConsistency());
    }

    public function testStaffContextComesFromTheRole(): void
    {
        // A coach carries the leadership floors; a facility manager does not.
        $coach = new Staff('A', 'B', StaffRole::COACH);
        $this->sub->fill($coach);

        $this->assertGreaterThanOrEqual(11, $coach->getPersonality()->getDetermination());
        $this->assertGreaterThanOrEqual(9,  $coach->getPersonality()->getTemperament());
        $this->assertGreaterThanOrEqual(12, $coach->getPersonality()->getPressure());
    }

    public function testPersonalityIsIndependentOfCoachingAbility(): void
    {
        // Ability says nothing about character — a limited coach can still be unflappable.
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $staff = new Staff('A', 'B', StaffRole::FACILITY_MANAGER);
            $staff->setCoachingAbility(1);
            $this->sub->fill($staff);
            $seen[] = max($staff->getPersonality()->toArray());
        }

        $this->assertGreaterThanOrEqual(15, max($seen), 'A low-ability coach must still be able to roll a strong trait');
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
