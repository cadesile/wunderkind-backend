<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\ClubSpotlight;
use App\Entity\MatchResult;
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
 * buildStadiumConfig() — the half of the spotlight snapshot that is merged over
 * stadium-compositor.js's DEFAULT_STADIUM_CONFIG. That default is a demo config
 * (shop:true, carpark:true, museum:false, red seats, a 2-1 scoreboard), so every
 * assertion here is really "we overrode the demo value", not "we set a field".
 */
class ClubSpotlightStadiumConfigTest extends TestCase
{
    /** @param array<string,int> $facilityLevels */
    private function stadiumConfig(
        array $facilityLevels,
        ?array $homeKitConfig = null,
        array $recentResults = [],
    ): array {
        $club = $this->club($homeKitConfig);

        $facilityRepo = $this->createStub(ClubFacilityRepository::class);
        $facilityRepo->method('findBy')->willReturn(array_map(
            static fn (string $slug): ClubFacility => new ClubFacility($club, $slug, $facilityLevels[$slug]),
            array_keys($facilityLevels),
        ));

        $service = new ClubSpotlightService(
            $this->createStub(SyncRecordRepository::class),
            $facilityRepo,
            $this->createStub(ClubSpotlightRepository::class),
            $this->createStub(MatchResultRepository::class),
            $this->createStub(TransferRepository::class),
            $this->createStub(PlayerCareerStatRepository::class),
            $this->createStub(UserLedgerRepository::class),
            $this->createStub(SeasonRecordRepository::class),
            $this->createStub(LeagueRepository::class),
            $this->createStub(InboxService::class),
            $this->createStub(PushNotificationService::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $method = new \ReflectionMethod($service, 'buildStadiumConfig');

        return $method->invoke($service, $club, $recentResults);
    }

    private function club(?array $homeKitConfig = null): Club
    {
        $user = new User('owner@spotlight.test');
        $club = new Club('Test FC', $user);
        if ($homeKitConfig !== null) {
            $club->setHomeKitConfig($homeKitConfig);
        }

        return $club;
    }

    private function matchResult(Club $club, int $for, int $against, ?bool $isHome): MatchResult
    {
        $m = new MatchResult($club, $for, $against, 1, 1);
        $m->setIsHome($isHome);

        return $m;
    }

    public function testUnbuiltFacilitiesAreSwitchedOffNotJustLevelledToZero(): void
    {
        $config = $this->stadiumConfig(['club_shop' => 0, 'museum' => 0, 'car_park' => 0]);

        // Without these the compositor's defaults draw a full two-row car park
        // and a shop sized off a negative level.
        $this->assertFalse($config['shop']);
        $this->assertFalse($config['carpark']);
        $this->assertFalse($config['museum']);
    }

    public function testBuiltFacilitiesAreSwitchedOn(): void
    {
        $config = $this->stadiumConfig(['club_shop' => 3, 'museum' => 2, 'car_park' => 1]);

        $this->assertTrue($config['shop']);
        $this->assertTrue($config['carpark']);
        // museum defaults to FALSE in the compositor, so a built museum is the
        // case that silently never rendered before.
        $this->assertTrue($config['museum']);
        // Doubled, like the stands: every StadiumConfig level field runs 1-10
        // since the v2 sprite redrew the buildings for 10 levels.
        $this->assertSame(6, $config['shopLevel']);
        $this->assertSame(4, $config['museumLevel']);
        $this->assertSame(2, $config['carparkLevel']);
    }

    /**
     * The booleans carry "not built", because the levels cannot: they floor at 1
     * so the renderer never indexes a size table out of range.
     */
    public function testUnbuiltBuildingsStillSendARenderableLevel(): void
    {
        $config = $this->stadiumConfig(['club_shop' => 0, 'museum' => 0, 'car_park' => 0]);

        $this->assertSame(1, $config['shopLevel']);
        $this->assertSame(1, $config['museumLevel']);
        $this->assertSame(1, $config['carparkLevel']);
    }

    public function testSeatAndRoofComeFromTheClubsOwnHomeKit(): void
    {
        $config = $this->stadiumConfig([], ['primary' => '#880E4F', 'secondary' => '#1565C0']);

        $this->assertSame('#880E4F', $config['seat']);
        $this->assertSame('#1565C0', $config['roof']);
    }

    /**
     * homeKitConfig is client-supplied verbatim and never validated server-side,
     * and the compositor's shade() indexes a 7-char #rrggbb directly.
     */
    public function testNonHexKitColoursAreLeftToTheCompositorDefault(): void
    {
        $config = $this->stadiumConfig([], ['primary' => 'red', 'secondary' => '#abc']);

        $this->assertArrayNotHasKey('seat', $config);
        $this->assertArrayNotHasKey('roof', $config);
    }

    public function testKitlessClubFallsBackToTheCompositorDefault(): void
    {
        $config = $this->stadiumConfig([]);

        $this->assertArrayNotHasKey('seat', $config);
        $this->assertArrayNotHasKey('roof', $config);
    }

    public function testScoreboardIsOffRatherThanShowingTheDefaultFabricatedScore(): void
    {
        $config = $this->stadiumConfig([]);

        $this->assertFalse($config['scoreboard'], 'DEFAULT_STADIUM_CONFIG would otherwise show a hardcoded 2-1.');
        $this->assertArrayNotHasKey('home', $config);
        $this->assertArrayNotHasKey('away', $config);
    }

    public function testScoreboardUsesTheMostRecentHomeFixture(): void
    {
        $club = $this->club();
        $config = $this->stadiumConfig([], null, [
            $this->matchResult($club, 1, 4, false),  // newest, but away — not our scoreboard
            $this->matchResult($club, 3, 0, true),   // most recent home game
            $this->matchResult($club, 9, 9, true),
        ]);

        $this->assertTrue($config['scoreboard']);
        $this->assertSame(3, $config['home']);
        $this->assertSame(0, $config['away']);
    }

    public function testAwayOnlyHistoryLeavesTheScoreboardOff(): void
    {
        $club = $this->club();
        $config = $this->stadiumConfig([], null, [
            $this->matchResult($club, 2, 1, false),
            $this->matchResult($club, 0, 3, null),
        ]);

        $this->assertFalse($config['scoreboard']);
    }

    public function testUnbuiltStandsStillRenderAtTheMinimumLevel(): void
    {
        $config = $this->stadiumConfig(['north_stand' => 3, 'east_stand' => 0]);

        $this->assertSame(6, $config['north'], 'FacilityTemplate 1-5 doubles into the sprite 1-10 scale.');
        $this->assertSame(1, $config['east'], 'An unbuilt stand floors at 1 rather than vanishing.');
    }
}

