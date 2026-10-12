<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\ClubSpotlight;
use App\Entity\User;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubSpotlightRepository;
use App\Repository\MatchResultRepository;
use App\Repository\LeagueRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SyncRecordRepository;
use App\Repository\TransferRepository;
use App\Repository\UserLedgerRepository;
use App\Service\ClubSpotlightService;
use App\Service\InboxService;
use App\Service\Notification\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Incumbent handling in selectNew(). The club already featured must not be
 * re-picked (notifyOwner() pushes on every selection), but "already featured"
 * has to be decided on the denormalized clubId — club names are not unique, so
 * a name match would exclude an innocent same-named club.
 */
class ClubSpotlightIncumbentTest extends TestCase
{
    private ?InboxService $inbox = null;
    private ?PushNotificationService $push = null;

    private function club(string $name): Club
    {
        $user = new User(bin2hex(random_bytes(6)) . '@incumbent.test');

        return new Club($name, $user);
    }

    /** @param Club[] $candidates */
    private function service(array $candidates, ClubSpotlight $current): ClubSpotlightService
    {
        $syncRepo = $this->createStub(SyncRecordRepository::class);
        $syncRepo->method('findMostActiveClubs')->willReturn($candidates);

        $spotlightRepo = $this->createStub(ClubSpotlightRepository::class);
        $spotlightRepo->method('getCurrent')->willReturn($current);

        $this->inbox = $this->createMock(InboxService::class);
        $this->push  = $this->createMock(PushNotificationService::class);

        return new ClubSpotlightService(
            $syncRepo,
            $this->createStub(ClubFacilityRepository::class),
            $spotlightRepo,
            $this->createStub(MatchResultRepository::class),
            $this->createStub(TransferRepository::class),
            $this->createStub(PlayerCareerStatRepository::class),
            $this->createStub(UserLedgerRepository::class),
            $this->createStub(SeasonRecordRepository::class),
            $this->createStub(LeagueRepository::class),
            $this->inbox,
            $this->push,
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function featuring(Club $club): ClubSpotlight
    {
        $s = new ClubSpotlight();
        $s->update(
            clubName: $club->getName(),
            clubId: (string) $club->getId(),
            clubReputation: 0,
            dividendDrawsPence: 0,
            homeKitConfig: null,
            awayKitConfig: null,
            badgeConfig: null,
            ownerName: null,
            ownerNationality: null,
            ownerAppearance: null,
            stadiumConfig: null,
            recentFixtures: [],
            recentTransfers: [],
            topPerformerName: null,
            topPerformerGoals: 0,
            topPerformerAssists: 0,
            topPerformerAppearanceConfig: null,
            trophies: [],
            establishedAt: null,
            windowStartsAt: new \DateTimeImmutable('-1 hour'),
            windowEndsAt: new \DateTimeImmutable('-1 minute'),
        );

        return $s;
    }

    public function testADifferentClubSharingTheIncumbentsNameIsStillEligible(): void
    {
        // Production has six clubs called "London FC". Matching on name alone
        // made all of them look like whichever one was already featured.
        $incumbent = $this->club('London FC');
        $namesake  = $this->club('London FC');

        $service = $this->service([$incumbent, $namesake], $this->featuring($incumbent));
        $this->push->expects($this->once())->method('notifyUsers');
        $this->inbox->expects($this->once())->method('sendSystemNotification');

        $picked = $service->selectNew();

        $this->assertSame($namesake, $picked);
        $this->assertNotSame($incumbent, $picked);
    }

    public function testTheIncumbentItselfIsNeverRePickedOrReNotified(): void
    {
        $incumbent = $this->club('Solo FC');

        $service = $this->service([$incumbent], $this->featuring($incumbent));
        $this->push->expects($this->never())->method('notifyUsers');
        $this->inbox->expects($this->never())->method('sendSystemNotification');

        $this->assertNull($service->selectNew(), 'Sole-candidate incumbent re-ups its window instead.');
    }

    public function testTheIncumbentsWindowIsExtendedRatherThanLeftToExpire(): void
    {
        $incumbent = $this->club('Solo FC');
        $current   = $this->featuring($incumbent);
        $this->assertFalse($current->isLive(), 'Fixture starts expired.');

        $service = $this->service([$incumbent], $current);
        $this->push->expects($this->never())->method('notifyUsers');
        $this->inbox->expects($this->never())->method('sendSystemNotification');
        $service->selectNew();

        $this->assertTrue($current->isLive(), 'A quiet cycle must keep the section rendering.');
        $this->assertSame('Solo FC', $current->getClubName());
    }

    public function testNoEligibleClubsLeavesTheRowCompletelyUntouched(): void
    {
        $incumbent = $this->club('Solo FC');
        $current   = $this->featuring($incumbent);

        $service = $this->service([], $current);
        $this->push->expects($this->never())->method('notifyUsers');
        $this->inbox->expects($this->never())->method('sendSystemNotification');

        $this->assertNull($service->selectNew());
        $this->assertFalse($current->isLive(), 'Nothing qualified, so the stale feature drops out.');
    }

    public function testAPreMigrationRowWithNoClubIdFallsBackToNameMatching(): void
    {
        $incumbent = $this->club('Legacy FC');
        $other     = $this->club('Other FC');

        $current = $this->featuring($incumbent);
        (new \ReflectionProperty($current, 'clubId'))->setValue($current, null);

        $service = $this->service([$incumbent, $other], $current);
        $this->push->expects($this->once())->method('notifyUsers');
        $this->inbox->expects($this->once())->method('sendSystemNotification');

        $picked = $service->selectNew();

        $this->assertSame($other, $picked, 'Name fallback still recognises the incumbent.');
    }
}
