<?php

declare(strict_types=1);

namespace App\Tests\Service\Personality;

use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Service\MarketDataService;
use App\Service\WorldInitializationService;
use PHPUnit\Framework\TestCase;

/**
 * The staff and scout payloads must carry the same `personality` block, in the
 * same shape, that the player payload has always carried.
 */
class PersonalitySerializationTest extends TestCase
{
    private const EXPECTED_KEYS = [
        'determination', 'professionalism', 'ambition', 'loyalty',
        'adaptability', 'pressure', 'temperament', 'consistency',
    ];

    public function testBuildStaffSnapshotEmitsPersonality(): void
    {
        $staff = new Staff('A', 'B', StaffRole::COACH);
        $staff->getPersonality()->setAmbition(17);

        $snap = $this->world()->buildStaffSnapshot($staff);

        $this->assertArrayHasKey('personality', $snap);
        $this->assertSame(self::EXPECTED_KEYS, array_keys($snap['personality']));
        $this->assertSame(17, $snap['personality']['ambition']);
    }

    public function testBuildScoutSnapshotEmitsPersonality(): void
    {
        $scout = new Scout('S');
        $scout->getPersonality()->setPressure(4);

        $snap = $this->world()->buildScoutSnapshot($scout);

        $this->assertArrayHasKey('personality', $snap);
        $this->assertSame(self::EXPECTED_KEYS, array_keys($snap['personality']));
        $this->assertSame(4, $snap['personality']['pressure']);
    }

    public function testSnapshotPersonalityShapeMatchesThePlayerSnapshot(): void
    {
        $svc = $this->world();
        $expected = array_keys($svc->buildPlayerSnapshot(new Player('T', 'P'))['personality']);

        $this->assertSame($expected, array_keys($svc->buildStaffSnapshot(new Staff('A', 'B'))['personality']));
        $this->assertSame($expected, array_keys($svc->buildScoutSnapshot(new Scout('S'))['personality']));
    }

    public function testMarketCoachAndScoutSerializersEmitPersonality(): void
    {
        $svc = (new \ReflectionClass(MarketDataService::class))->newInstanceWithoutConstructor();

        $staff = new Staff('A', 'B', StaffRole::COACH);
        $staff->getPersonality()->setLoyalty(19);
        $coach = $this->callPrivate($svc, 'serializeCoach', $staff);
        $this->assertSame(self::EXPECTED_KEYS, array_keys($coach['personality']));
        $this->assertSame(19, $coach['personality']['loyalty']);

        $scoutEntity = new Scout('S');
        $scoutEntity->getPersonality()->setConsistency(2);
        $scout = $this->callPrivate($svc, 'serializeScout', $scoutEntity);
        $this->assertSame(self::EXPECTED_KEYS, array_keys($scout['personality']));
        $this->assertSame(2, $scout['personality']['consistency']);
    }

    private function world(): WorldInitializationService
    {
        return (new \ReflectionClass(WorldInitializationService::class))->newInstanceWithoutConstructor();
    }

    private function callPrivate(object $svc, string $method, object $arg): array
    {
        $m = new \ReflectionMethod($svc, $method);
        $m->setAccessible(true);
        return $m->invoke($svc, $arg);
    }
}
