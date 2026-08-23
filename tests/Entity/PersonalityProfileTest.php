<?php
namespace App\Tests\Entity;

use App\Entity\PersonalityProfile;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use PHPUnit\Framework\TestCase;

class PersonalityProfileTest extends TestCase
{
    public function testStaffAndScoutExposeAPersonalityProfile(): void
    {
        $this->assertInstanceOf(PersonalityProfile::class, (new Staff())->getPersonality());
        $this->assertInstanceOf(PersonalityProfile::class, (new Scout('S'))->getPersonality());
    }

    public function testFreshProfileIsDefault(): void
    {
        $this->assertTrue((new PersonalityProfile())->isDefault());
    }

    public function testTouchedProfileIsNotDefault(): void
    {
        $p = new PersonalityProfile();
        $p->setAmbition(11);
        $this->assertFalse($p->isDefault());
    }

    public function testToArrayEmitsTheEightTraitsInPlayerSnapshotOrder(): void
    {
        $p = new PersonalityProfile();
        $p->setDetermination(1);
        $p->setProfessionalism(2);
        $p->setAmbition(3);
        $p->setLoyalty(4);
        $p->setAdaptability(5);
        $p->setPressure(6);
        $p->setTemperament(7);
        $p->setConsistency(8);

        $this->assertSame([
            'determination'   => 1,
            'professionalism' => 2,
            'ambition'        => 3,
            'loyalty'         => 4,
            'adaptability'    => 5,
            'pressure'        => 6,
            'temperament'     => 7,
            'consistency'     => 8,
        ], $p->toArray());
    }

    public function testStaffScoutAndPlayerProfilesAreTheSameShape(): void
    {
        $this->assertSame(
            array_keys((new Player())->getPersonality()->toArray()),
            array_keys((new Staff())->getPersonality()->toArray()),
        );
        $this->assertSame(
            array_keys((new Player())->getPersonality()->toArray()),
            array_keys((new Scout('S'))->getPersonality()->toArray()),
        );
    }
}
